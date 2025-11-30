<?php

require_once 'config_session.php';

// session_start() はそのまま維持
set_time_limit(0);
ini_set('memory_limit', '512M'); 

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die("Unauthorized access.");
}

require_once 'config.php';
require_once '../php/CrmDbDriver.php';

// CrmDbDriver の getAllCustomersForExport() メソッドが利用可能であることを前提とする
$crm = CrmDbDriver::createInstance();

// --- 1. ヘッダー設定: CSVファイルとしてダウンロードさせる ---
$filename = 'crm_customer_data_' . date('YmdHis') . '.csv';
// Content-Type を UTF-8 に設定
header('Content-Type: text/csv; charset=UTF-8'); 
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// --- 2. データ取得と出力 ---
$output = fopen('php://output', 'w');

// UTF-8 BOM を出力 (Excelなどの互換性のため)
// UTF-8 BOM: 0xEF, 0xBB, 0xBF
fwrite($output, "\xEF\xBB\xBF");

// CSVヘッダー行 (カラム名)
$header = [
    '電話番号', '携帯番号', 'FAX', 'メール', '姓', '名', '姓カナ', '名カナ',
    '社名・所属', '郵便番号', '住所', '住所カナ', 'メモ', '登録日時', '更新日時'
];

// ヘッダーを出力
fputcsv($output, $header);

// 全顧客データを取得 (CrmDbDriver::getAllCustomersForExport() を使用)
$customer_data = $crm->getAllCustomersForExport();

foreach ($customer_data as $row) {
    fputcsv($output, $row);
}

fclose($output);
exit;
?>
