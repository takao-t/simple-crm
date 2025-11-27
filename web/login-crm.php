<?php
// ※ ログインページはセッションチェックの「前」に動作する
session_start();
define('CRM_SYSTEM_INCLUDED', true);
require_once 'php/config.php';
require_once 'php/CrmUserDbDriver.php';

$userDb = new CrmUserDbDriver();
$error_message = '';

// 1. ユーザーが一人もいなければ、初回登録ページへ強制移動
if ($userDb->countUsers() === 0) {
    header('Location: register-first-admin.php');
    exit;
}

// 2. 既にログインしている場合は、メインページへ強制移動
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// --- ログイン処理 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error_message = 'ユーザ名とパスワードを入力してください。';
    } else {
        $user = $userDb->getUserByName($username);

        // ユーザーが存在し、かつパスワードが一致するか検証
        if ($user && password_verify($password, $user['password_hash'])) {
            
            // --- ログイン成功 ---
            session_regenerate_id(true); // セキュリティ対策
            
            // 必要な情報をセッションに格納
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['weight'] = $user['weight'];
            $_SESSION['extension'] = $user['extension']; // CTI連携用

            header('Location: index.php');
            exit;

        } else {
            // --- ログイン失敗 ---
            $error_message = 'ユーザ名またはパスワードが正しくありません。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>CRM - ログイン</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="crm.css">
</head>
<body class="login-page">
    <div class="login-container">
        <h2>CRM ログイン</h2>
        
        <?php if ($error_message): ?>
            <div class="error"><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form action="login-crm.php" method="post">
            <div>
                <label for="username">ユーザ名</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div>
                <label for="password">パスワード</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn">ログイン</button>
        </form>
    </div>
</body>
</html>
