<?php

/**
 * CRM（顧客管理）用のDB操作ドライバクラス
 * * レイアウト要件に基づく顧客情報のCRUD操作を提供します。
 * 電話番号(phone)をユニークキーとして扱います。
 */
class CrmDbDriverSQLite3
{
    public function __construct()
    {
        // データベースディレクトリの確認と作成
        $dir = dirname(CRM_DB_PATH);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new \Exception("CRM用DBディレクトリの作成に失敗しました: {$dir}");
            }
        }
        // 初期化（テーブル作成）
        $this->initializeDatabase();
        // スキーマの更新をチェック
        $this->updateSchema();
    }

    /**
     * データベース接続を取得するヘルパーメソッド
     */
    private function getDbConnection(): SQLite3
    {
        $db = new SQLite3(CRM_DB_PATH);
        $db->busyTimeout(5000); // ロック待ち時間のタイムアウト設定
        return $db;
    }

    /**
     * 顧客情報を保存（新規登録または更新）する
     * @param array $data フォームからの入力データ連想配列
     * @return bool 成功時true
     */
    public function saveCustomer(array $data): bool
    {
        try {
            $db = $this->getDbConnection();

            $dt = new \DateTime('now', new \DateTimeZone('Asia/Tokyo'));
            $timestamp = $dt->format('Y-m-d H:i:s');

            $phone_raw = $data['phone'] ?? '';
            if (empty($phone_raw)) {
                error_log("CrmDbDriver Error: Phone number is required.");
                return false;
            }
            $phone_clean = str_replace('-', '', $phone_raw);

            // --- トランザクション開始 ---
            $db->exec('BEGIN');

            // 1. 競合チェック (変更なし)
            $stmt_check = $db->prepare("SELECT phone FROM customers WHERE REPLACE(phone, '-', '') = :phone_clean AND phone != :phone_raw");
            $stmt_check->bindValue(':phone_clean', $phone_clean, SQLITE3_TEXT);
            $stmt_check->bindValue(':phone_raw', $phone_raw, SQLITE3_TEXT);
            $result = $stmt_check->execute();
            $existing_conflict = $result->fetchArray(SQLITE3_ASSOC);
            $stmt_check->close();

            if ($existing_conflict) {
                // 2. 競合削除 (変更なし)
                $db->exec("DELETE FROM customers WHERE phone = '" . $db->escapeString($existing_conflict['phone']) . "'");
            }

            // 3. データを保存 (UPSERT)
            // ★変更: last_updated_by を追加
            $sql = "INSERT INTO customers (
                        phone, last_name, first_name, last_name_kana, first_name_kana,
                        organization, fax, email, mobile_phone,
                        zip_code, address, address_kana, note, 
                        updated_at, created_at, last_updated_by 
                    ) VALUES (
                        :phone, :last_name, :first_name, :last_name_kana, :first_name_kana,
                        :organization, :fax, :email, :mobile_phone,
                        :zip_code, :address, :address_kana, :note, 
                        :updated_at, :updated_at, :last_updated_by
                    )
                    ON CONFLICT(phone) DO UPDATE SET
                        last_name = excluded.last_name,
                        first_name = excluded.first_name,
                        last_name_kana = excluded.last_name_kana,
                        first_name_kana = excluded.first_name_kana,
                        organization = excluded.organization,
                        fax = excluded.fax,
                        email = excluded.email,
                        mobile_phone = excluded.mobile_phone,
                        zip_code = excluded.zip_code,
                        address = excluded.address,
                        address_kana = excluded.address_kana,
                        note = excluded.note,
                        updated_at = excluded.updated_at,
                        last_updated_by = excluded.last_updated_by"; // ★更新時も上書き

            $stmt_save = $db->prepare($sql);

            // すべての値をバインド
            $stmt_save->bindValue(':phone', $phone_raw, SQLITE3_TEXT);
            $stmt_save->bindValue(':last_name', $data['last_name'] ?? '', SQLITE3_TEXT);
            $stmt_save->bindValue(':first_name', $data['first_name'] ?? '', SQLITE3_TEXT);
            $stmt_save->bindValue(':last_name_kana', $data['last_name_kana'] ?? '', SQLITE3_TEXT);
            $stmt_save->bindValue(':first_name_kana', $data['first_name_kana'] ?? '', SQLITE3_TEXT);
            $stmt_save->bindValue(':organization', $data['organization'] ?? '', SQLITE3_TEXT);
            $stmt_save->bindValue(':fax', $data['fax'] ?? '', SQLITE3_TEXT);
            $stmt_save->bindValue(':email', $data['email'] ?? '', SQLITE3_TEXT);
            $stmt_save->bindValue(':mobile_phone', $data['mobile_phone'] ?? '', SQLITE3_TEXT);
            $stmt_save->bindValue(':zip_code', $data['zip_code'] ?? '', SQLITE3_TEXT);
            $stmt_save->bindValue(':address', $data['address'] ?? '', SQLITE3_TEXT);
            $stmt_save->bindValue(':address_kana', $data['address_kana'] ?? '', SQLITE3_TEXT);
            $stmt_save->bindValue(':note', $data['note'] ?? '', SQLITE3_TEXT);
            $stmt_save->bindValue(':updated_at', $timestamp, SQLITE3_TEXT);
            $stmt_save->bindValue(':last_updated_by', $data['last_updated_by'] ?? '', SQLITE3_TEXT);

            $result = $stmt_save->execute();
            $stmt_save->close();

            // --- トランザクション完了 ---
            $db->exec('COMMIT');
            $db->close();

            return $result !== false;

        } catch (\Exception $e) {
            if (isset($db) && $db instanceof \SQLite3) {
                $db->exec('ROLLBACK');
                $db->close();
            }
            error_log("CrmDbDriver Save Error (transaction): " . $e->getMessage());
            return false;
        }
    }

    /**
     * 顧客を検索する (キーワード1つの場合)
     * ★ページネーション対応
     * @param string $keyword
     * @param int $page
     * @param int $per_page
     * @return array
     */
    public function searchCustomers(string $keyword, int $page, int $per_page): array
    {
        // オフセットを計算
        $offset = ($page - 1) * $per_page;
        
        try {
            $db = $this->getDbConnection();
            $results = [];

            $keyword_clean = str_replace('-', '', $keyword);
            $keyword_raw = $keyword;

            if (trim($keyword) === '') {
                // キーワードが空なら何も返さない（全件表示はlist-pageが担当）
                return []; 
            }
            
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
                    LIMIT :limit OFFSET :offset"; // ★LIMIT/OFFSET 
            
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':kw_raw', '%' . $keyword_raw . '%', SQLITE3_TEXT);
            $stmt->bindValue(':kw_clean', '%' . $keyword_clean . '%', SQLITE3_TEXT);
            $stmt->bindValue(':limit', $per_page, SQLITE3_INTEGER); // ★追加
            $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER); // ★追加

            $res = $stmt->execute();
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $results[] = $row;
            }
            
            $stmt->close();
            $db->close();
            return $results;

        } catch (\Exception $e) {
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
            $db = $this->getDbConnection();
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
            
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':kw_raw', '%' . $keyword_raw . '%', SQLITE3_TEXT);
            $stmt->bindValue(':kw_clean', '%' . $keyword_clean . '%', SQLITE3_TEXT);

            $count = $stmt->execute()->fetchArray(SQLITE3_NUM)[0] ?? 0;
            
            $stmt->close();
            $db->close();
            return (int)$count;

        } catch (\Exception $e) {
            error_log("CrmDbDriver SearchCount Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * 電話番号から顧客情報を1件取得する（着信ポップアップ用）
     * ★ハイフンを除去して比較
     * @param string $phone 電話番号
     * @return array|null 顧客データ、見つからない場合はnull
     */
    public function getCustomerByPhone(string $phone): ?array
    {
        try {
            $db = $this->getDbConnection();
            
            // 入力された電話番号からもハイフンを除去
            $phone_clean = str_replace('-', '', $phone);

            // DBのphoneカラムからもハイフンを除去して比較
            $stmt = $db->prepare("SELECT * FROM customers WHERE REPLACE(phone, '-', '') = :phone");
            $stmt->bindValue(':phone', $phone_clean, SQLITE3_TEXT);
            
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            
            $stmt->close();
            $db->close();

            return $row ?: null;

        } catch (\Exception $e) {
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
            $db = new SQLite3(CRM_DB_PATH);
            
            // customersテーブル作成
            // phone を UNIQUE とすることで、重複登録を防ぎ、UPSERTを可能にします
            $db->exec(
                'CREATE TABLE IF NOT EXISTS customers (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    last_name TEXT,
                    first_name TEXT,
                    last_name_kana TEXT,
                    first_name_kana TEXT,
                    organization TEXT,
                    phone TEXT NOT NULL UNIQUE,
                    fax TEXT,
                    email TEXT,
                    zip_code TEXT,
                    address TEXT,
                    address_kana TEXT,
                    note TEXT,
                    created_at TEXT,
                    updated_at TEXT
                )'
            );

            // 検索高速化のためのインデックス作成
            $db->exec('CREATE INDEX IF NOT EXISTS idx_phone ON customers(phone)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_names ON customers(last_name, first_name)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_org ON customers(organization)');
            
            $db->close();
        } catch (\Exception $e) {
            error_log("CrmDbDriver Initialization Error: " . $e->getMessage());
        }
    }

    /**
     * 顧客をAND条件で検索する（入力されたフィールドのみ対象）
     * ★ページネーション対応
     * @param array $search_data
     * @param int $page
     * @param int $per_page
     * @return array
     */
    public function searchCustomersComplex(array $search_data, int $page, int $per_page): array
    {
        // オフセットを計算
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
               " ORDER BY updated_at DESC LIMIT :limit OFFSET :offset"; // ★LIMIT/OFFSET

        try {
            $db = $this->getDbConnection();
            $results = [];
            $stmt = $db->prepare($sql);

            foreach ($bind_values as $key => $val) {
                $stmt->bindValue($key, $val, SQLITE3_TEXT);
            }
            $stmt->bindValue(':limit', $per_page, SQLITE3_INTEGER); // ★追加
            $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER); // ★追加

            $res = $stmt->execute();
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $results[] = $row;
            }
            
            $stmt->close();
            $db->close();
            return $results;

        } catch (\Exception $e) {
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
            $db = $this->getDbConnection();
            $stmt = $db->prepare($sql);

            foreach ($bind_values as $key => $val) {
                $stmt->bindValue($key, $val, SQLITE3_TEXT);
            }
            
            $count = $stmt->execute()->fetchArray(SQLITE3_NUM)[0] ?? 0;

            $stmt->close();
            $db->close();
            return (int)$count;

        } catch (\Exception $e) {
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
        // 電話番号が指定されていなければ失敗
        if (empty($phone)) {
            return false;
        }

        try {
            $db = $this->getDbConnection();
            $stmt = $db->prepare("DELETE FROM customers WHERE phone = :phone");
            $stmt->bindValue(':phone', $phone, SQLITE3_TEXT);
            
            $result = $stmt->execute();
            
            // 変更された行数を取得
            $changes = $db->changes();

            $stmt->close();
            $db->close();

            // 1行以上が影響を受けた（削除された）場合のみtrueを返す
            return $changes > 0;

        } catch (\Exception $e) {
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
            $db = $this->getDbConnection();
            $count = $db->querySingle("SELECT count(*) FROM customers");
            $db->close();
            return (int)$count;

        } catch (\Exception $e) {
            error_log("CrmDbDriver GetTotalCount Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * 顧客データをページネーション用に取得する
     * @param int $page 取得するページ番号
     * @param int $per_page 1ページあたりの件数
     * @return array 該当ページの顧客データの配列
     */
    public function getCustomersPaginated(int $page, int $per_page): array
    {
        // ページ番号からオフセットを計算 (1ページ目なら (1-1)*20=0)
        $offset = ($page - 1) * $per_page;
        
        try {
            $db = $this->getDbConnection();
            $results = [];

            // LIMIT (件数), OFFSET (開始位置) を指定
            $sql = "SELECT * FROM customers 
                    ORDER BY updated_at DESC 
                    LIMIT :limit OFFSET :offset";
            
            $stmt = $db->prepare($sql);
            // ※ LIMIT/OFFSET は bindValue が意図通りに動作しないケースがあるため、
            //    数値であることが確実な場合は直接埋め込むか、intval() を使うのが安全です。
            //    ここではパラメータがint型であることを信頼してbindします。
            $stmt->bindValue(':limit', $per_page, SQLITE3_INTEGER);
            $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);

            $res = $stmt->execute();
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $results[] = $row;
            }
            
            $stmt->close();
            $db->close();
            return $results;

        } catch (\Exception $e) {
            error_log("CrmDbDriver GetPaginated Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * データベーススキーマをチェックし、不足しているカラムがあれば追加する
     * (ALTER TABLEを安全に実行するため)
     */
    private function updateSchema()
    {
        try {
            $db = $this->getDbConnection();
            
            // カラムチェックを行うヘルパー関数 (内部定義)
            $checkColumn = function($colName) use ($db) {
                $res = $db->query("PRAGMA table_info(customers)");
                while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                    if ($row['name'] === $colName) {
                        return true;
                    }
                }
                return false;
            };

            // 1. mobile_phone のチェックと追加
            if (!$checkColumn('mobile_phone')) {
                $db->close(); // ロック解除のため一旦クローズ
                $db_write = new SQLite3(CRM_DB_PATH);
                $db_write->busyTimeout(5000);
                $db_write->exec('ALTER TABLE customers ADD COLUMN mobile_phone TEXT');
                $db_write->close();
                // 再接続
                $db = $this->getDbConnection();
            }

            // 2. ★追加: last_updated_by (最終更新者) のチェックと追加
            if (!$checkColumn('last_updated_by')) {
                $db->close();
                $db_write = new SQLite3(CRM_DB_PATH);
                $db_write->busyTimeout(5000);
                $db_write->exec('ALTER TABLE customers ADD COLUMN last_updated_by TEXT');
                $db_write->close();
                // 再接続
                $db = $this->getDbConnection();
            }
            
            $db->close();

        } catch (\Exception $e) {
            error_log("CrmDbDriver updateSchema Error: " . $e->getMessage());
            if (isset($db)) $db->close();
        }
    }

    /**
     * 全顧客データを取得する (CSVエクスポート用)
     * @return array
     */
    public function getAllCustomersForExport(): array
    {
        try {
            $db = $this->getDbConnection();
            $results = [];
            
            // CSV出力に必要なカラムのみを選択し、DESC順で取得
            $sql = "SELECT phone, mobile_phone, fax, email, last_name, first_name, last_name_kana, first_name_kana, organization, zip_code, address, address_kana, note, created_at, updated_at FROM customers ORDER BY updated_at DESC";
            
            $res = $db->query($sql);
            while ($row = $res->fetchArray(SQLITE3_NUM)) { // 数値インデックスで取得 (fputcsvとの互換性のため)
                $results[] = $row;
            }
            
            $db->close();
            return $results;

        } catch (\Exception $e) {
            error_log("CrmDbDriver GetAllForExport Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * カナ検索（タブ用）の該当件数を取得する
     * ★修正: カタカナ・ひらがな両対応
     * @param array $chars 検索対象の頭文字配列 (例: ['ア','イ','ウ'...])
     * @return int
     */
    public function getCustomersCountByKana(array $chars): int
    {
        // 検索対象がない場合は0件
        if (empty($chars)) {
            return 0;
        }

        try {
            $db = $this->getDbConnection();

            $where_clauses = [];
            
            // 入力文字ごとに、カタカナ用とひらがな用のプレースホルダを作成
            foreach ($chars as $index => $char) {
                $where_clauses[] = "last_name_kana LIKE :k{$index}"; // カタカナ用
                $where_clauses[] = "last_name_kana LIKE :h{$index}"; // ひらがな用
            }
            
            // すべて OR で繋ぐ
            $sql = "SELECT count(*) FROM customers WHERE " . implode(' OR ', $where_clauses);
            $stmt = $db->prepare($sql);

            // 値をバインド
            foreach ($chars as $index => $char) {
                // カタカナ (元の文字)
                $stmt->bindValue(":k{$index}", $char . '%', SQLITE3_TEXT);
                
                // ひらがな (変換してバインド)
                // 'c' オプションは 全角カタカナ -> 全角ひらがな 変換
                $hira_char = mb_convert_kana($char, 'c', 'UTF-8');
                $stmt->bindValue(":h{$index}", $hira_char . '%', SQLITE3_TEXT);
            }

            $count = $stmt->execute()->fetchArray(SQLITE3_NUM)[0] ?? 0;

            $stmt->close();
            $db->close();

            return (int)$count;

        } catch (\Exception $e) {
            error_log("CrmDbDriver CountByKana Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * カナ検索（タブ用）の結果を取得する
     * ★修正: カタカナ・ひらがな両対応
     * @param array $chars 検索対象の頭文字配列
     * @param int $page
     * @param int $per_page
     * @return array
     */
    public function getCustomersByKana(array $chars, int $page, int $per_page): array
    {
        // 検索対象がない場合は空配列
        if (empty($chars)) {
            return [];
        }

        $offset = ($page - 1) * $per_page;

        try {
            $db = $this->getDbConnection();
            $results = [];

            $where_clauses = [];
            
            // カウントメソッド同様、両方のパターンのWHERE句を作成
            foreach ($chars as $index => $char) {
                $where_clauses[] = "last_name_kana LIKE :k{$index}";
                $where_clauses[] = "last_name_kana LIKE :h{$index}";
            }

            // フリガナ順でソート
            // 注意: SQLiteの仕様上、ひらがなとカタカナが混ざると、「ひらがなグループ」→「カタカナグループ」の順に並ぶ場合があります
            $sql = "SELECT * FROM customers 
                    WHERE " . implode(' OR ', $where_clauses) . "
                    ORDER BY last_name_kana ASC, last_name ASC 
                    LIMIT :limit OFFSET :offset";

            $stmt = $db->prepare($sql);

            // 値をバインド
            foreach ($chars as $index => $char) {
                $stmt->bindValue(":k{$index}", $char . '%', SQLITE3_TEXT);
                
                $hira_char = mb_convert_kana($char, 'c', 'UTF-8');
                $stmt->bindValue(":h{$index}", $hira_char . '%', SQLITE3_TEXT);
            }
            
            $stmt->bindValue(':limit', $per_page, SQLITE3_INTEGER);
            $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);

            $res = $stmt->execute();
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $results[] = $row;
            }

            $stmt->close();
            $db->close();

            return $results;

        } catch (\Exception $e) {
            error_log("CrmDbDriver GetByKana Error: " . $e->getMessage());
            return [];
        }
    }

}
?>
