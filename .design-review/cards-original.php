<?php
declare(strict_types=1);

include 'library/checklogin.php';
include_once '../common/includes/config_read.php';
include_once 'lang/main.php';
include_once '../common/includes/validation.php';
include '../common/includes/db_open.php';

$fragmentMode = isset($_GET['fragment']) && $_GET['fragment'] === '1';
$onlineOnly = isset($_GET['online_only']) && $_GET['online_only'] === '1';

/* This secondary read-only panel refreshes after the main card page renders.
   Releasing the session prevents it from serializing a later sidebar click. */
if ($onlineOnly && $_SERVER['REQUEST_METHOD'] === 'GET' && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

function ce($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cards_bytes(float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }
    return number_format($bytes, $index > 1 ? 2 : 1) . ' ' . $units[$index];
}

function cards_duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($days > 0) {
        return sprintf('%d يوم %d س', $days, $hours);
    }
    if ($hours > 0) {
        return sprintf('%d س %d د', $hours, $minutes);
    }
    return sprintf('%d د', $minutes);
}

function cards_package_label(string $packageName): string
{
    $labels = [
        '200m' => '200 ريال',
        '300m' => '300 ريال',
        '500m' => '500 ريال',
        '1000m' => '1000 ريال',
        '1500m' => '1500 ريال',
        '2000m' => '2000 ريال',
    ];
    return $labels[$packageName] ?? ($packageName !== '' ? $packageName : 'كروت');
}

function cards_online_url(string $search, int $page): string
{
    return 'nawa-cards.php?' . http_build_query([
        'online_only' => '1',
        'online_search' => $search,
        'online_page' => max(1, $page),
    ]);
}

$successMsg = '';
$failureMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'delete_batch') {
    if (!isset($_POST['csrf_token']) || !dalo_check_csrf_token((string) $_POST['csrf_token'])) {
        $failureMsg = 'انتهت صلاحية الجلسة. حدّث الصفحة وحاول مرة أخرى.';
    } else {
        $batchId = max(0, (int) ($_POST['batch_id'] ?? 0));
        $batchExists = $batchId > 0 ? (int) $dbSocket->getOne("SELECT COUNT(*) FROM batch_history WHERE id={$batchId}") : 0;
        $cardCount = $batchId > 0 ? (int) $dbSocket->getOne("SELECT COUNT(DISTINCT username) FROM userbillinfo WHERE batch_id={$batchId}") : 0;
        $usedCount = $batchId > 0 ? (int) $dbSocket->getOne(
            "SELECT COUNT(DISTINCT ra.username) FROM radacct ra JOIN userbillinfo ubi ON ubi.username=ra.username WHERE ubi.batch_id={$batchId}"
        ) : 0;

        if ($batchExists === 0) {
            $failureMsg = 'الدفعة المطلوبة غير موجودة.';
        } elseif ($usedCount > 0) {
            $failureMsg = 'لن تُحذف الدفعة لأن ' . number_format($usedCount) . ' كرت منها له سجل استخدام. هذا يحمي الكروت الموزعة من الحذف بالخطأ.';
        } else {
            $queries = [
                "DELETE up FROM user_packages up JOIN userinfo ui ON ui.id=up.user_id JOIN userbillinfo ubi ON ubi.username=ui.username WHERE ubi.batch_id={$batchId}",
                "DELETE rc FROM radcheck rc JOIN userbillinfo ubi ON ubi.username=rc.username WHERE ubi.batch_id={$batchId}",
                "DELETE rr FROM radreply rr JOIN userbillinfo ubi ON ubi.username=rr.username WHERE ubi.batch_id={$batchId}",
                "DELETE rug FROM radusergroup rug JOIN userbillinfo ubi ON ubi.username=rug.username WHERE ubi.batch_id={$batchId}",
                "DELETE nua FROM nawa_user_allowances nua JOIN userbillinfo ubi ON ubi.username=nua.username WHERE ubi.batch_id={$batchId}",
                "DELETE nsc FROM nawa_subscription_cycles nsc JOIN userbillinfo ubi ON ubi.username=nsc.username WHERE ubi.batch_id={$batchId}",
                "DELETE ur FROM user_recharges ur JOIN userbillinfo ubi ON ubi.username=ur.username WHERE ubi.batch_id={$batchId}",
                "DELETE ui FROM userinfo ui JOIN userbillinfo ubi ON ubi.username=ui.username WHERE ubi.batch_id={$batchId}",
                "DELETE FROM userbillinfo WHERE batch_id={$batchId}",
                "DELETE FROM batch_history WHERE id={$batchId} LIMIT 1",
            ];
            $dbSocket->query('START TRANSACTION');
            $deleteOk = true;
            foreach ($queries as $sql) {
                $result = $dbSocket->query($sql);
                if (DB::isError($result)) { $deleteOk = false; break; }
            }
            if ($deleteOk) {
                $dbSocket->query('COMMIT');
                $successMsg = 'تم حذف الدفعة #' . $batchId . ' و' . number_format($cardCount) . ' كرت غير مستخدم بنجاح.';
            } else {
                $dbSocket->query('ROLLBACK');
                $failureMsg = 'تعذر حذف الدفعة، ولم يتم حفظ أي تغيير.';
            }
        }
    }
}

function cards_render_online_section(
    array $stats,
    array $sessions,
    string $search,
    int $page,
    int $pageCount,
    int $filteredCount
): void {
    $currentUrl = cards_online_url($search, $page);
    ?>
    <section class="nawa-card cards-online" data-online-section data-online-url="<?= ce($currentUrl) ?>">
        <header class="nawa-card-header cards-online-head">
            <div><h2><span class="cards-live-dot"></span> المتصلون الآن</h2><p>كل الكروت القديمة والجديدة التي لديها جلسة RADIUS فعلية خلال آخر 15 دقيقة، وكل كرت يظهر مرة واحدة.</p></div>
            <button class="nawa-button small" type="button" data-online-refresh><i class="bi bi-arrow-clockwise"></i> تحديث الآن</button>
        </header>

        <div class="cards-online-stats">
            <article><i class="bi bi-wifi"></i><div><span>إجمالي الكروت المتصلة</span><strong><?= number_format((int) ($stats['active_cards'] ?? 0)) ?></strong></div></article>
            <article><i class="bi bi-ticket-perforated"></i><div><span>من الدفعات الجديدة</span><strong><?= number_format((int) ($stats['batch_cards'] ?? 0)) ?></strong></div></article>
            <article><i class="bi bi-clock-history"></i><div><span>من الكروت القديمة</span><strong><?= number_format((int) ($stats['legacy_cards'] ?? 0)) ?></strong></div></article>
            <article><i class="bi bi-router"></i><div><span>نقاط الشبكة</span><strong><?= number_format((int) ($stats['active_nas'] ?? 0)) ?></strong></div></article>
        </div>

        <form class="cards-online-filter" data-online-search>
            <div><i class="bi bi-search"></i><input type="search" name="online_search" value="<?= ce($search) ?>" placeholder="ابحث برقم الكرت، الدفعة، IP أو MAC..."></div>
            <button class="nawa-button primary" type="submit">بحث</button>
            <?php if ($search !== ''): ?><button class="nawa-button" type="button" data-online-clear>مسح</button><?php endif; ?>
        </form>

        <?php if ($sessions === []): ?>
            <div class="nawa-empty"><i class="bi bi-wifi-off"></i><?= $search === '' ? 'لا توجد كروت متصلة الآن.' : 'لا توجد جلسات مطابقة للبحث.' ?></div>
        <?php else: ?>
            <div class="nawa-table-wrap">
                <table class="nawa-table cards-online-table">
                    <thead><tr><th>الكرت</th><th>الباقة والدفعة</th><th>بداية الاتصال</th><th>مدة الجلسة</th><th>الاستهلاك</th><th>الجهاز</th><th>عنوان الاتصال</th><th>آخر تحديث</th></tr></thead>
                    <tbody>
                    <?php foreach ($sessions as $session): ?>
                        <tr>
                            <td><span class="cards-online-user"><i></i><b dir="ltr"><?= ce($session['username']) ?></b></span></td>
                            <td><span class="nawa-user"><b><?= ce(cards_package_label((string) ($session['package_name'] ?? ''))) ?></b><small><?= (int) $session['batch_id'] > 0 ? 'الدفعة #' . (int) $session['batch_id'] : 'كرت قديم' ?></small></span></td>
                            <td><span class="cards-session-time"><b dir="ltr"><?= ce(date('H:i', strtotime((string) $session['acctstarttime']))) ?></b><small dir="ltr"><?= ce(date('Y-m-d', strtotime((string) $session['acctstarttime']))) ?></small></span></td>
                            <td><span class="nawa-badge muted"><?= ce(cards_duration((int) $session['session_seconds'])) ?></span></td>
                            <td><strong dir="ltr"><?= ce(cards_bytes((float) $session['session_bytes'])) ?></strong></td>
                            <td><span class="cards-device"><b dir="ltr"><?= ce($session['callingstationid'] ?: '—') ?></b><small>MAC</small></span></td>
                            <td><span class="cards-address"><b dir="ltr"><?= ce($session['framedipaddress'] ?: '—') ?></b><small dir="ltr">NAS <?= ce($session['nasipaddress'] ?: '—') ?></small></span></td>
                            <td><span class="cards-last-update"><i></i><b dir="ltr"><?= ce(date('H:i:s', strtotime((string) ($session['last_update'] ?: $session['acctstarttime'])))) ?></b></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <footer class="cards-online-footer">
                <span>عرض <?= number_format(count($sessions)) ?> من <?= number_format($filteredCount) ?> كرت متصل<?= $search !== '' ? ' مطابق' : '' ?></span>
                <?php if ($pageCount > 1): ?><nav class="cards-pagination" aria-label="صفحات المتصلين">
                    <button type="button" data-online-page="<?= ce(cards_online_url($search, $page - 1)) ?>"<?= $page <= 1 ? ' disabled' : '' ?>><i class="bi bi-chevron-right"></i></button>
                    <?php for ($number = max(1, $page - 2); $number <= min($pageCount, $page + 2); $number++): ?>
                        <button type="button" class="<?= $number === $page ? 'active' : '' ?>" data-online-page="<?= ce(cards_online_url($search, $number)) ?>"><?= $number ?></button>
                    <?php endfor; ?>
                    <button type="button" data-online-page="<?= ce(cards_online_url($search, $page + 1)) ?>"<?= $page >= $pageCount ? ' disabled' : '' ?>><i class="bi bi-chevron-left"></i></button>
                </nav><?php endif; ?>
            </footer>
        <?php endif; ?>
    </section>
    <?php
}

if ($onlineOnly) {
    $onlineSearch = mb_substr(trim((string) ($_GET['online_search'] ?? '')), 0, 80);
    $onlinePage = max(1, (int) ($_GET['online_page'] ?? 1));
    $onlinePerPage = 25;
    $escapedOnlineSearch = $dbSocket->escapeSimple($onlineSearch);

    // Build one live row per card first. A card is either from any recorded batch
    // or belongs to one of the legacy card packages. This deliberately does not
    // depend on batch_id alone, which used to hide all pre-batch cards.
    $liveCardsSql = "CREATE TEMPORARY TABLE nawa_live_cards ENGINE=MEMORY AS
        SELECT ra.radacctid,ra.username,COALESCE(cb.batch_id,0) batch_id,ui.package_name,
               ra.acctstarttime,COALESCE(ra.acctupdatetime,ra.acctstarttime) last_update,
               GREATEST(COALESCE(ra.acctsessiontime,TIMESTAMPDIFF(SECOND,ra.acctstarttime,NOW())),0) session_seconds,
               latest.active_sessions,
               latest.active_bytes session_bytes,
               ra.framedipaddress,ra.callingstationid,ra.nasipaddress
        FROM (
            SELECT username,MAX(radacctid) latest_radacctid,COUNT(*) active_sessions,
                   COALESCE(SUM(COALESCE(total_octets64,COALESCE(acctinputoctets,0)+COALESCE(acctoutputoctets,0))),0) active_bytes
            FROM radacct
            WHERE acctstoptime IS NULL
              AND COALESCE(acctupdatetime,acctstarttime) >= NOW() - INTERVAL 15 MINUTE
            GROUP BY username
        ) latest
        JOIN radacct ra ON ra.radacctid=latest.latest_radacctid
        LEFT JOIN userinfo ui ON ui.username=ra.username
        LEFT JOIN (
            SELECT username,MAX(batch_id) batch_id
            FROM userbillinfo
            WHERE batch_id IS NOT NULL
            GROUP BY username
        ) cb ON cb.username=ra.username
        WHERE cb.batch_id IS NOT NULL OR ui.package_id IN (53,54,55,56,57,58)";
    $liveCardsResult = $dbSocket->query($liveCardsSql);
    if (DB::isError($liveCardsResult)) {
        $dbSocket->query("CREATE TEMPORARY TABLE nawa_live_cards (
            radacctid BIGINT,username VARCHAR(128),batch_id BIGINT,package_name VARCHAR(128),
            acctstarttime DATETIME,last_update DATETIME,session_seconds BIGINT,
            active_sessions BIGINT,session_bytes BIGINT,framedipaddress VARCHAR(64),
            callingstationid VARCHAR(128),nasipaddress VARCHAR(64)
        ) ENGINE=MEMORY");
    }

    $onlineWhere = '';
    if ($onlineSearch !== '') {
        $onlineWhere = sprintf(
            " WHERE (username LIKE '%%%s%%' OR CAST(batch_id AS CHAR) LIKE '%%%s%%' OR COALESCE(package_name,'') LIKE '%%%s%%' OR COALESCE(framedipaddress,'') LIKE '%%%s%%' OR COALESCE(callingstationid,'') LIKE '%%%s%%' OR COALESCE(nasipaddress,'') LIKE '%%%s%%')",
            $escapedOnlineSearch,
            $escapedOnlineSearch,
            $escapedOnlineSearch,
            $escapedOnlineSearch,
            $escapedOnlineSearch,
            $escapedOnlineSearch
        );
    }

    $onlineStats = $dbSocket->getRow(
        "SELECT COALESCE(SUM(active_sessions),0) active_sessions,COUNT(*) active_cards,
                SUM(batch_id>0) batch_cards,SUM(batch_id=0) legacy_cards,
                COUNT(DISTINCT nasipaddress) active_nas,COALESCE(SUM(session_bytes),0) active_bytes
         FROM nawa_live_cards",
        [],
        DB_FETCHMODE_ASSOC
    );
    if (DB::isError($onlineStats) || !is_array($onlineStats)) {
        $onlineStats = ['active_sessions' => 0, 'active_cards' => 0, 'batch_cards' => 0, 'legacy_cards' => 0, 'active_nas' => 0, 'active_bytes' => 0];
    }

    $onlineCount = (int) $dbSocket->getOne(
        "SELECT COUNT(*) FROM nawa_live_cards" . $onlineWhere
    );
    $onlinePageCount = max(1, (int) ceil($onlineCount / $onlinePerPage));
    $onlinePage = min($onlinePage, $onlinePageCount);
    $onlineOffset = ($onlinePage - 1) * $onlinePerPage;

    $onlineSessions = [];
    $onlineResult = $dbSocket->query(
        "SELECT radacctid,username,batch_id,package_name,acctstarttime,last_update,
                session_seconds,session_bytes,framedipaddress,callingstationid,nasipaddress
         FROM nawa_live_cards"
        . $onlineWhere
        . " ORDER BY last_update DESC,radacctid DESC"
        . sprintf(' LIMIT %d,%d', $onlineOffset, $onlinePerPage)
    );
    if (!DB::isError($onlineResult)) {
        while ($row = $onlineResult->fetchRow(DB_FETCHMODE_ASSOC)) {
            $onlineSessions[] = $row;
        }
    }

    include '../common/includes/db_close.php';
    cards_render_online_section($onlineStats, $onlineSessions, $onlineSearch, $onlinePage, $onlinePageCount, $onlineCount);
    exit;
}

$catalogCacheFile = '/var/cache/3dradius/cards-catalog.json';
$catalogCache = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET' && is_file($catalogCacheFile) && time() - (int) @filemtime($catalogCacheFile) < 1800) {
    $decodedCatalog = json_decode((string) @file_get_contents($catalogCacheFile), true);
    if (is_array($decodedCatalog)) $catalogCache = $decodedCatalog;
}
$packages = is_array($catalogCache['packages'] ?? null) ? $catalogCache['packages'] : [];
$batches = is_array($catalogCache['batches'] ?? null) ? $catalogCache['batches'] : [];

if ($catalogCache === null) {
/* الكروت القديمة حسب الباقة */
$packageResult = $dbSocket->query(
    "SELECT p.id,p.name,ROUND(CASE WHEN LOWER(COALESCE(p.quota_unit,''))='mega' THEN p.total_data/1024 WHEN LOWER(COALESCE(p.quota_unit,''))='giga' THEN p.total_data WHEN p.total_data>1048576 THEN p.total_data/1073741824 ELSE p.total_data END,2) package_gb,p.validity_days,COUNT(ui.id) cards_count "
    . "FROM packages p LEFT JOIN userinfo ui ON ui.package_id=p.id WHERE p.is_recharge_card=1 OR p.id IN (53,54,55,56,57,58) "
    . "GROUP BY p.id,p.name,p.total_data,p.quota_unit,p.validity_days ORDER BY p.id"
);
if (!DB::isError($packageResult)) {
    while ($row = $packageResult->fetchRow(DB_FETCHMODE_ASSOC)) {
        $packages[] = $row;
    }
}

/* الدفعات الجديدة */
$batchResult = $dbSocket->query(
    "SELECT bh.id,bh.batch_name,bh.batch_description,bh.batch_status,bh.creationdate,bh.creationby,"
    . "COALESCE(bc.cards_count,0) cards_count,COALESCE(NULLIF(ui.package_name,''),p.name,'') package_name,bc.card_prefix "
    . "FROM (SELECT id,batch_name,batch_description,batch_status,creationdate,creationby FROM batch_history ORDER BY id DESC LIMIT 100) bh "
    . "LEFT JOIN (SELECT batch_id,COUNT(*) cards_count,MIN(username) sample_username,MIN(LEFT(username,2)) card_prefix FROM userbillinfo WHERE batch_id IS NOT NULL GROUP BY batch_id) bc ON bc.batch_id=bh.id "
    . "LEFT JOIN userinfo ui ON ui.username=bc.sample_username "
    . "LEFT JOIN packages p ON p.id=ui.package_id "
    . "ORDER BY bh.id DESC"
);
if (!DB::isError($batchResult)) {
    while ($row = $batchResult->fetchRow(DB_FETCHMODE_ASSOC)) {
        $batches[] = $row;
    }
}
    @file_put_contents($catalogCacheFile, json_encode([
        'packages' => $packages,
        'batches' => $batches,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

$csrf = dalo_csrf_token();

include '../common/includes/db_close.php';
?>
<style>
.cards-page{display:grid;gap:1rem}.cards-online{overflow:hidden}.cards-online-head h2{display:flex;align-items:center;gap:.5rem}.cards-live-dot,.cards-online-user>i,.cards-last-update>i{display:inline-block;width:.48rem;height:.48rem;border-radius:50%;background:#14ad82;box-shadow:0 0 0 .25rem rgba(20,173,130,.12)}.cards-online-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.7rem;padding:1rem;border-bottom:1px solid #ececf2}.cards-online-stats article{display:flex;align-items:center;gap:.7rem;padding:.75rem;border:1px solid #e7e8ef;border-radius:.8rem;background:var(--nawa-surface,#fff)}.cards-online-stats article>i{display:grid;place-items:center;width:2.35rem;height:2.35rem;border-radius:.7rem;color:var(--nawa-violet);background:var(--nawa-violet-soft);font-size:1rem}.cards-online-stats article div{display:grid;gap:.12rem}.cards-online-stats span{color:#80879a;font-size:.67rem}.cards-online-stats strong{font-size:.88rem}.cards-online-filter{display:flex;gap:.55rem;padding:1rem;border-bottom:1px solid #ececf2}.cards-online-filter>div{display:flex;align-items:center;gap:.5rem;min-width:0;flex:1;padding:0 .75rem;border:1px solid #dfe0e8;border-radius:.68rem;background:var(--nawa-surface,#fff)}.cards-online-filter>div:focus-within{border-color:var(--nawa-violet);box-shadow:0 0 0 .2rem var(--nawa-violet-shadow-low)}.cards-online-filter input{width:100%;min-height:2.45rem;border:0;outline:0;background:transparent}.cards-online-table td{vertical-align:middle}.cards-online-user,.cards-last-update{display:flex;align-items:center;gap:.45rem}.cards-online-user b{font-size:.76rem}.cards-session-time,.cards-device,.cards-address{display:grid;gap:.15rem}.cards-session-time b,.cards-device b,.cards-address b,.cards-last-update b{font-size:.7rem}.cards-session-time small,.cards-device small,.cards-address small{color:#858b9d;font-size:.62rem}.cards-online-footer{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.85rem 1rem;border-top:1px solid #ececf2;color:#7c8295;font-size:.7rem}.cards-pagination{display:flex;gap:.3rem;direction:rtl}.cards-pagination button{display:grid;place-items:center;min-width:2rem;height:2rem;padding:0 .45rem;border:1px solid #dddfe7;border-radius:.55rem;color:var(--nawa-ink);background:var(--nawa-surface);cursor:pointer}.cards-pagination button.active{color:#fff;border-color:var(--nawa-violet);background:var(--nawa-violet)}.cards-pagination button:disabled{cursor:not-allowed;opacity:.42}@media(max-width:900px){.cards-online-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:620px){.cards-online-stats{grid-template-columns:1fr 1fr}.cards-online-filter{flex-wrap:wrap}.cards-online-filter>div{flex-basis:100%}.cards-online-footer{align-items:flex-start;flex-direction:column}}
</style>

<div class="nawa-page cards-page" dir="rtl" data-cards-page>
    <section class="nawa-page-header">
        <div><h1>الكروت</h1><p>إدارة الكروت، متابعة المتصلين الآن والدفعات الجديدة لشبكة 3D.</p></div>
        <div class="nawa-page-actions">
            <button type="button" class="nawa-button" data-online-toggle aria-expanded="false"><i class="bi bi-wifi"></i> المتصلون الآن</button>
            <button type="button" class="nawa-button primary" onclick="window.open('nawa-batch-create.php','_blank')"><i class="bi bi-plus-lg"></i> دفعة كروت جديدة</button>
        </div>
    </section>

    <?php if ($successMsg !== ''): ?><div class="nawa-alert success"><i class="bi bi-check2-circle"></i><span><?= ce($successMsg) ?></span></div><?php endif; ?>
    <?php if ($failureMsg !== ''): ?><div class="nawa-alert danger"><i class="bi bi-exclamation-triangle"></i><span><?= ce($failureMsg) ?></span></div><?php endif; ?>

    <div class="cards-online-shell" data-online-shell hidden></div>

    <section class="nawa-stat-grid">
        <?php foreach ($packages as $package): ?>
            <article class="nawa-stat"><span><?= ce($package['name']) ?></span><strong><?= number_format((int) $package['cards_count']) ?></strong><small><?= ce($package['package_gb']) ?> GB</small></article>
        <?php endforeach; ?>
    </section>

    <section class="nawa-card">
        <header class="nawa-card-header"><div><h2>دفعات الكروت الجديدة</h2><p>أي دفعة تنشئها من الآن ستظهر هنا تلقائيًا.</p></div></header>
        <?php if ($batches === []): ?>
            <div class="nawa-empty" style="padding:55px"><i class="bi bi-ticket-perforated"></i>لا توجد دفعات جديدة بعد.</div>
        <?php else: ?>
            <div class="nawa-table-wrap"><table class="nawa-table"><thead><tr><th>#</th><th>اسم الدفعة</th><th>عدد الكروت</th><th>أول رقمين</th><th>التاريخ</th><th>الإجراءات</th></tr></thead><tbody>
            <?php foreach ($batches as $batch): ?>
                <tr>
                    <td><strong>#<?= (int) $batch['id'] ?></strong></td>
                    <td><span class="nawa-user"><b><?= ce(cards_package_label((string) ($batch['package_name'] ?? ''))) ?></b><small>دفعة كروت</small></span></td>
                    <td><span class="nawa-badge"><?= number_format((int) $batch['cards_count']) ?> كرت</span></td>
                    <td><span class="nawa-badge"><?= ce($batch['card_prefix'] ?: '—') ?></span></td>
                    <td><?= ce($batch['creationdate']) ?></td>
                    <td><div class="nawa-actions"><button type="button" class="nawa-button small" onclick="window.open('nawa-batch-print.php?batch_id=<?= (int) $batch['id'] ?>','_blank')"><i class="bi bi-eye"></i> عرض</button><button type="button" class="nawa-button small" onclick="window.open('nawa-batch-print.php?batch_id=<?= (int) $batch['id'] ?>&autoprint=1','_blank')"><i class="bi bi-printer"></i> طباعة</button><form method="post" style="display:inline" onsubmit="return confirm('سيتم حذف الدفعة #<?= (int) $batch['id'] ?> وكل كروتها غير المستخدمة. هل أنت متأكد؟')"><input type="hidden" name="csrf_token" value="<?= ce($csrf) ?>"><input type="hidden" name="action" value="delete_batch"><input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>"><button type="submit" class="nawa-button danger small"><i class="bi bi-trash3"></i> حذف الدفعة</button></form></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
</div>

<script>
(function () {
    var page = document.querySelector('[data-cards-page]');
    if (!page) return;
    if (window.nawaCardsOnlineTimer) window.clearTimeout(window.nawaCardsOnlineTimer);
    var shell = page.querySelector('[data-online-shell]');
    var toggle = page.querySelector('[data-online-toggle]');

    async function loadOnline(url, showBusy, restoreSearchFocus) {
        var section = page.querySelector('[data-online-section]');
        var refresh = section ? section.querySelector('[data-online-refresh]') : null;
        if (showBusy && refresh) { refresh.disabled = true; refresh.classList.add('is-loading'); }
        if (showBusy && !section && toggle) toggle.disabled = true;
        try {
            var response = await fetch(url || (section ? section.dataset.onlineUrl : 'nawa-cards.php?online_only=1&online_page=1'), { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            var holder = document.createElement('div'); holder.innerHTML = await response.text();
            var replacement = holder.querySelector('[data-online-section]');
            if (!replacement) { window.location.reload(); return; }
            if (section) section.replaceWith(replacement);
            else { shell.replaceChildren(replacement); shell.hidden = false; }
            if (toggle) { toggle.disabled = false; toggle.setAttribute('aria-expanded', 'true'); toggle.innerHTML = '<i class="bi bi-eye-slash"></i> إخفاء المتصلين'; }
            bindOnline();
            if (restoreSearchFocus) {
                var newInput = page.querySelector('[data-online-search] input[type="search"]');
                if (newInput) {
                    newInput.focus({ preventScroll: true });
                    var end = newInput.value.length;
                    if (typeof newInput.setSelectionRange === 'function') newInput.setSelectionRange(end, end);
                }
            }
        } catch (error) {
            if (refresh) { refresh.disabled = false; refresh.classList.remove('is-loading'); }
            if (toggle) toggle.disabled = false;
        }
    }

    function schedule() {
        if (window.nawaCardsOnlineTimer) window.clearTimeout(window.nawaCardsOnlineTimer);
        window.nawaCardsOnlineTimer = window.setTimeout(function () {
            if (!page.isConnected || shell.hidden) return;
            if (document.visibilityState === 'visible') loadOnline(null, false);
            else schedule();
        }, 30000);
    }

    function bindOnline() {
        var section = page.querySelector('[data-online-section]');
        if (!section) return;
        section.querySelector('[data-online-refresh]')?.addEventListener('click', function () { loadOnline(section.dataset.onlineUrl, true); });
        var onlineSearchForm = section.querySelector('[data-online-search]');
        onlineSearchForm?.addEventListener('submit', function (event) {
            event.preventDefault(); var value = new FormData(event.currentTarget).get('online_search') || '';
            loadOnline('nawa-cards.php?online_only=1&online_page=1&online_search=' + encodeURIComponent(value), true, true);
        });
        var onlineSearchInput = onlineSearchForm ? onlineSearchForm.querySelector('input[type="search"]') : null;
        onlineSearchInput?.addEventListener('input', function (event) {
            if (event.isComposing) return;
            if (window.nawaCardsOnlineSearchTimer) window.clearTimeout(window.nawaCardsOnlineSearchTimer);
            window.nawaCardsOnlineSearchTimer = window.setTimeout(function () {
                if (onlineSearchForm && onlineSearchForm.isConnected) onlineSearchForm.requestSubmit();
            }, 380);
        });
        section.querySelector('[data-online-clear]')?.addEventListener('click', function () { loadOnline('nawa-cards.php?online_only=1&online_page=1', true); });
        section.querySelectorAll('[data-online-page]').forEach(function (button) {
            button.addEventListener('click', function () { if (!button.disabled) loadOnline(button.dataset.onlinePage, true); });
        });
        schedule();
    }

    toggle?.addEventListener('click', function () {
        if (shell.hidden) {
            loadOnline('nawa-cards.php?online_only=1&online_page=1', true);
            return;
        }
        shell.hidden = true;
        if (window.nawaCardsOnlineTimer) window.clearTimeout(window.nawaCardsOnlineTimer);
        toggle.setAttribute('aria-expanded', 'false');
        toggle.innerHTML = '<i class="bi bi-wifi"></i> المتصلون الآن';
    });
})();
</script>
