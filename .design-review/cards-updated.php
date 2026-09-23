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

<style id="cards-reference-design">
.nawa-page.cards-reference{--u-ink:#0a2251;--u-muted:#7185ad;--u-line:#dbe7f8;--u-card:#ffffffec;--u-soft:#f4f8ff;--u-field:#fff;--u-green:#009b72;--u-green-bg:#dffbf1;--u-orange:#cf7905;--u-orange-bg:#fff3e1;--u-shadow:0 8px 26px #275eaa06;direction:rtl;padding:24px;max-width:1700px;margin:auto;background:transparent!important;color:var(--u-ink)!important}
.cards-reference .nawa-page-header{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:20px}
.cards-reference .nawa-page-header h1{font-size:27px;font-weight:800;color:var(--u-ink)!important;margin-bottom:7px}
.cards-reference .nawa-page-header p{color:var(--u-muted)!important;font-size:12px}
.cards-reference .nawa-page-actions{display:flex;gap:9px}
.cards-reference .nawa-button{border:1px solid var(--u-line)!important;border-radius:9px;background:var(--u-field)!important;color:var(--u-ink)!important;box-shadow:0 2px 5px #153a6b05;font-weight:700;transition:background .15s,border-color .15s}
.cards-reference .nawa-button:hover{border-color:#77a8de!important;background:var(--u-soft)!important}
.cards-reference .nawa-button.primary{background:linear-gradient(130deg,#ff3153,#ff003c)!important;color:#fff!important;border-color:#ff365c!important;box-shadow:0 5px 14px #ff174329}
.cards-reference .nawa-page-actions .nawa-button{padding:11px 16px;min-height:40px}
.cards-reference .nawa-stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}
.cards-reference .nawa-stat{position:relative;isolation:isolate;overflow:hidden;min-height:96px;display:flex;flex-direction:column;justify-content:center;padding:17px 20px 17px 86px;border:1px solid var(--u-line)!important;border-radius:16px;background:var(--u-card)!important;box-shadow:var(--u-shadow);--stat-color:#00bd83;--stat-tint:#d8fff0}
.cards-reference .nawa-stat:nth-child(2){--stat-color:#0785ff;--stat-tint:#dceeff}.cards-reference .nawa-stat:nth-child(3){--stat-color:#ff5d29;--stat-tint:#ffebe3}.cards-reference .nawa-stat:nth-child(4){--stat-color:#8150ff;--stat-tint:#eee6ff}
.cards-reference .nawa-stat:after{content:"";position:absolute;inset:52% 0 0;z-index:-1;background:linear-gradient(0deg,var(--stat-tint),transparent);opacity:.5;clip-path:polygon(0 65%,15% 45%,25% 58%,34% 20%,44% 17%,59% 68%,70% 63%,82% 84%,100% 65%,100% 100%,0 100%)}
.cards-reference .nawa-stat>span{color:var(--u-muted);font-size:12px}.cards-reference .nawa-stat>strong{font-size:28px;line-height:1.3;color:var(--u-ink)!important;margin-top:6px}
.cards-reference .users-stat-icon{position:absolute;left:18px;top:50%;transform:translateY(-50%);display:grid;place-items:center;width:53px;height:53px;border-radius:15px;background:linear-gradient(135deg,color-mix(in srgb,var(--stat-color) 80%,white),var(--stat-color));color:#fff;font-size:27px;box-shadow:0 5px 14px color-mix(in srgb,var(--stat-color) 30%,transparent),inset 0 1px 0 #fff6}
.cards-reference .nawa-card{background:var(--u-card)!important;border:1px solid var(--u-line)!important;border-radius:16px;box-shadow:var(--u-shadow);margin-bottom:15px;overflow:hidden;color:var(--u-ink)!important}
.cards-reference .subscriber-traffic-head{padding:14px 16px 10px;border:0;gap:14px}
.cards-reference .subscriber-traffic-head h2,.cards-reference .nawa-card-header h2{font-size:17px;color:var(--u-ink)!important;font-weight:800}
.cards-reference .subscriber-traffic-head p,.cards-reference .nawa-card-header p{color:var(--u-muted)!important;font-size:10px;line-height:1.7}
.cards-reference .subscriber-periods{padding:3px;border:1px solid var(--u-line);background:var(--u-soft);border-radius:10px;gap:3px}
.cards-reference .subscriber-periods button{min-width:61px;padding:8px 12px;color:var(--u-ink);font-size:10px;border-radius:7px}
.cards-reference .subscriber-periods button.active{background:linear-gradient(130deg,#ff3458,#ff003a);box-shadow:0 4px 12px #ff21412b;color:white}
.cards-reference .subscriber-traffic-grid{padding:0 14px 14px;gap:12px}
.cards-reference .subscriber-traffic-grid article{position:relative;padding:17px 16px 17px 78px;background:var(--u-field);border:1px solid var(--u-line);border-radius:11px;min-height:90px}
.cards-reference .subscriber-traffic-grid span{font-size:11px;color:var(--u-muted)}
.cards-reference .subscriber-traffic-grid strong{font-size:20px;color:var(--u-ink);font-variant-numeric:tabular-nums}
.cards-reference .subscriber-traffic-grid small{font-size:9px;color:var(--u-muted)}
.cards-reference .users-traffic-icon{position:absolute;left:15px;top:50%;transform:translateY(-50%);display:grid;place-items:center;width:49px;height:49px;font-size:26px;border-radius:14px;background:#d0fae9;color:#00aa76}
.cards-reference .subscriber-traffic-grid article:nth-child(2) .users-traffic-icon{color:#8c4ef4;background:#eee1ff}.cards-reference .subscriber-traffic-grid article:nth-child(3) .users-traffic-icon{color:#de8900;background:#fff1d7}
.cards-reference .nawa-card-header{padding:14px 16px 8px;border:0}
.cards-reference .nawa-filter.nawa-users-search{display:flex;gap:10px;align-items:center;padding:0 14px 12px;margin:0;background:transparent;border:0}
.cards-reference .nawa-page-size-control{background:var(--u-soft)!important;border:1px solid var(--u-line)!important;color:var(--u-muted)!important;box-shadow:none}
.cards-reference .nawa-page-size-control input{background:var(--u-field)!important;border-color:var(--u-line)!important;color:var(--u-ink)!important;min-height:36px}
.cards-reference .nawa-search-field{flex:1;min-width:140px;position:relative}
.cards-reference .nawa-search-field input{width:100%;height:40px;border:1px solid var(--u-line)!important;border-radius:9px;background:var(--u-field)!important;color:var(--u-ink)!important;padding-inline:36px 14px;box-shadow:inset 0 0 0 4px var(--u-soft)}
.cards-reference .nawa-search-field input::placeholder{color:var(--u-muted)!important}
.cards-reference .nawa-search-field>i{position:absolute;right:12px;top:12px;color:var(--u-muted)}
.cards-reference .nawa-search-button{min-height:40px;padding-inline:22px}
.cards-reference .nawa-table-wrap{margin:0 12px 12px;border:1px solid var(--u-line);border-radius:10px}
.cards-reference .nawa-table{width:100%;border-collapse:collapse;white-space:nowrap;font-size:11px;background:transparent}
.cards-reference .nawa-table th{padding:12px 9px;background:var(--u-soft)!important;color:var(--u-muted)!important;border-bottom:1px solid var(--u-line);font-size:11px}
.cards-reference .nawa-table td{padding:10px 8px;border-bottom:1px solid var(--u-line)!important;color:var(--u-ink)!important;background:transparent;vertical-align:middle}
.cards-reference .nawa-table td:first-child{display:table-cell}
.cards-reference .nawa-table tbody tr:nth-child(even){background:color-mix(in srgb,var(--u-soft) 55%,transparent)}
.cards-reference .nawa-table tbody tr:hover{background:var(--u-soft)}
.cards-reference .nawa-user b{color:var(--u-ink)!important;font-size:11px}.cards-reference .nawa-user small{color:var(--u-muted)!important;font-size:9px}
.cards-reference .nawa-table td:nth-child(2) b{color:#148fda!important}
.cards-reference .nawa-badge{display:inline-flex;align-items:center;justify-content:center;gap:4px;min-height:27px;padding:5px 10px;background:var(--u-green-bg)!important;color:var(--u-green)!important;border-radius:20px;font-size:10px;font-weight:700;line-height:1.4;border:0}
.cards-reference .nawa-badge.warning{background:var(--u-orange-bg)!important;color:var(--u-orange)!important}
.cards-reference .nawa-badge.muted{background:var(--u-soft)!important;color:var(--u-muted)!important}
.cards-reference .nawa-actions{display:flex;gap:5px;flex-wrap:nowrap;align-items:center}
.cards-reference .nawa-actions .nawa-button.small{padding:6px 8px;min-height:30px;font-size:9px;border-radius:8px;white-space:nowrap}
.cards-reference .nawa-actions .nawa-button:not(.primary){box-shadow:inset 0 1px 0 #ffffff10}
.cards-reference .nawa-actions .nawa-button[data-delete-user] i{color:#ff375b}
.cards-reference input:focus-visible,.cards-reference button:focus-visible,.cards-reference a:focus-visible{outline:2px solid #389cf9;outline-offset:3px}
body.dark .cards-reference,html[data-theme="dark"] .cards-reference,body[data-theme="dark"] .cards-reference{--u-ink:#eef5ff;--u-muted:#a6bfdf;--u-line:#254760;--u-card:#0b1c2cee;--u-soft:#132a40;--u-field:#0b2032;--u-green:#16e5b2;--u-green-bg:#053b33;--u-orange:#ffb52b;--u-orange-bg:#352d22;--u-shadow:0 10px 26px #0002}
body.dark .cards-reference .nawa-stat,html[data-theme="dark"] .cards-reference .nawa-stat,body[data-theme="dark"] .cards-reference .nawa-stat{background:linear-gradient(110deg,color-mix(in srgb,var(--stat-color) 18%,var(--u-card)),var(--u-card))!important;border-color:color-mix(in srgb,var(--stat-color) 55%,var(--u-line))!important}
body.dark .cards-reference .nawa-stat:after,html[data-theme="dark"] .cards-reference .nawa-stat:after{opacity:.08}
body.dark .cards-reference .users-traffic-icon{background:#0b4a3b;color:#00e29f}body.dark .cards-reference .subscriber-traffic-grid article:nth-child(2) .users-traffic-icon{background:#392760;color:#c58bff}body.dark .cards-reference .subscriber-traffic-grid article:nth-child(3) .users-traffic-icon{background:#493920;color:#ffbb30}
@media(max-width:1200px){.nawa-page.cards-reference{padding:20px 14px}.cards-reference .nawa-stat{padding-inline:14px 73px}.cards-reference .users-stat-icon{left:12px;width:46px;height:46px}.cards-reference .nawa-stat>span{font-size:10px}.cards-reference .nawa-table{min-width:1050px}}
@media(max-width:850px){.cards-reference .nawa-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.cards-reference .nawa-page-header{align-items:flex-start;flex-direction:column}.cards-reference .nawa-page-header h1{font-size:23px}.cards-reference .subscriber-traffic-head{flex-wrap:wrap}.cards-reference .subscriber-traffic-grid{grid-template-columns:1fr}.cards-reference .nawa-filter.nawa-users-search{flex-wrap:wrap}.cards-reference .nawa-search-field{order:-1;flex-basis:100%}.cards-reference .nawa-search-button{margin-inline-start:auto}}
@media(max-width:420px){.nawa-page.cards-reference{padding:16px 9px}.cards-reference .nawa-stat{padding:13px 11px 13px 56px;min-height:88px}.cards-reference .users-stat-icon{width:36px;height:39px;left:10px;font-size:21px}.cards-reference .nawa-stat>strong{font-size:24px}.cards-reference .nawa-page-actions .nawa-button{padding:10px;font-size:11px}.cards-reference .subscriber-periods button{min-width:48px;padding:8px}}

.cards-reference .nawa-page-header{position:relative;isolation:isolate;min-height:145px;padding:26px 24px;margin-bottom:16px;border:1px solid var(--u-line);border-radius:16px;background:radial-gradient(ellipse at 0 10%,#ffb8c566,transparent 55%),linear-gradient(115deg,#fff2f6,#edf5ff 60%,#fff);overflow:hidden;flex-wrap:wrap}
.cards-reference .nawa-page-header>div:first-child{position:relative;z-index:1;padding-inline-start:56px;max-width:65%}
.cards-reference .nawa-page-header>div:first-child:before{content:"\F484";font-family:bootstrap-icons;position:absolute;right:0;top:2px;display:grid;place-items:center;width:43px;height:43px;border-radius:11px;border:1px solid #ff879c;background:#fff4f6;color:#ff234d;font-size:25px}
.cards-reference .nawa-page-header h1{font-size:30px}
.cards-reference .nawa-page-actions{position:relative;z-index:2;flex-basis:100%;margin-top:8px}
.cards-reference .cards-hero-art{position:absolute;left:55px;top:15px;width:190px;height:115px;pointer-events:none;z-index:0;transform:rotate(-13deg)}
.cards-reference .cards-hero-art span{position:absolute;inset:15px 0 0;border-radius:12px;border:1px solid #ff6479;background:linear-gradient(135deg,#ff3851,#c80829 58%,#5d071e);box-shadow:-12px 12px 28px #48112035;color:#fff;display:flex;align-items:center;justify-content:center;font-size:19px;font-weight:800;letter-spacing:1px;direction:ltr}
.cards-reference .cards-hero-art span:nth-child(1){transform:translate(-38px,-7px) rotate(-8deg);background:linear-gradient(135deg,#101c2c,#901126)}
.cards-reference .cards-hero-art span:nth-child(2){transform:translate(-18px,-3px) rotate(-3deg);background:linear-gradient(135deg,#f6334e,#240d22)}
.cards-reference .nawa-stat-grid{grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px}
.cards-reference .nawa-stat{min-height:110px;padding:16px 16px 16px 66px;--stat-color:#ff244a;--stat-tint:#ffe6ec}
.cards-reference .nawa-stat:nth-child(6n+2){--stat-color:#0087ff;--stat-tint:#e0efff}.cards-reference .nawa-stat:nth-child(6n+3){--stat-color:#00bd83;--stat-tint:#dcfff3}.cards-reference .nawa-stat:nth-child(6n+4){--stat-color:#ff9800;--stat-tint:#fff0d5}.cards-reference .nawa-stat:nth-child(6n+5){--stat-color:#8b4cff;--stat-tint:#eee6ff}.cards-reference .nawa-stat:nth-child(6n+6){--stat-color:#6176a1;--stat-tint:#e9edf5}
.cards-reference .cards-stat-icon{position:absolute;left:14px;top:22px;display:grid;place-items:center;width:42px;height:43px;border-radius:12px;font-size:23px;color:#fff;background:var(--stat-color);box-shadow:0 5px 15px color-mix(in srgb,var(--stat-color) 28%,transparent),inset 0 1px 1px #fff8}
.cards-reference .nawa-stat>span{font-size:11px;order:2;margin-top:4px}.cards-reference .nawa-stat>strong{font-size:25px;order:1}.cards-reference .nawa-stat>small{color:var(--u-muted);font-size:11px;order:3;margin-top:4px}
.cards-reference .nawa-card-header{padding:19px 18px 15px}.cards-reference .nawa-card-header h2{font-size:20px}
.cards-reference .nawa-table th{padding:13px 15px}.cards-reference .nawa-table td{padding:12px 15px}.cards-reference .nawa-table td:nth-child(2) b{color:var(--u-ink)!important}
.cards-reference .nawa-actions{gap:10px}.cards-reference .nawa-actions .nawa-button.small{padding:6px 13px;border-radius:10px;font-size:10px}
.cards-reference .nawa-actions .nawa-button.danger{color:#ff3d5d!important;border-color:#ff4a6545!important;background:color-mix(in srgb,#ff3256 6%,var(--u-field))!important}
.cards-reference .cards-pagination button,.cards-reference .nawa-generic-table-pager button{border:1px solid var(--u-line);border-radius:8px;background:var(--u-field);color:var(--u-ink);min-width:32px;min-height:32px}
.cards-reference .cards-pagination button.active,.cards-reference .nawa-generic-table-pager button.active{background:#ff1945;color:#fff;border-color:#ff375e;box-shadow:0 4px 12px #ff23452a}
.cards-reference .cards-online-stats article{background:var(--u-field);border-color:var(--u-line);color:var(--u-ink)}
.cards-reference .cards-online-stats strong{color:var(--u-ink)}.cards-reference .cards-online-stats span{color:var(--u-muted)}
.cards-reference .cards-online-filter>div,.cards-reference .cards-online-filter input{background:var(--u-field);color:var(--u-ink);border-color:var(--u-line)}
.cards-reference .cards-online-footer{border-color:var(--u-line);color:var(--u-muted)}
body.dark .cards-reference .nawa-page-header,html[data-theme="dark"] .cards-reference .nawa-page-header{background:radial-gradient(ellipse at 0 0,#69172b66,transparent 48%),linear-gradient(110deg,#091522,#122b3f 55%,#291222);border-color:#4d3047}
body.dark .cards-reference .nawa-page-header>div:first-child:before{background:#67112970;border-color:#8c2743;color:#ff5068}
body.dark .cards-reference .nawa-stat{border-color:color-mix(in srgb,var(--stat-color) 30%,var(--u-line))!important}
@media(max-width:1000px){.cards-reference .nawa-stat-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.cards-reference .nawa-table{min-width:800px}.cards-reference .cards-hero-art{left:35px;width:150px;height:100px}}
@media(max-width:600px){.cards-reference .nawa-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.cards-reference .nawa-page-header{padding:20px 15px}.cards-reference .nawa-page-header>div:first-child{max-width:100%;padding-inline-start:50px}.cards-reference .cards-hero-art{opacity:.10;left:10px;top:12px}.cards-reference .nawa-page-actions{flex-wrap:wrap}.cards-reference .nawa-stat{padding-inline:12px 62px}.cards-reference .nawa-stat>strong{font-size:23px}.cards-reference .nawa-actions{gap:6px}}

.nawa-page.cards-reference{gap:0}

</style>
<div class="nawa-page cards-page cards-reference" dir="rtl" data-cards-page>
    <section class="nawa-page-header"><div class="cards-hero-art" aria-hidden="true"><span></span><span></span><span>3D RADIUS</span></div>
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
            <article class="nawa-stat"><i class="bi bi-box-seam cards-stat-icon" aria-hidden="true"></i><span><?= ce($package['name']) ?></span><strong><?= number_format((int) $package['cards_count']) ?></strong><small><?= ce($package['package_gb']) ?> GB</small></article>
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
