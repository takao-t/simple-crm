package main

import (
	"bufio"
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"net"
	"net/http"
	"os"
	"strings"
	"sync"
	"time"

	"github.com/gorilla/websocket"
)

// --- 構造体定義 ---

// AclEntry: 個別IPまたはCIDRを表す構造体
type AclEntry struct {
	IPNet *net.IPNet // CIDR (例: 192.168.0.0/16) の場合
	IP    net.IP     // 個別IP (例: 127.0.0.1) の場合
}

// Config: サーバー設定を保持する構造体
type Config struct {
	HttpPort    string // トリガー用ポート (HTTP)
	ClientPort  string // クライアント用ポート (WS/WSS)
	UseSSL      bool   // SSLを使用するかどうか
	CertFile    string // 証明書ファイルパス
	KeyFile     string // 鍵ファイルパス
	SecretToken string

	// ACL設定 (パース前の文字列)
	TriggerAllowStr string
	CrmWsAllowStr   string
	// ACL設定 (パース済み)
	TriggerACL []AclEntry // /api/trigger 用のACL
	CrmWsACL   []AclEntry // /crmws 用のACL
}

// Client: 個別のWebSocket接続を表す
type Client struct {
	hub  *Hub
	conn *websocket.Conn
	send chan []byte
}

// Hub: 接続されているクライアントとメッセージのブロードキャストを管理
type Hub struct {
	clients    sync.Map // map[*Client]bool
	broadcast  chan []byte
	register   chan *Client
	unregister chan *Client
}

// --- 定数 ---

const (
	writeWait = 10 * time.Second
)

var upgrader = websocket.Upgrader{
	CheckOrigin: func(r *http.Request) bool {
		return true
	},
}

// --- ACL関連関数 ---

// parseACL: コンマ区切りの文字列からACLエントリーのリストを作成
func parseACL(allowListStr string) ([]AclEntry, error) {
	if allowListStr == "" {
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

// getRemoteIP: リクエスト元のIPアドレスを取得
func getRemoteIP(r *http.Request) net.IP {
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		return nil
	}
	ip := net.ParseIP(host)
	if v4 := ip.To4(); v4 != nil {
		return v4
	}
	return ip
}

// checkACL: リクエスト元のIPがACLリストに含まれているか確認
func checkACL(r *http.Request, acl []AclEntry) bool {
	remoteIP := getRemoteIP(r)
	if remoteIP == nil {
		log.Printf("ACL Check: Failed to parse remote IP from %s", r.RemoteAddr)
		return false
	}
	remoteIPStr := remoteIP.String()

	if len(acl) == 0 {
		log.Printf("ACL Denied (Default: Deny All): %s", remoteIPStr)
		return false
	}

	for _, entry := range acl {
		if entry.IPNet != nil && entry.IPNet.Contains(remoteIP) {
			return true
		}
		if entry.IP != nil && entry.IP.Equal(remoteIP) {
			return true
		}
	}

	log.Printf("ACL Denied: %s is not in the allowed list.", remoteIPStr)
	return false
}

// --- 設定読み込み ---

func loadConfig(path string) (*Config, error) {
	file, err := os.Open(path)
	if err != nil {
		return nil, fmt.Errorf("failed to open config file %s: %w", path, err)
	}
	defer file.Close()

	config := &Config{
		HttpPort:   "8989", // デフォルト値
		ClientPort: "8990", // デフォルト値
		UseSSL:     false,
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
			case "HTTP_PORT", "PORT": // 互換性のためにPORTも残す
				config.HttpPort = val
			case "CLIENT_PORT", "WSS_PORT":
				config.ClientPort = val
			case "USE_SSL":
				valLower := strings.ToLower(val)
				if valLower == "true" || valLower == "1" || valLower == "yes" {
					config.UseSSL = true
				} else {
					config.UseSSL = false
				}
			case "CERT_FILE":
				config.CertFile = val
			case "KEY_FILE":
				config.KeyFile = val
			case "SECRET_TOKEN":
				config.SecretToken = val
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

	config.TriggerACL, err = parseACL(config.TriggerAllowStr)
	if err != nil {
		return nil, fmt.Errorf("failed to parse TRIGGER_ALLOW: %w", err)
	}
	config.CrmWsACL, err = parseACL(config.CrmWsAllowStr)
	if err != nil {
		return nil, fmt.Errorf("failed to parse CRMWS_ALLOW: %w", err)
	}

	return config, nil
}

// --- Hub/Client ロジック ---

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
			if _, ok := h.clients.Load(client); ok {
				h.clients.Delete(client)
				close(client.send)
				log.Printf("Client unregistered: %p. Total clients: %d", client, h.clientCount())
			}

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

// serveWs: WebSocket接続を処理するハンドラー
func serveWs(hub *Hub, config *Config, w http.ResponseWriter, r *http.Request) {
	if !checkACL(r, config.CrmWsACL) {
		http.Error(w, "Forbidden (ACL)", http.StatusForbidden)
		return
	}

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

	if !checkACL(r, config.TriggerACL) {
		http.Error(w, "Forbidden (ACL)", http.StatusForbidden)
		return
	}

	token := r.URL.Query().Get("token")
	if token != config.SecretToken {
		log.Printf("Auth failed: Invalid token '%s'", token)
		http.Error(w, "Unauthorized", http.StatusUnauthorized)
		return
	}

	phone := r.URL.Query().Get("phone")
	if phone == "" {
		http.Error(w, "Bad Request: 'phone' parameter is missing", http.StatusBadRequest)
		return
	}
	exten := r.URL.Query().Get("exten")

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

	message, err := json.Marshal(data)
	if err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}

	hub.broadcast <- message

	log.Printf("Trigger received: Phone=%s. Broadcasted to %d clients.", phone, hub.clientCount())

	w.WriteHeader(http.StatusOK)
	w.Write([]byte("OK"))
}

// --- Main関数 ---

func main() {
	log.SetFlags(log.Ldate | log.Ltime | log.Lshortfile)

	configFilePath := flag.String("config", "/usr/local/etc/popup-notifier.conf", "Path to the configuration file.")
	flag.Parse()

	config, err := loadConfig(*configFilePath)
	if err != nil {
		log.Fatalf("Configuration error: %v", err)
	}

	log.Printf("Starting CTI Server...")
	log.Printf("- HTTP Trigger Listener: :%s", config.HttpPort)

	protocol := "WS"
	if config.UseSSL {
		protocol = "WSS"
	}
	log.Printf("- WebSocket Client Listener (%s): :%s", protocol, config.ClientPort)
	log.Printf("- Loaded SECRET_TOKEN: %s", config.SecretToken)
	log.Printf("- TRIGGER_ALLOW: %s (Parsed: %d entries)", config.TriggerAllowStr, len(config.TriggerACL))
	log.Printf("- CRMWS_ALLOW: %s (Parsed: %d entries)", config.CrmWsAllowStr, len(config.CrmWsACL))

	hub := newHub()
	go hub.run()

	// ---------------------------------------------------------
	// 1. HTTPサーバー (トリガー用) の起動 - Goroutine
	// ---------------------------------------------------------
	httpMux := http.NewServeMux()
	httpMux.HandleFunc("/api/trigger", func(w http.ResponseWriter, r *http.Request) {
		triggerHandler(hub, config, w, r)
	})

	go func() {
		// トリガー用は常にHTTPで起動
		err := http.ListenAndServe(":"+config.HttpPort, httpMux)
		if err != nil {
			log.Fatalf("HTTP Trigger Server failed: %v", err)
		}
	}()

	// ---------------------------------------------------------
	// 2. クライアント用サーバー (設定によりWS/WSS) の起動 - Main Thread
	// ---------------------------------------------------------
	clientMux := http.NewServeMux()
	clientMux.HandleFunc("/crmws", func(w http.ResponseWriter, r *http.Request) {
		serveWs(hub, config, w, r)
	})

	addr := ":" + config.ClientPort
	if config.UseSSL {
		// 証明書ファイルの存在チェック
		if _, err := os.Stat(config.CertFile); os.IsNotExist(err) {
			log.Fatalf("Error: USE_SSL is true but CERT_FILE not found: %s", config.CertFile)
		}
		if _, err := os.Stat(config.KeyFile); os.IsNotExist(err) {
			log.Fatalf("Error: USE_SSL is true but KEY_FILE not found: %s", config.KeyFile)
		}

		log.Printf("Listening for Secure WebSocket (WSS) on %s", addr)
		err = http.ListenAndServeTLS(addr, config.CertFile, config.KeyFile, clientMux)
	} else {
		log.Printf("Listening for WebSocket (WS) on %s", addr)
		err = http.ListenAndServe(addr, clientMux)
	}

	if err != nil {
		log.Fatal("Client Server failed: ", err)
	}
}
