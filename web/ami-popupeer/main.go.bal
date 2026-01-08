package main

import (
	"bufio"
	"encoding/json"
	"fmt"
	"net"
	"os"
	"strings"
	"sync"
	"time"
)

// --- グローバルな設定と状態管理 ---

// AmiConfig は設定ファイルの内容を保持する構造体です。
type AmiConfig struct {
	Host            string   `json:"ami_host"`
	Username        string   `json:"ami_username"`
	Secret          string   `json:"ami_secret"`
	ExternalTrunks  []string `json:"external_trunks"`
	WebSocketPort   string   `json:"websocket_port"`
}

// EventData は AMI の一つのイベントまたはレスポンスを表すマップ
type EventData map[string]string

// CallChannel はアクティブな一つのチャネルの状態を保持する構造体です。
type CallChannel struct {
	UniqueID     string
	LinkedID     string
	Channel      string
	CallerIDNum  string
	CallerIDName string
	IsInternal   bool // 内線/外線判定フラグ
}

// activeChannels はアクティブなチャネルを Uniqueid で追跡するグローバルマップ
var activeChannels = make(map[string]*CallChannel)
var stateMutex sync.RWMutex // activeChannels マップへの同時アクセスを保護

// 設定情報を保持するグローバル変数
var config AmiConfig

// --- ユーティリティ関数 ---

// loadConfig は設定ファイルを読み込みます。
func loadConfig(path string) error {
	data, err := os.ReadFile(path)
	if err != nil {
		return fmt.Errorf("failed to read config file: %w", err)
	}
	if err := json.Unmarshal(data, &config); err != nil {
		return fmt.Errorf("failed to unmarshal config: %w", err)
	}
	return nil
}

// isInternalChannel は設定されたトランク名リストに基づいて内線かどうかを判定します。
func isInternalChannel(channelName string) bool {
	// PJSIP/demophone-0000003b の "demophone" 部分をチェックするために、前方一致ではなく Contains を使用
	
	// 1. 設定ファイル内のいずれかのトランク判定文字列を含む場合、外線 (return false)。
	for _, trunk := range config.ExternalTrunks {
		if strings.Contains(channelName, trunk) {
			return false // 外線
		}
	}

	// 2. Localチャネルは通常、内部的なルーティングに使用されるため、ここでは「内線」として扱わない
	if strings.HasPrefix(channelName, "Local/") {
		return false
	}

	// 3. 上記で除外されなかった SIP/PJSIP チャネルは内線と見なす
	if strings.HasPrefix(channelName, "SIP/") || strings.HasPrefix(channelName, "PJSIP/") {
		return true // 内線
	}
	
	return false
}

// parseAMIMessage は bufio.Reader から一つの完全な AMI メッセージを読み取ってパースする
func parseAMIMessage(reader *bufio.Reader) (EventData, error) {
	message := make(EventData)
	
	for {
		line, err := reader.ReadString('\n')
		if err != nil {
			return nil, err
		}
		if line == "\r\n" {
			break 
		}

		trimmedLine := strings.TrimSpace(line)
		if trimmedLine == "" {
			continue
		}

		// Key: Value の形式をパース
		parts := strings.SplitN(trimmedLine, ":", 2)
		if len(parts) == 2 {
			key := strings.TrimSpace(parts[0])
			value := strings.TrimSpace(parts[1])
			message[key] = value
		}
	}
	
	if len(message) == 0 {
		return nil, fmt.Errorf("received empty AMI message")
	}
	
	return message, nil
}

// processLoginResponse はログイン後の応答を読み取り、成功したか確認します。
func processLoginResponse(reader *bufio.Reader) bool {
	loginResponse, err := parseAMIMessage(reader)
	if err != nil {
		fmt.Printf("❌ Error reading login response: %v\n", err)
		return false
	}
	
	if loginResponse["Response"] == "Success" {
		return true
	} 

	fmt.Printf("🚫 Login Failed. Response: %s, Message: %s\n", 
		loginResponse["Response"], loginResponse["Message"])
	return false
}

// --- イベントハンドラ ---

// handleAMIEvent は受信したAMIイベントを処理し、状態を更新または検出します。
func handleAMIEvent(e EventData) {
	eventType := e["Event"]
	
	switch eventType {
	case "Newchannel":
		// 新しいチャネルが作成されたときに、状態マップに追加
		uniqueID := e["Uniqueid"]
		channelName := e["Channel"]
		
		channel := &CallChannel{
			UniqueID:     uniqueID,
			LinkedID:     e["Linkedid"],
			Channel:      channelName,
			CallerIDNum:  e["CallerIDNum"],
			CallerIDName: e["CallerIDName"],
			IsInternal:   isInternalChannel(channelName),
		}
		
		stateMutex.Lock()
		activeChannels[uniqueID] = channel
		stateMutex.Unlock()
		
		fmt.Printf("ℹ️ Newchannel: %s (LinkedID: %s, Internal: %t) added.\n", channelName, channel.LinkedID, channel.IsInternal)
		
	case "BridgeEnter":
		// チャネルがブリッジに参加した（通話成立のトリガー）
		uniqueID := e["Channel"] // BridgeEnterはChannelキーでUniqueidを示す
		linkedID := e["Linkedid"]
		
		stateMutex.RLock()
		internalChannel, ok := activeChannels[uniqueID]
		stateMutex.RUnlock()

		if !ok || !internalChannel.IsInternal {
			// 内線ではない、または追跡されていないチャネルは無視
			return
		}

		// 検出基準: 同じ Linkedid を持つ外線チャネルが既に存在しているかチェック
		stateMutex.RLock()
		var externalChannel *CallChannel
		for _, ch := range activeChannels {
			// 1. 同じ Linkedid を持つ（親通話に属する）
			// 2. 自身ではない
			// 3. 外線である
			if ch.UniqueID != uniqueID && ch.LinkedID == linkedID && !ch.IsInternal {
				externalChannel = ch
				break
			}
		}
		stateMutex.RUnlock()
		
		if externalChannel != nil {
			// 内線と外線が同じ通話内でブリッジに参加したことを検出
			triggerPopup(internalChannel, externalChannel)
		}

	case "Hangup":
		// チャネルが切断されたときに、状態マップから削除
		uniqueID := e["Uniqueid"]
		
		stateMutex.Lock()
		if _, exists := activeChannels[uniqueID]; exists {
			delete(activeChannels, uniqueID)
			// fmt.Printf("✅ Hangup: Uniqueid %s removed from state.\n", uniqueID)
		}
		stateMutex.Unlock()
		
	default:
		// その他のイベントは無視
	}
}

// triggerPopup はポップアップに必要な情報をコンソールに出力します。
// TODO: Step 4でWebSocket送信ロジックに置き換えます。
func triggerPopup(internal *CallChannel, external *CallChannel) {
	// WebSocket経由でブラウザに送るJSONペイロードを生成
	message := fmt.Sprintf(
		`{"event":"call_bridged", "internal_channel": "%s", "external_caller_id": "%s", "external_caller_name": "%s", "uniqueid": "%s"}`,
		internal.Channel, external.CallerIDNum, external.CallerIDName, external.LinkedID,
	)
	
	fmt.Printf("\n\n#################################################################\n")
	fmt.Printf("🎯 POPUP TRIGGERED: Internal Call established!\n")
	fmt.Printf("  -> 内線 (Responder): %s (ID: %s)\n", internal.Channel, internal.CallerIDNum)
	fmt.Printf("  -> 外線 (Caller):   %s (ID: %s, Name: %s)\n", external.Channel, external.CallerIDNum, external.CallerIDName)
	fmt.Printf("  -> JSON Payload (Next Step): %s\n", message)
	fmt.Printf("#################################################################\n\n")

	// TODO: Step 4: ここに WebSocket 送信ロジックを実装
}

// --- メイン関数 ---

func main() {
	// 1. 設定ファイルの読み込み
	if err := loadConfig("config.json"); err != nil {
		fmt.Printf("❌ Configuration Error: %v\n", err)
		os.Exit(1)
	}
	fmt.Println("✅ Configuration loaded successfully.")
	
	// 2. AMI接続
	amiAddr := fmt.Sprintf("%s", config.Host)
	fmt.Printf("AMI Popupper PoC: Connecting to %s\n", amiAddr)
	
	conn, err := net.Dial("tcp", amiAddr)
	if err != nil {
		fmt.Printf("❌ Error connecting to AMI: %v\n", err)
		os.Exit(1)
	}
	
	defer func() {
		fmt.Println("\nAttempting Logoff and connection close...")
		// Logoffコマンドの送信
		logoffCommand := "Action: Logoff\r\n\r\n"
		conn.Write([]byte(logoffCommand)) 
		conn.Close()
		fmt.Println("Connection closed.")
	}()

	reader := bufio.NewReader(conn)
	
	// AMIヘッダー読み取り（スキップ）
	if _, err := reader.ReadString('\n'); err != nil {
		fmt.Printf("❌ Error reading AMI header: %v\n", err)
		return
	}

	// ログイン
	loginCommand := fmt.Sprintf(
		"Action: Login\r\nUsername: %s\r\nSecret: %s\r\n\r\n",
		config.Username, config.Secret,
	)
	if _, err = conn.Write([]byte(loginCommand)); err != nil {
		fmt.Printf("❌ Error sending login command: %v\n", err)
		return
	}

	if !processLoginResponse(reader) {
		return
	}
	
	fmt.Println("🚀 Starting AMI event monitoring...")
	
	// イベントの受信ループ
	for {
		event, err := parseAMIMessage(reader)
		if err != nil {
			if err.Error() == "EOF" {
				fmt.Println("\n\nAMI connection closed (EOF). Exiting.")
				break
			}
			fmt.Printf("\n\n⚠️ Error during event reading: %v. Retrying...\n", err)
			time.Sleep(500 * time.Millisecond)
			continue
		}

		if event["Event"] != "" {
			handleAMIEvent(event)
		}
	}
}
