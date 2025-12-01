<?php

class ZipCodeDbDriverSQLite3
{
    private $dbPath;

    public function __construct()
    {
        // CRMのDBと同じディレクトリに 'zipcode.db' を作成する
        $dir = dirname(CRM_DB_PATH);
        $this->dbPath = $dir . '/zipcode.db';

        // ディレクトリがない場合の処理は念のため
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->initializeDatabase();
    }

    private function getDbConnection(): SQLite3
    {
        $db = new SQLite3($this->dbPath);
        $db->busyTimeout(5000);
        return $db;
    }

    private function initializeDatabase(): void
    {
        $db = $this->getDbConnection();
        // zip_codesテーブル作成
        $sql = "CREATE TABLE IF NOT EXISTS zip_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            jis_code TEXT,
            zip_old TEXT,
            zip TEXT NOT NULL,
            pref_kana TEXT,
            city_kana TEXT,
            town_kana TEXT,
            pref TEXT,
            city TEXT,
            town TEXT,
            flag1 INTEGER,
            flag2 INTEGER,
            flag3 INTEGER,
            flag4 INTEGER,
            flag5 INTEGER,
            flag6 INTEGER
        )";
        $db->exec($sql);
        
        // 検索用インデックス
        $db->exec("CREATE INDEX IF NOT EXISTS idx_zip ON zip_codes(zip)");
        $db->close();
    }

    /**
     * CSVファイルからデータをインポート（洗い替え）する
     */
    public function importFromCsv(string $csvFilePath): void
    {
        if (!file_exists($csvFilePath)) {
            throw new Exception("CSVファイルが見つかりません: $csvFilePath");
        }

        $db = $this->getDbConnection();
        $db->exec('BEGIN');

        try {
            // 既存データを全削除（洗い替え）
            $db->exec("DELETE FROM zip_codes");

            // CSV読み込み
            $fp = fopen($csvFilePath, 'r');
            
            $sql = "INSERT INTO zip_codes (
                jis_code, zip_old, zip, 
                pref_kana, city_kana, town_kana, 
                pref, city, town, 
                flag1, flag2, flag3, flag4, flag5, flag6
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $db->prepare($sql);

            $count = 0;
            while (($data = fgetcsv($fp)) !== false) {
                // カラム数が足りない行はスキップ
                if (count($data) < 15) continue;

                // バインド (CSVの列順通り)
                // 1行1レコード形式のutf_ken_all.csvは全15カラム
                $stmt->bindValue(1, $data[0], SQLITE3_TEXT);  // JIS
                $stmt->bindValue(2, $data[1], SQLITE3_TEXT);  // 旧郵便番号
                $stmt->bindValue(3, $data[2], SQLITE3_TEXT);  // 郵便番号(7桁)
                $stmt->bindValue(4, $data[3], SQLITE3_TEXT);  // 都道府県カナ
                $stmt->bindValue(5, $data[4], SQLITE3_TEXT);  // 市区町村カナ
                $stmt->bindValue(6, $data[5], SQLITE3_TEXT);  // 町域カナ
                $stmt->bindValue(7, $data[6], SQLITE3_TEXT);  // 都道府県
                $stmt->bindValue(8, $data[7], SQLITE3_TEXT);  // 市区町村
                $stmt->bindValue(9, $data[8], SQLITE3_TEXT);  // 町域
                $stmt->bindValue(10, $data[9], SQLITE3_INTEGER);
                $stmt->bindValue(11, $data[10], SQLITE3_INTEGER);
                $stmt->bindValue(12, $data[11], SQLITE3_INTEGER);
                $stmt->bindValue(13, $data[12], SQLITE3_INTEGER);
                $stmt->bindValue(14, $data[13], SQLITE3_INTEGER);
                $stmt->bindValue(15, $data[14], SQLITE3_INTEGER);

                $stmt->execute();
                $stmt->reset(); // SQLite3Stmtはresetが必要
                
                $count++;
                if ($count % 10000 === 0) {
                    echo "Imported {$count} rows...\n";
                }
            }
            fclose($fp);
            
            $db->exec('COMMIT');
            echo "Import Complete! Total {$count} rows.\n";

        } catch (Exception $e) {
            $db->exec('ROLLBACK');
            throw $e;
        } finally {
            $db->close();
        }
    }

    /**
     * 郵便番号から住所を検索する
     * @return array ヒットした行の配列（複数件ある場合も考慮して配列の配列を返す）
     */
    public function searchAddress(string $zipcode): array
    {
        $db = $this->getDbConnection();
        $zip_clean = str_replace('-', '', $zipcode);
        
        $sql = "SELECT pref, city, town, pref_kana, city_kana, town_kana FROM zip_codes WHERE zip = ?";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(1, $zip_clean, SQLITE3_TEXT);
        
        $result = $stmt->execute();
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        $db->close();
        
        return $rows;
    }
}
