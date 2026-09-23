<?php
include("library/checklogin.php");
include_once("../common/includes/config_read.php");
include("../common/includes/db_open.php");

function wh($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$rows = [];
$tableFound = false;

/*
 * نحاول اكتشاف جدول hotspots من الإعدادات أو من قاعدة البيانات.
 */
$candidates = [];

if (!empty($configValues['CONFIG_DB_TBL_RADHG'])) {
    $candidates[] = $configValues['CONFIG_DB_TBL_RADHG'];
}

$candidates = array_merge($candidates, [
    'hotspots',
    'hotspot',
    'radhotspot'
]);

foreach (array_unique($candidates) as $tbl) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tbl);
    if ($safe === '') continue;

    $check = $dbSocket->query("SHOW TABLES LIKE '{$safe}'");

    if ($check && $check->numRows() > 0) {
        $tableFound = true;

        $res = $dbSocket->query("SELECT * FROM {$safe} ORDER BY 1 DESC LIMIT 500");

        if ($res) {
            while ($row = $res->fetchRow(DB_FETCHMODE_ASSOC)) {
                $rows[] = $row;
            }
        }

        break;
    }
}

include("../common/includes/db_close.php");

function pick($row, $keys, $default = '') {
    foreach ($keys as $k) {
        if (isset($row[$k]) && $row[$k] !== '') {
            return $row[$k];
        }
    }
    return $default;
}
?>

<style>
.wireless-page .wireless-grid{
    display:grid;
    grid-template-columns:1fr;
    gap:20px;
}

.wireless-panel{
    background:#fff;
    border:1px solid #e9ebf2;
    border-radius:20px;
    box-shadow:0 8px 30px rgba(26,36,70,.04);
}

.wireless-panel-head{
    padding:20px 22px;
    border-bottom:1px solid #edf0f5;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
}

.wireless-panel-head h2{
    margin:0;
    font-size:18px;
}

.wireless-panel-head p{
    margin:5px 0 0;
    font-size:12px;
    color:#9299aa;
}

.wireless-search{
    width:290px;
}

.wireless-search input{
    width:100%;
    border:1px solid #e5e8f0;
    border-radius:12px;
    padding:12px 14px;
    background:#fbfcff;
    outline:none;
}

.wireless-table-wrap{
    overflow:auto;
}

.wireless-table{
    width:100%;
    border-collapse:collapse;
}

.wireless-table th,
.wireless-table td{
    padding:14px 16px;
    border-bottom:1px solid #edf0f5;
    text-align:right;
    font-size:13px;
}

.wireless-table th{
    color:#8a92a4;
    background:#fbfcff;
    font-size:12px;
}

.wireless-ip{
    direction:ltr;
    display:inline-block;
    font-family:Consolas,monospace;
}

.wireless-badge{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:5px 9px;
    border-radius:999px;
    font-size:11px;
    font-weight:700;
    background:#eefcf8;
    color:#0f9f77;
}

.wireless-empty{
    padding:60px 25px;
    text-align:center;
}

.wireless-empty .icon{
    width:74px;
    height:74px;
    margin:0 auto 18px;
    border-radius:20px;
    background:#eef8ff;
    color:#3ba3e6;
    display:grid;
    place-items:center;
    font-size:30px;
}

.wireless-empty h3{
    margin:0 0 8px;
    font-size:20px;
}

.wireless-empty p{
    color:#8a92a4;
    margin:0;
}

@media(max-width:900px){
    .wireless-panel-head{
        align-items:stretch;
        flex-direction:column;
    }

    .wireless-search{
        width:100%;
    }
}
</style>

<div class="wireless-page">

<section class="page-heading">
    <div>
        <p class="eyebrow"><span></span> إدارة الأجهزة</p>
        <h1>الأجهزة اللاسلكية <span>📶</span></h1>
        <p>عرض وإدارة نقاط الهوتسبوت والأجهزة اللاسلكية المرتبطة بالنظام.</p>
    </div>

    <div class="heading-actions">
        <button
            class="secondary-button"
            type="button"
            onclick="loadSection('wireless-modern.php?fragment=1')">
            <i class="bi bi-arrow-clockwise"></i>
            تحديث
        </button>
    </div>
</section>

<section class="status-strip">
    <div class="status-main">
        <span class="status-icon">
            <i class="bi bi-wifi"></i>
        </span>

        <span>
            <strong><?=count($rows)?> جهاز لاسلكي</strong>
            <small>الأجهزة المسجلة في قاعدة البيانات الحالية</small>
        </span>
    </div>
</section>

<div class="wireless-grid">

    <article class="wireless-panel">

        <div class="wireless-panel-head">
            <div>
                <h2>قائمة الأجهزة اللاسلكية</h2>
                <p>نقاط الهوتسبوت والأجهزة المسجلة</p>
            </div>

            <div class="wireless-search">
                <input
                    type="search"
                    placeholder="بحث بالاسم أو IP..."
                    oninput="filterWireless(this.value)">
            </div>
        </div>

        <?php if (count($rows)): ?>

        <div class="wireless-table-wrap">
            <table class="wireless-table" id="wirelessTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الاسم</th>
                        <th>IP</th>
                        <th>النوع</th>
                        <th>الوصف</th>
                        <th>الحالة</th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($rows as $i => $row):

                    $id = pick($row, ['id','hotspotid','hsid'], $i + 1);
                    $name = pick($row, ['name','hotspotname','shortname','nasname'], 'جهاز لاسلكي');
                    $ip = pick($row, ['ip','ipaddress','nasname','host'], '-');
                    $type = pick($row, ['type','hotspottype'], 'Hotspot');
                    $desc = pick($row, ['description','notes','comment'], '');
                ?>

                    <tr data-search="<?=wh(strtolower(
                        $name . ' ' . $ip . ' ' . $type . ' ' . $desc
                    ))?>">

                        <td><?=wh($id)?></td>

                        <td>
                            <strong><?=wh($name)?></strong>
                        </td>

                        <td>
                            <span class="wireless-ip">
                                <?=wh($ip)?>
                            </span>
                        </td>

                        <td><?=wh($type)?></td>

                        <td><?=wh($desc)?></td>

                        <td>
                            <span class="wireless-badge">
                                <i class="bi bi-check-circle"></i>
                                مسجل
                            </span>
                        </td>

                    </tr>

                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php else: ?>

        <div class="wireless-empty">
            <div class="icon">
                <i class="bi bi-wifi"></i>
            </div>

            <h3>لا توجد أجهزة لاسلكية مسجلة</h3>

            <p>
                الصفحة أصبحت جاهزة داخل لوحة 3D Radius،
                لكن قاعدة البيانات الحالية لا تحتوي سجلات Hotspot.
            </p>
        </div>

        <?php endif; ?>

    </article>

</div>
</div>

<script>
function filterWireless(value) {
    const q = value.trim().toLowerCase();

    document.querySelectorAll('#wirelessTable tbody tr').forEach(function(row) {
        row.style.display =
            row.dataset.search.includes(q) ? '' : 'none';
    });
}
</script>
