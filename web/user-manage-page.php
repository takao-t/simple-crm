<?php
// 直接アクセスの禁止
if (!defined('CRM_SYSTEM_INCLUDED')) {
    die("Direct access is not permitted.");
}

// (CrmUserDbDriver は index.php で読み込み済みのはずだが、念のため)
if (!class_exists('CrmUserDbDriver')) {
    require_once 'php/CrmUserDbDriver.php';
}

$userDb = CrmUserDbDriver::createInstance();

$message = '';
$message_type = ''; // 'success' or 'error'

// 現在のユーザー情報をセッションから取得
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_user_bphone = $_SESSION['bphone'] ?? 'no';
$current_user_weight = $_SESSION['weight'] ?? 0;
$is_admin = ($current_user_weight >= 90);

// --- POST処理 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. パスワード変更
    if (isset($_POST['action_change_password'])) {
        $old_pass = $_POST['old_password'];
        $new_pass = $_POST['new_password'];
        $confirm_pass = $_POST['confirm_password'];

        $user = $userDb->getUserByName($_SESSION['username']);

        if (!$user || !password_verify($old_pass, $user['password_hash'])) {
            $message = '現在のパスワードが正しくありません。';
            $message_type = 'error';
        } elseif ($new_pass !== $confirm_pass) {
            $message = '新しいパスワードが一致しません。';
            $message_type = 'error';
        } elseif (empty($new_pass)) {
            $message = '新しいパスワードは空にできません。';
            $message_type = 'error';
        } else {
            if ($userDb->updateUserPassword($current_user_id, $new_pass)) {
                $message = 'パスワードを変更しました。';
                $message_type = 'success';
            } else {
                $message = 'パスワード変更に失敗しました。';
                $message_type = 'error';
            }
        }
    }

    // --- 以下、管理者(90)のみの処理 ---
    if ($is_admin) {
        
        // 2. ユーザー新規登録
        if (isset($_POST['action_create_user'])) {
            $new_username = $_POST['new_username'];
            $new_password = $_POST['new_password'];
            $new_extension = $_POST['new_extension'];
            $new_bphone = $_POST['new_bphone'];
            $new_weight = (int)($_POST['new_weight'] ?? 30);

            if (empty($new_username) || empty($new_password) || empty($new_extension)) {
                $message = '新規ユーザーの「ユーザ名」「パスワード」「内線番号」は必須です。';
                $message_type = 'error';
            } else {
                if ($userDb->createUser($new_username, $new_password, $new_extension, $new_bphone, $new_weight)) {
                    $message = "ユーザー「{$new_username}」を登録しました。";
                    $message_type = 'success';
                } else {
                    $message = "ユーザー登録に失敗しました（ユーザー名が重複している可能性があります）。";
                    $message_type = 'error';
                }
            }
        }
        
        // 3. ユーザー削除
        if (isset($_POST['action_delete_user'])) {
            $user_id_to_delete = (int)$_POST['user_id'];
            
            if ($user_id_to_delete === $current_user_id) {
                $message = '自分自身のアカウントは削除できません。';
                $message_type = 'error';
            } else {
                if ($userDb->deleteUser($user_id_to_delete)) {
                    $message = 'ユーザーを削除しました。';
                    $message_type = 'success';
                } else {
                    $message = 'ユーザー削除に失敗しました。';
                    $message_type = 'error';
                }
            }
        }
        
        // 4. ユーザー情報更新 (内線番号と権限)
        if (isset($_POST['action_update_user'])) {
            $user_id_to_update = (int)$_POST['user_id'];
            $updated_username = $_POST['username'];
            $updated_extension = $_POST['extension'];
            $updated_bphone = $_POST['bphone'];
            $updated_weight = (int)$_POST['weight'];

            // バリデーション
            if (empty($updated_extension)) {
                $message = '内線番号は必須です。';
                $message_type = 'error';
            } elseif ($updated_weight !== 30 && $updated_weight !== 90) {
                $message = '権限の値が不正です。';
                $message_type = 'error';
            } else {
                // データベース更新メソッドを呼び出す (CrmUserDbDriver::updateUserを使用)
                if ($userDb->updateUser($user_id_to_update, $updated_username, $updated_extension, $updated_bphone,  $updated_weight)) {
                    $message = "ユーザー「{$updated_username}」の情報を更新しました。";
                    $message_type = 'success';
                } else {
                    $message = 'ユーザー情報の更新に失敗しました。';
                    $message_type = 'error';
                }
            }
        }
    }
}

// --- GET表示用のデータ取得 ---
$user_list = [];
if ($is_admin) {
    $user_list = $userDb->getAllUsers();
}
?>

<h2>ユーザー管理</h2>

<?php if ($message): ?>
    <div class="crm-message-area <?= $message_type == 'success' ? 'msg-success' : 'msg-error' ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

        <div class="user-manage-section">
            <h3>自分のパスワード変更</h3>
            <form action="" method="post" class="password-form">
                <input type="hidden" name="action_change_password" value="1">
                
                <strong>現在のユーザ名: <?= htmlspecialchars($_SESSION['username'] ?? '') ?></strong>
                
                <label for="old_password">現在のパスワード:</label>
                <input type="password" name="old_password" id="old_password" class="input-middle2" required>
                
                <label for="new_password">新しいパスワード:</label>
                <input type="password" name="new_password" id="new_password" class="input-middle2" required>
                
                <label for="confirm_password">新しいパスワード(確認):</label>
                <input type="password" name="confirm_password" id="confirm_password" class="input-middle2" required>
                
                <button type="submit" class="btn btn-primary">パスワード変更</button>
            </form>
        </div>

<?php if ($is_admin): ?>

    <div class="user-manage-section">
        <h3>ユーザー一覧 (更新・削除)</h3>
        <table class="user-list-table">
            <thead>
                <tr>
                    <th>ユーザ名</th>
                    <th>内線番号</th>
                    <th>ブラウザフォン</th>
                    <th>権限</th>
                    <th>登録日</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($user_list as $user): ?>
                
                <form action="" method="post">
                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                    <input type="hidden" name="username" value="<?= htmlspecialchars($user['username']) ?>">
                    
                    <tr>
                        <td><?= htmlspecialchars($user['username']) ?></td>
                        <td>
                            <input type="text" name="extension" value="<?= htmlspecialchars($user['extension']) ?>" class="input-xshort" required>
                        </td>
                        <td>
                            <select name="bphone" class="input-short2">
                                <option value="yes" <?= ($user['bphone'] === 'yes') ? 'selected' : '' ?>>使う</option>
                                <option value="no" <?= ($user['bphone'] !== 'yes') ? 'selected' : '' ?>>使わない</option>
                            </select>
                        </td>
                        <td>
                            <select name="weight" class="input-xmiddle">
                                <option value="30" <?= ($user['weight'] < 90) ? 'selected' : '' ?>>一般ユーザ</option>
                                <option value="90" <?= ($user['weight'] >= 90) ? 'selected' : '' ?>>管理者</option>
                            </select>
                        </td>
                        <td><?= htmlspecialchars($user['created_at']) ?></td>
                        <td>
                            <?php if ($user['id'] === $current_user_id): ?>
                                <span style="font-size: 0.8em; color: var(--secondary-text-color);">(自分)</span>
                            <?php else: ?>
                                <button type="submit" name="action_update_user" value="1" class="btn btn-row" style="padding: 1px 6px;">
                                   更新 
                                </button>
                                
                                <button type="submit" name="action_delete_user" value="1" class="btn btn-danger" style="padding: 1px 6px; margin-left: 5px;"
                                    onclick="return confirm('ユーザー「<?= htmlspecialchars($user['username']) ?>」を本当に削除しますか？');">
                                    削除
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                </form>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

        <div class="user-manage-section">
            <h3>ユーザー新規登録</h3>
            <form action="" method="post" class="form-grid">
                <input type="hidden" name="action_create_user" value="1">
                
                <label for="new_username">ユーザ名:</label>
                <input type="text" name="new_username" id="new_username" class="input-middle2" required>
                
                <label for="new_password_reg">パスワード:</label>
                <input type="password" name="new_password" id="new_password_reg" class="input-middle2" required>
                
                <label for="new_extension">内線番号:</label>
                <input type="text" name="new_extension" id="new_extension" class="input-short2" required>
                
                <label for="new_bphone">ブラウザフォン:</label>
                <select name="new_bphone" id="new_bphone" class="input-short2">
                    <option value="no" selected>使わない</option>
                    <option value="yes">使う</option>
                </select>

                <label for="new_weight">権限:</label>
                <select name="new_weight" id="new_weight" class="input-short2">
                    <option value="30" selected>一般ユーザ</option>
                    <option value="90">管理者</option>
                </select>
                
                <span></span> 
                <button type="submit" class="btn btn-primary">新規登録</button>
            </form>
        </div>

<?php endif; ?>
