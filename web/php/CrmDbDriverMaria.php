<?php

/**
 * CRM（顧客管理）用のDB操作ドライバクラス (MariaDB/MySQL対応版)
 */
class CrmDbDriverMaria
{
    public function __construct()
    {
        // ディレクトリ作成処理は削除
        require_once __DIR__ . '/config_maria.php';
        $this->initializeDatabase();
        $this->updateSchema();
    }

    /**
     * データベース接続を取得するヘルパーメソッド
     */
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
     * 顧客情報を保存（新規登録または更新）する
     * @param array $data フォームからの入力データ連想配列
     * @return bool 成功時true
     */
    public function saveCustomer(array $data): bool
    {
        $pdo = null;
        try {
            $pdo = $this->getDbConnection();

            $dt = new \DateTime('now', new \DateTimeZone('Asia/Tokyo'));
            $timestamp = $dt->format('Y-m-d H:i:s');

            $phone_raw = $data['phone'] ?? '';
            if (empty($phone_raw)) {
                error_log("CrmDbDriver Error: Phone number is required.");
                return false;
            }
            $phone_clean = str_replace('-', '', $phone_raw);

            // --- トランザクション開始 ---
            $pdo->beginTransaction();

            // 1. 競合チェック
            $stmt_check = $pdo->prepare("SELECT phone FROM customers WHERE REPLACE(phone, '-', '') = :phone_clean AND phone != :phone_raw");
            $stmt_check->bindValue(':phone_clean', $phone_clean, PDO::PARAM_STR);
            $stmt_check->bindValue(':phone_raw', $phone_raw, PDO::PARAM_STR);
            $stmt_check->execute();
            $existing_conflict = $stmt_check->fetch();
            $stmt_check = null;

            if ($existing_conflict) {
                $stmt_del = $pdo->prepare("DELETE FROM customers WHERE phone = :existing_phone");
                $stmt_del->bindValue(':existing_phone', $existing_conflict['phone'], PDO::PARAM_STR);
                $stmt_del->execute();
            }

            // 3. データを保存 (UPSERT)
            // ★修正: VALUES の中のパラメータを :updated_at と :created_at に分離
            $sql = "INSERT INTO customers (
                        phone, last_name, first_name, last_name_kana, first_name_kana,
                        organization, fax, email, mobile_phone,
                        zip_code, address, address_kana, note, 
                        updated_at, created_at, last_updated_by 
                    ) VALUES (
                        :phone, :last_name, :first_name, :last_name_kana, :first_name_kana,
                        :organization, :fax, :email, :mobile_phone,
                        :zip_code, :address, :address_kana, :note, 
                        :updated_at, :created_at, :last_updated_by
                    )
                    ON DUPLICATE KEY UPDATE
                        last_name = VALUES(last_name),
                        first_name = VALUES(first_name),
                        last_name_kana = VALUES(last_name_kana),
                        first_name_kana = VALUES(first_name_kana),
                        organization = VALUES(organization),
                        fax = VALUES(fax),
                        email = VALUES(email),
                        mobile_phone = VALUES(mobile_phone),
                        zip_code = VALUES(zip_code),
                        address = VALUES(address),
                        address_kana = VALUES(address_kana),
                        note = VALUES(note),
                        updated_at = VALUES(updated_at),
                        last_updated_by = VALUES(last_updated_by)";

            $stmt_save = $pdo->prepare($sql);

            $stmt_save->bindValue(':phone', $phone_raw, PDO::PARAM_STR);
            $stmt_save->bindValue(':last_name', $data['last_name'] ?? '', PDO::PARAM_STR);
            $stmt_save->bindValue(':first_name', $data['first_name'] ?? '', PDO::PARAM_STR);
            $stmt_save->bindValue(':last_name_kana', $data['last_name_kana'] ?? '', PDO::PARAM_STR);
            $stmt_save->bindValue(':first_name_kana', $data['first_name_kana'] ?? '', PDO::PARAM_STR);
            $stmt_save->bindValue(':organization', $data['organization'] ?? '', PDO::PARAM_STR);
            $stmt_save->bindValue(':fax', $data['fax'] ?? '', PDO::PARAM_STR);
            $stmt_save->bindValue(':email', $data['email'] ?? '', PDO::PARAM_STR);
            $stmt_save->bindValue(':mobile_phone', $data['mobile_phone'] ?? '', PDO::PARAM_STR);
            $stmt_save->bindValue(':zip_code', $data['zip_code'] ?? '', PDO::PARAM_STR);
            $stmt_save->bindValue(':address', $data['address'] ?? '', PDO::PARAM_STR);
            $stmt_save->bindValue(':address_kana', $data['address_kana'] ?? '', PDO::PARAM_STR);
            $stmt_save->bindValue(':note', $data['note'] ?? '', PDO::PARAM_STR);
            
            // ★修正: それぞれ個別にバインド
            $stmt_save->bindValue(':updated_at', $timestamp, PDO::PARAM_STR);
            $stmt_save->bindValue(':created_at', $timestamp, PDO::PARAM_STR);
            
            $stmt_save->bindValue(':last_updated_by', $data['last_updated_by'] ?? '', PDO::PARAM_STR);

            $result = $stmt_save->execute();

            $pdo->commit();
            return $result;

        } catch (\Exception $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("CrmDbDriver Save Error (transaction): " . $e->getMessage());
            return false;
        }
    }

    /**
     * 顧客を検索する (キーワード1つの場合)
     * @param string $keyword
     * @param int $page
     * @param int $per_page
     * @return array
     */
    public function searchCustomers(string $keyword, int $page, int $per_page): array
    {
        $offset = ($page - 1) * $per_page;
        
        try {
            $pdo = $this->getDbConnection();

            $keyword_clean = str_replace('-', '', $keyword);
            $keyword_raw = $keyword;

            if (trim($keyword) === '') {
                return []; 
            }
            
            // MariaDBでも REPLACE関数 は使用可能
            $sql = "SELECT * FROM customers WHERE 
                    last_name LIKE :kw_raw OR first_name LIKE :kw_raw OR 
                    last_name_kana LIKE :kw_raw OR first_name_kana LIKE :kw_raw OR 
                    organization LIKE :kw_raw OR email LIKE :kw_raw OR 
                    address LIKE :kw_raw OR address_kana LIKE :kw_raw OR
                    REPLACE(phone, '-', '') LIKE :kw_clean OR
                    REPLACE(fax, '-', '') LIKE :kw_clean OR
                    REPLACE(mobile_phone, '-', '') LIKE :kw_clean OR
                    REPLACE(zip_code, '-', '') LIKE :kw_clean
                    ORDER BY updated_at DESC 
                    LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':kw_raw', '%' . $keyword_raw . '%', PDO::PARAM_STR);
            $stmt->bindValue(':kw_clean', '%' . $keyword_clean . '%', PDO::PARAM_STR);
            $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

            $stmt->execute();
            return $stmt->fetchAll();

        } catch (\PDOException $e) {
            error_log("CrmDbDriver Search Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 顧客検索の総件数を取得する (キーワード1つの場合)
     * @param string $keyword
     * @return int
     */
    public function searchCustomersCount(string $keyword): int
    {
        try {
            $pdo = $this->getDbConnection();
            $keyword_clean = str_replace('-', '', $keyword);
            $keyword_raw = $keyword;

            if (trim($keyword) === '') {
                return 0;
            }
            
            $sql = "SELECT count(*) FROM customers WHERE 
                    last_name LIKE :kw_raw OR first_name LIKE :kw_raw OR 
                    last_name_kana LIKE :kw_raw OR first_name_kana LIKE :kw_raw OR 
                    organization LIKE :kw_raw OR email LIKE :kw_raw OR 
                    address LIKE :kw_raw OR address_kana LIKE :kw_raw OR
                    REPLACE(phone, '-', '') LIKE :kw_clean OR
                    REPLACE(fax, '-', '') LIKE :kw_clean OR
                    REPLACE(mobile_phone, '-', '') LIKE :kw_clean OR
                    REPLACE(zip_code, '-', '') LIKE :kw_clean";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':kw_raw', '%' . $keyword_raw . '%', PDO::PARAM_STR);
            $stmt->bindValue(':kw_clean', '%' . $keyword_clean . '%', PDO::PARAM_STR);

            $stmt->execute();
            return (int)$stmt->fetchColumn();

        } catch (\PDOException $e) {
            error_log("CrmDbDriver SearchCount Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * 電話番号から顧客情報を1件取得する
     * @param string $phone 電話番号
     * @return array|null
     */
    public function getCustomerByPhone(string $phone): ?array
    {
        try {
            $pdo = $this->getDbConnection();
            $phone_clean = str_replace('-', '', $phone);

            $stmt = $pdo->prepare("SELECT * FROM customers WHERE REPLACE(phone, '-', '') = :phone");
            $stmt->bindValue(':phone', $phone_clean, PDO::PARAM_STR);
            
            $stmt->execute();
            $row = $stmt->fetch();
            return $row ?: null;

        } catch (\PDOException $e) {
            error_log("CrmDbDriver GetByPhone Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * データベースとテーブルの初期化
     */
    private function initializeDatabase(): void
    {
        try {
            $pdo = $this->getDbConnection();
            
            // phoneカラムにUnique制約があるため、TEXTではなく VARCHAR(255) 推奨
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS customers (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    last_name TEXT,
                    first_name TEXT,
                    last_name_kana TEXT,
                    first_name_kana TEXT,
                    organization TEXT,
                    phone VARCHAR(255) NOT NULL UNIQUE,
                    fax VARCHAR(255),
                    mobile_phone VARCHAR(255),
                    email TEXT,
                    zip_code VARCHAR(255),
                    address TEXT,
                    address_kana TEXT,
                    note TEXT,
                    created_at DATETIME,
                    updated_at DATETIME,
                    last_updated_by TEXT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            
            // Index作成
            // MySQL/MariaDB 10.1.4+ syntax
            // $pdo->exec('CREATE INDEX IF NOT EXISTS idx_phone ON customers(phone)'); // Uniqueなので自動作成される
            // 複合インデックスの作成（キー長制限回避のためPrefix長を指定するか、カラムをVARCHARにする等の考慮が必要ですが、ここでは簡易的に実装）
            // MySQLのTEXT型に対するインデックスはPrefix指定が必須ですが、ここではエラー回避のため一旦スキップ、または個別にVARCHAR変更推奨
            // 本格的な環境ではスキーマ設計として全ての検索対象カラムをVARCHAR(255)等にするのが望ましいです。
            
        } catch (\PDOException $e) {
            error_log("CrmDbDriver Initialization Error: " . $e->getMessage());
        }
    }

    /**
     * 顧客をAND条件で検索する
     * @param array $search_data
     * @param int $page
     * @param int $per_page
     * @return array
     */
    public function searchCustomersComplex(array $search_data, int $page, int $per_page): array
    {
        $offset = ($page - 1) * $per_page;
        
        $search_map = [
            'last_name' => 'LIKE', 'first_name' => 'LIKE', 'last_name_kana' => 'LIKE',
            'first_name_kana' => 'LIKE', 'organization' => 'LIKE', 'phone' => 'LIKE',
            'fax' => 'LIKE', 'email' => 'LIKE', 'zip_code' => 'LIKE',
            'address' => 'LIKE', 'address_kana' => 'LIKE',
        ];
        $hyphen_fields = ['phone', 'fax', 'zip_code'];

        $where_clauses = [];
        $bind_values = [];

        foreach ($search_map as $key => $operator) {
            $value = trim($search_data[$key] ?? '');
            if ($value !== '') {
                $param_name = ':' . $key;
                if (in_array($key, $hyphen_fields)) {
                    $where_clauses[] = "REPLACE({$key}, '-', '') LIKE {$param_name}";
                    $bind_values[$param_name] = '%' . str_replace('-', '', $value) . '%';
                } else {
                    $where_clauses[] = "{$key} LIKE {$param_name}";
                    $bind_values[$param_name] = '%' . $value . '%';
                }
            }
        }
        if (empty($where_clauses)) return [];

        $sql = "SELECT * FROM customers WHERE " . implode(' AND ', $where_clauses) .
               " ORDER BY updated_at DESC LIMIT :limit OFFSET :offset";

        try {
            $pdo = $this->getDbConnection();
            $stmt = $pdo->prepare($sql);

            foreach ($bind_values as $key => $val) {
                $stmt->bindValue($key, $val, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

            $stmt->execute();
            return $stmt->fetchAll();

        } catch (\PDOException $e) {
            error_log("CrmDbDriver SearchComplex Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 顧客検索の総件数を取得する (AND条件)
     * @param array $search_data
     * @return int
     */
    public function searchCustomersComplexCount(array $search_data): int
    {
        $search_map = [
            'last_name' => 'LIKE', 'first_name' => 'LIKE', 'last_name_kana' => 'LIKE',
            'first_name_kana' => 'LIKE', 'organization' => 'LIKE', 'phone' => 'LIKE',
            'fax' => 'LIKE', 'email' => 'LIKE', 'zip_code' => 'LIKE',
            'address' => 'LIKE', 'address_kana' => 'LIKE',
        ];
        $hyphen_fields = ['phone', 'fax', 'zip_code', 'mobile_phone'];

        $where_clauses = [];
        $bind_values = [];

        foreach ($search_map as $key => $operator) {
            $value = trim($search_data[$key] ?? '');
            if ($value !== '') {
                $param_name = ':' . $key;
                if (in_array($key, $hyphen_fields)) {
                    $where_clauses[] = "REPLACE({$key}, '-', '') LIKE {$param_name}";
                    $bind_values[$param_name] = '%' . str_replace('-', '', $value) . '%';
                } else {
                    $where_clauses[] = "{$key} LIKE {$param_name}";
                    $bind_values[$param_name] = '%' . $value . '%';
                }
            }
        }
        if (empty($where_clauses)) return 0;

        $sql = "SELECT count(*) FROM customers WHERE " . implode(' AND ', $where_clauses);

        try {
            $pdo = $this->getDbConnection();
            $stmt = $pdo->prepare($sql);

            foreach ($bind_values as $key => $val) {
                $stmt->bindValue($key, $val, PDO::PARAM_STR);
            }
            
            $stmt->execute();
            return (int)$stmt->fetchColumn();

        } catch (\PDOException $e) {
            error_log("CrmDbDriver SearchComplexCount Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * 電話番号をキーに顧客情報を削除する
     * @param string $phone 電話番号
     * @return bool 成功時true
     */
    public function deleteCustomerByPhone(string $phone): bool
    {
        if (empty($phone)) {
            return false;
        }

        try {
            $pdo = $this->getDbConnection();
            $stmt = $pdo->prepare("DELETE FROM customers WHERE phone = :phone");
            $stmt->bindValue(':phone', $phone, PDO::PARAM_STR);
            
            $stmt->execute();
            
            return $stmt->rowCount() > 0;

        } catch (\PDOException $e) {
            error_log("CrmDbDriver DeleteByPhone Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 顧客の総件数を取得する
     * @return int 総件数
     */
    public function getTotalCustomerCount(): int
    {
        try {
            $pdo = $this->getDbConnection();
            $stmt = $pdo->query("SELECT count(*) FROM customers");
            return (int)$stmt->fetchColumn();

        } catch (\PDOException $e) {
            error_log("CrmDbDriver GetTotalCount Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * 顧客データをページネーション用に取得する
     * @param int $page 取得するページ番号
     * @param int $per_page 1ページあたりの件数
     * @return array
     */
    public function getCustomersPaginated(int $page, int $per_page): array
    {
        $offset = ($page - 1) * $per_page;
        
        try {
            $pdo = $this->getDbConnection();

            $sql = "SELECT * FROM customers 
                    ORDER BY updated_at DESC 
                    LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

            $stmt->execute();
            return $stmt->fetchAll();

        } catch (\PDOException $e) {
            error_log("CrmDbDriver GetPaginated Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * データベーススキーマをチェックし、不足しているカラムがあれば追加する
     */
    private function updateSchema()
    {
        try {
            $pdo = $this->getDbConnection();
            
            // カラムチェック用クロージャ
            // information_schema を使って安全に確認します
            $checkColumn = function($colName) use ($pdo) {
                $sql = "SELECT COUNT(*) FROM information_schema.columns 
                        WHERE table_schema = :db_name 
                        AND table_name = 'customers' 
                        AND column_name = :col_name";
                
                $stmt = $pdo->prepare($sql);
                // 定数 CRM_DB_NAME を使用します
                $stmt->bindValue(':db_name', CRM_DB_NAME, PDO::PARAM_STR);
                $stmt->bindValue(':col_name', $colName, PDO::PARAM_STR);
                $stmt->execute();
                
                return ((int)$stmt->fetchColumn()) > 0;
            };

            // mobile_phone のチェックと追加
            if (!$checkColumn('mobile_phone')) {
                // ALTER TABLE はプリペアドステートメントが使えないため、カラム名は固定文字列として扱います
                $pdo->exec('ALTER TABLE customers ADD COLUMN mobile_phone VARCHAR(255)');
            }

            // last_updated_by のチェックと追加
            if (!$checkColumn('last_updated_by')) {
                $pdo->exec('ALTER TABLE customers ADD COLUMN last_updated_by VARCHAR(255)');
            }

        } catch (\PDOException $e) {
            error_log("CrmDbDriver updateSchema Error: " . $e->getMessage());
        }
    }

    /**
     * 全顧客データを取得する (CSVエクスポート用)
     * @return array
     */
    public function getAllCustomersForExport(): array
    {
        try {
            $pdo = $this->getDbConnection();
            
            $sql = "SELECT phone, mobile_phone, fax, email, last_name, first_name, last_name_kana, first_name_kana, organization, zip_code, address, address_kana, note, created_at, updated_at FROM customers ORDER BY updated_at DESC";
            
            // fputcsvとの互換性のため fetchAll(PDO::FETCH_NUM) を使用
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_NUM);

        } catch (\PDOException $e) {
            error_log("CrmDbDriver GetAllForExport Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * カナ検索（タブ用）の該当件数を取得する
     * @param array $chars
     * @return int
     */
    public function getCustomersCountByKana(array $chars): int
    {
        if (empty($chars)) {
            return 0;
        }

        try {
            $pdo = $this->getDbConnection();

            $where_clauses = [];
            foreach ($chars as $index => $char) {
                $where_clauses[] = "last_name_kana LIKE :k{$index}";
                $where_clauses[] = "last_name_kana LIKE :h{$index}";
            }
            
            $sql = "SELECT count(*) FROM customers WHERE " . implode(' OR ', $where_clauses);
            $stmt = $pdo->prepare($sql);

            foreach ($chars as $index => $char) {
                $stmt->bindValue(":k{$index}", $char . '%', PDO::PARAM_STR);
                $hira_char = mb_convert_kana($char, 'c', 'UTF-8');
                $stmt->bindValue(":h{$index}", $hira_char . '%', PDO::PARAM_STR);
            }

            $stmt->execute();
            return (int)$stmt->fetchColumn();

        } catch (\PDOException $e) {
            error_log("CrmDbDriver CountByKana Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * カナ検索（タブ用）の結果を取得する
     * @param array $chars
     * @param int $page
     * @param int $per_page
     * @return array
     */
    public function getCustomersByKana(array $chars, int $page, int $per_page): array
    {
        if (empty($chars)) {
            return [];
        }

        $offset = ($page - 1) * $per_page;

        try {
            $pdo = $this->getDbConnection();

            $where_clauses = [];
            foreach ($chars as $index => $char) {
                $where_clauses[] = "last_name_kana LIKE :k{$index}";
                $where_clauses[] = "last_name_kana LIKE :h{$index}";
            }

            // MariaDBの場合、Collation設定によってはひらがな/カタカナが同一視される場合がありますが、
            // ここでは元のロジック通り、last_name_kanaでソートします。
            $sql = "SELECT * FROM customers 
                    WHERE " . implode(' OR ', $where_clauses) . "
                    ORDER BY last_name_kana ASC, last_name ASC 
                    LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);

            foreach ($chars as $index => $char) {
                $stmt->bindValue(":k{$index}", $char . '%', PDO::PARAM_STR);
                $hira_char = mb_convert_kana($char, 'c', 'UTF-8');
                $stmt->bindValue(":h{$index}", $hira_char . '%', PDO::PARAM_STR);
            }
            
            $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

            $stmt->execute();
            return $stmt->fetchAll();

        } catch (\PDOException $e) {
            error_log("CrmDbDriver GetByKana Error: " . $e->getMessage());
            return [];
        }
    }
}
?>
