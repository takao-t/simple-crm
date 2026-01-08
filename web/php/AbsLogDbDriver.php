<?php

/**
 * ABS着信ログ（abslog.sqlite3）操作用ドライバクラス
 * 読み取り専用・参照特化
 */
class AbsLogDbDriver
{
    private $dbPath;

    public function __construct()
    {
        // config.php で定義されたパスを使用
        $this->dbPath = ABS_LOG_PATH;
    }

    /**
     * データベース接続を取得する
     * 参照専用のため、READONLYモードでのオープンを試みる
     */
    private function getDbConnection(): ?SQLite3
    {
        if (!file_exists($this->dbPath)) {
            // ファイルが存在しない場合はnullを返す（呼び出し元でハンドリング）
            return null;
        }

        try {
            // SQLITE3_OPEN_READONLY フラグを使用
            $db = new SQLite3($this->dbPath, SQLITE3_OPEN_READONLY);
            $db->busyTimeout(1000); // ログ参照なのでタイムアウトは短めでOK
            return $db;
        } catch (\Exception $e) {
            error_log("AbsLogDbDriver Connection Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 履歴の総件数を取得する
     * @return int
     */
    public function getHistoryCount(): int
    {
        $db = $this->getDbConnection();
        if (!$db) return 0;

        try {
            // カウント処理だけ行い即クローズ
            $count = $db->querySingle("SELECT count(*) FROM abslog");
            return (int)$count;
        } catch (\Exception $e) {
            error_log("AbsLogDbDriver Count Error: " . $e->getMessage());
            return 0;
        } finally {
            $db->close();
        }
    }

    /**
     * 履歴をページネーション用に取得する
     * @param int $page ページ番号
     * @param int $per_page 1ページあたりの件数
     * @return array
     */
    public function getHistoryPaginated(int $page, int $per_page): array
    {
        $db = $this->getDbConnection();
        if (!$db) return [];

        $offset = ($page - 1) * $per_page;
        $results = [];

        try {
            // TIMESTAMPの降順（新しい順）で取得
            $sql = "SELECT ID, KIND, NUMBER, DESTNUM, TIMESTAMP 
                    FROM abslog 
                    ORDER BY ID DESC 
                    LIMIT :limit OFFSET :offset";
            
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':limit', $per_page, SQLITE3_INTEGER);
            $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);

            $res = $stmt->execute();
            
            // ロック時間を最小にするため、ループ内で配列に詰め替え
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $results[] = $row;
            }
            
            // 詰め替え終わったら即クローズ
            $stmt->close();
            
        } catch (\Exception $e) {
            error_log("AbsLogDbDriver Fetch Error: " . $e->getMessage());
        } finally {
            $db->close();
        }

        return $results;
    }
}
