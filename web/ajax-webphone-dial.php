<?php
// ajax-webphone-dial.php
require_once 'php/config_session.php';
session_start();
require_once 'php/config.php';
require_once 'php/AbspManager.php';
// 設定取得用にUserDbDriverを読み込み
require_once 'php/CrmUserDbDriver.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

$target_number = $_POST['number'] ?? '';
$my_extension = $_SESSION['extension'] ?? '';

// バリデーション
if (empty($target_number) || empty($my_extension)) {
    http_response_code(400);
    exit('Invalid param');
}

// 番号のサニタイズ (数字、*、# のみ許可)
$target_number_clean = preg_replace('/[^0-9*#]/', '', $target_number);

if (USE_ABS) {
    // 1. プレフィックスの取得
    $userDb = CrmUserDbDriver::createInstance();
    $prefix = $userDb->getSystemSetting('outbound_prefix', ''); // 設定がない場合は空文字
    // 5桁以下の数字なら内線扱い
    if (strlen($target_number_clean) <= 5) $prefix = '';
    
    // 2. AMI接続
    $ami = new \AbspFunctions\AbspManager(AMI_HOST, AMI_USER, AMI_PASS, AMI_PORT);
    
    // 3. 発信コマンドの組み立て
    // 自分の端末(WebPhone)を鳴らすチャネル
    $my_channel = "Local/{$my_extension}@c2c-inside"; 
    
    // 相手先(外線)の番号 (プレフィックス + 相手番号)
    $destination = $prefix . $target_number_clean;
    
    // 外線発信用のコンテキスト
    $outbound_context = "c2c-outside";

    // Originate実行
    // 自分を鳴らし、応答したら相手先へ繋ぐ
    $command = "channel originate {$my_channel} extension {$destination}@{$outbound_context}";
    
    // デバッグ用ログが必要ならファイル出力などをここに
    // error_log("Dialing: $command");

    $ami->execCliCommand($command);
    echo "OK";
} else {
    // シミュレーションモード
    echo "Simulated: Dialing " . $target_number . " (Prefix added)";
}
