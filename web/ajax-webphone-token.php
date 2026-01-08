<?php
// ajax-webphone-token.php
require_once 'php/config_session.php';
session_start();
require_once 'lib/TokenProvider.php'; // 既存のJWTクラス
// 設定ファイル読み込み(JWT_SECRETなど)
require_once 'php/config.php'; 

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

$ext = $_SESSION['extension'] ?? 'unknown';
$tokenProvider = new TokenProvider(JWT_SECRET); // config.phpで定義済と仮定
$token = $tokenProvider->generateToken($ext, 3600); // 1時間有効

header('Content-Type: application/json');
echo json_encode(['token' => $token]);
