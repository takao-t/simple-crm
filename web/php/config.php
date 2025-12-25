<?php
/**
 * システム設定ファイル
 * データベースパス、機能フラグなど、インストール時に決定する値を定義する
 */

// --- データベース設定 ---
// CRMユーザー用データベースのパス
//define('USER_DB_PATH', __DIR__ . '/../db/crm_user_db.sqlite3');
define('USER_DB_PATH', '/var/lib/simple_crm/crm_user_db.sqlite3');
// CRM顧客用データベースのパス
//  郵便番号DBも同じパスを使用する
//define('CRM_DB_PATH', __DIR__ . '/../db/crm_db.sqlite3');
define('CRM_DB_PATH', '/var/lib/simple_crm/crm_db.sqlite3');


// --- 機能フラグ ---
// CTIポップアップ機能の使用可否 (true: 有効 / false: 無効)
// インストール時にポップアップを使用しない場合は 'false' に設定
define('FEATURE_CTI_POPUP_ENABLED', true);
//define('FEATURE_CTI_POPUP_ENABLED', false);

// CTIタブローテーション設定
// 同時に開くCTIポップアップタブの最大数
define('MAX_CTI_TABS', 4);

//
// PBXにABSを使用する場合にはtrueを設定、使用しない場合にはfalse
//
define('USE_ABS', true);

// --- ABSログ設定 ---
// ABSの着信ログデータベースのパス (USE_ABS = true の場合のみ使用)
define('ABS_LOG_PATH', '/var/log/asterisk/abslog.sqlite3');

// --- AMI接続情報 ---
// ABS連携等でAMI接続が必要な場合以下を設定
define('AMI_HOST', 'localhost');
define('AMI_USER', 'abspadmin');
define('AMI_PASS', 'amipass1234');
define('AMI_PORT', '5038');
