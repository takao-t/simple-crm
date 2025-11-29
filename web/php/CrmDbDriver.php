<?php
require_once __DIR__ . '/CrmDbDriverSQLite3.php';
require_once __DIR__ . '/CrmDbDriverMaria.php';
require_once __DIR__ . '/config_maria.php';

class CrmDbDriver
{
    /**
     * 設定に応じて適切なドライバのインスタンスを返します
     * @return object CrmDbDriverSQLite3 または CrmDbDriverMaria
     */
    public static function createInstance()
    {
        // config_maria.php 内で定義した定数や、環境変数で切り替え
        // 例: define('DB_TYPE', 'mariadb'); // or 'sqlite'

        if (defined('DB_TYPE') && DB_TYPE === 'mariadb') {
            return new CrmDbDriverMaria();
        }

        // デフォルトはSQLite
        return new CrmDbDriverSQLite3();
    }
}
