<?php
// 直接アクセスの禁止
if (!defined('CRM_SYSTEM_INCLUDED')) {
    die("Direct access is not permitted.");
}

require_once 'php/CrmDbDriver.php';

// --- ページネーション設定 ---
define('ROWS_PER_PAGE', 20); 

$crm = new CrmDbDriver();

// --------------------------------------------------
// 1. 五十音タブの定義
//    DB検索はカタカナで行うがドライバ内部で、ひらがなもヒットする
// --------------------------------------------------
$tabs = [
    'all' => ['label' => '全件', 'chars' => []],
    'a'   => ['label' => 'あ',   'chars' => ['ア','イ','ウ','エ','オ']],
    'ka'  => ['label' => 'か',   'chars' => ['カ','キ','ク','ケ','コ', 'ガ','ギ','グ','ゲ','ゴ']],
    'sa'  => ['label' => 'さ',   'chars' => ['サ','シ','ス','セ','ソ', 'ザ','ジ','ズ','ゼ','ゾ']],
    'ta'  => ['label' => 'た',   'chars' => ['タ','チ','ツ','テ','ト', 'ダ','ヂ','ヅ','デ','ド']],
    'na'  => ['label' => 'な',   'chars' => ['ナ','ニ','ヌ','ネ','ノ']],
    'ha'  => ['label' => 'は',   'chars' => ['ハ','ヒ','フ','ヘ','ホ', 'バ','ビ','ブ','ベ','ボ', 'パ','ピ','プ','ペ','ポ']],
    'ma'  => ['label' => 'ま',   'chars' => ['マ','ミ','ム','メ','モ']],
    'ya'  => ['label' => 'や',   'chars' => ['ヤ','ユ','ヨ']],
    'ra'  => ['label' => 'ら',   'chars' => ['ラ','リ','ル','レ','ロ']],
    'wa'  => ['label' => 'わ',   'chars' => ['ワ','ヲ','ン']],
    'etc' => ['label' => '他',   'chars' => []] 
];

// 現在選択されているタブを取得 (デフォルトは 'all')
$current_tab = $_GET['tab'] ?? 'all';
if (!array_key_exists($current_tab, $tabs)) {
    $current_tab = 'all';
}

// --------------------------------------------------
// 2. データの取得処理 (絞り込み対応)
// --------------------------------------------------

// URLの ?p=xx からページ番号を取得
$current_page = max(1, intval($_GET['p'] ?? 1));

// DBドライバに渡す検索条件
if ($current_tab === 'all') {
    // 全件モード
    $total_rows = $crm->getTotalCustomerCount();
    $customers = $crm->getCustomersPaginated($current_page, ROWS_PER_PAGE);
} else {
    // カナ絞り込みモード (ひらがな/カタカナ両対応)
    $target_chars = $tabs[$current_tab]['chars'];
    
    // カナ検索用の件数取得
    $total_rows = $crm->getCustomersCountByKana($target_chars);
    
    // カナ検索用のデータ取得
    $customers = $crm->getCustomersByKana($target_chars, $current_page, ROWS_PER_PAGE);
}

// 総ページ数計算
$total_pages = ceil($total_rows / ROWS_PER_PAGE);

// ページ番号の補正 (範囲外なら最終ページへ)
if ($current_page > $total_pages && $total_pages > 0) {
    $current_page = $total_pages;
}
?>

<h2>全件一覧表示</h2>

<div class="tab-navigation">
    <?php foreach ($tabs as $key => $tab): ?>
        <a href="index.php?page=list-page&tab=<?= $key ?>" 
           class="tab-item <?= ($current_tab === $key) ? 'active' : '' ?>">
            <?= htmlspecialchars($tab['label']) ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($current_tab === 'all'): ?>
    <div style="margin-bottom: 20px; margin-top: 15px;">
        <a href="php/export-csv.php" class="btn btn-neutral" style="padding: 5px 15px;">
            <span style="font-weight: bold;">📥 CSVデータを全件エクスポート</span>
        </a>
    </div>
<?php endif; ?>

<div class="table-container">
    <table class="customer-list-table">
        <thead>
            <tr>
                <th>氏名</th>
                <th>会社名</th>
                <th>電話番号</th>
                <th>住所</th>
                <th>最終更新日</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($customers)): ?>
                <tr>
                    <td colspan="5" style="text-align: center; padding: 30px;">
                        <?= ($current_tab !== 'all') ? '該当するデータがありません。' : 'データがありません。' ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($customers as $row): ?>
                    <tr onclick="location.href='index.php?page=crm-page&phone=<?= urlencode($row['phone']) ?>'">
                        <td><?= htmlspecialchars($row['last_name'] . ' ' . $row['first_name']) ?></td>
                        <td><?= htmlspecialchars($row['organization']) ?></td>
                        <td><?= htmlspecialchars($row['phone']) ?></td>
                        <td><?= htmlspecialchars($row['address']) ?></td>
                        <td><?= htmlspecialchars($row['updated_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="pagination-container">
    <div class="pagination-summary">
        <?php if ($total_rows > 0): ?>
            全 <?= $total_rows ?> 件中 <?= min($total_rows, ($current_page - 1) * ROWS_PER_PAGE + 1) ?> - <?= min($total_rows, $current_page * ROWS_PER_PAGE) ?> 件表示
        <?php else: ?>
            全 0 件
        <?php endif; ?>
    </div>
    
    <?php if ($total_pages > 1): ?>
    <div class="pagination-links">
        <?php 
            // ページリンク生成用のヘルパー関数 (現在のタブを維持するため)
            function get_page_link($p, $tab) {
                return "index.php?page=list-page&tab={$tab}&p={$p}";
            }
        ?>

        <?php if ($current_page > 1): ?>
            <a href="<?= get_page_link($current_page - 1, $current_tab) ?>">&laquo; 前へ</a>
        <?php else: ?>
            <span class="disabled">&laquo; 前へ</span>
        <?php endif; ?>

        <?php
            $start_page = max(1, $current_page - 2);
            $end_page = min($total_pages, $current_page + 2);
            
            if ($start_page > 1) echo '<a href="'.get_page_link(1, $current_tab).'">1</a><span>...</span>';
            
            for ($i = $start_page; $i <= $end_page; $i++):
                if ($i == $current_page):
                    echo '<span class="current-page">' . $i . '</span>';
                else:
                    echo '<a href="'.get_page_link($i, $current_tab).'">' . $i . '</a>';
                endif;
            endfor;
            
            if ($end_page < $total_pages) echo '<span>...</span><a href="'.get_page_link($total_pages, $current_tab).'">' . $total_pages . '</a>';
        ?>

        <?php if ($current_page < $total_pages): ?>
            <a href="<?= get_page_link($current_page + 1, $current_tab) ?>">次へ &raquo;</a>
        <?php else: ?>
            <span class="disabled">次へ &raquo;</span>
        <?php endif; ?>

    </div>
    <?php endif; ?>
</div>
