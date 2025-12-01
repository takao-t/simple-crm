<?php

// セッション関連設定は外部
require_once 'php/config_session.php';

session_start();

require_once 'php/config.php';

// デフォルト挙動
if (!defined('USE_ABS')) {
    define('USE_ABS', false);
}
if (!defined('FEATURE_CTI_POPUP_ENABLED')) {
    define('FEATURE_CTI_POPUP_ENABLED', false);
}

//各ページの単独読み込み拒否用
define('CRM_SYSTEM_INCLUDED', true); 

if(USE_ABS){
    require_once 'php/astman.php';
    require_once 'php/functions.php';
}

// デバッグ用
// echo 'GC Max Lifetime: ' . ini_get('session.gc_maxlifetime') . '<br>';
// echo 'Cookie Lifetime: ' . ini_get('session.cookie_lifetime');

require_once 'php/CrmUserDbDriver.php';
$userDb = CrmUserDbDriver::createInstance();

// 設定DBからポートを取得
$WS_PORT = $userDb->getSystemSetting('ws_port', '');

// サーバーのIPまたはホスト名を取得
// ブラウザがアクセスしたホスト名を使用
$ws_host = $_SERVER['HTTP_HOST']; 
// Host:Port の形式で取得される場合があるため、Host部分のみ抽出
if (strpos($ws_host, ':') !== false) {
    $ws_host = substr($ws_host, 0, strpos($ws_host, ':'));
}

// Websocket接続にSSLを使うかどうかを自動判定
// 自動判定に問題がある場合には手動でwsかwssを設定すること
$ws_protocol = (empty($_SERVER['HTTPS']) ? 'ws://' : 'wss://');

// WebSocketのURL全体を構築
// $WS_URL = "ws://{$ws_host}:" . $WS_PORT . "/crmws";
$WS_URL = $ws_protocol . "{$ws_host}:" . $WS_PORT . "/crmws";
//デバッグ表示
//echo $WS_URL;

// 1. DBにユーザーが一人もいなければ、初回登録ページへ
if ($userDb->countUsers() === 0) {
    header('Location: register-first-admin.php');
    exit;
}

// 2. セッションがなければ（未ログイン）、ログインページへ
if (!isset($_SESSION['user_id'])) {
    header('Location: login-crm.php');
    exit;
}

$current_user_weight = $_SESSION['weight'] ?? 0;
$is_admin = ($current_user_weight >= 90);

// デフォルトのタイムゾーン設定
date_default_timezone_set('Asia/Tokyo');

// --- 簡易ルーティング処理 ---
// URLパラメータ ?page=xxx を取得。なければ 'crm-page' とする
$page = $_GET['page'] ?? 'crm-page';

// 許可されたページとファイル名のマッピング
// 将来ページが増えたらここに追加するだけでOK
$routes = [
    'crm-page' => 'crm-page.php',
    'list-page' => 'list-page.php',
    'user-manage-page' => 'user-manage-page.php',
    'system-settings-page' => 'system-settings-page.php',
];

// ページが存在するかチェック
if (array_key_exists($page, $routes) && file_exists($routes[$page])) {
    $include_file = $routes[$page];
} else {
    // 存在しないページが指定された場合はCRMトップへ強制遷移
    $include_file = 'crm-page.php';
    $page = 'crm-page';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simple-CRMシステム</title>
    <link rel="stylesheet" href="style.css">     <link rel="stylesheet" href="crm.css">
    <style>
        /* メニュー独自の微調整があればここに記述 */
        .menu-title {
            color: #fff;
            padding: 10px 5px;
            font-weight: bold;
            border-bottom: 1px solid #5f6368;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <script>
        // PHPから動的に生成されたWebSocket URLをJavaScript変数に格納する
        // 必ず引用符（' や "）で囲んで文字列として出力する
        const DYNAMIC_WS_URL = '<?= htmlspecialchars($WS_URL, ENT_QUOTES, 'UTF-8') ?>';
        const MY_EXTENSION = '<?= htmlspecialchars($_SESSION['extension'], ENT_QUOTES, 'UTF-8') ?>';
        const CTI_ENABLED = <?= FEATURE_CTI_POPUP_ENABLED ? 'true' : 'false' ?>;
        const MAX_CTI_TABS = <?= MAX_CTI_TABS ?>;
    </script>
    <div class="container">
        
        <nav class="menu">
            <div class="menu-title">メインメニュー</div>

            <ul>
                <li>
                    <a href="index.php?page=crm-page" class="<?= ($page === 'crm-page') ? 'active' : '' ?>">
                        🔍 検索・編集
                    </a>
                </li>
                <li>
                    <a href="index.php?page=list-page" class="<?= ($page === 'list-page') ? 'active' : '' ?>">
                        📜 一覧表示
                    </a>
                </li>
                
            </ul>

            <div class="menu-section-start"></div>
            <ul>

                <li>
                    <a href="index.php?page=user-manage-page" class="<?= ($page === 'user-manage-page') ? 'active' : '' ?>">
                        👤 ユーザー管理
                    </a>
                </li>
                <?php if ($is_admin): ?>
                    <li>
                        <a href="index.php?page=system-settings-page" class="<?= ($page === 'system-settings-page') ? 'active' : '' ?>">
                        ⚙️ システム設定
                        </a>
                    </li>
                <?php endif; ?>
                
                <?php if (FEATURE_CTI_POPUP_ENABLED): ?>
                    <li>
                        <a href="#" id="popup-toggle-btn">
                            ポップアップ機能
                        </a>
                    </li>
                <?php endif; ?>
                
                <li>
                    <a href="logout-crm.php">
                        🚪 ログアウト (<?= htmlspecialchars($_SESSION['username'] ?? 'user') ?>)
                    </a>
                </li>
                <li>
                    <a href="#" id="theme-toggle-btn">🌓 表示モード切替</a>
                </li>
            </ul>
        </nav>

        <main class="content">
            <?php
            // ルーティングで決定したファイルを読み込む
            require_once $include_file;
            ?>
        </main>

    </div>

    <script src="switch-theme.js"></script>

    <?php if (FEATURE_CTI_POPUP_ENABLED): ?>
        <script src="cti-popup.js"></script>
    <?php endif; ?>

    </body>
</html>
