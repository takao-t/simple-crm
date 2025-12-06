<?php

//
// CID名をCRMのDBから検索するPHPスクリプト
// 引数1に電話番号を指定する
//
// このスクリプトの起動位置が問題になるので、configとDBドライバはフルパス指定する
//
require_once '/var/www/html/crm/php/config.php';
require_once '/var/www/html/crm/php/CrmDbDriver.php';

// DBドライバのクラス(SQLite3,MariaDB両対応)
// 使用するDBを切り替えた場合でもこのスクリプトの修正は必要ない
$crm = CrmDbDriver::createInstance();

// 番号を引数1として取得
if(isset($argv[1])){
    $phone_query = trim($argv[1]);
} else {
    die;
}

// 結果文字列を初期化
$result = '';

// 引数番号のバリデーション
if (!empty($phone_query) && !preg_match('/^[0-9*#-]+$/', $phone_query)) {
    // バリデーションエラーなら結果として'番号エラー'を表示
    $result = '番号指定エラー';
} else {
    // DBから番号で検索
    $customer = $crm->getCustomerByPhone($phone_query);

    if($customer){
        // 結果がありなら'社名':'姓''名'をCID名として使用
        // 表示内容を調整したい場合はここを変更
        $result = $customer['organization'] . ':' .$customer['last_name'] . $customer['first_name'];
    } else {
        // 結果がない場合にはCID名として'未登録'とする
        //$result = '未登録';
        // 結果がない場合にはCID(番号)を返す
        $result = $phone_query;
    }
}

// 結果を出力
print($result);
print("\n");
