<?php
/**
 * 郵便番号データインポートツール
 * Usage: php import-zip.php [csv_file_path]
 */

// 必要なファイルを読み込み
// 実行場所に応じてパスは適宜調整してください。以下は同じディレクトリにある想定
//require_once __DIR__ . '/php/ZipCodeDbDriver.php';
// フルパス指定する場合には以下を使用
require_once '/var/www/html/crm/php/ZipCodeDbDriver.php';

// 引数チェック
if ($argc < 2) {
    echo "Usage: php import-zip.php <path_to_utf_ken_all.csv>\n";
    exit(1);
}

$csvFile = $argv[1];

if (!file_exists($csvFile)) {
    echo "Error: File not found - $csvFile\n";
    exit(1);
}

echo "Starting import from: $csvFile\n";
echo "Database Type: " . ((defined('DB_TYPE') && DB_TYPE === 'mariadb') ? 'MariaDB' : 'SQLite3') . "\n";

try {
    // ファクトリからドライバを取得
    $driver = ZipCodeDbDriver::createInstance();
    
    // インポート実行
    $startTime = microtime(true);
    $driver->importFromCsv($csvFile);
    $endTime = microtime(true);
    
    $duration = round($endTime - $startTime, 2);
    echo "Success! Import took {$duration} seconds.\n";

} catch (Exception $e) {
    echo "Error occurred: " . $e->getMessage() . "\n";
    exit(1);
}
