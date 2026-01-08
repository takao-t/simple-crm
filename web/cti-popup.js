document.addEventListener('DOMContentLoaded', function() {

    // ----------------------------------------------------
    // 1. CTI WebSocket クライアントロジック
    // ----------------------------------------------------

    // DYNAMIC_WS_URL は index.php で定義された変数から取得します
    const WS_SERVER_HOST = DYNAMIC_WS_URL; 
    // 自内線番号
    const MY_EXTEN = MY_EXTENSION;
    console.log('自内線番号', MY_EXTEN);
    let websocket;

    // タブローテーション用設定
    //const MAX_TABS = window.MAX_CTI_TABS ?? 8; // グローバル変数MAX_CTI_TABSが未定義なら 8
    const MAX_TABS = MAX_CTI_TABS ?? 8; // グローバル変数MAX_CTI_TABSが未定義なら 8
    let currentTabIndex = 1;  // 現在開くべきタブのインデックス (1から開始)
    
    // 状態管理: localStorageから前回の状態を読み込む
    let isPopupDisabled = (localStorage.getItem('popupDisabled') === 'true'); 
    
    // UI要素の取得
    const popupToggleButton = document.getElementById('popup-toggle-btn');

    /**
     * WebSocket接続を確立または再確立する
     */
    function connectWebSocket() {
        if (websocket && websocket.readyState === WebSocket.OPEN) {
            return;
        }

        try {
            // WS_SERVER_HOSTはPHPで動的に生成されたアドレス
            websocket = new WebSocket(WS_SERVER_HOST);
        } catch (error) {
            console.error('CTI WebSocket: 接続エラー (URL不正など):', error);
            // 接続に失敗しても、再接続ロジックに移る
        }
        
        websocket.onopen = function() {
            console.log('CTI WebSocket: 接続成功。');
            console.log('最大CTIタブ数:', MAX_TABS);
        };

        websocket.onmessage = function(event) {
            console.log('CTI WebSocket: メッセージ受信', event.data);
            handleCTIMessage(event.data);
        };

        websocket.onclose = function(event) {
            console.warn('CTI WebSocket: 接続が切れました。コード:', event.code, '理由:', event.reason);
            // 接続断を検知したら、5秒後に再接続を試みる
            setTimeout(connectWebSocket, 5000);
        };

        websocket.onerror = function(error) {
            console.error('CTI WebSocket: エラー発生', error);
            websocket.close();
        };
    }

    /**
     * WebSocketで受信したメッセージを処理し、ポップアップウィンドウを開く
     * @param {string} messageJson JSON形式の文字列
     */
    function handleCTIMessage(messageJson) {
        
        // ポップアップ停止フラグをチェック
        if (isPopupDisabled) {
            console.warn('着信通知を受信しましたが、ポップアップは現在停止中です。');
            return; // 処理を中断する
        }

        try {
            const message = JSON.parse(messageJson);

            if (message.type === 'CALL_IN' && message.data && message.data.phone) {
                const phoneNumber = message.data.phone;
                const targetExten = message.data.exten; // ★ 新しく取得する内線番号

                // -----------------------------------------------------------------
                // 選択的ポップアップロジック
                // -----------------------------------------------------------------
                let shouldPopup = false;
            
                if (targetExten === undefined || targetExten === '' || targetExten === 'all') {
                    // 1. targetExten がない/空の場合 (全クライアントが対象)
                    shouldPopup = true;
                    console.log('ブロードキャスト着信 (全クライアント対象)');
                } else if (targetExten === MY_EXTEN) {
                    // 2. targetExten が自分の内線番号と一致する場合 (特定内線への着信)
                     shouldPopup = true;
                     console.log(`特定着信: 自分の内線 (${MY_EXTEN}) へ`);
                } else {
                    // 3. targetExten があり、自分のものではない場合
                    console.log(`特定着信: 内線 ${targetExten} への着信のため無視します。`);
                }
                // -----------------------------------------------------------------

                if (shouldPopup) {
                    const phoneNumber = message.data.phone;
                    const crmPageUrl = `index.php?page=crm-page&phone=${encodeURIComponent(phoneNumber)}&popup=1`;
                
                    // -----------------------------------------------------------------
                    // タブローテーションロジックの実行
                    // -----------------------------------------------------------------
                
                    // 1. 新しいタブ名を作成 (例: CTITab1, CTITab2, ...)
                    const targetTabName = `CTITab${currentTabIndex}`;
                
                    // 2. タブを開く
                    window.open(crmPageUrl, targetTabName); 
                
                    // 3. インデックスを更新 (ローテーション)
                    currentTabIndex++;
                    if (currentTabIndex > MAX_TABS) {
                        currentTabIndex = 1;
                    }
                
                    console.log(`ポップアップ実行: ${phoneNumber} の情報を ${targetTabName} で開きました。`);
                }
            }
        } catch (e) {
            console.error('CTI WebSocket: JSONパースエラー', e);
        }
    }


    // ----------------------------------------------------
    // 2. ポップアップ停止/再開機能のロジック
    // ----------------------------------------------------

    /**
     * ポップアップの有効/無効を切り替え、状態をlocalStorageに保存する
     * @param {boolean} isDisabled - true: 停止, false: 再開
     */
    function togglePopup(isDisabled) {
        isPopupDisabled = isDisabled;
        localStorage.setItem('popupDisabled', isDisabled);
        updateToggleButtonUI(); 
        console.log('ポップアップ機能が ' + (isDisabled ? '停止' : '再開') + 'されました。');
    }

    /**
     * ボタンのテキストとスタイルを現在の状態に合わせて更新する
     */
    function updateToggleButtonUI() {
        if (!popupToggleButton) return;

        if (isPopupDisabled) {
            popupToggleButton.textContent = '✅ ポップアップを再開';
            // 停止中を視覚的に分かりやすくする (CSS側でactive-dangerを定義する必要あり)
            popupToggleButton.classList.add('active-danger');
        } else {
            popupToggleButton.textContent = '❌ ポップアップを停止';
            popupToggleButton.classList.remove('active-danger');
        }
    }

    // ----------------------------------------------------
    // 3. 初期化とイベントリスナーの設定
    // ----------------------------------------------------

    // ボタンのイベントリスナーを設定
    if (popupToggleButton) {
        popupToggleButton.addEventListener('click', function(e) {
            e.preventDefault();
            // 現在の状態を反転させてトグル関数を呼び出す
            togglePopup(!isPopupDisabled); 
        });
    }

    // ページ読み込み時にUIを初期化
    updateToggleButtonUI();

    // CTI機能が無効ならWebSocket接続を行わない
    if (typeof CTI_ENABLED !== 'undefined' && CTI_ENABLED) {
        connectWebSocket();
    } else {
        // CTI機能が無効の場合、ポップアップボタンも非表示にするか無効化するとUXが良い
        if (popupToggleButton) {
            popupToggleButton.style.display = 'none'; // ボタンを非表示にする
        }
        console.warn("CTIポップアップ機能はシステム設定により無効化されています。");
    }
});
