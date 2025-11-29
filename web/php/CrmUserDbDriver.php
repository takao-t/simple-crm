<?php
require_once __DIR__ . '/CrmUserDbDriverSQLite3.php';
require_once __DIR__ . '/CrmUserDbDriverMaria.php';
require_once __DIR__ . '/config_maria.php';

class CrmUserDbDriver
{
    /**
     * 設定に応じて適切なドライバのインスタンスを返します
     */
    public static function createInstance()
    {
        // config_maria.php 内で定義した定数や、環境変数で切り替え
        // 例: define('DB_TYPE', 'mariadb'); // or 'sqlite'

        if (defined('DB_TYPE') && DB_TYPE === 'mariadb') {
            return new CrmUserDbDriverMaria();
        }

        // デフォルトはSQLite
        return new CrmUserDbDriverSQLite3();
    }
}
