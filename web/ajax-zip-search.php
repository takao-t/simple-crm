<?php
/**
 * 郵便番号検索用 Ajax API
 * 入力: GET['zip']
 * 出力: JSON形式の住所データ
 */

require_once 'php/ZipCodeDbDriver.php';

// JSONとしてレスポンスを返す
header('Content-Type: application/json; charset=utf-8');

// 郵便番号がなければ空配列を返して終了
$zip = $_GET['zip'] ?? '';
if ($zip === '') {
    echo json_encode([]);
    exit;
}

try {
    $driver = ZipCodeDbDriver::createInstance();
    $results = $driver->searchAddress($zip);
    
    // 結果をそのままJSONで返す
    echo json_encode($results);

} catch (Exception $e) {
    // エラー時は500ステータス
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
