<?php
// ※ このファイルは index.php (ゲートキーパー) の外で動かすため、
//    独自にセッションとDBドライバを読み込む
session_start();
define('CRM_SYSTEM_INCLUDED', true); // 
require_once 'php/config.php';
require_once 'php/CrmUserDbDriver.php';

$userDb = new CrmUserDbDriver();
$error_message = '';

// 重要: 既にユーザーが存在する場合は、このページへのアクセスを拒否
if ($userDb->countUsers() > 0) {
    header('Location: login-crm.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $extension = $_POST['extension'] ?? '';

    if (empty($username) || empty($password) || empty($extension)) {
        $error_message = 'すべての項目を入力してください。';
    } elseif ($password !== $password_confirm) {
        $error_message = 'パスワードが一致しません。';
    } else {
        // 最初のユーザーを「ウェイト90 (管理者)」として登録
        if ($userDb->createUser($username, $password, $extension, 90)) {
            // 成功したら、そのままログインセッションも作成
            session_regenerate_id(true);
            $user = $userDb->getUserByName($username);
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['weight'] = $user['weight'];
            $_SESSION['extension'] = $user['extension'];

            header('Location: index.php'); // メインページへ
            exit;
        } else {
            $error_message = 'ユーザー登録に失敗しました。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>CRM - 初回管理者登録</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="crm.css">
</head>
<body class="login-page">
    <div class="login-container">
        <h2>CRM 初回管理者登録</h2>
        <p style="text-align: center; font-size: 0.9em; margin-bottom: 1em;">
            最初の管理者ユーザーを登録します。
        </p>
        
        <?php if ($error_message): ?>
            <div class="error" style="text-align: left;"><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form action="register-first-admin.php" method="post">
            <div>
                <label for="username">管理者 ユーザ名</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div>
                <label for="extension">内線番号 (CTI連携用)</label>
                <input type="text" id="extension" name="extension" required>
            </div>
            <div>
                <label for="password">パスワード</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div>
                <label for="password_confirm">パスワード (確認)</label>
                <input type="password" id="password_confirm" name="password_confirm" required>
            </div>
            <button type="submit" class="btn">登録してログイン</button>
        </form>
    </div>
</body>
</html>
