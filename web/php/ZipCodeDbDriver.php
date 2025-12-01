<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_maria.php';
require_once __DIR__ . '/ZipCodeDbDriverSQLite3.php';
require_once __DIR__ . '/ZipCodeDbDriverMaria.php';

class ZipCodeDbDriver
{
    /**
     * 設定(DB_TYPE)に応じて適切なドライバのインスタンスを返します
     * @return object ZipCodeDbDriverSQLite3 または ZipCodeDbDriverMaria
     */
    public static function createInstance()
    {
        // config_maria.php 内の定数 DB_TYPE を確認
        if (defined('DB_TYPE') && DB_TYPE === 'mariadb') {
            return new ZipCodeDbDriverMaria();
        }

        // デフォルトはSQLite
        return new ZipCodeDbDriverSQLite3();
    }
}
