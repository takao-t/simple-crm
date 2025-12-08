<?php
// 直接アクセスの禁止
if (!defined('CRM_SYSTEM_INCLUDED')) {
    die("Direct access is not permitted.");
}

// ABS機能が無効な場合はエラー表示またはリダイレクト
if (!defined('USE_ABS') || !USE_ABS) {
    echo '<div class="notice-message">この機能は無効化されています。</div>';
    return;
}

require_once 'php/AbsLogDbDriver.php';

// 1ページあたりの表示件数
define('HISTORY_ROWS_PER_PAGE', 20);

// ドライバ初期化
$absDb = new AbsLogDbDriver();

// ページネーション設定
$current_page = max(1, intval($_GET['p'] ?? 1));
$total_rows = $absDb->getHistoryCount();
$total_pages = ceil($total_rows / HISTORY_ROWS_PER_PAGE);

// 範囲外ページの補正
if ($current_page > $total_pages && $total_pages > 0) {
    $current_page = $total_pages;
}

// データ取得
$history_rows = $absDb->getHistoryPaginated($current_page, HISTORY_ROWS_PER_PAGE);

// 状態表示用のマッピング
$status_map = [
    'INCOMING' => '着信',
    'BLOCKED'  => '着信拒否',
];

?>

<h2>着信履歴</h2>

<div class="crm-container">

    <?php if ($total_rows === 0): ?>
        <p>履歴はありません。</p>
    <?php else: ?>
        
        <div class="pagination-summary">
            全 <?= $total_rows ?> 件中 <?= count($history_rows) ?> 件表示 (ページ <?= $current_page ?>/<?= $total_pages ?>)
        </div>

        <div class="table-container">
            <table class="absp-table">
                <thead>
                    <tr>
                        <th style="width: 120px;">日付</th>
                        <th style="width: 100px;">時刻</th>
                        <th>電話番号</th>
                        <th style="width: 100px;">状態</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history_rows as $row): ?>
                        <?php 
                            // 日時のパース (例: 2025-11-09 16:40:19)
                            // スペースで分割して日付と時刻に分ける
                            $ts_parts = explode(' ', $row['TIMESTAMP']);
                            $date_part = $ts_parts[0] ?? '';
                            $time_part = $ts_parts[1] ?? '';
                            
                            // 表示用ステータス
                            $status_label = $status_map[$row['KIND']] ?? $row['KIND'];
                            
                            // 状態に応じたスタイル（着信拒否を赤文字にする等）
                            $status_style = ($row['KIND'] === 'BLOCKED') ? 'color: #d9534f; font-weight: bold;' : '';
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($date_part) ?></td>
                            <td><?= htmlspecialchars($time_part) ?></td>
                            <td>
                                <a href="index.php?page=crm-page&phone=<?= urlencode($row['NUMBER']) ?>" title="この番号を検索">
                                    <?= htmlspecialchars($row['NUMBER']) ?>
                                </a>
                            </td>
                            <td style="<?= $status_style ?>">
                                <?= htmlspecialchars($status_label) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination-container">
            <div class="pagination-links">
                <?php if ($current_page > 1): ?>
                    <a href="index.php?page=call-history-page&p=<?= $current_page - 1 ?>">&laquo; 前へ</a>
                <?php else: ?>
                    <span class="disabled">&laquo; 前へ</span>
                <?php endif; ?>

                <?php
                    // ページリンクの範囲計算
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);
                    
                    if ($start_page > 1) echo '<a href="index.php?page=call-history-page&p=1">1</a><span>...</span>';
                    
                    for ($i = $start_page; $i <= $end_page; $i++):
                        if ($i == $current_page):
                            echo '<span class="current-page">' . $i . '</span>';
                        else:
                            echo '<a href="index.php?page=call-history-page&p=' . $i . '">' . $i . '</a>';
                        endif;
                    endfor;
                    
                    if ($end_page < $total_pages) echo '<span>...</span><a href="index.php?page=call-history-page&p=' . $total_pages . '">' . $total_pages . '</a>';
                ?>

                <?php if ($current_page < $total_pages): ?>
                    <a href="index.php?page=call-history-page&p=<?= $current_page + 1 ?>">次へ &raquo;</a>
                <?php else: ?>
                    <span class="disabled">次へ &raquo;</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    <?php endif; ?>
</div>
