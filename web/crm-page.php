<?php
// 直接アクセスの禁止
if (!defined('CRM_SYSTEM_INCLUDED')) {
    die("Direct access is not permitted.");
}

// 追加: 検索結果の1ページあたりの表示件数
define('SEARCH_ROWS_PER_PAGE', 10); // 「少なめ」に10件

require_once 'php/CrmDbDriver.php';
require_once 'php/CrmUserDbDriver.php';

$crm = CrmDbDriver::createInstance();
$userDb = CrmUserDbDriver::createInstance();

$message = '';
$message_type = ''; // 'success' or 'error'
$search_results = [];
$debug_message = '';

// ページネーション用変数
$total_rows = 0;
$total_pages = 0;
$current_page = 1;
$search_query_params = []; // 検索条件を維持するための配列
$is_search_request = false;

// フォームの初期値
$form_data = [
    'last_name' => '', 'first_name' => '',
    'last_name_kana' => '', 'first_name_kana' => '',
    'organization' => '',
    'phone' => '', 'fax' => '', 'email' => '', 'mobile_phone' => '',
    'zip_code' => '', 'address' => '', 'address_kana' => '',
    'note' => ''
];

// --- ロジック部 ---

// 1. POSTリクエスト処理 (保存・検索・削除・C2C)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // フォームデータをPOSTから取得
    foreach ($form_data as $key => $val) {
        $form_data[$key] = $_POST[$key] ?? '';
    }

    // C2C(発信)ボタンが押されたかどうかを「先」に判定し、フラグを立てる
    $c2c_field_map = [
        'action_c2c_phone' => 'phone',
        'action_c2c_mobile' => 'mobile_phone',
        'action_c2c_fax' => 'fax'
    ];
    $c2c_action_name = null;
    foreach ($c2c_field_map as $action_name => $field_name) {
        if (isset($_POST[$action_name])) {
            $c2c_action_name = $action_name;
            break;
        }
    }


    // --- 1. 削除ボタンが押された場合 ---
    if (isset($_POST['action_delete'])) {
        $phone_to_delete = $form_data['phone'];
        if ($phone_to_delete === '') {
            $message = '削除対象の電話番号がありません。';
            $message_type = 'error';
        } else {
            if ($crm->deleteCustomerByPhone($phone_to_delete)) {
                $message = "情報（" . htmlspecialchars($phone_to_delete) . "）を削除しました。";
                $message_type = 'success';
                // フォームをクリア
                foreach ($form_data as $key => &$val) $val = ''; unset($val); 
            } else {
                $message = '削除に失敗しました。';
                $message_type = 'error';
            }
        }
    }

    // --- 2. C2C(発信)ボタンが押された場合 ---
    // (先ほど準備した $c2c_action_name フラグをここでチェック)
    elseif ($c2c_action_name !== null) {
        $field_name = $c2c_field_map[$c2c_action_name];
        $user_extension = $_SESSION['extension'] ?? '';
        $target_number = $form_data[$field_name] ?? '';

        // C2Cブロックのバリデーション
        if (!empty($target_number) && !preg_match('/^[0-9*#-]+$/', $target_number)) {
             $message = '発信エラー: 番号には数字、*、#、ハイフンのみ使用できます。';
             $message_type = 'error';
        }

        if (empty($user_extension)) {
            $message = '発信エラー: あなたの内線番号が設定されていません。 (セッション: extension)';
            $message_type = 'error';
        } elseif (empty($target_number)) {
            $message = '発信エラー: 発信先の番号が入力されていません。';
            $message_type = 'error';
        } else {
            $target_number_clean = str_replace('-', '', $target_number);
            $prefix = $userDb->getSystemSetting('outbound_prefix', '');
            $command = "channel originate Local/{$user_extension}@c2c-inside extension {$prefix}{$target_number_clean}@c2c-outside";
            if(USE_ABS){
                AbspFunctions\exec_cli_command($command);
            
                //$debug_message = 'DEBUG (Click2Call): ' . htmlspecialchars($command);
                $message = '発信処理を開始しました。';
                $message_type = 'success';
            } else {
                $message = '発信処理は許可されていません。';
                $message_type = 'error';
            }
        }
    }

    // --- 3. 更新/保存ボタンが押された場合 ---
    elseif (isset($_POST['action_save'])) {

        // 1. 必須項目の組み合わせチェック
        // 「電話番号」は必須。加えて「姓」OR「社名」のどちらかが必須。
        if ($form_data['phone'] === '') {
            $message = '保存エラー: 電話番号は必須です。';
            $message_type = 'error';
        } elseif ($form_data['last_name'] === '' && $form_data['organization'] === '') {
            $message = '保存エラー: 「姓」または「社名・所属」のどちらかは必ず入力してください。';
            $message_type = 'error';
        }
        // 2. 番号フィールドの文字種チェック (電話、携帯、FAX)
        elseif (!preg_match('/^[0-9*#-]+$/', $form_data['phone'])) {
            $message = '保存エラー: 電話番号には数字、*、#、ハイフンのみ使用できます。';
            $message_type = 'error';
        }
        elseif ($form_data['mobile_phone'] !== '' && !preg_match('/^[0-9*#-]+$/', $form_data['mobile_phone'])) {
            $message = '保存エラー: 携帯番号には数字、*、#、ハイフンのみ使用できます。';
            $message_type = 'error';
        }
        elseif ($form_data['fax'] !== '' && !preg_match('/^[0-9*#-]+$/', $form_data['fax'])) {
            $message = '保存エラー: FAX番号には数字、*、#、ハイフンのみ使用できます。';
            $message_type = 'error';
        }
        else {
            $form_data['last_updated_by'] = $_SESSION['username'] ?? 'unknown';
            if ($crm->saveCustomer($form_data)) {
                $message = '情報を保存しました。';
                $message_type = 'success';
            } else {
                $message = '保存に失敗しました。ログを確認してください。';
                $message_type = 'error';
            }
        }
    }
    
    // --- 4. 検索ボタンが押された場合 ---
    elseif (isset($_POST['action_search'])) {
        $is_search_request = true;
        $current_page = 1;
        $search_query_params = $form_data;
    }

} // --- POSTリクエスト処理 END ---

// 2. GETリクエスト処理
else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    
    // A. CTI連携 / リスト選択
    if (isset($_GET['phone']) && $_GET['phone'] !== '') {
        $phone_query = $_GET['phone'];
        $customer = $crm->getCustomerByPhone($phone_query);
        if ($customer) {
            $form_data = $customer;
            $message = "情報: {$phone_query} の情報を表示します。";
            $message_type = 'success';
        } else {
            $form_data['phone'] = $phone_query;
            $message = "着信: {$phone_query} は未登録です。";
            $message_type = 'error';
        }
    }
    // B. ページネーションまたはGET検索
    // (p=, last_name= など、phone以外のパラメータがある場合)
    else if (!empty($_GET)) {
        // GETパラメータからフォームデータを復元
        foreach ($form_data as $key => $val) {
            $form_data[$key] = $_GET[$key] ?? '';
        }
        $is_search_request = true;
        $current_page = max(1, intval($_GET['p'] ?? 1)); // ページ番号を取得
        $search_query_params = $_GET;
        unset($search_query_params['page'], $search_query_params['p']); // ページャー用パラメータは除外
    }
}

// --- 3. 検索リクエスト実行ブロック (POST と GET[p=] の両方で実行) ---
if ($is_search_request) {
    
    // 検索対象（*）のキー
    $search_keys = [
        'last_name', 'first_name', 'last_name_kana', 'first_name_kana',
        'organization', 'phone', 'fax', 'email', 'mobile_phone',
        'zip_code', 'address', 'address_kana'
    ];
    
    $criteria = [];
    foreach ($search_keys as $key) {
        if (!empty($form_data[$key])) {
            $criteria[$key] = $form_data[$key];
        }
    }

    $criteria_count = count($criteria);
    $exact_match = null;
    $message_suffix = '';

    if ($criteria_count === 0) {
        $message = '検索条件を1つ以上入力してください。';
        $message_type = 'error';
    
    } elseif ($criteria_count === 1) {
        // --- 検索条件が1つの場合 ---
        $keyword = reset($criteria);
        $field_name = key($criteria);
        $message_suffix = " (「" . htmlspecialchars($keyword) . "」で全欄検索)";

        if ($field_name === 'phone' && $current_page === 1) { // 1ページ目のみ完全一致を試す
            $exact_match = $crm->getCustomerByPhone($keyword);
        }

        if ($exact_match) {
            $form_data = $exact_match;
            $message = '電話番号で1件見つかりました。';
            $message_type = 'success';
        } else {
            // ページネーション対応
            $total_rows = $crm->searchCustomersCount($keyword);
            $total_pages = ceil($total_rows / SEARCH_ROWS_PER_PAGE);
            if ($current_page > $total_pages && $total_pages > 0) $current_page = $total_pages;
            
            $search_results = $crm->searchCustomers($keyword, $current_page, SEARCH_ROWS_PER_PAGE);
        }

    } else {
        // --- 検索条件が複数の場合: AND検索 ---
        $message_suffix = " (複数条件AND検索)";
        
        // ページネーション対応
        $total_rows = $crm->searchCustomersComplexCount($form_data);
        $total_pages = ceil($total_rows / SEARCH_ROWS_PER_PAGE);
        if ($current_page > $total_pages && $total_pages > 0) $current_page = $total_pages;

        $search_results = $crm->searchCustomersComplex($form_data, $current_page, SEARCH_ROWS_PER_PAGE);
    }

    // --- 検索結果のハンドリング ---
    if ($exact_match === null && $criteria_count > 0) {
        $count = $total_rows; // 表示件数(count($search_results))ではなく総件数
        
        if ($count === 0) {
            $message = "検索条件に一致する結果は0件でした。" . $message_suffix;
            $message_type = 'error';
        } elseif ($count === 1 && $current_page === 1) { // 1件ヒットで1ページ目なら
            $form_data = $search_results[0]; // フォームにロード
            $message = "1件見つかりました。" . $message_suffix;
            $message_type = 'success';
            $search_results = []; // リスト表示不要
            $total_rows = 0; // ページネーション非表示
        } else {
            $message = "{$count}件見つかりました。 (ページ {$current_page}/{$total_pages})" . $message_suffix;
            $message_type = 'success';
        }
    }
}

?>

<h2>情報管理 (CRM)</h2>

<div class="crm-container">
    <form action="index.php?page=crm-page" method="post" id="crm-form">
        
        <div class="crm-grid-row">
            <div class="crm-label-group">
                <label>*姓</label>
                <input type="text" name="last_name" class="input-short2" value="<?= htmlspecialchars($form_data['last_name']) ?>">
            </div>
            <div class="crm-label-group">
                <label>*名</label>
                <input type="text" name="first_name" class="input-short2" value="<?= htmlspecialchars($form_data['first_name']) ?>">
            </div>
        </div>
        <div class="crm-grid-row">
            <div class="crm-label-group">
                <label>*ふりがな(姓)</label>
                <input type="text" name="last_name_kana" class="input-short2" value="<?= htmlspecialchars($form_data['last_name_kana']) ?>">
            </div>
            <div class="crm-label-group">
                <label>*ふりがな(名)</label>
                <input type="text" name="first_name_kana" class="input-short2" value="<?= htmlspecialchars($form_data['first_name_kana']) ?>">
            </div>
        </div>
        <div class="crm-grid-row">
            <div class="crm-label-group" style="flex-grow: 1; max-width: 500px;">
                <label>*社名・所属</label>
                <input type="text" name="organization" style="width: 100%;" value="<?= htmlspecialchars($form_data['organization']) ?>">
            </div>
        </div>

        <div class="crm-grid-row">
            
            <div class="crm-label-group">
                <label>*電話 (必須/ID)</label>
                <div class="input-with-button">
                    <input type="text" name="phone" class="input-middle2" 
                           value="<?= htmlspecialchars($form_data['phone']) ?>"
                           pattern="[0-9*#-]+" title="数字、*、#、ハイフンのみ入力可能です">
                    <?php if (USE_ABS): ?>
                    <button type="submit" name="action_c2c_phone" value="1" class="btn btn-call" title="この番号に発信">
                        発信
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="crm-label-group">
                <label>*携帯番号</label>
                <div class="input-with-button">
                    <input type="text" name="mobile_phone" class="input-middle2" 
                           value="<?= htmlspecialchars($form_data['mobile_phone']) ?>"
                           pattern="[0-9*#-]*" title="数字、*、#、ハイフンのみ入力可能です">
                    <?php if (USE_ABS): ?>
                    <button type="submit" name="action_c2c_mobile" value="1" class="btn btn-call" title="この番号に発信">
                        発信
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="crm-label-group">
                <label>*FAX</label>
                <input type="text" name="fax" class="input-middle2" 
                       value="<?= htmlspecialchars($form_data['fax']) ?>"
                       pattern="[0-9*#-]*" title="数字、*、#、ハイフンのみ入力可能です">
            </div>
        </div>

        <div class="crm-grid-row">
            <div class="crm-label-group">
                <label>*郵便番号</label>
                <div class="input-with-button">
                    <input type="text" name="zip_code" class="input-short2" value="<?= htmlspecialchars($form_data['zip_code']) ?>">
                    <button type="button" id="btn-zip-search" class="btn btn-row" title="住所を検索して自動入力">
                        住所検索
                    </button>
                </div>
            </div>
        </div>

        <div class="crm-grid-row">
            <div class="crm-label-group" style="flex-grow: 1; max-width: 600px;">
                <label>*住所</label>
                <input type="text" name="address" style="width: 100%;" value="<?= htmlspecialchars($form_data['address']) ?>">
            </div>
        </div>
        <div class="crm-grid-row">
            <div class="crm-label-group" style="flex-grow: 1; max-width: 600px;">
                <label>*ふりがな(住所)</label>
                <input type="text" name="address_kana" style="width: 100%;" value="<?= htmlspecialchars($form_data['address_kana']) ?>">
            </div>
        </div>
        <div class="crm-grid-row">
            <div class="crm-label-group" style="flex-grow: 1; max-width: 600px;">
                <label>メモ</label>
                <textarea name="note" rows="5" style="width: 100%;"><?= htmlspecialchars($form_data['note']) ?></textarea>
            </div>
        </div>

        <?php if (!empty($form_data['updated_at'])): ?>
        <div class="crm-grid-row" style="justify-content: flex-end; max-width: 600px; margin-top: 5px; margin-bottom: -10px;">
            <div style="font-size: 0.85em; color: var(--secondary-text-color); text-align: right;">
                最終更新: <?= htmlspecialchars($form_data['updated_at']) ?>
                <?php if (!empty($form_data['last_updated_by'])): ?>
                    (更新者: <?= htmlspecialchars($form_data['last_updated_by']) ?>)
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="crm-grid-row" style="justify-content: flex-end; max-width: 600px; margin-top: 15px;">
            <div style="margin-right: auto; display: flex; gap: 10px;">
                <button type="button" id="btn-clear" class="btn btn-row btn-neutral">新規 / クリア</button>
                <button type="submit" name="action_delete" id="btn-delete" value="1" class="btn btn-danger" style="padding: 5px 15px;" disabled>
                    削除
                </button>
            </div>
            <button type="submit" name="action_save" id="btn-save" value="1" class="btn btn-row" style="padding: 5px 20px;">更新</button>
            <button type="submit" name="action_search" id="btn-search" value="1" class="btn btn-primary" style="margin-right: 10px; padding: 5px 15px;">検索</button>
        </div>
    </form>

    <div id="msg-area" class="crm-message-area <?= $message_type == 'success' ? 'msg-success' : 'msg-error' ?>">
        <?= htmlspecialchars($message) ?>
    </div>
    <?php if (!empty($debug_message)): ?>
        <div class="crm-message-area" style="background-color: #3b3b3b; color: #a4ffa4; font-family: monospace; margin-top: 10px; border-color: #555;">
            <?= $debug_message ?>
        </div>
    <?php endif; ?>

    <?php if ($total_rows > 0 && !empty($search_results)): ?>
    <div class="search-result-list">
        
        <table>
            <thead>
                <tr>
                    <th>氏名</th>
                    <th>社名・所属</th>
                    <th>電話番号</th>
                    <th>住所</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($search_results as $row): ?>
                <tr onclick="location.href='index.php?page=crm-page&phone=<?= urlencode($row['phone']) ?>'">
                    <td><?= htmlspecialchars($row['last_name'] . ' ' . $row['first_name']) ?></td>
                    <td><?= htmlspecialchars($row['organization']) ?></td>
                    <td><?= htmlspecialchars($row['phone']) ?></td>
                    <td><?= htmlspecialchars($row['address']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="pagination-container">
            <div class="pagination-summary">
                全 <?= $total_rows ?> 件 (<?= SEARCH_ROWS_PER_PAGE ?> 件ずつ表示)
            </div>
            
            <?php if ($total_pages > 1): // 1ページしかなければ非表示 ?>
            <div class="pagination-links">
                
                <?php
                // 検索条件をURLクエリ文字列に変換
                unset($search_query_params['page'], $search_query_params['p']); // ページ番号自体は除く
                $http_query_string = http_build_query($search_query_params);
                ?>

                <?php if ($current_page > 1): ?>
                    <a href="index.php?page=crm-page&p=<?= $current_page - 1 ?>&<?= $http_query_string ?>">&laquo; 前へ</a>
                <?php else: ?>
                    <span class="disabled">&laquo; 前へ</span>
                <?php endif; ?>

                <?php
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);
                    
                    if ($start_page > 1) echo '<a href="index.php?page=crm-page&p=1&' . $http_query_string . '">1</a><span>...</span>';
                    
                    for ($i = $start_page; $i <= $end_page; $i++):
                        if ($i == $current_page):
                            echo '<span class="current-page">' . $i . '</span>';
                        else:
                            echo '<a href="index.php?page=crm-page&p=' . $i . '&' . $http_query_string . '">' . $i . '</a>';
                        endif;
                    endfor;
                    
                    if ($end_page < $total_pages) echo '<span>...</span><a href="index.php?page=crm-page&p=' . $total_pages . '&' . $http_query_string . '">' . $total_pages . '</a>';
                ?>

                <?php if ($current_page < $total_pages): ?>
                    <a href="index.php?page=crm-page&p=<?= $current_page + 1 ?>&<?= $http_query_string ?>">次へ &raquo;</a>
                <?php else: ?>
                    <span class="disabled">次へ &raquo;</span>
                <?php endif; ?>

            </div>
            <?php endif; ?>
        </div>
        </div>
    <?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- 1. 操作対象の要素を取得 ---
    const btnClear = document.getElementById('btn-clear');
    const btnDelete = document.getElementById('btn-delete');
    const phoneInput = document.querySelector('input[name="phone"]');
    const inputs = document.querySelectorAll('.crm-container input, .crm-container textarea');
    const msgArea = document.getElementById('msg-area');
    const btnSearch = document.getElementById('btn-search');
    
    // --- 2. 削除ボタンの制御ロジック ---
    function updateDeleteButtonState() {
        if (!btnDelete || !phoneInput) return;
        const currentPhone = phoneInput.value;

        if (currentPhone !== '') {
            btnDelete.disabled = false;
            btnDelete.onclick = function(event) {
                const phoneOnConfirm = phoneInput.value;
                if (phoneOnConfirm === '') {
                    event.preventDefault();
                    alert('削除するデータがロードされていません。');
                    return false;
                }
                const message = `この情報（${phoneOnConfirm}）を完全に削除します。\nこの操作は元に戻せません。よろしいですか？`;
                if (!confirm(message)) {
                    event.preventDefault();
                    return false;
                }
            };
        } else {
            btnDelete.disabled = true;
            btnDelete.onclick = null;
        }
    }

    // --- 3. クリアボタンの動作 ---
    if (btnClear) {
        btnClear.addEventListener('click', function() {
            inputs.forEach(input => { input.value = ''; });
            if (msgArea) {
                msgArea.textContent = '';
                msgArea.className = 'crm-message-area';
            }
            history.replaceState(null, null, window.location.pathname + '?page=crm-page');
            updateDeleteButtonState();
            if (phoneInput) phoneInput.focus();
        });
    }

    // --- 4. Enterキーの挙動制御 (Smart Enter) ---
    const searchFields = [
        'input[name="phone"]',
        'input[name="last_name"]',
        'input[name="organization"]'
    ];
    const searchInputs = document.querySelectorAll(searchFields.join(','));
    
    if (btnSearch) {
        searchInputs.forEach(input => {
            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    
                    // フォームの action 属性を更新して、POST検索であることを明示する
                    const form = document.getElementById('crm-form');
                    if(form) form.action = 'index.php?page=crm-page'; // POST先
                    
                    btnSearch.click();
                }
            });
        });
    }
    
    //  ページネーションリンク(GET)でフォームが送信されないよう、
    //  検索ボタン以外のEnterキー挙動を制御
    const otherInputs = document.querySelectorAll('.crm-container input:not([name="phone"]):not([name="last_name"]):not([name="organization"])');
    otherInputs.forEach(input => {
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault(); // Enterでの送信を無効化
            }
        });
    });

    // --- 5. ページ読み込み時の初期化 ---
    updateDeleteButtonState();

    //  ページネーション(GET)の場合、フォームのaction属性を更新
    //  (これにより、Enterキー検索がPOSTとして動作する)
    const form = document.getElementById('crm-form');
    if (form && window.location.search.includes('p=')) {
        // 現在のGETパラメータ（検索条件）を維持したまま、
        // フォームの送信先（action）をPOST用に設定し直す
        form.action = 'index.php?page=crm-page';
    }

    // --- 6. 郵便番号検索 (Ajax) ---
    const btnZipSearch = document.getElementById('btn-zip-search');
    if (btnZipSearch) {
        btnZipSearch.addEventListener('click', function() {
            const zipInput = document.querySelector('input[name="zip_code"]');
            const addressInput = document.querySelector('input[name="address"]');
            const addressKanaInput = document.querySelector('input[name="address_kana"]');
            const zipVal = zipInput.value;

            if (!zipVal) {
                alert('郵便番号を入力してください。');
                return;
            }

            // ボタンを一時的に無効化（連打防止）
            btnZipSearch.disabled = true;
            btnZipSearch.textContent = '検索中...';

            fetch('ajax-zip-search.php?zip=' + encodeURIComponent(zipVal))
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data && data.length > 0) {
                        // 最初の候補を使用
                        const item = data[0];
                        
                        // 住所連結（スペースなし）
                        const fullAddress = item.pref + item.city + item.town;
                        const fullKana = item.pref_kana + item.city_kana + item.town_kana;

                        // 値をセット
                        if (addressInput) addressInput.value = fullAddress;
                        if (addressKanaInput) addressKanaInput.value = fullKana;

                    } else {
                        alert('該当する住所が見つかりませんでした。');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('検索中にエラーが発生しました。');
                })
                .finally(() => {
                    // ボタンの状態を戻す
                    btnZipSearch.disabled = false;
                    btnZipSearch.textContent = '住所検索';
                });
        });
    }
});
</script>
