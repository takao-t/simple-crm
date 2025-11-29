<?php
// 直接アクセスの禁止
if (!defined('CRM_SYSTEM_INCLUDED')) {
    die("Direct access is not permitted.");
}

// このページ自体へのアクセス権限チェック
// (index.php で $is_admin は定義済みのはずだが、念のためセッションで再確認)
$current_user_weight = $_SESSION['weight'] ?? 0;
if ($current_user_weight < 90) {
    // 管理者でなければ、CRMトップページにリダイレクト
    header('Location: index.php?page=crm-page');
    exit;
}

// (CrmDbDriverはインポート処理で使用するためここで読み込む)
require_once 'php/CrmDbDriver.php'; 
$crm = CrmDbDriver::createInstance();

// (CrmUserDbDriver は index.php で読み込み済み)
$userDb = CrmUserDbDriver::createInstance();

$message = '';
$message_type = ''; // 'success' or 'error'

// --- POST処理 (設定の保存・インポート) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. システム設定の保存
    if (isset($_POST['action_save_settings'])) {
        
        $outbound_prefix = $_POST['outbound_prefix'] ?? '';
        $cti_token = $_POST['cti_token'] ?? '';
        $ws_port = $_POST['ws_port'] ?? ''; 

        $valid = true;
        
        // バリデーションチェック (簡略化)
        if (!preg_match('/^[0-9*#]*$/', $outbound_prefix)) {
            $message = '保存失敗: プレフィクスには数字、*、# のみ使用できます。';
            $message_type = 'error';
            $valid = false;
        } elseif (empty($cti_token)) {
            $message = '保存失敗: CTIシークレットトークンは必須です。';
            $message_type = 'error';
            $valid = false;
        } elseif (!filter_var($ws_port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]])) {
            $message = '保存失敗: WSポートは1から65535の有効な数値である必要があります。';
            $message_type = 'error';
            $valid = false;
        }

        if ($valid) {
            $userDb->saveSystemSetting('outbound_prefix', $outbound_prefix);
            $userDb->saveSystemSetting('cti_token', $cti_token);
            $userDb->saveSystemSetting('ws_port', $ws_port);
            
            //トークンとポートはAstDBにも保存 (USE_ABSはconfig.phpで設定)
            if (defined('USE_ABS') && USE_ABS) {
                AbspFunctions\put_db_item('ABS/CTI', 'TOKEN', $cti_token);
                AbspFunctions\put_db_item('ABS/CTI', 'PORT', $ws_port);

                // --- ABS通知設定の保存処理 ---
                $abs_notification_pos = $_POST['abs_notification_pos'] ?? '';
                if ($abs_notification_pos === 'INCOMING' || $abs_notification_pos === 'ANSWER') {
                    // 値がある場合は保存
                    AbspFunctions\put_db_item('ABS/CTI', 'POS', $abs_notification_pos);
                } else {
                    // 「なし」または空の場合は削除
                    AbspFunctions\del_db_item('ABS/CTI', 'POS');
                }
                // --- CID参照方法設定の保存処理 ---
                $abs_cidname_ref = $_POST['abs_cidname_ref'] ?? '';
                if ($abs_cidname_ref === 'SCRM') {
                    // 値がある場合は保存
                    AbspFunctions\put_db_item('ABS/CTI', 'CIDREF', $abs_cidname_ref);
                } else {
                    // 「なし」または空の場合は削除
                    AbspFunctions\del_db_item('ABS/CTI', 'CIDREF');
                }
            }
            
            $message = 'システム設定を保存しました。';
            $message_type = 'success';
        }
    }

    // 2. CSVインポート処理 (統合ロジック)
    if (isset($_POST['action_import_csv']) && isset($_FILES['csv_file'])) {

        $file = $_FILES['csv_file'];
        // __DIR__ は system-settings-page.php がある階層。
        // tmp/ は index.php と同じ階層（ルート）にある想定。
        $upload_dir = __DIR__ . '/tmp/'; 

        $row_count = 0; $success_count = 0; $error_count = 0;
        
        // 1. アップロードエラーチェック
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $message = "ファイルのアップロードに失敗しました。エラーコード: {$file['error']}";
            $message_type = 'error';
            goto import_end;
        }
        
        // 2. ファイルをテンポラリに移動
        $temp_filepath = $upload_dir . uniqid() . '.csv';
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }

        if (!move_uploaded_file($file['tmp_name'], $temp_filepath)) {
            $message = 'テンポラリファイルへの移動に失敗しました。ディレクトリ権限を確認してください。';
            $message_type = 'error'; goto import_end;
        }

        // 3. CSV処理
        $handle = fopen($temp_filepath, 'r');
        if ($handle === false) {
            $message = 'テンポラリファイルを開けませんでした。'; $message_type = 'error'; goto cleanup;
        }

        $db_columns = [
            'phone', 'mobile_phone', 'fax', 'email', 'last_name', 'first_name', 
            'last_name_kana', 'first_name_kana', 'organization', 'zip_code', 
            'address', 'address_kana', 'note'
        ];
        $num_expected_columns = count($db_columns);
        $header = fgetcsv($handle); // ヘッダー行をスキップ
        
        while (($raw_row = fgetcsv($handle)) !== false) {
            $row_count++;
            $row = [];
            foreach ($raw_row as $cell) {
                // 文字化けを防ぐため、Excelが出力するであろうSJISやBOM付きUTF-8からUTF-8に変換
                $row[] = mb_convert_encoding($cell, 'UTF-8', 'auto');
            }

            if (count($row) < $num_expected_columns) { $error_count++; continue; }
            $data = array_combine($db_columns, array_slice($row, 0, $num_expected_columns));
            $data['last_updated_by'] = $_SESSION['username'] ?? 'admin_import';
            
            // 必須項目チェック (電話 + (姓 OR 社名) )
            if (empty($data['phone'])) { $error_count++; continue; }
            if (empty($data['last_name']) && empty($data['organization'])) { $error_count++; continue; }

            if ($crm->saveCustomer($data)) {
                $success_count++;
            } else {
                $error_count++;
            }
        }
        
        fclose($handle);

        $message_type = 'success';
        $message = "CSVインポートが完了しました。総レコード数: {$row_count}、成功: {$success_count}件、失敗: {$error_count}件。";

        cleanup:
            if (isset($temp_filepath) && file_exists($temp_filepath)) {
                unlink($temp_filepath);
            }

        import_end:;
    }

} // --- POST処理 END ---

// --- GET処理 (リダイレクトされたインポート結果の表示) ---
if (empty($message) && isset($_GET['import_result'])) {
    $message = htmlspecialchars($_GET['import_msg'] ?? 'インポート処理結果がありません。');
    $message_type = ($_GET['import_result'] === 'success') ? 'success' : 'error';
}

// --- GET表示用のデータ取得 ---
$current_prefix = $userDb->getSystemSetting('outbound_prefix', '');
$current_cti_token = $userDb->getSystemSetting('cti_token', '');
$current_ws_port = $userDb->getSystemSetting('ws_port', '');

// ABS通知とCIDname参照設定の現在値取得
$current_abs_pos = ''; // デフォルトは空（なし）
if (defined('USE_ABS') && USE_ABS) {
    $current_abs_pos = AbspFunctions\get_db_item('ABS/CTI', 'POS');
    $current_cidname_ref = AbspFunctions\get_db_item('ABS/CTI', 'CIDREF');
}

?>

<h2>⚙️ システム設定</h2>
<p style="font-size: 0.9em; color: var(--secondary-text-color); margin-top: -10px;">
    CRMの動作に関する全体設定を行います。（管理者のみ）
</p>

<?php if ($message): ?>
    <div class="crm-message-area <?= $message_type == 'success' ? 'msg-success' : 'msg-error' ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<form action="" method="post">
    <input type="hidden" name="action_save_settings" value="1">

    <div class="user-manage-section" style="padding-top: 0.5em;">
        <h3>外線発信 (Click to Call) 設定</h3>
        <div class="form-grid"> 
            <label for="outbound_prefix">外線発信プレフィクス:</label>
            <input type="text" name="outbound_prefix" id="outbound_prefix" class="input-short2" 
                value="<?= htmlspecialchars($current_prefix) ?>"
                pattern="[0-9*#]*"
                title="使用できるのは数字、*、# のみです。"> 
            <span></span> 
        </div>
    </div>

    <div class="user-manage-section">
        <h3>CTI (着信通知) 設定</h3>
        
        <div class="crm-grid-row" style="align-items: flex-start;">
            
            <div class="crm-label-group">
                <label for="cti_token">CTIシークレットトークン:</label>
                <input type="text" name="cti_token" id="cti_token" class="input-middle"
                       value="<?= htmlspecialchars($current_cti_token) ?>" required>
                <span style="font-size: 0.8em; color: var(--secondary-text-color);">
                    ※ Goサーバーと一致させてください。
                </span>
            </div>
            
            <div class="crm-label-group">
                <label for="ws_port">WebSocketポート:</label>
                <input type="number" name="ws_port" id="ws_port" class="input-xmiddle"
                       value="<?= htmlspecialchars($current_ws_port) ?>"
                       min="1" max="65535" required>
                <span style="font-size: 0.8em; color: var(--secondary-text-color);">
                    ※ Goサーバーの起動ポートと一致させてください。
                </span>
            </div>
        </div>

        <?php if (defined('USE_ABS') && USE_ABS): ?>
            <div class="crm-grid-row" style="margin-top: 20px;">
                <div class="crm-label-group">
                    <label for="abs_notification_pos">ABS通知設定:</label>
                    <select name="abs_notification_pos" id="abs_notification_pos" class="input-middle">
                        <option value="" <?= ($current_abs_pos !== 'INCOMING' && $current_abs_pos !== 'ANSWER') ? 'selected' : '' ?>>なし</option>
                        <option value="INCOMING" <?= $current_abs_pos === 'INCOMING' ? 'selected' : '' ?>>着信時</option>
                        <option value="ANSWER" <?= $current_abs_pos === 'ANSWER' ? 'selected' : '' ?>>応答時</option>
                    </select>
                    <span style="font-size: 0.8em; color: var(--secondary-text-color);">
                        ※ ABSシステムからの通知タイミングを設定します。
                    </span>
                </div>
            </div>
	    <div class="crm-grid-row" style="margin-top: 20px;">
		<div class="crm-label-group">
		    <label for="abs_">ABS CIDname参照方法:</label>
		    <select name="abs_cidname_ref" id="abs_cidname_ref" class="input-middle">
			<option value="" <?= $current_cidname_ref === '' ? 'selected' : '' ?>>AstDB参照</option>
			<option value="SCRM" <?= $current_cidname_ref === 'SCRM' ? 'selected' : '' ?>>簡単CRM参照</option>
		    </select>
		    <span style="font-size: 0.8em; color: var(--secondary-text-color);">
			電話機への通知用CIDnameの参照方法を設定します。
		    </span>
		</div>
	    </div>
        <?php endif; ?>

    </div>
    
    <div class="crm-grid-row" style="margin-top: 25px;">
        <button type="submit" class="btn btn-primary" style="padding: 5px 20px;">設定を保存</button>
    </div>

</form>
<div class="user-manage-section">

<h3>📥 CSVインポート (データ一括更新)</h3>
    <p style="font-size: 0.9em; color: var(--secondary-text-color); margin-top: -10px;">
        既存のデータを上書き（電話番号がキー）、または新規登録します。
        エクスポートされたCSVファイル形式を使用してください。
    </p>

<form action="" method="post" enctype="multipart/form-data" class="form-grid"> 
        <input type="hidden" name="action_import_csv" value="1"> 
        <label for="csv_file">CSVファイル:</label>
        <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
    
        <span></span>
        <button type="submit" class="btn btn-primary btn-danger">インポートを実行</button>
</form>

</div>
