<?php
/**
 * 郵便番号検索テストツール
 * Usage: php test-zip.php [zipcode]
 */

// ドライバ読み込み (import-zip.php と同じ階層にある前提)
require_once __DIR__ . '/php/ZipCodeDbDriver.php';

// 引数チェック
if ($argc < 2) {
    echo "使用法: php test-zip.php <郵便番号(ハイフンあり/なし)>\n";
    echo "例: php test-zip.php 100-0001\n";
    exit(1);
}

$inputZip = $argv[1];

echo "検索中: {$inputZip} ...\n";

try {
    // ファクトリからドライバを取得 (設定に応じてSQLite3/MariaDBが選ばれます)
    $driver = ZipCodeDbDriver::createInstance();
    
    // 検索実行
    $startTime = microtime(true);
    $results = $driver->searchAddress($inputZip);
    $endTime = microtime(true);
    
    // 結果表示
    if (empty($results)) {
        echo "該当する住所は見つかりませんでした。\n";
    } else {
        $count = count($results);
        echo "{$count} 件見つかりました (" . round(($endTime - $startTime) * 1000, 2) . " ms):\n";
        
        foreach ($results as $i => $row) {
            $num = $i + 1;
            
            // 住所結合
            $address = $row['pref'] . $row['city'] . $row['town'];
            $addressKana = $row['pref_kana'] . $row['city_kana'] . $row['town_kana'];
            
            echo "--------------------------------------------------\n";
            echo "[{$num}] 郵便番号: {$inputZip}\n"; // DB内のzipを表示したい場合は $row['zip'] をselectに追加が必要ですが、今回は入力値を表示
            echo "     住  所: {$address}\n";
            echo "     カ  ナ: {$addressKana}\n";
        }
        echo "--------------------------------------------------\n";
    }

} catch (Exception $e) {
    echo "エラーが発生しました: " . $e->getMessage() . "\n";
    exit(1);
}
