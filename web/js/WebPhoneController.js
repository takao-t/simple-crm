import { WebPhone } from './WebPhone.js';

export class WebPhoneController {
    constructor(config) {
        this.config = config;
        this.phone = null; // WebPhone Instance
        this.state = 'DISCONNECTED'; // DISCONNECTED, IDLE, RINGING, TALKING

        // DOM Elements
        this.el = {
            btnConnect: document.getElementById('wp-btn-connect'),
            btnDisconnect: document.getElementById('wp-btn-disconnect'),
            input: document.getElementById('wp-input-number'),
            btnCall: document.getElementById('wp-btn-call'),
            btnHangup: document.getElementById('wp-btn-hangup'),
            status: document.getElementById('wp-status-display')
        };

        this.bindEvents();
        
        // ページリロード時のログイン状態復帰チェック
        const shouldAutoConnect = sessionStorage.getItem('wp_autoconnect') === 'true';
        if (shouldAutoConnect) {
            this.handleConnect();
        } else {
            this.updateUI();
        }
    }

    bindEvents() {
        this.el.btnConnect.addEventListener('click', () => this.handleConnect());
        this.el.btnDisconnect.addEventListener('click', () => this.handleDisconnect());
        
        this.el.btnCall.addEventListener('click', () => this.handleCallAction());
        this.el.btnHangup.addEventListener('click', () => this.handleHangupAction());
    }

    async handleConnect() {
        this.updateStatus('接続処理中...', '');
        
        // 切断理由を一時的に保持する変数
        let disconnectReason = null;

        try {
            // 1. PHPからJWTトークンを取得
            const res = await fetch(this.config.tokenUrl);
            if (!res.ok) throw new Error('Token fetch failed');
            const data = await res.json();

            // 2. WebPhone初期化
            this.phone = new WebPhone({
                wsUrl: this.config.wsUrl,
                token: data.token
            });

            // 3. イベントハンドラ設定
            this.phone.on('onConnect', () => {
                this.state = 'IDLE';
                sessionStorage.setItem('wp_autoconnect', 'true');
                this.updateUI();
                this.updateStatus('待受中 (IDLE)', 'status-connected');
                disconnectReason = null; // 接続成功時にリセット
            });

            this.phone.on('onDisconnect', (evt) => {
                this.state = 'DISCONNECTED';
                this.updateUI();

                // 優先順位: 
                // 1. 直前のonHangup/onErrorで保存された理由 (BUSY/KICKEDなど)
                // 2. onDisconnectイベント自体が持っている理由 (もしあれば)
                // 3. デフォルトの「未接続」
                const reason = disconnectReason || (typeof evt === 'string' ? evt : null);

                if (reason) {
                    // 理由がある場合はエラーっぽく表示（ここではstatus-talkingクラスを流用して目立たせる）
                    this.updateStatus(`未接続: ${reason}`, 'status-talking');
                } else {
                    this.updateStatus('未接続', '');
                }
                
                // 次回のためにリセット
                disconnectReason = null;
            });

            this.phone.on('onRing', () => {
                this.state = 'RINGING';
                this.updateUI();
                this.updateStatus('着信中...', 'status-ringing');
            });

            this.phone.on('onHangup', (reason) => {
                // サーバーから "BUSY" や "KICKED" が送られてきた場合
                if (reason === 'BUSY' || reason === 'KICKED' || reason === 'DUPLICATE') {
                    disconnectReason = reason; // 切断理由として保存
                    this.updateStatus(`接続拒否: ${reason}`, 'status-talking');
                    // ここではIDLEに戻さず、直後に来るonDisconnectを待つ
                    return; 
                }

                // 通常の通話終了処理
                this.state = 'IDLE';
                this.updateUI();
                this.updateStatus(`切断: ${reason}`, 'status-connected');
                setTimeout(() => {
                    // まだIDLEなら待受表示に戻す（切断されていない場合のみ）
                    if(this.state === 'IDLE') this.updateStatus('待受中 (IDLE)', 'status-connected');
                }, 2000);
            });
            
            this.phone.on('onError', (e) => {
                console.error(e);
                if (e instanceof Event || (e && !e.message)) {
                    disconnectReason = 'サーバ未起動またはタイムアウト';
                } else {
                    // それ以外(明示的なエラーメッセージがある場合)
                    disconnectReason = e.message || e;
                }
                this.updateStatus('エラー発生', 'status-talking');
            });

            // 4. WebSocket接続開始
            await this.phone.connect();

        } catch (e) {
            console.error(e);
            this.updateStatus('接続失敗', 'status-talking');
            this.state = 'DISCONNECTED';
            this.updateUI();
        }
    }

    handleDisconnect() {
        if (this.phone) {
            this.phone.disconnect();
            this.phone = null;
        }
        sessionStorage.removeItem('wp_autoconnect');
        this.state = 'DISCONNECTED';
        this.updateUI();
        this.updateStatus('未接続', '');
    }

    // --- 通話ボタンのロジック ---
    handleCallAction() {
        const inputVal = this.el.input.value.trim();

        if (this.state === 'RINGING') {
            // 2. 着信中 -> 応答処理
            // WebPhone側のanswer内で this.audioEngine.startMic() が呼ばれる
            this.phone.answer();
            this.state = 'TALKING';
            this.updateStatus('通話中', 'status-talking');
            this.updateUI();
        } 
        else if (this.state === 'IDLE') {
            if (inputVal !== '') {
                // 4. アイドル + 入力あり -> 発信処理(PHP API)
                this.triggerCallbackDial(inputVal);
            }
            // 1. アイドル + 入力なし -> 何もしない
        }
        // 3. 通話中 -> 何もしない
    }

    // --- 終話ボタンのロジック ---
    handleHangupAction() {
        const inputVal = this.el.input.value.trim();

        if (this.state === 'RINGING' || this.state === 'TALKING') {
            // 2. 着信中 -> 拒否 / 3. 通話中 -> 切断
            this.phone.hangup();
            // 状態変更は onHangup イベントで行う
        } 
        else if (this.state === 'IDLE' && inputVal !== '') {
            // 4. アイドル + 入力あり -> クリア
            this.el.input.value = '';
        }
        // 1. アイドル + 入力なし -> 何もしない
    }

    // API経由での発信リクエスト（コールバック）
    async triggerCallbackDial(number) {
        this.updateStatus('発信要求中...', 'status-ringing');
        try {
            const formData = new FormData();
            formData.append('number', number);
            
            const res = await fetch(this.config.dialUrl, {
                method: 'POST',
                body: formData
            });
            
            if (!res.ok) throw new Error('API Error');
            
            // 成功してもWebPhoneの状態はまだ変えない。
            // Asteriskから着信(RINGING)が来るのを待つ。
            this.updateStatus('呼び出し待ち...', 'status-connected');

        } catch (e) {
            alert('発信リクエストに失敗しました');
            this.updateStatus('待受中 (IDLE)', 'status-connected');
        }
    }

    // UI状態の一括更新
    updateUI() {
        const isConn = (this.state !== 'DISCONNECTED');
        const isRinging = (this.state === 'RINGING');
        const isTalking = (this.state === 'TALKING');

        // 接続/切断ボタン
        this.el.btnConnect.disabled = isConn;
        this.el.btnDisconnect.disabled = !isConn;

        // 通話/終話ボタン (未接続時はグレーアウト)
        this.el.btnCall.disabled = !isConn;
        this.el.btnHangup.disabled = !isConn;
        
        // 入力欄 (通話中は変更不可にするなどお好みで)
        this.el.input.disabled = !isConn; 

        // ボタンの見た目やテキストを状態に応じて変える場合はここで操作
        if (isRinging) {
            this.el.btnCall.textContent = '応答';
            this.el.btnCall.classList.add('blink'); // 点滅クラスなどあれば
        } else {
            this.el.btnCall.textContent = '通話';
            this.el.btnCall.classList.remove('blink');
        }
    }

    updateStatus(text, className) {
        this.el.status.textContent = text;
        this.el.status.className = 'wp-status ' + className;
    }
}
