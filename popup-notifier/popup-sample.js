document.addEventListener('DOMContentLoaded', function() {

    const WS_SERVER_HOST = 'ws://192.168.100.1:8989/crmws'
    // 自内線番号
    const MY_EXTEN = '2001';
    console.log('自内線番号', MY_EXTEN);
    let websocket;

    /**
     * WebSocket接続を確立または再確立する
     */
    function connectWebSocket() {
        if (websocket && websocket.readyState === WebSocket.OPEN) {
            return;
        }

        try {
            websocket = new WebSocket(WS_SERVER_HOST);
        } catch (error) {
            console.error('CTI WebSocket: 接続エラー:', error);
        }
        
        websocket.onopen = function() {
            console.log('CTI WebSocket: 接続成功。');
        };

        websocket.onmessage = function(event) {
            console.log('CTI WebSocket: メッセージ受信', event.data);
            handleCTIMessage(event.data);
        };

        websocket.onclose = function(event) {
            console.warn('CTI WebSocket: 接続が切れました。コード:', event.code, '理由:', event.reason);
            // 接続断を検知したら、10秒後に再接続を試みる
            setTimeout(connectWebSocket, 10000);
        };

        websocket.onerror = function(error) {
            console.error('CTI WebSocket: エラー', error);
            websocket.close();
        };
    }

    /**
     * WebSocketで受信したメッセージを処理し、ポップアップウィンドウを開く
     * @param {string} messageJson JSON形式の文字列
     */
    function handleCTIMessage(messageJson) {
        
        try {
            const message = JSON.parse(messageJson);

            if (message.type === 'CALL_IN' && message.data && message.data.phone) {
                const phoneNumber = message.data.phone;
                const targetExten = message.data.exten;

                // -----------------------------------------------------------------
                // 選択的ポップアップロジック
                // -----------------------------------------------------------------
                let shouldPopup = false;
            
                if (targetExten === undefined || targetExten === '' || targetExten === 'all') {
                    shouldPopup = true;
                    console.log('ブロードキャスト着信 (全クライアント対象)');
                } else if (targetExten === MY_EXTEN) {
                     shouldPopup = true;
                     console.log(`特定着信: 自分の内線 (${MY_EXTEN}) へ`);
                } else {
                    console.log(`特定着信: 内線 ${targetExten} への着信のため無視します。`);
                }
                // -----------------------------------------------------------------

                if (shouldPopup) {
                    const phoneNumber = message.data.phone;
                    // ポップアップさせるページのURL
                    // URLはサンプル
                    const crmPageUrl = `index.php?page=crm-page&phone=${encodeURIComponent(phoneNumber)}`;
                    window.open(crmPageUrl, '_blank'); 
                
                    console.log(`ポップアップ実行`);
                }
            }
        } catch (e) {
            console.error('CTI WebSocket: JSONパースエラー', e);
        }
    }

    connectWebSocket();

});
