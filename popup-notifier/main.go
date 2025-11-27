package main

import (
	"bufio"
	"fmt"
	"flag"
	"log"
	"net/http"
	"os"
	"strings"
	"sync"
	"time"
	"encoding/json"
	"net"

	"github.com/gorilla/websocket"
)

// AclEntry: 個別IPまたはCIDRを表す構造体
type AclEntry struct {
	IPNet *net.IPNet // CIDR (例: 192.168.0.0/16) の場合
	IP    net.IP     // 個別IP (例: 127.0.0.1) の場合
}

// Config: サーバー設定を保持する構造体
type Config struct {
	Port        string
	SecretToken string
	// ACL設定 (パース前の文字列)
	TriggerAllowStr string
	CrmWsAllowStr   string
	// ACL設定 (パース済み)
	TriggerACL []AclEntry // /api/trigger 用のACL
	CrmWsACL   []AclEntry   // /crmws 用のACL
}

// parseACL: コンマ区切りの文字列からACLエントリーのリストを作成
func parseACL(allowListStr string) ([]AclEntry, error) {
	if allowListStr == "" {
		// 設定がない場合は空のリストを返す (ハンドラーで全拒否として扱われる)
		return []AclEntry{}, nil
	}

	var entries []AclEntry
	parts := strings.Split(allowListStr, ",")

	for _, part := range parts {
		ipStr := strings.TrimSpace(part)
		if ipStr == "" {
			continue
		}

		// 1. CIDR形式のパースを試みる
		_, ipNet, err := net.ParseCIDR(ipStr)
		if err == nil {
			entries = append(entries, AclEntry{IPNet: ipNet})
			continue
		}

		// 2. 個別IP形式のパースを試みる
		ip := net.ParseIP(ipStr)
		if ip != nil {
			// IPv4アドレスの場合はIPv4として扱う
			if v4 := ip.To4(); v4 != nil {
				entries = append(entries, AclEntry{IP: v4})
			} else {
				entries = append(entries, AclEntry{IP: ip})
			}
			continue
		}

		return nil, fmt.Errorf("invalid IP or CIDR format: %s", ipStr)
	}

	return entries, nil
}

// loadConfig: 設定ファイルを読み込む (key=value形式)
func loadConfig(path string) (*Config, error) {
	file, err := os.Open(path)
	if err != nil {
		return nil, fmt.Errorf("failed to open config file %s: %w", path, err)
	}
	defer file.Close()

	config := &Config{
		Port: "8080", // デフォルト値を設定
	}

	scanner := bufio.NewScanner(file)
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}

		parts := strings.SplitN(line, "=", 2)
		if len(parts) == 2 {
			key := strings.TrimSpace(parts[0])
			val := strings.TrimSpace(parts[1])
			switch key {
			case "PORT":
				config.Port = val
			case "SECRET_TOKEN":
				config.SecretToken = val
			// ACL設定の追加
			case "TRIGGER_ALLOW":
				config.TriggerAllowStr = val
			case "CRMWS_ALLOW":
				config.CrmWsAllowStr = val
			}
		}
	}
    
	if config.SecretToken == "" {
		return nil, fmt.Errorf("SECRET_TOKEN is missing in config file %s", path)
	}

	// ACLのパース
	config.TriggerACL, err = parseACL(config.TriggerAllowStr)
	if err != nil {
		return nil, fmt.Errorf("failed to parse TRIGGER_ALLOW: %w", err)
	}
	config.CrmWsACL, err = parseACL(config.CrmWsAllowStr)
	if err != nil {
		return nil, fmt.Errorf("failed to parse CRMWs_ALLOW: %w", err)
	}

	return config, nil
}

// --- ACLチェックヘルパー ---

// getRemoteIP: リクエスト元のIPアドレスを取得 (RemoteAddrからホスト部をパース)
func getRemoteIP(r *http.Request) net.IP {
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		return nil
	}
	ip := net.ParseIP(host)
    // IPv4にマップされたIPv6アドレスをIPv4アドレスとして返す
    if v4 := ip.To4(); v4 != nil {
        return v4
    }
	return ip
}

// checkACL: リクエスト元のIPがACLリストに含まれているか確認
// ACLが空の場合 (設定がない場合) は「全拒否」(false)
func checkACL(r *http.Request, acl []AclEntry) bool {
	remoteIP := getRemoteIP(r)

	// IPが取得できない場合は拒否
	if remoteIP == nil {
		log.Printf("ACL Check: Failed to parse remote IP from %s", r.RemoteAddr)
		return false
	}
    
	remoteIPStr := remoteIP.String()

	// ACLが空の場合は「全拒否」のルールを適用
	if len(acl) == 0 {
		log.Printf("ACL Denied (Default: Deny All): %s", remoteIPStr)
		return false
	}

	for _, entry := range acl {
		// CIDRチェック (例: 192.168.0.0/16 に含まれるか)
		if entry.IPNet != nil && entry.IPNet.Contains(remoteIP) {
			return true 
		}
		// 個別IPチェック (例: 127.0.0.1 と一致するか)
		if entry.IP != nil && entry.IP.Equal(remoteIP) {
			return true 
		}
	}

	log.Printf("ACL Denied: %s is not in the allowed list.", remoteIPStr)
	return false
}

// --- Hub/Client の定義 ---

// Hub: 接続されているクライアントとメッセージのブロードキャストを管理
type Hub struct {
    clients      sync.Map // map[*Client]bool
    broadcast    chan []byte
    register     chan *Client
    unregister   chan *Client
}

func newHub() *Hub {
    return &Hub{
        broadcast:  make(chan []byte),
        register:   make(chan *Client),
        unregister: make(chan *Client),
    }
}

func (h *Hub) run() {
    for {
        select {
        case client := <-h.register:
            h.clients.Store(client, true)
            log.Printf("Client registered: %p. Total clients: %d", client, h.clientCount())
        
        case client := <-h.unregister:
            h.clients.Delete(client)
            close(client.send)
            log.Printf("Client unregistered: %p. Total clients: %d", client, h.clientCount())

        case message := <-h.broadcast:
            h.clients.Range(func(key, value interface{}) bool {
                client := key.(*Client)
                select {
                case client.send <- message:
                default:
                    h.unregister <- client
                }
                return true
            })
        }
    }
}

func (h *Hub) clientCount() int {
    count := 0
    h.clients.Range(func(_, _ interface{}) bool {
        count++
        return true
    })
    return count
}

// Client: 個別のWebSocket接続を表す
type Client struct {
    hub  *Hub
    conn *websocket.Conn
    send chan []byte
}

const (
    writeWait = 10 * time.Second
)

func (c *Client) writePump() {
    defer func() {
        c.hub.unregister <- c
        c.conn.Close()
    }()
    
    for {
        select {
        case message, ok := <-c.send:
            c.conn.SetWriteDeadline(time.Now().Add(writeWait))
            if !ok {
                c.conn.WriteMessage(websocket.CloseMessage, []byte{})
                return
            }

            w, err := c.conn.NextWriter(websocket.TextMessage)
            if err != nil {
                return
            }
            w.Write(message)

            if err := w.Close(); err != nil {
                return
            }
        }
    }
}

// --- ハンドラー ---

var upgrader = websocket.Upgrader{
    CheckOrigin: func(r *http.Request) bool {
        return true
    },
}

// serveWs: WebSocket接続を処理するハンドラー
func serveWs(hub *Hub, config *Config, w http.ResponseWriter, r *http.Request) {
    // --- ACLチェック ---
	if !checkACL(r, config.CrmWsACL) {
		http.Error(w, "Forbidden (ACL)", http.StatusForbidden)
		return
	}
    // -------------------
    
    conn, err := upgrader.Upgrade(w, r, nil)
    if err != nil {
        log.Println("Upgrade error:", err)
        return
    }
    client := &Client{hub: hub, conn: conn, send: make(chan []byte, 256)}
    
    client.hub.register <- client

    go client.writePump()
}

// triggerHandler: Asteriskからの着信通知を受け取る
func triggerHandler(hub *Hub, config *Config, w http.ResponseWriter, r *http.Request) {
    if r.Method != http.MethodPost {
        http.Error(w, "Method Not Allowed", http.StatusMethodNotAllowed)
        return
    }
    
    // --- ACLチェック ---
	if !checkACL(r, config.TriggerACL) {
		http.Error(w, "Forbidden (ACL)", http.StatusForbidden)
		return
	}
    // -------------------

    // 1. トークン認証
    token := r.URL.Query().Get("token")
    if token != config.SecretToken { // Configからトークンを比較
        log.Printf("Auth failed: Invalid token '%s'", token)
        http.Error(w, "Unauthorized", http.StatusUnauthorized)
        return
    }

    // 2.1 パラメータ取得 (phone=...)
    phone := r.URL.Query().Get("phone")
    if phone == "" {
        http.Error(w, "Bad Request: 'phone' parameter is missing", http.StatusBadRequest)
        return
    }

    // 2.2 パラメータ取得(exten=...)
    exten := r.URL.Query().Get("exten")

    // 3. JSONメッセージの作成

    data := struct {
        Type string `json:"type"`
        Data struct {
            Phone string `json:"phone"`
            Exten string `json:"exten"`
        } `json:"data"`
    }{
        Type: "CALL_IN",
        Data: struct {
            Phone string `json:"phone"`
            Exten string `json:"exten"`
        }{
            Phone: phone,
            Exten: exten,
        },
    }

    // JSONバイト列に変換
    message, err := json.Marshal(data)
    if err != nil {
        http.Error(w, err.Error(), http.StatusInternalServerError)
        return
    }
    
    // 4. メッセージをハブのブロードキャストチャネルへ送る
    hub.broadcast <- message
    
    log.Printf("Trigger received: Phone=%s. Broadcasted to %d clients.", phone, hub.clientCount())

    w.WriteHeader(http.StatusOK)
    w.Write([]byte("OK"))
}

// --- Main関数 ---

func main() {
    log.SetFlags(log.Ldate | log.Ltime | log.Lshortfile)
    
    // 1. 設定の読み込み
    // デフォルト値としてパスを設定
    configFilePath := flag.String("config", "/usr/local/etc/popup-notifier.conf", "Path to the configuration file.")

    // 2. コマンドライン引数をパース
    flag.Parse()

    // 3. 設定の読み込みに、パースされたパスを使用
    config, err := loadConfig(*configFilePath)
    if err != nil {
        // 設定ファイルが見つからない、または形式が不正な場合は致命的エラー
        log.Fatalf("Configuration error: %v", err)
    }

    log.Printf("Starting CTI WebSocket Server on :%s...", config.Port)
    log.Printf("Loaded SECRET_TOKEN: %s", config.SecretToken)
    log.Printf("TRIGGER_ALLOW: %s (Parsed: %d entries)", config.TriggerAllowStr, len(config.TriggerACL))
    log.Printf("CRMWS_ALLOW: %s (Parsed: %d entries)", config.CrmWsAllowStr, len(config.CrmWsACL))

    // 3. Hubのインスタンス化と実行
    hub := newHub()
    go hub.run()
    
    // 4. ルーティング設定
    // serveWsにconfigを渡すように修正
    http.HandleFunc("/crmws", func(w http.ResponseWriter, r *http.Request) {
        serveWs(hub, config, w, r) 
    })
    
    // 設定を渡すためにクロージャを使用
    http.HandleFunc("/api/trigger", func(w http.ResponseWriter, r *http.Request) {
        triggerHandler(hub, config, w, r)
    })

    // 5. 指定ポートででサーバーを起動
    err = http.ListenAndServe(":"+config.Port, nil)
    if err != nil {
        log.Fatal("ListenAndServe failed: ", err)
    }
}
