<?php
/**
 * CRMユーザー管理用のDB操作ドライバクラス (MariaDB/MySQL対応版)
 */
class CrmUserDbDriverMaria
{
    public function __construct()
    {
        // ディレクトリ作成等の処理は不要のため削除
        // 念の為テーブルが存在するかチェック（事前作成済みなら不要ですが安全策として残しています）
        require_once __DIR__ . '/config_maria.php';
        $this->initializeDatabase();
    }

    private function getDbConnection(): PDO
    {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', CRM_DB_HOST, CRM_DB_NAME);
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        return new PDO($dsn, CRM_DB_USER, CRM_DB_PASS, $options);
    }

    /**
     * データベースとテーブルの初期化
     */
    private function initializeDatabase(): void
    {
        try {
            $pdo = $this->getDbConnection();
            
            // username は Indexを貼るため TEXT ではなく VARCHAR(255) 推奨
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS crm_users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(255) NOT NULL UNIQUE,
                    password_hash TEXT NOT NULL,
                    extension TEXT,
                    weight INT DEFAULT 30,
                    created_at DATETIME
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            // MySQLではUNIQUE制約でIndexが自動生成されるため、明示的なCREATE INDEXは不要な場合が多いですが念の為
            // $pdo->exec('CREATE INDEX IF NOT EXISTS idx_username ON crm_users(username)'); // MariaDB 10.1.4+ syntax

            // KVS用
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS system_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    `key` VARCHAR(255) NOT NULL UNIQUE,
                    value TEXT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            
        } catch (\PDOException $e) {
            error_log("CrmUserDbDriver Initialization Error: " . $e->getMessage());
        }
    }

    /**
     * ユーザー数をカウントする
     * @return int ユーザー総数
     */
    public function countUsers(): int
    {
        try {
            $pdo = $this->getDbConnection();
            $stmt = $pdo->query("SELECT count(*) FROM crm_users");
            return (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            error_log("CrmUserDbDriver countUsers Error: " . $e->getMessage());
            return -1;
        }
    }

    /**
     * ユーザー名からユーザー情報を取得する
     * @param string $username
     * @return array|null
     */
    public function getUserByName(string $username): ?array
    {
        try {
            $pdo = $this->getDbConnection();
            $stmt = $pdo->prepare("SELECT * FROM crm_users WHERE username = :username");
            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            
            $stmt->execute();
            $row = $stmt->fetch();
            return $row ?: null;

        } catch (\PDOException $e) {
            error_log("CrmUserDbDriver GetByName Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * ユーザーを新規作成する (パスワードはハッシュ化)
     * @param string $username
     * @param string $password
     * @param string $extension
     * @param int $weight
     * @return bool
     */
    public function createUser(string $username, string $password, string $extension, int $weight): bool
    {
        if (empty($username) || empty($password)) return false;

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) return false;

        $dt = new \DateTime('now', new \DateTimeZone('Asia/Tokyo'));
        $timestamp = $dt->format('Y-m-d H:i:s');

        try {
            $pdo = $this->getDbConnection();
            $sql = "INSERT INTO crm_users (username, password_hash, extension, weight, created_at) 
                    VALUES (:username, :password_hash, :extension, :weight, :created_at)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            $stmt->bindValue(':password_hash', $hash, PDO::PARAM_STR);
            $stmt->bindValue(':extension', $extension, PDO::PARAM_STR);
            $stmt->bindValue(':weight', $weight, PDO::PARAM_INT);
            $stmt->bindValue(':created_at', $timestamp, PDO::PARAM_STR);

            return $stmt->execute();

        } catch (\PDOException $e) {
            // UNIQUE制約違反 (ユーザー名重複) SQLSTATE 23000
            if ($e->getCode() == '23000') { 
                error_log("CrmUserDbDriver Create Error: Username already exists.");
            } else {
                error_log("CrmUserDbDriver Create Error: " . $e->getMessage());
            }
            return false;
        }
    }
    
    /**
     * ユーザーのパスワードを更新する
     * @param int $user_id
     * @param string $new_password
     * @return bool
     */
    public function updateUserPassword(int $user_id, string $new_password): bool
    {
        if (empty($new_password)) return false;

        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        if ($hash === false) return false;

        try {
            $pdo = $this->getDbConnection();
            $stmt = $pdo->prepare("UPDATE crm_users SET password_hash = :hash WHERE id = :id");
            $stmt->bindValue(':hash', $hash, PDO::PARAM_STR);
            $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
            
            return $stmt->execute();

        } catch (\PDOException $e) {
            error_log("CrmUserDbDriver updateUserPassword Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 全ユーザーのリストを取得する (パスワードハッシュを除く)
     * @return array
     */
    public function getAllUsers(): array
    {
        try {
            $pdo = $this->getDbConnection();
            $stmt = $pdo->query("SELECT id, username, extension, weight, created_at FROM crm_users ORDER BY username");
            return $stmt->fetchAll();

        } catch (\PDOException $e) {
            error_log("CrmUserDbDriver getAllUsers Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * ユーザーIDでユーザーを削除する
     * @param int $user_id
     * @return bool
     */
    public function deleteUser(int $user_id): bool
    {
        try {
            $pdo = $this->getDbConnection();
            $stmt = $pdo->prepare("DELETE FROM crm_users WHERE id = :id");
            $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
            
            return $stmt->execute();

        } catch (\PDOException $e) {
            error_log("CrmUserDbDriver deleteUser Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * (管理者用) ユーザー情報を更新する
     * @param int $user_id
     * @param string $username
     * @param string $extension
     * @param int $weight
     * @return bool
     */
    public function updateUser(int $user_id, string $username, string $extension, int $weight): bool
    {
        try {
            $pdo = $this->getDbConnection();
            $stmt = $pdo->prepare("UPDATE crm_users SET username = :username, extension = :extension, weight = :weight WHERE id = :id");
            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            $stmt->bindValue(':extension', $extension, PDO::PARAM_STR);
            $stmt->bindValue(':weight', $weight, PDO::PARAM_INT);
            $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
            
            return $stmt->execute();

        } catch (\PDOException $e) {
            error_log("CrmUserDbDriver updateUser Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * システム設定を取得する (KVS)
     * @param string $key
     * @param mixed $default デフォルト値
     * @return string|null
     */
    public function getSystemSetting(string $key, $default = null): ?string
    {
        try {
            $pdo = $this->getDbConnection();
            // `key` は予約語の可能性があるためバッククォート推奨
            $stmt = $pdo->prepare("SELECT value FROM system_settings WHERE `key` = :key");
            $stmt->bindValue(':key', $key, PDO::PARAM_STR);
            
            $stmt->execute();
            $row = $stmt->fetch();

            return $row ? $row['value'] : $default;

        } catch (\PDOException $e) {
            error_log("CrmUserDbDriver getSetting Error: " . $e->getMessage());
            return $default;
        }
    }

    /**
     * システム設定を保存する (KVS)
     * (UPSERT: 存在すれば更新、なければ挿入)
     * @param string $key
     * @param string $value
     * @return bool
     */
    public function saveSystemSetting(string $key, string $value): bool
    {
        try {
            $pdo = $this->getDbConnection();
            // MySQL/MariaDBのUPSERT構文
            $sql = "INSERT INTO system_settings (`key`, value) VALUES (:key, :value)
                    ON DUPLICATE KEY UPDATE value = VALUES(value)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':key', $key, PDO::PARAM_STR);
            $stmt->bindValue(':value', $value, PDO::PARAM_STR);

            return $stmt->execute();

        } catch (\PDOException $e) {
            error_log("CrmUserDbDriver saveSetting Error: " . $e->getMessage());
            return false;
        }
    }
}
?>
