<?php
/**
 * CRMユーザー管理用のDB操作ドライバクラス
 */
class CrmUserDbDriverSQLite3
{
    public function __construct()
    {
        $dir = dirname(USER_DB_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        // 初期化（テーブル作成）
        $this->initializeDatabase();
    }

    private function getDbConnection(): SQLite3
    {
        $db = new SQLite3(USER_DB_PATH);
        $db->busyTimeout(5000);
        return $db;
    }

    /**
     * データベースとテーブルの初期化
     */
    private function initializeDatabase(): void
    {
        try {
            $db = new SQLite3(USER_DB_PATH);
            // username を UNIQUE (重複不可) に設定
            $db->exec(
                'CREATE TABLE IF NOT EXISTS crm_users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT NOT NULL UNIQUE,
                    password_hash TEXT NOT NULL,
                    extension TEXT,
                    weight INTEGER DEFAULT 30,
                    created_at TEXT
                )'
            );
            $db->exec('CREATE INDEX IF NOT EXISTS idx_username ON crm_users(username)');

            // KVS用
            $db->exec(
                'CREATE TABLE IF NOT EXISTS system_settings (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    key TEXT NOT NULL UNIQUE,
                    value TEXT
                )'
            );

            $db->exec('CREATE INDEX IF NOT EXISTS idx_key ON system_settings(key)');
            $db->close();
        } catch (\Exception $e) {
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
            $db = $this->getDbConnection();
            $count = $db->querySingle("SELECT count(*) FROM crm_users");
            $db->close();
            return (int)$count;
        } catch (\Exception $e) {
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
            $db = $this->getDbConnection();
            $stmt = $db->prepare("SELECT * FROM crm_users WHERE username = :username");
            $stmt->bindValue(':username', $username, SQLITE3_TEXT);
            
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            $stmt->close();
            $db->close();
            return $row ?: null;

        } catch (\Exception $e) {
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

        // パスワードをハッシュ化
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) return false;

        $dt = new \DateTime('now', new \DateTimeZone('Asia/Tokyo'));
        $timestamp = $dt->format('Y-m-d H:i:s');

        try {
            $db = $this->getDbConnection();
            $sql = "INSERT INTO crm_users (username, password_hash, extension, weight, created_at) 
                    VALUES (:username, :password_hash, :extension, :weight, :created_at)";
            
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':username', $username, SQLITE3_TEXT);
            $stmt->bindValue(':password_hash', $hash, SQLITE3_TEXT);
            $stmt->bindValue(':extension', $extension, SQLITE3_TEXT);
            $stmt->bindValue(':weight', $weight, SQLITE3_INTEGER);
            $stmt->bindValue(':created_at', $timestamp, SQLITE3_TEXT);

            $result = $stmt->execute();
            $stmt->close();
            $db->close();
            return $result !== false;

        } catch (\Exception $e) {
            // UNIQUE制約違反 (ユーザー名重複)
            if ($e->getCode() == 19) { 
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
            $db = $this->getDbConnection();
            $stmt = $db->prepare("UPDATE crm_users SET password_hash = :hash WHERE id = :id");
            $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
            $stmt->bindValue(':id', $user_id, SQLITE3_INTEGER);
            
            $result = $stmt->execute();
            $stmt->close();
            $db->close();
            return $result !== false;

        } catch (\Exception $e) {
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
            $db = $this->getDbConnection();
            $results = [];
            $res = $db->query("SELECT id, username, extension, weight, created_at FROM crm_users ORDER BY username");
            
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $results[] = $row;
            }
            $db->close();
            return $results;

        } catch (\Exception $e) {
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
            $db = $this->getDbConnection();
            $stmt = $db->prepare("DELETE FROM crm_users WHERE id = :id");
            $stmt->bindValue(':id', $user_id, SQLITE3_INTEGER);
            
            $result = $stmt->execute();
            $stmt->close();
            $db->close();
            return $result !== false;

        } catch (\Exception $e) {
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
            $db = $this->getDbConnection();
            $stmt = $db->prepare("UPDATE crm_users SET username = :username, extension = :extension, weight = :weight WHERE id = :id");
            $stmt->bindValue(':username', $username, SQLITE3_TEXT);
            $stmt->bindValue(':extension', $extension, SQLITE3_TEXT);
            $stmt->bindValue(':weight', $weight, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $user_id, SQLITE3_INTEGER);
            
            $result = $stmt->execute();
            $stmt->close();
            $db->close();
            return $result !== false;

        } catch (\Exception $e) {
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
            $db = $this->getDbConnection();
            $stmt = $db->prepare("SELECT value FROM system_settings WHERE key = :key");
            $stmt->bindValue(':key', $key, SQLITE3_TEXT);
            
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            $stmt->close();
            $db->close();

            // 見つかれば値を、見つからなければ $default を返す
            return $row ? $row['value'] : $default;

        } catch (\Exception $e) {
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
            $db = $this->getDbConnection();
            // keyが競合(UNIQUE)したら、valueをUPDATEする (UPSERT)
            $sql = "INSERT INTO system_settings (key, value) VALUES (:key, :value)
                    ON CONFLICT(key) DO UPDATE SET value = excluded.value";
            
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':key', $key, SQLITE3_TEXT);
            $stmt->bindValue(':value', $value, SQLITE3_TEXT);

            $result = $stmt->execute();
            $stmt->close();
            $db->close();
            return $result !== false;

        } catch (\Exception $e) {
            error_log("CrmUserDbDriver saveSetting Error: " . $e->getMessage());
            return false;
        }
    }
}
?>
