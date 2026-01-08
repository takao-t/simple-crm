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

type AmiConfig struct {
	Host            string   `json:"ami_host"`
	Username        string   `json:"ami_username"`
	Secret          string   `json:"ami_secret"`
	ExternalTrunks  []string `json:"external_trunks"`
	WebSocketPort   string   `json:"websocket_port"`
}

type EventData map[string]string

// CallChannel はチャネルの状態を保持
type CallChannel struct {
	UniqueID     string
	LinkedID     string
	Channel      string
	CallerIDNum  string
	CallerIDName string
	IsInternal   bool // 内線/外線判定フラグ
	IsBridged    bool // ブリッジに参加したか (新しいフィールド)
	IsUp         bool   // ChannelStateDesc が Up か
}

var activeChannels = make(map[string]*CallChannel)
var stateMutex sync.RWMutex 
var config AmiConfig

// --- ユーティリティ関数 ---

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

func isInternalChannel(channelName string) bool {
	for _, trunk := range config.ExternalTrunks {
		if strings.Contains(channelName, trunk) {
			return false // 外線
		}
	}
	if strings.HasPrefix(channelName, "Local/") {
		return false // Localチャネルは通常、内部ルーティング用
	}
	if strings.HasPrefix(channelName, "SIP/") || strings.HasPrefix(channelName, "PJSIP/") {
		return true // 内線
	}
	return false
}

// parseAMIMessage, processLoginResponse は前述のコードと同一のため省略
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
	uniqueID := e["Uniqueid"]
	channelName := e["Channel"]
	
	stateMutex.Lock()
	defer stateMutex.Unlock()
	
	// 既存のチャネルまたは新規チャネルを取得/作成
	channel, exists := activeChannels[uniqueID]
	if !exists && eventType == "Newchannel" {
		channel = &CallChannel{
			UniqueID:     uniqueID,
			LinkedID:     e["Linkedid"],
			Channel:      channelName,
			IsInternal:   isInternalChannel(channelName),
		}
		activeChannels[uniqueID] = channel
	} else if !exists {
		// Newchannel以外のイベントで追跡していないIDの場合は無視
		return
	}

	// 状態の更新
	switch eventType {
	case "Newchannel":
		// 初回情報の設定（Newchannel以外でも CallerID は更新される可能性があるが、PoCではNewchannel時に固定）
		if channel.CallerIDNum == "" {
			channel.CallerIDNum = e["CallerIDNum"]
			channel.CallerIDName = e["CallerIDName"]
		}
		
	case "Newstate":
		// チャネルの状態が Up (通話可能) になったことを記録
		if e["ChannelStateDesc"] == "Up" {
			channel.IsUp = true
		}
		
	case "BridgeEnter":
		// ブリッジに参加したことを記録
		channel.IsBridged = true
		
		// 🚀 ポップアップ検出ロジック (内線がブリッジに参加した時に、対応する外線を探す)
		if channel.IsInternal {
			// Linkedid を元に、ペアとなる外線チャネルを検索
			var externalChannel *CallChannel
			for _, ch := range activeChannels {
				// 1. 同じ Linkedid を持つ
				// 2. 自身ではない
				// 3. 外線である
				if ch.UniqueID != uniqueID && ch.LinkedID == channel.LinkedID && !ch.IsInternal {
					externalChannel = ch
					break
				}
			}
			
			// 最終チェック: 外線チャネルが見つかった場合
			if externalChannel != nil {
				// Internal と External のペアが揃った -> ポップアップをトリガー
				triggerPopup(channel, externalChannel)
			}
		}

	case "Hangup":
		// 終了したチャネルを削除
		delete(activeChannels, uniqueID)
	}
}

// triggerPopup はポップアップに必要な情報をコンソールに出力します。
func triggerPopup(internal *CallChannel, external *CallChannel) {
	// 一度検出したら重複して通知しないように、Internalチャネルをすぐに非アクティブ化 (IsBridgedをfalseに)
	// Mutexは既にロックされているため安全
	if !internal.IsBridged { 
		return // 既にトリガー済みか、Localチャネルのブリッジに巻き込まれただけの可能性
	}
	internal.IsBridged = false 
	
	// JSONペイロードを生成
	message := fmt.Sprintf(
		`{"event":"call_bridged", "internal_channel": "%s", "external_caller_id": "%s", "external_caller_name": "%s", "uniqueid": "%s"}`,
		internal.Channel, external.CallerIDNum, external.CallerIDName, external.LinkedID,
	)
	
	fmt.Printf("\n\n#################################################################\n")
	fmt.Printf("🎯 POPUP TRIGGERED (Bridge Detected)!\n")
	fmt.Printf("  -> 内線 (Responder): %s (ID: %s)\n", internal.Channel, internal.CallerIDNum)
	fmt.Printf("  -> 外線 (Caller):   %s (ID: %s, Name: %s)\n", external.Channel, external.CallerIDNum, external.CallerIDName)
	fmt.Printf("  -> JSON Payload (Next Step): %s\n", message)
	fmt.Printf("#################################################################\n\n")

	// TODO: Step 4: ここに WebSocket 送信ロジックを実装
}

// --- メイン関数 ---

func main() {
	if err := loadConfig("config.json"); err != nil {
		fmt.Printf("❌ Configuration Error: %v\n", err)
		os.Exit(1)
	}
	fmt.Println("✅ Configuration loaded successfully.")
	
	amiAddr := fmt.Sprintf("%s", config.Host)
	fmt.Printf("AMI Popupper PoC: Connecting to %s\n", amiAddr)
	
	conn, err := net.Dial("tcp", amiAddr)
	if err != nil {
		fmt.Printf("❌ Error connecting to AMI: %v\n", err)
		os.Exit(1)
	}
	
	defer func() {
		fmt.Println("\nAttempting Logoff and connection close...")
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
