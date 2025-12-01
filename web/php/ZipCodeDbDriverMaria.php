<?php

class ZipCodeDbDriverMaria
{
    public function __construct()
    {
        // コンストラクタではテーブル作成を行わず、接続確認のみ
        // (テーブルがない場合の作成は検索時やインポート時に担保、あるいは初回アクセス時)
        $this->ensureTableExists();
    }

    private function getDbConnection(): PDO
    {
        // config_maria.php の定数を使用
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', CRM_DB_HOST, CRM_DB_NAME);
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        return new PDO($dsn, CRM_DB_USER, CRM_DB_PASS, $options);
    }

    // テーブル作成ロジックを分離
    private function createTable(PDO $pdo): void
    {
        // 安全のため住所系カラムはすべて TEXT 型に変更して桁あふれを防ぐ
        $sql = "CREATE TABLE IF NOT EXISTS zip_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            jis_code VARCHAR(10),
            zip_old VARCHAR(10),
            zip VARCHAR(10) NOT NULL,
            pref_kana TEXT,
            city_kana TEXT,
            town_kana TEXT,
            pref TEXT,
            city TEXT,
            town TEXT,
            flag1 TINYINT,
            flag2 TINYINT,
            flag3 TINYINT,
            flag4 TINYINT,
            flag5 TINYINT,
            flag6 TINYINT,
            INDEX idx_zip (zip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $pdo->exec($sql);
    }

    private function ensureTableExists(): void
    {
        try {
            $pdo = $this->getDbConnection();
            $this->createTable($pdo);
        } catch (PDOException $e) {
            // 接続エラー等はここでログ出力
            error_log("ZipCodeDbDriverMaria Init Error: " . $e->getMessage());
        }
    }

    public function importFromCsv(string $csvFilePath): void
    {
        if (!file_exists($csvFilePath)) {
            throw new Exception("CSVファイルが見つかりません: $csvFilePath");
        }

        $pdo = $this->getDbConnection();

        // 1. テーブルの再作成 (DDL)
        try {
            $pdo->exec("DROP TABLE IF EXISTS zip_codes");
            $this->createTable($pdo);
        } catch (PDOException $e) {
            throw new Exception("テーブル作成に失敗しました: " . $e->getMessage());
        }

        // 2. データのインポート (DML)
        try {
            $pdo->beginTransaction();

            $fp = fopen($csvFilePath, 'r');
            
            $sql = "INSERT INTO zip_codes (
                jis_code, zip_old, zip, 
                pref_kana, city_kana, town_kana, 
                pref, city, town, 
                flag1, flag2, flag3, flag4, flag5, flag6
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql);

            $count = 0;
            while (($data = fgetcsv($fp)) !== false) {
                // 空行や壊れた行をスキップ
                if (count($data) < 15) continue;

                $stmt->execute([
                    $data[0], $data[1], $data[2], 
                    $data[3], $data[4], $data[5], 
                    $data[6], $data[7], $data[8], 
                    $data[9], $data[10], $data[11], $data[12], $data[13], $data[14]
                ]);
                
                $count++;
                if ($count % 10000 === 0) {
                    echo "Imported {$count} rows...\n";
                }
            }
            fclose($fp);
            
            $pdo->commit();
            echo "Import Complete! Total {$count} rows.\n";

        } catch (Exception $e) {
            // エラー時はロールバック
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function searchAddress(string $zipcode): array
    {
        $pdo = $this->getDbConnection();
        $zip_clean = str_replace('-', '', $zipcode);
        
        $sql = "SELECT pref, city, town, pref_kana, city_kana, town_kana FROM zip_codes WHERE zip = :zip";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':zip', $zip_clean, PDO::PARAM_STR);
        
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
