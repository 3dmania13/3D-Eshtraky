<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Aden');

include 'library/checklogin.php';
$operator = $_SESSION['operator_user'];
include_once '../common/includes/config_read.php';
$operator_perm_file = 'mng_list_all';
include 'library/check_operator_perm.php';
include_once 'lang/main.php';
include_once '../common/includes/validation.php';
include '../common/includes/layout.php';
include_once 'include/nawa/functions.php';
include '../common/includes/db_open.php';

$fragmentMode = isset($_GET['fragment']) && $_GET['fragment'] === '1';
$statsOnly = isset($_GET['stats_only']) && $_GET['stats_only'] === '1';

/* Aggregate statistics are loaded after the subscriber list is usable. They
   are read-only and must not hold the operator session lock while scanning. */
if ($statsOnly && $_SERVER['REQUEST_METHOD'] === 'GET' && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$successMsg = '';
$failureMsg = '';
if (isset($_GET['created']) && $_GET['created'] === '1') $successMsg = 'تم إنشاء المستخدم وربط الباقة بنجاح.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !dalo_check_csrf_token((string) $_POST['csrf_token'])) {
        $failureMsg = 'انتهت صلاحية الجلسة. حدّث الصفحة وحاول مرة أخرى.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $username = trim((string) ($_POST['username'] ?? ''));
        if ($action === 'settle') {
            $mode = (string) ($_POST['settlement_mode'] ?? 'package');
            $clearCurrent = isset($_POST['clear_current']) && $_POST['clear_current'] === '1';
            if ($mode === 'package') {
                [$ok, $message] = nawa_settle_package(
                    $dbSocket,
                    $username,
                    (int) ($_POST['package_id'] ?? 0),
                    $clearCurrent
                );
            } elseif ($mode === 'offer') {
                [$ok, $message] = nawa_settle_package_offer(
                    $dbSocket,
                    $username,
                    (int) ($_POST['offer_package_id'] ?? 0),
                    (int) ($_POST['offer_id'] ?? 0),
                    $clearCurrent
                );
            } elseif ($mode === 'custom') {
                [$ok, $message] = nawa_settle_quota(
                    $dbSocket,
                    $username,
                    (float) ($_POST['custom_gb'] ?? 0),
                    $clearCurrent,
                    'custom'
                );
            } elseif ($mode === 'custom_package') {
                [$ok, $message] = nawa_settle_package(
                    $dbSocket,
                    $username,
                    (int) ($_POST['custom_package_id'] ?? 0),
                    $clearCurrent,
                    trim((string) ($_POST['custom_rate_limit'] ?? ''))
                );
            } else {
                $ok = false;
                $message = 'نوع التسديد غير صحيح.';
            }
        } elseif ($action === 'rename') {
            [$ok, $message] = nawa_rename_user(
                $dbSocket,
                $username,
                trim((string) ($_POST['new_username'] ?? ''))
            );
        } elseif ($action === 'adjust') {
            [$ok, $message] = nawa_adjust_user(
                $dbSocket,
                $username,
                max(0, (int) ($_POST['add_days'] ?? 0)),
                max(0, (float) ($_POST['add_gb'] ?? 0)),
                max(0, (float) ($_POST['subtract_gb'] ?? 0))
            );
        } elseif ($action === 'set_speed') {
            [$ok, $message] = nawa_set_user_rate_limit(
                $dbSocket,
                $username,
                trim((string) ($_POST['speed'] ?? ''))
            );
        } elseif ($action === 'toggle_disabled') {
            [$ok, $message] = nawa_set_user_disabled(
                $dbSocket,
                $username,
                (string) ($_POST['disabled'] ?? '1') === '1'
            );
        } elseif ($action === 'delete') {
            [$ok, $message] = nawa_delete_user($dbSocket, $username);
        } else {
            $ok = false;
            $message = 'العملية المطلوبة غير صحيحة.';
        }
        if ($ok) {
            $successMsg = $message;
            @unlink(rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '3dradius-users-stats-v2.json');
        } else {
            $failureMsg = $message;
        }
    }
}

$search = trim((string) ($_GET['search'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = (int) ($_GET['per_page'] ?? 50);
if ($pageSize < 1 || $pageSize > 500) $pageSize = 50;
$sort = (string) ($_GET['sort'] ?? 'username');
$direction = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
$sortColumns = ['username' => 'ui.username', 'package' => "COALESCE(NULLIF(ui.package_name,''),'')", 'used' => 'COALESCE(ui.used_quota,0)', 'remaining' => $remainingExpression, 'expires' => 'COALESCE(ui.expiry_date,ui.expires_at,nua.expires_at)', 'status' => "CASE WHEN ui.is_disabled=1 OR ui.status='disabled' THEN 0 WHEN EXISTS (SELECT 1 FROM nawa_live_accounts live_account WHERE live_account.username=ui.username LIMIT 1) THEN 2 ELSE 1 END"];
if (!isset($sortColumns[$sort])) $sort = 'username';
$orderBy = $sortColumns[$sort] . ' ' . $direction . ', ui.id DESC';

$filterPackage = trim((string) ($_GET['package_filter'] ?? ''));

$usedMin = ($_GET['used_min'] ?? '') !== ''
    ? max(0, (float) $_GET['used_min'])
    : null;

$usedMax = ($_GET['used_max'] ?? '') !== ''
    ? max(0, (float) $_GET['used_max'])
    : null;

$remainingMin = ($_GET['remaining_min'] ?? '') !== ''
    ? max(0, (float) $_GET['remaining_min'])
    : null;

$remainingMax = ($_GET['remaining_max'] ?? '') !== ''
    ? max(0, (float) $_GET['remaining_max'])
    : null;

$offset = ($page - 1) * $pageSize;
$subscriberCondition = "
    (
        ui.package_id IS NULL
        OR ui.package_id NOT IN (53,54,55,56,57,58)
    )
    AND NOT EXISTS (
        SELECT 1
        FROM userbillinfo ubi_card
        WHERE ubi_card.username = ui.username
          AND ubi_card.batch_id IS NOT NULL
    )
";

$userWhere = "WHERE " . $subscriberCondition;

if ($search !== '') {
    $escapedSearch = $dbSocket->escapeSimple(str_replace(['%', '_'], '', $search));

    $userWhere .= sprintf(
        " AND (
            ui.username LIKE '%%%s%%'
            OR ui.firstname LIKE '%%%s%%'
            OR ui.lastname LIKE '%%%s%%'
            OR ui.mobilephone LIKE '%%%s%%'
        )",
        $escapedSearch,
        $escapedSearch,
        $escapedSearch,
        $escapedSearch
    );
}


/* فلاتر الباقة والاستهلاك والمتبقي */

$quotaExpression = "
COALESCE(ui.total_quota,0)
";

$usageExpression = "COALESCE(ui.used_quota,0)";

$remainingExpression = "
GREATEST(
    {$quotaExpression} - {$usageExpression},
    0
)
";

if ($filterPackage !== '') {
    $pkg = $dbSocket->escapeSimple($filterPackage);

    $userWhere .= sprintf(
        " AND (
            ui.package_name='%s'
            OR EXISTS (
                SELECT 1
                FROM radusergroup rug_filter
                WHERE rug_filter.username=ui.username
                  AND rug_filter.groupname='%s'
            )
        )",
        $pkg,
        $pkg
    );
}

if ($usedMin !== null) {
    $userWhere .= " AND {$usageExpression} >= " . nawa_gb_to_bytes($usedMin);
}

if ($usedMax !== null) {
    $userWhere .= " AND {$usageExpression} <= " . nawa_gb_to_bytes($usedMax);
}

if ($remainingMin !== null) {
    $userWhere .= " AND {$remainingExpression} >= " . nawa_gb_to_bytes($remainingMin);
}

if ($remainingMax !== null) {
    $userWhere .= " AND {$remainingExpression} <= " . nawa_gb_to_bytes($remainingMax);
}


/* عدد المشتركين الحقيقيين فقط */
$countWhere = "
WHERE
    (
        ui.package_id IS NULL
        OR ui.package_id NOT IN (53,54,55,56,57,58)
    )
    AND NOT EXISTS (
        SELECT 1
        FROM userbillinfo ubi_card
        WHERE ubi_card.username = ui.username
          AND ubi_card.batch_id IS NOT NULL
    )
";

if ($search !== '') {
    $countWhere .= sprintf(
        " AND (
            ui.username LIKE '%%%s%%'
            OR ui.firstname LIKE '%%%s%%'
            OR ui.lastname LIKE '%%%s%%'
            OR ui.mobilephone LIKE '%%%s%%'
        )",
        $escapedSearch,
        $escapedSearch,
        $escapedSearch,
        $escapedSearch
    );
}

/* نفس فلاتر الجدول على إجمالي النتائج */
if ($filterPackage !== '') {
    $pkg = $dbSocket->escapeSimple($filterPackage);

    $countWhere .= sprintf(
        " AND (
            ui.package_name='%s'
            OR EXISTS (
                SELECT 1
                FROM radusergroup rug_filter
                WHERE rug_filter.username=ui.username
                  AND rug_filter.groupname='%s'
            )
        )",
        $pkg,
        $pkg
    );
}

if ($usedMin !== null) {
    $countWhere .= " AND {$usageExpression} >= " . nawa_gb_to_bytes($usedMin);
}

if ($usedMax !== null) {
    $countWhere .= " AND {$usageExpression} <= " . nawa_gb_to_bytes($usedMax);
}

if ($remainingMin !== null) {
    $countWhere .= " AND {$remainingExpression} >= " . nawa_gb_to_bytes($remainingMin);
}

if ($remainingMax !== null) {
    $countWhere .= " AND {$remainingExpression} <= " . nawa_gb_to_bytes($remainingMax);
}

$totalSql = "
    SELECT COUNT(*)
    FROM userinfo ui FORCE INDEX (package_id)
    LEFT JOIN nawa_user_allowances nua
        ON nua.username=ui.username
    " . $countWhere;
$defaultSubscriberList = $search === '' && $filterPackage === ''
    && $usedMin === null && $usedMax === null
    && $remainingMin === null && $remainingMax === null;
if ($defaultSubscriberList) {
    $cachedSubscriberCount = $dbSocket->getOne("SELECT total_subscribers
        FROM nawa_report_state
        WHERE id=1 AND counts_refreshed_at>=NOW()-INTERVAL 10 MINUTE");
    $totalUsers = DB::isError($cachedSubscriberCount)
        ? (int) $dbSocket->getOne($totalSql)
        : (int) $cachedSubscriberCount;
} else {
    $totalUsers = (int) $dbSocket->getOne($totalSql);
}


$sql = sprintf("
    SELECT
        ui.username,
        CONCAT(
            COALESCE(ui.firstname,''),
            ' ',
            COALESCE(ui.lastname,'')
        ) AS fullname,

        COALESCE(ui.mobilephone,'') AS mobilephone,

        COALESCE(
            NULLIF(ui.package_name,''),
            (
                SELECT rug.groupname
                FROM radusergroup rug
                WHERE rug.username = ui.username
                  AND rug.groupname <> 'daloRADIUS-Disabled-Users'
                ORDER BY rug.priority, rug.groupname
                LIMIT 1
            ),
            ''
        ) AS groups,

        COALESCE(ui.total_quota,0) AS quota_bytes,

        COALESCE(ui.used_quota,0) AS usage_bytes,

        COALESCE(
            ui.expiry_date,
            ui.expires_at,
            nua.expires_at
        ) AS expires_at,

        NULL AS quota_expired_at,

        CASE
            WHEN ui.is_disabled = 1
              OR ui.status = 'disabled'
              OR EXISTS (
                    SELECT 1
                    FROM radusergroup rd
                    WHERE rd.username = ui.username
                      AND rd.groupname = 'daloRADIUS-Disabled-Users'
              )
            THEN 1
            ELSE 0
        END AS disabled,

        CASE
            WHEN EXISTS (
                SELECT 1
                FROM nawa_live_accounts live_account
                WHERE live_account.username = ui.username
                LIMIT 1
            )
            THEN 1
            ELSE 0
        END AS online,

        ui.package_id,
        ui.status

    FROM userinfo ui FORCE INDEX (package_id)

    LEFT JOIN nawa_user_allowances nua
        ON nua.username = ui.username

    %s

    ORDER BY %s
    LIMIT %d, %d
", $userWhere, $orderBy, $offset, $pageSize);



$result = $dbSocket->query($sql);




$users = [];
if (nawa_db_ok($result)) {
    while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
        $users[] = $row;
    }
}
$catalogCache = $_SESSION['nawa_users_catalog_cache'] ?? null;
if (is_array($catalogCache) && (time() - (int) ($catalogCache['time'] ?? 0)) < 60) {
    $groups = (array) ($catalogCache['groups'] ?? []);
    $packages = (array) ($catalogCache['packages'] ?? []);
    $offers = (array) ($catalogCache['offers'] ?? []);
} else {
    $groups = nawa_groups($dbSocket);
    $packages = nawa_packages($dbSocket, true);
    $offers = nawa_offers($dbSocket, true);
    $_SESSION['nawa_users_catalog_cache'] = [
        'time' => time(),
        'groups' => $groups,
        'packages' => $packages,
        'offers' => $offers,
    ];
}
$subscriberCurrent = ['quota_bytes' => 0, 'used_bytes' => 0, 'remaining_bytes' => 0];
$subscriberUsagePeriods = ['day' => 0, 'week' => 0, 'month' => 0, 'quarter' => 0];
$onlineUsers = 0;
$settledThisMonth = 0;
$expiredUsers = 0;

/*
 * These dashboard totals used to execute eight expensive aggregate queries on
 * every keypress.  Keep the subscriber list live, but share aggregate results
 * for 30 seconds and calculate all four traffic periods in one table scan.
 */
$statsCacheFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '3dradius-users-stats-v2.json';
$statsCache = null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && is_file($statsCacheFile) && (time() - (int) @filemtime($statsCacheFile)) < 30) {
    $decodedStats = json_decode((string) @file_get_contents($statsCacheFile), true);
    if (is_array($decodedStats)) $statsCache = $decodedStats;
}

$statsReady = is_array($statsCache);
if ($statsReady) {
    $subscriberCurrent = (array) ($statsCache['current'] ?? $subscriberCurrent);
    $subscriberUsagePeriods = array_merge($subscriberUsagePeriods, (array) ($statsCache['periods'] ?? []));
    $onlineUsers = (int) ($statsCache['online'] ?? 0);
    $settledThisMonth = (int) ($statsCache['settled'] ?? 0);
    $expiredUsers = (int) ($statsCache['expired'] ?? 0);
} elseif ($statsOnly) {
    $subscriberCurrentResult = $dbSocket->query("SELECT
            COALESCE(SUM(COALESCE(ui.total_quota,0)),0) quota_bytes,
            COALESCE(SUM(COALESCE(ui.used_quota,0)),0) used_bytes,
            COALESCE(SUM(GREATEST(COALESCE(ui.total_quota,0)-COALESCE(ui.used_quota,0),0)),0) remaining_bytes
        FROM userinfo ui
        WHERE NOT EXISTS (SELECT 1 FROM userbillinfo ubi_card WHERE ubi_card.username=ui.username AND ubi_card.batch_id IS NOT NULL)");
    if (!DB::isError($subscriberCurrentResult) && $subscriberCurrentResult->numRows() > 0) {
        $subscriberCurrent = $subscriberCurrentResult->fetchRow(DB_FETCHMODE_ASSOC);
    }

    $periodResult = $dbSocket->query("SELECT
            COALESCE(SUM(CASE WHEN ud.usage_date >= CURDATE() THEN ud.upload_bytes+ud.download_bytes ELSE 0 END),0) day_bytes,
            COALESCE(SUM(CASE WHEN ud.usage_date >= CURDATE()-INTERVAL 6 DAY THEN ud.upload_bytes+ud.download_bytes ELSE 0 END),0) week_bytes,
            COALESCE(SUM(CASE WHEN ud.usage_date >= CURDATE()-INTERVAL 29 DAY THEN ud.upload_bytes+ud.download_bytes ELSE 0 END),0) month_bytes,
            COALESCE(SUM(ud.upload_bytes+ud.download_bytes),0) quarter_bytes
        FROM nawa_usage_daily ud
        WHERE ud.usage_date >= CURDATE()-INTERVAL 89 DAY
          AND NOT EXISTS (
              SELECT 1 FROM userbillinfo ubi_card
              WHERE ubi_card.username = (ud.username COLLATE utf8mb4_general_ci)
                AND ubi_card.batch_id IS NOT NULL
          )");
    if (!DB::isError($periodResult) && $periodResult->numRows() > 0) {
        $periodRow = $periodResult->fetchRow(DB_FETCHMODE_ASSOC);
        $subscriberUsagePeriods = [
            'day' => (float) ($periodRow['day_bytes'] ?? 0),
            'week' => (float) ($periodRow['week_bytes'] ?? 0),
            'month' => (float) ($periodRow['month_bytes'] ?? 0),
            'quarter' => (float) ($periodRow['quarter_bytes'] ?? 0),
        ];
    }

    $onlineResult = $dbSocket->getOne("SELECT COUNT(DISTINCT ra.username)
        FROM radacct ra
        JOIN userinfo ui ON ui.username=ra.username
        WHERE ra.acctstoptime IS NULL
          AND (ui.package_id IS NULL OR ui.package_id NOT IN (53,54,55,56,57,58))
          AND NOT EXISTS (SELECT 1 FROM userbillinfo ubi_card WHERE ubi_card.username=ui.username AND ubi_card.batch_id IS NOT NULL)");
    $onlineUsers = DB::isError($onlineResult) ? 0 : (int) $onlineResult;

    $settledResult = $dbSocket->getOne("SELECT COUNT(*) FROM nawa_audit_log
        WHERE action_name='user.package_settle'
          AND created_at >= DATE_FORMAT(CURRENT_DATE,'%Y-%m-01')
          AND created_at < DATE_ADD(DATE_FORMAT(CURRENT_DATE,'%Y-%m-01'),INTERVAL 1 MONTH)");
    $settledThisMonth = DB::isError($settledResult) ? 0 : (int) $settledResult;

    $expiredResult = $dbSocket->getOne("SELECT COUNT(*) FROM userinfo ui
        WHERE (ui.package_id IS NULL OR ui.package_id NOT IN (53,54,55,56,57,58))
          AND NOT EXISTS (SELECT 1 FROM userbillinfo ubi_card WHERE ubi_card.username=ui.username AND ubi_card.batch_id IS NOT NULL)
          AND COALESCE(NULLIF(ui.expiry_date,'0000-00-00 00:00:00'),NULLIF(ui.expires_at,'0000-00-00 00:00:00')) IS NOT NULL
          AND COALESCE(NULLIF(ui.expiry_date,'0000-00-00 00:00:00'),NULLIF(ui.expires_at,'0000-00-00 00:00:00')) < NOW()");
    $expiredUsers = DB::isError($expiredResult) ? 0 : (int) $expiredResult;

    @file_put_contents($statsCacheFile, json_encode([
        'current' => $subscriberCurrent,
        'periods' => $subscriberUsagePeriods,
        'online' => $onlineUsers,
        'settled' => $settledThisMonth,
        'expired' => $expiredUsers,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    $statsReady = true;
}

if ($statsOnly) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => $statsReady,
        'online' => $onlineUsers,
        'settled' => $settledThisMonth,
        'expired' => $expiredUsers,
        'current' => $subscriberCurrent,
        'periods' => $subscriberUsagePeriods,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    include '../common/includes/db_close.php';
    exit;
}
$csrf = dalo_csrf_token();

if (!$fragmentMode) print_html_prologue('المستخدمون والتسديد', $langCode, ['static/css/nawa-production.css']);
?>
<style>
.subscriber-traffic-card{overflow:hidden}.subscriber-traffic-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 17px;border-bottom:1px solid #eceef2}.subscriber-traffic-head h2{margin:0;font-size:14px}.subscriber-traffic-head p{margin:4px 0 0;color:#858d9d;font-size:10px}.subscriber-periods{display:flex;gap:4px;padding:4px;border:1px solid #e1e4e9;border-radius:10px;background:#f7f8fa}.subscriber-periods button{padding:7px 10px;border:0;border-radius:7px;background:transparent;color:#747c8d;font-size:9px;font-weight:900;cursor:pointer}.subscriber-periods button.active{color:#fff;background:#e5221a}.subscriber-traffic-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;padding:15px}.subscriber-traffic-grid article{padding:14px;border:1px solid #e5e7ed;border-radius:13px;background:#fff}.subscriber-traffic-grid span,.subscriber-traffic-grid strong,.subscriber-traffic-grid small{display:block}.subscriber-traffic-grid span{color:#858c9b;font-size:9px}.subscriber-traffic-grid strong{margin-top:6px;font-size:19px}.subscriber-traffic-grid small{margin-top:4px;color:#969cab;font-size:8px}@media(max-width:760px){.subscriber-traffic-head{align-items:stretch;flex-direction:column}.subscriber-periods{overflow:auto}.subscriber-traffic-grid{grid-template-columns:1fr}}
</style>
<style id="users-reference-design">
.nawa-page.users-reference{--u-ink:#0a2251;--u-muted:#7185ad;--u-line:#dbe7f8;--u-card:#ffffffec;--u-soft:#f4f8ff;--u-field:#fff;--u-green:#009b72;--u-green-bg:#dffbf1;--u-orange:#cf7905;--u-orange-bg:#fff3e1;--u-shadow:0 8px 26px #275eaa06;direction:rtl;padding:24px;max-width:1700px;margin:auto;background:transparent!important;color:var(--u-ink)!important}
.users-reference .nawa-page-header{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:20px}
.users-reference .nawa-page-header h1{font-size:27px;font-weight:800;color:var(--u-ink)!important;margin-bottom:7px}
.users-reference .nawa-page-header p{color:var(--u-muted)!important;font-size:12px}
.users-reference .nawa-page-actions{display:flex;gap:9px}
.users-reference .nawa-button{border:1px solid var(--u-line)!important;border-radius:9px;background:var(--u-field)!important;color:var(--u-ink)!important;box-shadow:0 2px 5px #153a6b05;font-weight:700;transition:background .15s,border-color .15s}
.users-reference .nawa-button:hover{border-color:#77a8de!important;background:var(--u-soft)!important}
.users-reference .nawa-button.primary{background:linear-gradient(130deg,#ff3153,#ff003c)!important;color:#fff!important;border-color:#ff365c!important;box-shadow:0 5px 14px #ff174329}
.users-reference .nawa-page-actions .nawa-button{padding:11px 16px;min-height:40px}
.users-reference .nawa-stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}
.users-reference .nawa-stat{position:relative;isolation:isolate;overflow:hidden;min-height:96px;display:flex;flex-direction:column;justify-content:center;padding:17px 20px 17px 86px;border:1px solid var(--u-line)!important;border-radius:16px;background:var(--u-card)!important;box-shadow:var(--u-shadow);--stat-color:#00bd83;--stat-tint:#d8fff0}
.users-reference .nawa-stat:nth-child(2){--stat-color:#0785ff;--stat-tint:#dceeff}.users-reference .nawa-stat:nth-child(3){--stat-color:#ff5d29;--stat-tint:#ffebe3}.users-reference .nawa-stat:nth-child(4){--stat-color:#8150ff;--stat-tint:#eee6ff}
.users-reference .nawa-stat:after{content:"";position:absolute;inset:52% 0 0;z-index:-1;background:linear-gradient(0deg,var(--stat-tint),transparent);opacity:.5;clip-path:polygon(0 65%,15% 45%,25% 58%,34% 20%,44% 17%,59% 68%,70% 63%,82% 84%,100% 65%,100% 100%,0 100%)}
.users-reference .nawa-stat>span{color:var(--u-muted);font-size:12px}.users-reference .nawa-stat>strong{font-size:28px;line-height:1.3;color:var(--u-ink)!important;margin-top:6px}
.users-reference .users-stat-icon{position:absolute;left:18px;top:50%;transform:translateY(-50%);display:grid;place-items:center;width:53px;height:53px;border-radius:15px;background:linear-gradient(135deg,color-mix(in srgb,var(--stat-color) 80%,white),var(--stat-color));color:#fff;font-size:27px;box-shadow:0 5px 14px color-mix(in srgb,var(--stat-color) 30%,transparent),inset 0 1px 0 #fff6}
.users-reference .nawa-card{background:var(--u-card)!important;border:1px solid var(--u-line)!important;border-radius:16px;box-shadow:var(--u-shadow);margin-bottom:15px;overflow:hidden;color:var(--u-ink)!important}
.users-reference .subscriber-traffic-head{padding:14px 16px 10px;border:0;gap:14px}
.users-reference .subscriber-traffic-head h2,.users-reference .nawa-card-header h2{font-size:17px;color:var(--u-ink)!important;font-weight:800}
.users-reference .subscriber-traffic-head p,.users-reference .nawa-card-header p{color:var(--u-muted)!important;font-size:10px;line-height:1.7}
.users-reference .subscriber-periods{padding:3px;border:1px solid var(--u-line);background:var(--u-soft);border-radius:10px;gap:3px}
.users-reference .subscriber-periods button{min-width:61px;padding:8px 12px;color:var(--u-ink);font-size:10px;border-radius:7px}
.users-reference .subscriber-periods button.active{background:linear-gradient(130deg,#ff3458,#ff003a);box-shadow:0 4px 12px #ff21412b;color:white}
.users-reference .subscriber-traffic-grid{padding:0 14px 14px;gap:12px}
.users-reference .subscriber-traffic-grid article{position:relative;padding:17px 16px 17px 78px;background:var(--u-field);border:1px solid var(--u-line);border-radius:11px;min-height:90px}
.users-reference .subscriber-traffic-grid span{font-size:11px;color:var(--u-muted)}
.users-reference .subscriber-traffic-grid strong{font-size:20px;color:var(--u-ink);font-variant-numeric:tabular-nums}
.users-reference .subscriber-traffic-grid small{font-size:9px;color:var(--u-muted)}
.users-reference .users-traffic-icon{position:absolute;left:15px;top:50%;transform:translateY(-50%);display:grid;place-items:center;width:49px;height:49px;font-size:26px;border-radius:14px;background:#d0fae9;color:#00aa76}
.users-reference .subscriber-traffic-grid article:nth-child(2) .users-traffic-icon{color:#8c4ef4;background:#eee1ff}.users-reference .subscriber-traffic-grid article:nth-child(3) .users-traffic-icon{color:#de8900;background:#fff1d7}
.users-reference .nawa-card-header{padding:14px 16px 8px;border:0}
.users-reference .nawa-filter.nawa-users-search{display:flex;gap:10px;align-items:center;padding:0 14px 12px;margin:0;background:transparent;border:0}
.users-reference .nawa-page-size-control{background:var(--u-soft)!important;border:1px solid var(--u-line)!important;color:var(--u-muted)!important;box-shadow:none}
.users-reference .nawa-page-size-control input{background:var(--u-field)!important;border-color:var(--u-line)!important;color:var(--u-ink)!important;min-height:36px}
.users-reference .nawa-search-field{flex:1;min-width:140px;position:relative}
.users-reference .nawa-search-field input{width:100%;height:40px;border:1px solid var(--u-line)!important;border-radius:9px;background:var(--u-field)!important;color:var(--u-ink)!important;padding-inline:36px 14px;box-shadow:inset 0 0 0 4px var(--u-soft)}
.users-reference .nawa-search-field input::placeholder{color:var(--u-muted)!important}
.users-reference .nawa-search-field>i{position:absolute;right:12px;top:12px;color:var(--u-muted)}
.users-reference .nawa-search-button{min-height:40px;padding-inline:22px}
.users-reference .nawa-table-wrap{margin:0 12px 12px;border:1px solid var(--u-line);border-radius:10px}
.users-reference .nawa-table{width:100%;border-collapse:collapse;white-space:nowrap;font-size:11px;background:transparent}
.users-reference .nawa-table th{padding:12px 9px;background:var(--u-soft)!important;color:var(--u-muted)!important;border-bottom:1px solid var(--u-line);font-size:11px}
.users-reference .nawa-table td{padding:10px 8px;border-bottom:1px solid var(--u-line)!important;color:var(--u-ink)!important;background:transparent;vertical-align:middle}
.users-reference .nawa-table td:first-child{display:table-cell}
.users-reference .nawa-table tbody tr:nth-child(even){background:color-mix(in srgb,var(--u-soft) 55%,transparent)}
.users-reference .nawa-table tbody tr:hover{background:var(--u-soft)}
.users-reference .nawa-user b{color:var(--u-ink)!important;font-size:11px}.users-reference .nawa-user small{color:var(--u-muted)!important;font-size:9px}
.users-reference .nawa-table td:nth-child(2) b{color:#148fda!important}
.users-reference .nawa-badge{display:inline-flex;align-items:center;justify-content:center;gap:4px;min-height:27px;padding:5px 10px;background:var(--u-green-bg)!important;color:var(--u-green)!important;border-radius:20px;font-size:10px;font-weight:700;line-height:1.4;border:0}
.users-reference .nawa-badge.warning{background:var(--u-orange-bg)!important;color:var(--u-orange)!important}
.users-reference .nawa-badge.muted{background:var(--u-soft)!important;color:var(--u-muted)!important}
.users-reference .nawa-actions{display:flex;gap:5px;flex-wrap:nowrap;align-items:center}
.users-reference .nawa-actions .nawa-button.small{padding:6px 8px;min-height:30px;font-size:9px;border-radius:8px;white-space:nowrap}
.users-reference .nawa-actions .nawa-button:not(.primary){box-shadow:inset 0 1px 0 #ffffff10}
.users-reference .nawa-actions .nawa-button[data-delete-user] i{color:#ff375b}
.users-reference input:focus-visible,.users-reference button:focus-visible,.users-reference a:focus-visible{outline:2px solid #389cf9;outline-offset:3px}
body.dark .users-reference,html[data-theme="dark"] .users-reference,body[data-theme="dark"] .users-reference{--u-ink:#eef5ff;--u-muted:#a6bfdf;--u-line:#254760;--u-card:#0b1c2cee;--u-soft:#132a40;--u-field:#0b2032;--u-green:#16e5b2;--u-green-bg:#053b33;--u-orange:#ffb52b;--u-orange-bg:#352d22;--u-shadow:0 10px 26px #0002}
body.dark .users-reference .nawa-stat,html[data-theme="dark"] .users-reference .nawa-stat,body[data-theme="dark"] .users-reference .nawa-stat{background:linear-gradient(110deg,color-mix(in srgb,var(--stat-color) 18%,var(--u-card)),var(--u-card))!important;border-color:color-mix(in srgb,var(--stat-color) 55%,var(--u-line))!important}
body.dark .users-reference .nawa-stat:after,html[data-theme="dark"] .users-reference .nawa-stat:after{opacity:.08}
body.dark .users-reference .users-traffic-icon{background:#0b4a3b;color:#00e29f}body.dark .users-reference .subscriber-traffic-grid article:nth-child(2) .users-traffic-icon{background:#392760;color:#c58bff}body.dark .users-reference .subscriber-traffic-grid article:nth-child(3) .users-traffic-icon{background:#493920;color:#ffbb30}
@media(max-width:1200px){.nawa-page.users-reference{padding:20px 14px}.users-reference .nawa-stat{padding-inline:14px 73px}.users-reference .users-stat-icon{left:12px;width:46px;height:46px}.users-reference .nawa-stat>span{font-size:10px}.users-reference .nawa-table{min-width:1050px}}
@media(max-width:850px){.users-reference .nawa-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.users-reference .nawa-page-header{align-items:flex-start;flex-direction:column}.users-reference .nawa-page-header h1{font-size:23px}.users-reference .subscriber-traffic-head{flex-wrap:wrap}.users-reference .subscriber-traffic-grid{grid-template-columns:1fr}.users-reference .nawa-filter.nawa-users-search{flex-wrap:wrap}.users-reference .nawa-search-field{order:-1;flex-basis:100%}.users-reference .nawa-search-button{margin-inline-start:auto}}
@media(max-width:420px){.nawa-page.users-reference{padding:16px 9px}.users-reference .nawa-stat{padding:13px 11px 13px 56px;min-height:88px}.users-reference .users-stat-icon{width:36px;height:39px;left:10px;font-size:21px}.users-reference .nawa-stat>strong{font-size:24px}.users-reference .nawa-page-actions .nawa-button{padding:10px;font-size:11px}.users-reference .subscriber-periods button{min-width:48px;padding:8px}}

</style>
<div class="nawa-page users-reference">
    <section class="nawa-page-header">
        <div><h1>المستخدمون والتسديد</h1><p>إدارة حسابات RADIUS الفعلية والباقات ودورات الاستخدام.</p></div>
        <div class="nawa-page-actions"><a class="nawa-button" href="nawa-offers.php" onclick="if(window.loadSection){loadSection('nawa-offers.php?fragment=1');return false}"><i class="bi bi-percent"></i> إدارة العروض</a><a class="nawa-button primary" href="nawa-user-new.php" onclick="if(window.loadSection){loadSection('nawa-user-new.php?fragment=1');return false}"><i class="bi bi-person-plus"></i> إضافة مستخدم</a></div>
    </section>

    <?php if ($successMsg !== ''): ?><div class="nawa-alert success"><i class="bi bi-check2-circle"></i><span><?= nawa_e($successMsg) ?></span></div><?php endif; ?>
    <?php if ($failureMsg !== ''): ?><div class="nawa-alert danger"><i class="bi bi-exclamation-triangle"></i><span><?= nawa_e($failureMsg) ?></span></div><?php endif; ?>

    <section class="nawa-stat-grid">
        <article class="nawa-stat"><i class="bi bi-people users-stat-icon" aria-hidden="true"></i><span>إجمالي المستخدمين</span><strong><?= number_format($totalUsers) ?></strong></article>
        <article class="nawa-stat"><i class="bi bi-person-fill users-stat-icon" aria-hidden="true"></i><span>متصلون الآن</span><strong data-users-stat="online"><?= $statsReady ? number_format($onlineUsers) : '—' ?></strong></article>
        <article class="nawa-stat"><i class="bi bi-calendar-check users-stat-icon" aria-hidden="true"></i><span>باقات مسددة هذا الشهر</span><strong data-users-stat="settled"><?= $statsReady ? number_format($settledThisMonth) : '—' ?></strong></article>
        <article class="nawa-stat"><i class="bi bi-people-fill users-stat-icon" aria-hidden="true"></i><span>منتهية الباقة</span><strong data-users-stat="expired"><?= $statsReady ? number_format($expiredUsers) : '—' ?></strong></article>
    </section>

    <section class="nawa-card subscriber-traffic-card" data-subscriber-traffic data-period-values='<?= nawa_e(json_encode($subscriberUsagePeriods, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
        <header class="subscriber-traffic-head"><div><h2>قيقات المشتركين</h2><p>الموجود والمتبقي حاليًا، والمصروف فعليًا حسب الفترة المختارة.</p></div><div class="subscriber-periods"><button type="button" class="active" data-usage-period="day">يومي</button><button type="button" data-usage-period="week">أسبوعي</button><button type="button" data-usage-period="month">شهري</button><button type="button" data-usage-period="quarter">3 أشهر</button></div></header>
        <div class="subscriber-traffic-grid"><article><i class="bi bi-cloud-arrow-up-fill users-traffic-icon" aria-hidden="true"></i><span>إجمالي القيقات الموجودة</span><strong data-users-stat="quota"><?= $statsReady ? number_format((float) ($subscriberCurrent['quota_bytes'] ?? 0) / 1073741824, 2) . ' GB' : '—' ?></strong><small>مجموع باقات المشتركين الحالية</small></article><article><i class="bi bi-box-arrow-up users-traffic-icon" aria-hidden="true"></i><span>المصروف خلال الفترة</span><strong data-period-used><?= $statsReady ? number_format((float) ($subscriberUsagePeriods['day'] ?? 0) / 1073741824, 2) . ' GB' : '—' ?></strong><small data-period-label>اليوم</small></article><article><i class="bi bi-database users-traffic-icon" aria-hidden="true"></i><span>المتبقي الفعلي الآن</span><strong data-users-stat="remaining"><?= $statsReady ? number_format((float) ($subscriberCurrent['remaining_bytes'] ?? 0) / 1073741824, 2) . ' GB' : '—' ?></strong><small>بعد طرح استهلاك كل مشترك من باقته</small></article></div>
    </section>

    <section class="nawa-card">
        <header class="nawa-card-header"><div><h2>قائمة المستخدمين</h2><p>سدّد بباقة أو عرض أو قيمة مخصصة، وعدّل الأيام والقيقات الإضافية من نفس الجدول.</p></div></header>
        
<form class="nawa-filter nawa-users-search" method="get" data-users-search>
    <input type="hidden" name="sort" value="<?= nawa_e($sort) ?>">
    <input type="hidden" name="dir" value="<?= strtolower($direction) ?>">
    <label class="nawa-page-size-control" style="display:flex;align-items:center;gap:7px;font-size:10px;font-weight:800;color:#667085;white-space:nowrap"><span>عرض في الصفحة</span><input name="per_page" data-users-per-page type="number" min="1" max="500" inputmode="numeric" value="<?= $pageSize ?>" style="width:62px;border:1px solid #dce4ef;border-radius:9px;padding:8px;color:#334155"></label>

    <div class="nawa-search-field">
        <i class="bi bi-search"></i>
        <input
            type="search"
            name="search"
            value="<?= nawa_e($search) ?>"
            placeholder="ابحث برمز المشترك أو الاسم أو رقم الجوال..."
            autocomplete="off"
        >
    </div>

    <button class="nawa-button primary nawa-search-button" type="submit">
        <i class="bi bi-search"></i>
        بحث
    </button>

</form>

        <?php if (count($users) === 0): ?>
            <div class="nawa-empty"><i class="bi bi-people"></i>لا توجد حسابات مطابقة.</div>
        <?php else: ?>
        <div class="nawa-table-wrap" style="max-height:none !important; overflow-x:auto !important; overflow-y:hidden !important; -webkit-overflow-scrolling:touch;"><table class="nawa-table"><thead><tr><?php $sortHeader = static function ($key, $label) use ($sort, $direction) { $active = $sort === $key; $next = $active && $direction === 'ASC' ? 'desc' : 'asc'; $icon = $active ? ($direction === 'ASC' ? 'bi-sort-up' : 'bi-sort-down') : 'bi-arrow-down-up'; return '<th><button style="border:0;background:transparent;cursor:pointer;font:inherit;font-weight:800;color:' . ($active ? '#3569d4' : 'inherit') . '" type="button" data-users-sort="' . nawa_e($key) . '" data-users-dir="' . $next . '">' . nawa_e($label) . ' <i class="bi ' . $icon . '"></i></button></th>'; }; ?><?= $sortHeader('username', 'المستخدم') ?><?= $sortHeader('package', 'الباقة') ?><?= $sortHeader('used', 'المستهلك') ?><?= $sortHeader('remaining', 'المتبقي') ?><th>متبقي على الانتهاء</th><?= $sortHeader('expires', 'انتهاء الرصيد') ?><?= $sortHeader('status', 'الحالة') ?><th>الإجراءات</th></tr></thead><tbody>
        <?php foreach ($users as $user):
            $usage = (float) $user['usage_bytes'];
            $usageText = $usage >= 1073741824 ? number_format($usage / 1073741824, 2) . ' GB' : number_format($usage / 1048576, 1) . ' MB';
            $quotaBytes = (float) ($user['quota_bytes'] ?? 0);
            $quotaGb = $quotaBytes > 0 ? nawa_bytes_to_gb($quotaBytes) : 0;
            $remainingGb = $quotaBytes > 0 ? max(0, nawa_bytes_to_gb($quotaBytes - $usage)) : null;
            $quotaExpiredAt = trim((string) ($user['quota_expired_at'] ?? ''));

            $expiresAt = trim((string) ($user['expires_at'] ?? ''));

            $expiryTimestamp = $expiresAt !== '' ? strtotime($expiresAt) : false;

            $quotaExpiryTimestamp = $quotaExpiredAt !== ''
                ? strtotime($quotaExpiredAt)
                : false;

            $expiryText = $expiryTimestamp !== false
                ? date('d/m/Y', $expiryTimestamp)
                : 'بدون تاريخ محدد';

            $expiryTimeText = $expiryTimestamp !== false
                ? date('H:i', $expiryTimestamp)
                : '';

            $expired = $expiryTimestamp !== false && $expiryTimestamp < time();
            $daysRemaining = $expiryTimestamp !== false ? (int) ceil(($expiryTimestamp - time()) / 86400) : null;
            $fullname = trim((string) $user['fullname']) !== '' ? $user['fullname'] : '—';
        ?>
            <tr>
                <td><span class="nawa-user"><b><?= nawa_e($user['username']) ?></b><small><?= nawa_e($fullname) ?><?= $user['mobilephone'] !== '' ? ' · ' . nawa_e($user['mobilephone']) : '' ?></small></span></td>
                <td>
                    <span class="nawa-user">
                        <b><?= $quotaGb > 0 ? nawa_e(number_format($quotaGb, 2)) . ' GB' : 'غير محددة' ?></b>
                        <small><?= $user['groups'] ? nawa_e($user['groups']) : 'بدون باقة' ?></small>
                    </span>
                </td>

                <td>
                    <span class="nawa-badge warning">
                        <?= nawa_e($usageText) ?>
                    </span>
                </td>

                <td>
                    <?php if ($remainingGb !== null): ?>
                        <span class="nawa-badge">
                            <?= nawa_e(number_format($remainingGb, 2)) ?> GB
                        </span>
                    <?php else: ?>
                        <span class="nawa-badge muted">غير محدد</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="nawa-badge <?= $expired ? 'warning' : 'muted' ?>" style="display:block;text-align:center">

                        <?php if ($expired): ?>
                            <div>منتهية</div>
                        <?php endif; ?>

                        <div>
                            <?php
                            if ($daysRemaining === null) {
                                echo 'بدون تاريخ محدد';
                            } elseif ($daysRemaining > 0) {
                                echo 'متبقي ' . $daysRemaining . ' يوم';
                            } elseif ($daysRemaining === 0) {
                                echo 'ينتهي اليوم';
                            } else {
                                echo 'منتهي منذ ' . abs($daysRemaining) . ' يوم';
                            }
                            ?>
                        </div>

                        

                    </div>
                </td>

                <td>
                    <span class="nawa-badge <?= $quotaExpiryTimestamp !== false ? 'warning' : 'muted' ?>">
                        <?php if ($quotaExpiryTimestamp !== false): ?>
                            <?= nawa_e(date('Y-m-d', $quotaExpiryTimestamp)) ?>
                            <small style="display:block;margin-top:3px;font-size:11px">
                                <?= nawa_e(date('H:i', $quotaExpiryTimestamp)) ?>
                            </small>
                        <?php else: ?>
                            لم ينتهِ
                        <?php endif; ?>
                    </span>
                </td>
                <td>
                    <?php if ((int) $user['disabled'] === 1): ?>
                        <span class="nawa-badge warning"><i class="bi bi-slash-circle"></i> معطل</span>
                    <?php else: ?>
                        <span class="nawa-badge <?= (int) $user['online'] === 1 ? '' : 'muted' ?>"><?= (int) $user['online'] === 1 ? 'متصل' : 'غير متصل' ?></span>
                    <?php endif; ?>
                </td>
                <td><div class="nawa-actions"><button class="nawa-button small" type="button" onclick="loadSection('nawa-user-usage.php?fragment=1&amp;username=<?= rawurlencode((string) $user['username']) ?>')"><i class="bi bi-bar-chart-line"></i> تفاصيل الاستخدام</button><button class="nawa-button primary small" type="button" data-speed-user="<?= nawa_e($user['username']) ?>"><i class="bi bi-speedometer2"></i> تحديد السرعة</button><button class="nawa-button success small" type="button" data-settle-user="<?= nawa_e($user['username']) ?>"><i class="bi bi-arrow-repeat"></i> تسديد</button><button class="nawa-button small" type="button" data-adjust-user="<?= nawa_e($user['username']) ?>" data-current-quota="<?= nawa_e($quotaGb) ?>" data-current-expiry="<?= nawa_e($expiryText) ?>"><i class="bi bi-pencil-square"></i> تعديل</button><button class="nawa-button <?= (int) $user['disabled'] === 1 ? 'success' : 'danger' ?> small" type="button" data-toggle-user="<?= nawa_e($user['username']) ?>" data-toggle-disabled="<?= (int) $user['disabled'] === 1 ? '0' : '1' ?>"><i class="bi <?= (int) $user['disabled'] === 1 ? 'bi-play-circle' : 'bi-slash-circle' ?>"></i> <?= (int) $user['disabled'] === 1 ? 'تفعيل' : 'تعطيل' ?></button><button class="nawa-button danger small" type="button" data-delete-user="<?= nawa_e($user['username']) ?>"><i class="bi bi-trash3"></i> حذف</button></div></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
        <?php
$pages = max(1, (int) ceil($totalUsers / $pageSize));

if ($pages > 1):
    $fromPage = max(1, $page - 4);
    $toPage   = min($pages, $page + 4);
?>
<footer class="p-3 d-flex gap-2 justify-content-center flex-wrap">
    <?php if ($page > 1): ?>
        <button
            type="button"
            class="nawa-button small"
            data-users-page="<?= $page - 1 ?>"
            data-users-search-value="<?= nawa_e($search) ?>" data-users-sort-value="<?= nawa_e($sort) ?>" data-users-dir-value="<?= strtolower($direction) ?>" data-users-per-page-value="<?= $pageSize ?>"
        >السابق</button>
    <?php endif; ?>

    <?php for ($i = $fromPage; $i <= $toPage; $i++): ?>
        <button
            type="button"
            class="nawa-button small <?= $i === $page ? 'primary' : '' ?>"
            data-users-page="<?= $i ?>"
            data-users-search-value="<?= nawa_e($search) ?>" data-users-sort-value="<?= nawa_e($sort) ?>" data-users-dir-value="<?= strtolower($direction) ?>" data-users-per-page-value="<?= $pageSize ?>"
        ><?= $i ?></button>
    <?php endfor; ?>

    <?php if ($page < $pages): ?>
        <button
            type="button"
            class="nawa-button small"
            data-users-page="<?= $page + 1 ?>"
            data-users-search-value="<?= nawa_e($search) ?>" data-users-sort-value="<?= nawa_e($sort) ?>" data-users-dir-value="<?= strtolower($direction) ?>" data-users-per-page-value="<?= $pageSize ?>"
        >التالي</button>
    <?php endif; ?>
</footer>
<?php endif; ?>
    </section>
</div>

<div class="nawa-modal" id="settleModal" aria-hidden="true">
    <div class="nawa-modal-dialog nawa-modal-wide">
        <div class="nawa-modal-head"><div><h2>تسديد المستخدم <span data-modal-username></span></h2><small>اختر طريقة واحدة للتسديد</small></div><button type="button" data-modal-close aria-label="إغلاق"><i class="bi bi-x-lg"></i></button></div>
        <form method="post" data-settlement-form novalidate>
            <div class="nawa-modal-body">
                <input type="hidden" name="csrf_token" value="<?= nawa_e($csrf) ?>">
                <input type="hidden" name="action" value="settle">
                <input type="hidden" name="username" data-username-input>
                <input type="hidden" name="settlement_mode" value="package" data-settlement-mode>
                <?php $customSpeedOptions = nawa_speed_options($dbSocket); ?>

                <div class="nawa-segmented" role="tablist" aria-label="طريقة التسديد">
                    <button type="button" class="active" data-settlement-tab="package"><i class="bi bi-box-seam"></i> باقات</button>
                    <button type="button" data-settlement-tab="offer"><i class="bi bi-percent"></i> عروض</button>
                    <button type="button" data-settlement-tab="custom"><i class="bi bi-sliders"></i> مخصص</button>
                    <button type="button" data-settlement-tab="custom_package"><i class="bi bi-speedometer2"></i> باقات مخصصة</button>
                </div>

                
<section class="nawa-settlement-panel active" data-settlement-panel="package">

<div style="margin:0 0 16px">
    <input
        id="settlementPackageSearch"
        type="search"
        placeholder="ابحث عن الباقة..."
        autocomplete="off"
        style="width:100%;padding:14px 16px;border-radius:12px;border:1px solid #3b4252;background:#10141d;color:#fff;font-size:16px;box-sizing:border-box"
        oninput="
            const q=this.value.trim().toLowerCase();
            const panel=this.closest('section');
            let n=0;
            panel.querySelectorAll('input[name=package_id]').forEach(function(r){
                const card=r.closest('label');
                if(!card)return;
                const show=!q || (card.textContent||'').toLowerCase().includes(q);
                card.style.display=show?'':'none';
                if(show)n++;
            });
            const c=panel.querySelector('.package-search-count');
            if(c)c.textContent=q ? ('النتائج: '+n+' باقة') : '';
        "
    >
    <div class="package-search-count"
         style="margin-top:7px;font-size:13px;opacity:.7"></div>
</div>

                    <div class="nawa-panel-intro">
                        <b>الباقات المتاحة</b>
                        <small>اختر باقة وسيتم تطبيق القيقات والأيام مباشرة على المستخدم.</small>
                    </div>

                    <?php if (count($packages) === 0): ?>

                        <div class="nawa-empty compact">
                            لا توجد باقات متاحة للتسديد.
                        </div>

                    <?php else: ?>

                        
<div style="margin:16px 0">

    <label style="display:block;margin-bottom:8px;font-weight:700">
        اختر الباقة
    </label>

    <select
        name="package_id"
        id="packageSelect"
        required
        style="width:100%;padding:15px;border-radius:12px;border:1px solid #3b4252;background:#10141d;color:#fff;font-size:16px"
    >
        <option value="">-- اختر الباقة --</option>

        <?php foreach ($packages as $package):
            $packageGb = round(
                (float)$package['total_data'] / 1073741824,
                2
            );
        ?>
            <option value="<?= (int)$package['id'] ?>">
                <?= nawa_e($package['name']) ?>
                — <?= nawa_e(number_format($packageGb, 2)) ?> GB
                — <?= number_format((int)$package['validity_days']) ?> يوم
            </option>
        <?php endforeach; ?>
    </select>

</div>

<script>
(function () {
    var search = document.getElementById('settlementPackageSearch');
    var select = document.getElementById('packageSelect');

    if (!search || !select) return;

    var original = Array.from(select.options).map(function(o) {
        return {
            value: o.value,
            text: o.text
        };
    });

    search.addEventListener('input', function () {
        var q = this.value.trim().toLowerCase();
        var current = select.value;

        select.innerHTML = '';

        original.forEach(function (item) {
            if (
                item.value === '' ||
                !q ||
                item.text.toLowerCase().includes(q)
            ) {
                var o = document.createElement('option');
                o.value = item.value;
                o.textContent = item.text;
                select.appendChild(o);
            }
        });

        if ([...select.options].some(o => o.value === current)) {
            select.value = current;
        }
    });
})();
</script>


                    <?php endif; ?>
                </section>

                <section class="nawa-settlement-panel" data-settlement-panel="offer" hidden>
                    <div class="nawa-panel-intro"><b>العروض الفعالة</b><small>اختر الباقة ثم العرض؛ 150% تحول 70 قيقا إلى 105 قيقا.</small></div>
                    <?php if (count($offers) === 0): ?>
                        <div class="nawa-empty compact"><i class="bi bi-percent"></i>لا توجد عروض فعالة. <a href="nawa-offers.php">إضافة عرض</a></div>
                    <?php else: ?>
                        <div class="nawa-choice-grid">
                            <?php foreach ($offers as $offer):
                                $offerPercentage = rtrim(rtrim(number_format((float) $offer['percentage'], 2, '.', ''), '0'), '.');
                            ?><label class="nawa-choice-card offer"><input type="radio" name="offer_id" value="<?= (int) $offer['id'] ?>" data-offer-choice data-percentage="<?= nawa_e($offer['percentage']) ?>"><span><strong><?= nawa_e($offerPercentage) ?>%</strong><b><?= nawa_e($offer['offer_name']) ?></b><small><?= trim((string) $offer['notes']) !== '' ? nawa_e($offer['notes']) : 'عرض قيقات' ?></small></span></label><?php endforeach; ?>
                        </div>
                        <label class="nawa-field mt-3"><span>الباقة الأساسية</span><select name="offer_package_id" required data-offer-package><option value="">اختر الباقة</option><?php foreach($packages as $package): $offerBaseGb=round((float)$package['total_data']/1073741824,2); ?><option value="<?=(int)$package['id']?>" data-gb="<?=nawa_e($offerBaseGb)?>"><?=nawa_e($package['name'])?> — <?=number_format($offerBaseGb,2)?> GB — <?=number_format((int)$package['validity_days'])?> يوم</option><?php endforeach;?></select></label>
                        <div class="nawa-result-box"><span>الاستحقاق بعد العرض</span><strong data-offer-result>اختر عرضًا واكتب قيمة الإيداع</strong></div>
                    <?php endif; ?>
                </section>

                <section class="nawa-settlement-panel" data-settlement-panel="custom" hidden>
                    <div class="nawa-panel-intro"><b>تسديد مخصص</b><small>القيمة التي تكتبها هي إجمالي القيقات الجديدة؛ 50 تعني 50 قيقا.</small></div>
                    <label class="nawa-field"><span>عدد القيقات</span><div class="nawa-input-suffix"><input type="number" name="custom_gb" min="0.01" max="100000" step="0.01" inputmode="decimal" placeholder="50"><b>GB</b></div></label>
                </section>

                <section class="nawa-settlement-panel" data-settlement-panel="custom_package" hidden>
                    <div class="nawa-panel-intro"><b>باقات مخصصة</b><small>اختر الباقة ثم السرعة الخاصة بهذا المشترك. الجيجا والأيام والسعر تؤخذ من الباقة المختارة.</small></div>
                    <label class="nawa-field"><span>الباقة</span><select name="custom_package_id" data-custom-package><option value="">اختر الباقة</option><?php foreach($packages as $package): $customPackageGb=round((float)$package['total_data']/1073741824,2); ?><option value="<?=(int)$package['id']?>"><?=nawa_e($package['name'])?> — <?=number_format($customPackageGb,2)?> GB — <?=number_format((int)$package['validity_days'])?> يوم</option><?php endforeach;?></select></label>
                    <label class="nawa-field mt-3"><span>السرعة الخاصة</span><select name="custom_rate_limit" data-custom-rate><option value="">اختر السرعة</option><?php foreach($customSpeedOptions as $speed): ?><option value="<?=nawa_e($speed)?>"><?=nawa_e($speed)?></option><?php endforeach;?></select></label>
                    <?php if ($customSpeedOptions === []): ?><div class="nawa-empty compact mt-3"><i class="bi bi-speedometer2"></i>لا توجد سرعات معرفة في RADIUS.</div><?php endif; ?>
                </section>

                <label class="nawa-warning-box"><input type="checkbox" name="clear_current" value="1" checked><span><b>بدء دورة جديدة من الصفر</b><small>يُغلق احتساب الدورة الحالية ويبدأ الاستهلاك الجديد من وقت التسديد. سجل المحاسبة السابق يبقى محفوظًا.</small></span></label>
            </div>
            <div class="nawa-modal-foot"><button class="nawa-button primary" type="submit"><i class="bi bi-check2-circle"></i> تأكيد التسديد</button><button class="nawa-button" type="button" data-modal-close>إلغاء</button></div>
        </form>
    </div>
</div>

<div class="nawa-modal" id="adjustModal" aria-hidden="true">
    <div class="nawa-modal-dialog">
        <div class="nawa-modal-head"><div><h2>تعديل اشتراك <span data-modal-username></span></h2><small>إضافة فوق الاشتراك الحالي بدون تصفير الاستهلاك</small></div><button type="button" data-modal-close aria-label="إغلاق"><i class="bi bi-x-lg"></i></button></div>
        <form method="post">
            <div class="nawa-modal-body">
                <input type="hidden" name="csrf_token" value="<?= nawa_e($csrf) ?>">
                <input type="hidden" name="action" value="adjust">
                <input type="hidden" name="username" data-username-input>
                <div class="nawa-current-summary"><span><small>الحد الحالي</small><b data-adjust-current-quota>—</b></span><span><small>الصلاحية الحالية</small><b data-adjust-current-expiry>—</b></span></div>
                <div class="nawa-segmented mb-3" data-adjust-tabs>
                    <button type="button" data-adjust-tab="both">
                        <i class="bi bi-plus-circle"></i>
                        الأيام والقيقات معًا
                    </button>

                    <button type="button" data-adjust-tab="days">
                        <i class="bi bi-calendar-plus"></i>
                        زيادة أيام
                    </button>

                    <button type="button" data-adjust-tab="gb">
                        <i class="bi bi-database-add"></i>
                        زيادة قيقات
                    </button>

                    <button type="button" data-adjust-tab="subtract">
                        <i class="bi bi-dash-circle"></i>
                        خصم قيقات
                    </button>

                    <button type="button" data-adjust-tab="rename">
                        <i class="bi bi-person-badge"></i>
                        تغيير الرمز
                    </button>
                </div>

                <div class="nawa-form-grid">

                    <label class="nawa-field" data-adjust-panel="days" hidden>
                        <span>عدد الأيام المراد إضافتها</span>
                        <div class="nawa-input-suffix">
                            <input
                                type="number"
                                name="add_days"
                                min="1"
                                max="3650"
                                step="1"
                                inputmode="numeric"
                                placeholder="مثال: 10"
                                data-adjust-days-input
                                disabled
                            >
                            <b>يوم</b>
                        </div>
                        <small>تُضاف فوق تاريخ الصلاحية الحالي.</small>
                    </label>

                    <label class="nawa-field" data-adjust-panel="gb" hidden>
                        <span>عدد القيقات المراد إضافتها</span>
                        <div class="nawa-input-suffix">
                            <input
                                type="number"
                                name="add_gb"
                                min="0.01"
                                max="100000"
                                step="0.01"
                                inputmode="decimal"
                                placeholder="مثال: 10"
                                data-adjust-gb-input
                                disabled
                            >
                            <b>GB</b>
                        </div>
                        <small>تُضاف فوق الحد الحالي بدون تصفير الاستهلاك.</small>
                    </label>

                                        <label class="nawa-field" data-adjust-panel="subtract" hidden>
                        <span>عدد القيقات المراد خصمها</span>
                        <div class="nawa-input-suffix">
                            <input
                                type="number"
                                name="subtract_gb"
                                min="0.01"
                                max="100000"
                                step="0.01"
                                inputmode="decimal"
                                placeholder="مثال: 10"
                                data-adjust-subtract-input
                                disabled
                            >
                            <b>GB</b>
                        </div>
                        <small>سيتم خصمها من الحد والمتبقي بدون تغيير الاستهلاك المسجل.</small>
                    </label>

<label class="nawa-field full" data-adjust-panel="rename" hidden>
                        <span>الرمز الجديد</span>
                        <input
                            type="text"
                            name="new_username"
                            minlength="3"
                            maxlength="64"
                            autocomplete="off"
                            placeholder="اكتب الرمز الجديد"
                            data-adjust-rename-input
                            disabled
                        >
                        <small>سيصبح الرمز الجديد هو اسم المستخدم وكلمة المرور معًا.</small>
                    </label>

                </div>
                <div class="nawa-info-box"><i class="bi bi-info-circle"></i><span>يمكنك إضافة الأيام أو القيقات أو تغيير رمز المستخدم.</span></div>
            </div>
            <div class="nawa-modal-foot"><button class="nawa-button primary" type="submit"><i class="bi bi-check2-circle"></i> حفظ التعديل</button><button class="nawa-button" type="button" data-modal-close>إلغاء</button></div>
        </form>
    </div>
</div>


<div class="nawa-modal" id="speedModal" aria-hidden="true">
    <div class="nawa-modal-dialog">
        <div class="nawa-modal-head">
            <div>
                <h2>تحديد سرعة <span data-modal-username></span></h2>
                <small>اختر السرعة المطلوبة لهذا المشترك فقط</small>
            </div>
            <button type="button" data-modal-close aria-label="إغلاق">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <form method="post" data-speed-form>
            <div class="nawa-modal-body">
                <input type="hidden" name="csrf_token" value="<?= nawa_e($csrf) ?>">
                <input type="hidden" name="action" value="set_speed">
                <input type="hidden" name="username" data-username-input>

                <label class="nawa-field">
                    <span>سرعة الإنترنت</span>

                    <select name="speed" required data-speed-select>
                        <option value="">اختر السرعة</option>
                        <option value="512K">512 كيلو</option>
                        <option value="1M">1 ميقا</option>
                        <option value="2M">2 ميقا</option>
                        <option value="3M">3 ميقا</option>
                        <option value="4M">4 ميقا</option>
                        <option value="5M">5 ميقا</option>
                        <option value="open">مفتوح</option>
                    </select>
                </label>

                <div class="nawa-alert info mt-3">
                    <i class="bi bi-info-circle"></i>
                    <span>
                        تغيير السرعة لا يغيّر القيقات أو الباقة أو تاريخ الانتهاء.
                        إذا كان المشترك متصلاً فسيتم تحديث جلسته ليأخذ السرعة الجديدة.
                    </span>
                </div>
            </div>

            <div class="nawa-modal-foot">
                <button class="nawa-button primary" type="submit">
                    <i class="bi bi-check2-circle"></i>
                    تطبيق السرعة
                </button>

                <button class="nawa-button" type="button" data-modal-close>
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<div class="nawa-modal" id="toggleUserModal" aria-hidden="true">
    <div class="nawa-modal-dialog">
        <div class="nawa-modal-head"><h2><span data-toggle-title>تعطيل المشترك</span> <span data-modal-username></span>؟</h2><button type="button" data-modal-close><i class="bi bi-x-lg"></i></button></div>
        <form method="post">
            <div class="nawa-modal-body">
                <input type="hidden" name="csrf_token" value="<?= nawa_e($csrf) ?>">
                <input type="hidden" name="action" value="toggle_disabled">
                <input type="hidden" name="username" data-username-input>
                <input type="hidden" name="disabled" value="1" data-disabled-input>
                <div class="nawa-alert warning mb-0" data-toggle-message><i class="bi bi-slash-circle"></i><span>سيُرفض دخول هذا المشترك فعليًا من FreeRADIUS حتى تعيد تفعيله.</span></div>
            </div>
            <div class="nawa-modal-foot"><button class="nawa-button danger" type="submit" data-toggle-submit>نعم، تعطيل المشترك</button><button class="nawa-button" type="button" data-modal-close>إلغاء</button></div>
        </form>
    </div>
</div>

<div class="nawa-modal" id="deleteModal" aria-hidden="true"><div class="nawa-modal-dialog"><div class="nawa-modal-head"><h2>حذف المستخدم <span data-modal-username></span>؟</h2><button type="button" data-modal-close><i class="bi bi-x-lg"></i></button></div><form method="post"><div class="nawa-modal-body"><input type="hidden" name="csrf_token" value="<?= nawa_e($csrf) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="username" data-username-input><div class="nawa-alert danger mb-0"><i class="bi bi-exclamation-triangle"></i><span>سيُحذف حساب الدخول وارتباط الباقة. سيبقى سجل المحاسبة محفوظًا.</span></div></div><div class="nawa-modal-foot"><button class="nawa-button danger" type="submit">نعم، حذف المستخدم</button><button class="nawa-button" type="button" data-modal-close>إلغاء</button></div></form></div></div>

<?php
$inlineJs = <<<'JS'
document.querySelectorAll('[data-settle-user]').forEach(function (button) {
  button.addEventListener('click', function () {
    var form = document.querySelector('[data-settlement-form]');
    form.reset(); 
// NAWA_PACKAGE_CLICK_FIX_V1
document.addEventListener('click', function (event) {
    var card = event.target.closest('label');
    if (!card) return;

    var radio = card.querySelector('input[type="radio"][name="package_id"]');
    if (!radio) return;

    radio.checked = true;
    radio.dispatchEvent(new Event('change', { bubbles: true }));
});

setSettlementMode('package'); updateOfferResult();
    openNawaModal('settleModal', button.dataset.settleUser);
  });
});

function setAdjustMode(mode) {
    var modal = document.getElementById('adjustModal');
    if (!modal) return;

    var actionInput = modal.querySelector('input[name="action"]');
    if (actionInput) {
        actionInput.value = mode === 'rename' ? 'rename' : 'adjust';
    }

    modal.querySelectorAll('[data-adjust-tab]').forEach(function(btn) {
        btn.classList.toggle('active', btn.dataset.adjustTab === mode);
    });

    modal.querySelectorAll('[data-adjust-panel]').forEach(function(panel) {
        var active =
            (mode === 'both' && (
                panel.dataset.adjustPanel === 'days' ||
                panel.dataset.adjustPanel === 'gb'
            )) ||
            (panel.dataset.adjustPanel === mode);
        panel.hidden = !active;

        panel.querySelectorAll('input').forEach(function(input) {
            input.disabled = !active;
            input.required = false;
            if (!active) input.value = '';
        });
    });
}

document.querySelectorAll('[data-adjust-tab]').forEach(function(button) {
    button.addEventListener('click', function() {
        setAdjustMode(button.dataset.adjustTab);

        var modal = document.getElementById('adjustModal');
        var target = null;

        if (button.dataset.adjustTab === 'rename') {
            target = modal.querySelector('[data-adjust-rename-input]');
        } else if (button.dataset.adjustTab === 'subtract') {
            target = modal.querySelector('[data-adjust-subtract-input]');
        } else if (button.dataset.adjustTab === 'gb') {
            target = modal.querySelector('[data-adjust-gb-input]');
        } else {
            target = modal.querySelector('[data-adjust-days-input]');
        }

        if (target) target.focus();
    });
});

document.querySelectorAll('[data-adjust-user]').forEach(function (button) {
  button.addEventListener('click', function () {
    var modal = document.getElementById('adjustModal');
    modal.querySelector('form').reset();
    setAdjustMode(null);
    modal.querySelector('[data-adjust-current-quota]').textContent = Number(button.dataset.currentQuota) > 0 ? Number(button.dataset.currentQuota).toLocaleString('ar', { maximumFractionDigits: 2 }) + ' GB' : 'غير محدد';
    modal.querySelector('[data-adjust-current-expiry]').textContent = button.dataset.currentExpiry;
    openNawaModal('adjustModal', button.dataset.adjustUser);
  });
});

document.querySelectorAll('[data-speed-user]').forEach(function (button) {
    button.addEventListener('click', function () {
        var modal = document.getElementById('speedModal');

        if (!modal) {
            return;
        }

        var form = modal.querySelector('[data-speed-form]');
        var select = modal.querySelector('[data-speed-select]');

        if (form) {
            form.reset();
        }

        if (select) {
            select.value = '';
        }

        openNawaModal(
            'speedModal',
            button.dataset.speedUser
        );

        if (select) {
            setTimeout(function () {
                select.focus();
            }, 50);
        }
    });
});


document.querySelectorAll('[data-toggle-user]').forEach(function (button) {
  button.addEventListener('click', function () {
    var modal = document.getElementById('toggleUserModal');
    var disabling = button.dataset.toggleDisabled === '1';
    modal.querySelector('[data-disabled-input]').value = disabling ? '1' : '0';
    modal.querySelector('[data-toggle-title]').textContent = disabling ? 'تعطيل المشترك' : 'تفعيل المشترك';
    modal.querySelector('[data-toggle-message] span').textContent = disabling
      ? 'سيُرفض دخول هذا المشترك فعليًا من FreeRADIUS حتى تعيد تفعيله.'
      : 'سيُسمح للمشترك بتسجيل الدخول مجددًا باستخدام بياناته الحالية.';
    var submit = modal.querySelector('[data-toggle-submit]');
    submit.textContent = disabling ? 'نعم، تعطيل المشترك' : 'نعم، تفعيل المشترك';
    submit.classList.toggle('danger', disabling);
    submit.classList.toggle('success', !disabling);
    openNawaModal('toggleUserModal', button.dataset.toggleUser);
  });
});
document.querySelectorAll('[data-delete-user]').forEach(function (button) {
  button.addEventListener('click', function () { openNawaModal('deleteModal', button.dataset.deleteUser); });
});
document.querySelectorAll('[data-settlement-tab]').forEach(function (button) {
  button.addEventListener('click', function () { setSettlementMode(button.dataset.settlementTab); });
});

// NAWA_CUSTOM_SETTLEMENT_SUBMIT_FIX
(function () {
    var form = document.querySelector('[data-settlement-form]');
    if (!form || form.dataset.customSubmitFixed === '1') return;

    form.dataset.customSubmitFixed = '1';

    form.addEventListener('submit', function (event) {
        var modeInput = form.querySelector('[data-settlement-mode]');
        var mode = modeInput ? modeInput.value : 'package';

        // أي قسم مخفي: تعطيل كل حقوله وإلغاء required
        form.querySelectorAll('[data-settlement-panel]').forEach(function (panel) {
            var active = panel.dataset.settlementPanel === mode;

            panel.querySelectorAll('input, select, textarea').forEach(function (input) {
                if (!active) {
                    input.required = false;
                    input.disabled = true;
                }
            });
        });

        // التسديد المخصص
        if (mode === 'custom') {
            var custom = form.querySelector('input[name="custom_gb"]');

            if (!custom) {
                event.preventDefault();
                alert('حقل عدد القيقات غير موجود.');
                return;
            }

            custom.disabled = false;
            custom.required = false;

            var value = Number(custom.value);

            if (!Number.isFinite(value) || value <= 0) {
                event.preventDefault();
                custom.focus();
                alert('اكتب عدد القيقات أولاً.');
                return;
            }
        }

        if (mode === 'custom_package') {
            var customPackage = form.querySelector('[data-custom-package]');
            var customRate = form.querySelector('[data-custom-rate]');

            if (!customPackage || !customPackage.value) {
                event.preventDefault();
                if (customPackage) customPackage.focus();
                alert('اختر الباقة أولاً.');
                return;
            }

            if (!customRate || !customRate.value) {
                event.preventDefault();
                if (customRate) customRate.focus();
                alert('اختر السرعة أولاً.');
                return;
            }

            customPackage.disabled = false;
            customRate.disabled = false;
        }
    });
})();

function setSettlementMode(mode) {
  var form = document.querySelector('[data-settlement-form]');
  form.querySelector('[data-settlement-mode]').value = mode;
  form.querySelectorAll('[data-settlement-tab]').forEach(function (tab) { tab.classList.toggle('active', tab.dataset.settlementTab === mode); });
  form.querySelectorAll('[data-settlement-panel]').forEach(function (panel) {
    var active = panel.dataset.settlementPanel === mode;
    panel.hidden = !active; panel.classList.toggle('active', active);
    panel.querySelectorAll('input,select,textarea').forEach(function (input) { input.disabled = !active; input.required = false; });
    if (active) {
      var radio = panel.querySelector('input[type="radio"]');
      if (radio) radio.required = true;
      var number = panel.querySelector('input[type="number"]');
      if (number) {
          number.disabled = false;
          number.required = true;
      }
      var select = panel.querySelector('select');
      if (select) { select.disabled = false; select.required = true; }
    }
  });
}
function updateOfferResult() {
  var selected = document.querySelector('[data-offer-choice]:checked');
  var base = document.querySelector('[data-offer-package]');
  var result = document.querySelector('[data-offer-result]');
  if (!result) return;
  var baseOption = base && base.options ? base.options[base.selectedIndex] : null;
  var baseValue = baseOption ? Number(baseOption.dataset.gb || 0) : 0;
  var percentage = selected ? Number(selected.dataset.percentage) : 0;
  result.textContent = baseValue > 0 && percentage > 0
    ? (baseValue * percentage / 100).toLocaleString('ar', { maximumFractionDigits: 2 }) + ' قيقا'
    : 'اختر الباقة والعرض';
}
document.querySelectorAll('[data-offer-choice]').forEach(function (input) { input.addEventListener('change', updateOfferResult); });
var offerPackageInput = document.querySelector('[data-offer-package]');
if (offerPackageInput) offerPackageInput.addEventListener('change', updateOfferResult);
function openNawaModal(id, username) {
  var modal = document.getElementById(id);
  modal.querySelectorAll('[data-modal-username]').forEach(function (el) { el.textContent = username; });
  modal.querySelectorAll('[data-username-input]').forEach(function (el) { el.value = username; });
  modal.classList.add('show'); modal.setAttribute('aria-hidden', 'false');
}
document.querySelectorAll('[data-modal-close]').forEach(function (button) {
  button.addEventListener('click', function () { var modal = button.closest('.nawa-modal'); modal.classList.remove('show'); modal.setAttribute('aria-hidden', 'true'); });
});
document.querySelectorAll('.nawa-modal').forEach(function (modal) { modal.addEventListener('click', function (event) { if (event.target === modal) { modal.classList.remove('show'); modal.setAttribute('aria-hidden', 'true'); } }); });
document.addEventListener('keydown', function (event) { if (event.key === 'Escape') { document.querySelectorAll('.nawa-modal.show').forEach(function (modal) { modal.classList.remove('show'); modal.setAttribute('aria-hidden', 'true'); }); } });
setSettlementMode('package');
var subscriberTraffic = document.querySelector('[data-subscriber-traffic]');
if (subscriberTraffic) {
  var periodValues = {};
  try { periodValues = JSON.parse(subscriberTraffic.dataset.periodValues || '{}'); } catch (error) {}
  var usersPage = subscriberTraffic.closest('.nawa-page') || document;
  var formatStatNumber = function(value) { return Number(value || 0).toLocaleString('en-US'); };
  var formatStatGb = function(value) { return (Number(value || 0) / 1073741824).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' GB'; };
  fetch('nawa-users.php?stats_only=1', { credentials: 'same-origin', cache: 'no-store' })
    .then(function(response) { if (!response.ok) throw new Error('HTTP ' + response.status); return response.json(); })
    .then(function(stats) {
      if (!stats || !stats.ok || !subscriberTraffic.isConnected) return;
      var setText = function(selector, value) { var node = usersPage.querySelector(selector); if (node) node.textContent = value; };
      setText('[data-users-stat="online"]', formatStatNumber(stats.online));
      setText('[data-users-stat="settled"]', formatStatNumber(stats.settled));
      setText('[data-users-stat="expired"]', formatStatNumber(stats.expired));
      setText('[data-users-stat="quota"]', formatStatGb(stats.current && stats.current.quota_bytes));
      setText('[data-users-stat="remaining"]', formatStatGb(stats.current && stats.current.remaining_bytes));
      periodValues = Object.assign(periodValues, stats.periods || {});
      var activePeriod = subscriberTraffic.querySelector('[data-usage-period].active');
      if (activePeriod) setText('[data-period-used]', formatStatGb(periodValues[activePeriod.dataset.usagePeriod]));
    })
    .catch(function() {});
  var periodNames = { day: 'اليوم', week: 'آخر 7 أيام', month: 'آخر 30 يومًا', quarter: 'آخر 3 أشهر' };
  subscriberTraffic.querySelectorAll('[data-usage-period]').forEach(function (button) {
    button.addEventListener('click', function () {
      subscriberTraffic.querySelectorAll('[data-usage-period]').forEach(function (item) { item.classList.toggle('active', item === button); });
      var bytes = Number(periodValues[button.dataset.usagePeriod] || 0);
      subscriberTraffic.querySelector('[data-period-used]').textContent = (bytes / 1073741824).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' GB';
      subscriberTraffic.querySelector('[data-period-label]').textContent = periodNames[button.dataset.usagePeriod] || '';
    });
  });
}
JS;
include '../common/includes/db_close.php';

if ($fragmentMode) {
    echo '<script>' . $inlineJs . '</script>';
} else {
    print_footer_and_html_epilogue($inlineJs);
}
?>


<!-- NAWA_PACKAGE_SEARCH_V1 -->
<script>
(function () {
    function installPackageSearch() {
        const radios = document.querySelectorAll('input[type="radio"][name="package_id"]');
        if (!radios.length) return;

        if (document.getElementById('nawaPackageSearch')) return;

        const firstCard = radios[0].closest('label');
        if (!firstCard) return;

        const box = document.createElement('div');
        box.style.margin = '0 0 16px 0';
        box.innerHTML = `
            <input
                type="search"
                id="nawaPackageSearch"
                placeholder="ابحث عن الباقة بالاسم أو الجيجا أو الأيام..."
                autocomplete="off"
                style="width:100%;padding:14px 16px;border-radius:12px;border:1px solid #3b4252;background:#10141d;color:#fff;font-size:16px;box-sizing:border-box"
            >
            <div id="nawaPackageSearchCount"
                 style="margin-top:7px;font-size:13px;opacity:.7"></div>
        `;

        firstCard.parentNode.insertBefore(box, firstCard);

        const input = document.getElementById('nawaPackageSearch');

        input.addEventListener('input', function () {
            const q = this.value.trim().toLowerCase();
            let visible = 0;

            radios.forEach(function (radio) {
                const card = radio.closest('label');
                if (!card) return;

                const text = (card.textContent || '').toLowerCase();
                const show = !q || text.includes(q);

                card.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            document.getElementById('nawaPackageSearchCount').textContent =
                q ? 'النتائج: ' + visible + ' باقة' : '';
        });
    }

    installPackageSearch();
    setTimeout(installPackageSearch, 300);
})();
</script>
