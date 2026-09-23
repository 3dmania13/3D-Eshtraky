<?php
@ini_set('pcre.jit', '0');
include("library/checklogin.php");
include_once("../common/includes/config_read.php");
include("../common/includes/db_open.php");

$operator = $_SESSION['operator_user'] ?? 'admin';
$operatorInitial = mb_strtoupper(mb_substr((string) $operator, 0, 1));
$rebootCsrfToken = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token'])
    ? $_SESSION['csrf_token']
    : dalo_csrf_token();

function scalar_value($db, $sql, $default = 0) {
    $res = $db->query($sql);
    if (DB::isError($res)) return $default;
    $row = $res->fetchRow();
    return isset($row[0]) ? $row[0] : $default;
}

$operatorId = (int) ($_SESSION['operator_id'] ?? 0);
$tdnAdminAllowed = $operatorId > 0 && (int) scalar_value(
    $dbSocket,
    sprintf(
        "SELECT access FROM %s WHERE operator_id=%d AND file='tdn_admin' LIMIT 1",
        $configValues['CONFIG_DB_TBL_DALOOPERATORS_ACL'],
        $operatorId
    ),
    0
) === 1;

$pointsAdminAllowed = $operatorId > 0 && (int) scalar_value(
    $dbSocket,
    sprintf(
        "SELECT access FROM %s WHERE operator_id=%d AND file='points_admin' LIMIT 1",
        $configValues['CONFIG_DB_TBL_DALOOPERATORS_ACL'],
        $operatorId
    ),
    0
) === 1;

function dashboard_rows($db, $sql) {
    $result = $db->query($sql);
    if (DB::isError($result) || !is_object($result)) return [];
    $rows = [];
    while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) $rows[] = $row;
    return $rows;
}

function dashboard_row($db, $sql, array $default = []) {
    $rows = dashboard_rows($db, $sql);
    return isset($rows[0]) ? $rows[0] : $default;
}

function dashboard_e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function dashboard_bytes($bytes) {
    $bytes = max(0, (float) $bytes);
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $index = 0;
    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }
    return number_format($bytes, $index >= 3 ? 2 : 1) . ' ' . $units[$index];
}

function dashboard_duration($seconds) {
    $seconds = max(0, (int) $seconds);
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($days > 0) return sprintf('%d يوم و%d ساعة', $days, $hours);
    if ($hours > 0) return sprintf('%d ساعة و%d دقيقة', $hours, $minutes);
    return sprintf('%d دقيقة', $minutes);
}

function dashboard_percent($part, $total, $minimum = 0) {
    if ((float) $total <= 0) return 0;
    return min(100, max((float) $minimum, round(((float) $part / (float) $total) * 100, 1)));
}

// Account classification is refreshed by the report rollup worker. These
// counts are non-financial and may be a few minutes old; customer operations
// and lists below remain authoritative and live.
$cachedAccountStats = dashboard_row(
    $dbSocket,
    "SELECT total_users,total_cards,total_subscribers
     FROM nawa_report_state
     WHERE id=1 AND counts_refreshed_at>=NOW()-INTERVAL 10 MINUTE",
    []
);
if ($cachedAccountStats !== []) {
    $totalAccounts = (int) $cachedAccountStats['total_users'];
    $totalCards = (int) $cachedAccountStats['total_cards'];
    $totalSubscribers = (int) $cachedAccountStats['total_subscribers'];
} else {
    $totalAccounts = (int) scalar_value($dbSocket, 'SELECT COUNT(*) FROM userinfo');
    $packageCardAccounts = (int) scalar_value(
        $dbSocket,
        'SELECT COUNT(*) FROM userinfo WHERE package_id IN (53,54,55,56,57,58)'
    );
    $extraBatchCards = (int) scalar_value(
        $dbSocket,
        "SELECT COUNT(DISTINCT ubi.username)
         FROM userbillinfo ubi
         JOIN userinfo ui ON ui.username=ubi.username
         WHERE ubi.batch_id IS NOT NULL
           AND (ui.package_id IS NULL OR ui.package_id NOT IN (53,54,55,56,57,58))"
    );
    $totalCards = $packageCardAccounts + $extraBatchCards;
    $totalSubscribers = max(0, $totalAccounts - $totalCards);
}

$liveStats = dashboard_row(
    $dbSocket,
    "SELECT live_total,live_subscribers,live_cards,live_nas,live_bytes
     FROM nawa_report_state
     WHERE id=1 AND online_refreshed_at>=NOW()-INTERVAL 4 MINUTE",
    []
);
if ($liveStats === []) {
    // Safety fallback if the background rollup has not completed yet.
    $liveStats = dashboard_row(
        $dbSocket,
        "SELECT COUNT(*) live_total,
                COALESCE(SUM(account_type='subscriber'),0) live_subscribers,
                COALESCE(SUM(account_type='card'),0) live_cards,
                COUNT(DISTINCT nasipaddress) live_nas,
                COALESCE(SUM(session_bytes),0) live_bytes
         FROM (
             SELECT ra.username,ra.nasipaddress,
                    COALESCE(ra.total_octets64,COALESCE(ra.acctinputoctets,0)+COALESCE(ra.acctoutputoctets,0)) session_bytes,
                    IF(cb.username IS NOT NULL OR ui.package_id IN (53,54,55,56,57,58),'card','subscriber') account_type
             FROM (
                 SELECT username,MAX(radacctid) latest_radacctid
                 FROM radacct
                 WHERE acctstoptime IS NULL
                   AND COALESCE(acctupdatetime,acctstarttime)>=NOW()-INTERVAL 15 MINUTE
                 GROUP BY username
             ) latest
             JOIN radacct ra ON ra.radacctid=latest.latest_radacctid
             LEFT JOIN userinfo ui ON ui.username=ra.username
             LEFT JOIN (SELECT username FROM userbillinfo WHERE batch_id IS NOT NULL GROUP BY username) cb ON cb.username=ra.username
         ) live_fallback",
        ['live_total' => 0, 'live_subscribers' => 0, 'live_cards' => 0, 'live_nas' => 0, 'live_bytes' => 0]
    );
}

$liveTotal = (int) scalar_value($dbSocket, "SELECT COALESCE(SUM(hs.active_count),0) FROM communication_networks n JOIN communication_hotspot_status hs ON hs.network_id=n.id WHERE n.network_key IN ('network1','network2','network3','network4','network5','network6') AND hs.status='live' AND hs.source='routeros_ip_hotspot_active' AND hs.checked_at>=NOW()-INTERVAL 5 MINUTE");
$liveSubscribers = (int) $liveStats['live_subscribers'];
$liveCards = (int) $liveStats['live_cards'];
$activeNas = (int) $liveStats['live_nas'];
$liveBytes = (float) $liveStats['live_bytes'];
$totalNas = (int) scalar_value($dbSocket, 'SELECT COUNT(*) FROM nas');
$offlineNas = max(0, $totalNas - $activeNas);
$onlineNetworkDevices = (int) scalar_value(
    $dbSocket,
    "SELECT COUNT(*) FROM communication_devices
     WHERE network_id IS NOT NULL
       AND device_category IN ('general_modem','wireless','subscriber')
       AND status='online'
       AND last_discovery>=NOW()-INTERVAL 2 MINUTE"
);

$dailyIncomeRows = dashboard_rows(
    $dbSocket,
    "SELECT income_day,
            SUM(operations_count) operations_count,
            SUM(income_total) income_total
     FROM (

         /* 1) Subscriber package settlements */
         SELECT
             DATE(a.created_at) income_day,
             COUNT(*) operations_count,
             COALESCE(SUM(
                 CASE
                     WHEN p.sell_price > 0 THEN p.sell_price
                     WHEN p.price > 0 THEN p.price
                     ELSE CAST(
                         REGEXP_SUBSTR(COALESCE(p.name,''),'[0-9]+')
                         AS DECIMAL(12,2)
                     )
                 END
             ),0) income_total
         FROM nawa_audit_log a
         JOIN packages p
           ON p.id=CAST(
               JSON_UNQUOTE(
                   JSON_EXTRACT(a.details,'$.package_id')
               ) AS UNSIGNED
           )
         WHERE a.action_name='user.package_settle'
           AND a.created_at>=DATE_SUB(CURDATE(),INTERVAL 1 YEAR)
         GROUP BY DATE(a.created_at)

         UNION ALL

         /* 2) Card first activation only - cached */
         SELECT
             DATE(c.first_session) income_day,
             COUNT(*) operations_count,
             COALESCE(SUM(c.amount_yer),0) income_total
         FROM nawa_card_first_activation c
         WHERE c.first_session>=DATE_SUB(CURDATE(),INTERVAL 1 YEAR)
         GROUP BY DATE(c.first_session)

         UNION ALL

         /* 3) POS / 3D Net wallet credit */
         SELECT
             DATE(w.created_at) income_day,
             COUNT(*) operations_count,
             COALESCE(SUM(w.amount_yer),0) income_total
         FROM three_d_net_wallet_ledger w
         WHERE w.entry_type='ADMIN_CREDIT'
           AND w.amount_yer>0
           AND w.created_at>=DATE_SUB(CURDATE(),INTERVAL 1 YEAR)
         GROUP BY DATE(w.created_at)

         UNION ALL

         /* 4) PPPoE / Broadband packages */
         SELECT
             DATE(r.created_at) income_day,
             COUNT(*) operations_count,
             COALESCE(SUM(
                 CASE
                     WHEN r.price>0 THEN r.price
                     WHEN pp.price>0 THEN pp.price
                     ELSE 0
                 END
             ),0) income_total
         FROM nawa_pppoe_recharges r
         LEFT JOIN nawa_pppoe_packages pp
           ON pp.id=r.package_id
         WHERE r.action_type IN (
                 'initial',
                 'recharge',
                 'renew',
                 'package_change'
               )
           AND r.created_at>=DATE_SUB(CURDATE(),INTERVAL 1 YEAR)
         GROUP BY DATE(r.created_at)

     ) income_sources
     GROUP BY income_day
     ORDER BY income_day"
);

$hourlyIncomeRows = dashboard_rows(
    $dbSocket,
    "SELECT income_hour,
            SUM(operations_count) operations_count,
            SUM(income_total) income_total
     FROM (

         /* 1) Subscriber package settlements */
         SELECT
             HOUR(a.created_at) income_hour,
             COUNT(*) operations_count,
             COALESCE(SUM(
                 CASE
                     WHEN p.sell_price > 0 THEN p.sell_price
                     WHEN p.price > 0 THEN p.price
                     ELSE CAST(
                         REGEXP_SUBSTR(COALESCE(p.name,''),'[0-9]+')
                         AS DECIMAL(12,2)
                     )
                 END
             ),0) income_total
         FROM nawa_audit_log a
         JOIN packages p
           ON p.id=CAST(
               JSON_UNQUOTE(
                   JSON_EXTRACT(a.details,'$.package_id')
               ) AS UNSIGNED
           )
         WHERE a.action_name='user.package_settle'
           AND a.created_at>=CURDATE()
           AND a.created_at<CURDATE()+INTERVAL 1 DAY
         GROUP BY HOUR(a.created_at)

         UNION ALL

         /* 2) Card first activation only - cached */
         SELECT
             HOUR(c.first_session) income_hour,
             COUNT(*) operations_count,
             COALESCE(SUM(c.amount_yer),0) income_total
         FROM nawa_card_first_activation c
         WHERE c.first_session>=CURDATE()
           AND c.first_session<CURDATE()+INTERVAL 1 DAY
         GROUP BY HOUR(c.first_session)

         UNION ALL

         /* 3) POS / 3D Net wallet credit */
         SELECT
             HOUR(w.created_at) income_hour,
             COUNT(*) operations_count,
             COALESCE(SUM(w.amount_yer),0) income_total
         FROM three_d_net_wallet_ledger w
         WHERE w.entry_type='ADMIN_CREDIT'
           AND w.amount_yer>0
           AND w.created_at>=CURDATE()
           AND w.created_at<CURDATE()+INTERVAL 1 DAY
         GROUP BY HOUR(w.created_at)

         UNION ALL

         /* 4) PPPoE / Broadband packages */
         SELECT
             HOUR(r.created_at) income_hour,
             COUNT(*) operations_count,
             COALESCE(SUM(
                 CASE
                     WHEN r.price>0 THEN r.price
                     WHEN pp.price>0 THEN pp.price
                     ELSE 0
                 END
             ),0) income_total
         FROM nawa_pppoe_recharges r
         LEFT JOIN nawa_pppoe_packages pp
           ON pp.id=r.package_id
         WHERE r.action_type IN (
                 'initial',
                 'recharge',
                 'renew',
                 'package_change'
               )
           AND r.created_at>=CURDATE()
           AND r.created_at<CURDATE()+INTERVAL 1 DAY
         GROUP BY HOUR(r.created_at)

     ) income_sources
     GROUP BY income_hour
     ORDER BY income_hour"
);

$recentBatches = dashboard_rows(
    $dbSocket,
    "SELECT bh.id,bh.batch_name,bh.batch_status,bh.creationdate,bh.creationby,
            COUNT(DISTINCT ubi.username) card_count
     FROM (
         SELECT id,batch_name,batch_status,creationdate,creationby
         FROM batch_history ORDER BY id DESC LIMIT 5
     ) bh
     LEFT JOIN userbillinfo ubi ON ubi.batch_id=bh.id
     GROUP BY bh.id,bh.batch_name,bh.batch_status,bh.creationdate,bh.creationby
     ORDER BY bh.id DESC"
);

$backupSettings = dashboard_row($dbSocket, 'SELECT * FROM nawa_backup_settings WHERE id=1', []);
$connectedClouds = (int) scalar_value(
    $dbSocket,
    'SELECT COUNT(*) FROM nawa_backup_cloud_accounts WHERE is_connected=1'
);
$backupPath = rtrim((string) ($configValues['CONFIG_PATH_DALO_VARIABLE_DATA'] ?? ''), '/\\') . '/backup';
$backupFiles = [];
if (is_dir($backupPath)) {
    foreach ((array) glob($backupPath . '/*') as $candidate) {
        if (is_file($candidate) && substr(basename($candidate), 0, 1) !== '.') $backupFiles[] = $candidate;
    }
    usort($backupFiles, function ($a, $b) { return filemtime($b) <=> filemtime($a); });
}
$lastBackupPath = $backupFiles[0] ?? '';
$lastBackupAt = $lastBackupPath !== '' ? date('Y-m-d H:i', filemtime($lastBackupPath)) : 'لم تُنشأ بعد';
$lastBackupSize = $lastBackupPath !== '' ? dashboard_bytes(filesize($lastBackupPath)) : '—';
$backupMode = (($backupSettings['backup_mode'] ?? 'manual') === 'automatic') ? 'تلقائي' : 'يدوي';
$activeProviderLabels = ['onedrive' => 'OneDrive', 'google_drive' => 'Google Drive', 'dropbox' => 'Dropbox'];
$activeProvider = (string) ($backupSettings['active_provider'] ?? '');
$activeProviderLabel = $activeProviderLabels[$activeProvider] ?? 'غير محدد';

$uptimeSeconds = 0;
$uptimeAvailable = false;
if (is_readable('/proc/uptime')) {
    $uptimeParts = explode(' ', trim((string) file_get_contents('/proc/uptime')));
    $uptimeSeconds = (int) ((float) ($uptimeParts[0] ?? 0));
    $uptimeAvailable = $uptimeSeconds > 0;
}
$rawLoadAverage = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
$loadAverage = is_array($rawLoadAverage) ? $rawLoadAverage : [0, 0, 0];

$cpuCores = 1;
if (is_readable('/proc/cpuinfo')) {
    preg_match_all('/^processor\s*:/m', (string) file_get_contents('/proc/cpuinfo'), $cpuMatches);
    $cpuCores = max(1, count($cpuMatches[0] ?? []));
} elseif (function_exists('shell_exec') && is_executable('/usr/bin/nproc')) {
    $detectedCores = (int) trim((string) @shell_exec('/usr/bin/nproc 2>/dev/null'));
    if ($detectedCores > 0) $cpuCores = $detectedCores;
}

/*
 * Real Linux CPU utilisation.
 *
 * /proc/stat exposes cumulative CPU counters, so take two samples and
 * calculate the percentage of non-idle time between them.
 *
 * Load average is intentionally kept separate because it measures runnable /
 * waiting workload, not actual CPU utilisation.
 */
$readCpuStat = static function (): ?array {
    if (!is_readable('/proc/stat')) {
        return null;
    }

    $fh = @fopen('/proc/stat', 'r');
    if (!$fh) {
        return null;
    }

    $line = fgets($fh);
    fclose($fh);

    if (!is_string($line) || !preg_match('/^cpu\s+(.+)$/', trim($line), $m)) {
        return null;
    }

    $values = preg_split('/\s+/', trim($m[1]));
    $values = array_map('floatval', $values);

    /*
     * Linux fields:
     * user nice system idle iowait irq softirq steal guest guest_nice
     */
    $idle = ($values[3] ?? 0) + ($values[4] ?? 0);
    $total = array_sum(array_slice($values, 0, 8));

    return [
        'idle' => $idle,
        'total' => $total,
    ];
};

$cpuPercent = 0.0;
$cpuAvailable = false;

$cpuSample1 = $readCpuStat();

if ($cpuSample1 !== null) {
    usleep(200000);
    $cpuSample2 = $readCpuStat();

    if ($cpuSample2 !== null) {
        $totalDelta = $cpuSample2['total'] - $cpuSample1['total'];
        $idleDelta = $cpuSample2['idle'] - $cpuSample1['idle'];

        if ($totalDelta > 0) {
            $cpuPercent = round(
                max(0, min(100, (($totalDelta - $idleDelta) / $totalDelta) * 100)),
                1
            );
            $cpuAvailable = true;
        }
    }
}
$diskTotal = (float) @disk_total_space('/');
$diskFree = (float) @disk_free_space('/');
$diskAvailable = $diskTotal > 0;
$diskPercent = $diskTotal > 0 ? round((1 - ($diskFree / $diskTotal)) * 100, 1) : 0;
$healthResources = [];
if ($cpuAvailable) $healthResources[] = $cpuPercent;
if ($diskAvailable) $healthResources[] = $diskPercent;
$healthPressure = $healthResources === [] ? 100 : array_sum($healthResources) / count($healthResources);
$healthScore = (int) round(max(0, 100 - ($healthPressure / 2)));
$healthLabel = $healthScore >= 85 ? 'ممتاز' : ($healthScore >= 70 ? 'جيد' : 'يحتاج متابعة');
$uptimeText = $uptimeAvailable ? dashboard_duration($uptimeSeconds) : 'غير متاح من خدمة الويب';

$alertCount = 0;
if ($offlineNas > 0) $alertCount++;
if ($lastBackupPath === '') $alertCount++;
if ($connectedClouds === 0) $alertCount++;
$persistedAlertCount = (int) scalar_value(
    $dbSocket,
    "SELECT COUNT(*) FROM nawa_alert_state WHERE status='active'",
    -1
);
if ($persistedAlertCount >= 0) $alertCount = $persistedAlertCount;

include("../common/includes/db_close.php");

$totalUsersFmt = number_format($totalAccounts);
$totalSubscribersFmt = number_format($totalSubscribers);
$totalCardsFmt = number_format($totalCards);
$liveTotalFmt = number_format($liveTotal);
$liveSubscribersFmt = number_format($liveSubscribers);
$liveCardsFmt = number_format($liveCards);
$activeNasFmt = number_format($activeNas);
$totalNasFmt = number_format($totalNas);
$onlineNetworkDevicesFmt = number_format($onlineNetworkDevices);

$todayArabic = date('Y-m-d H:i');
$incomeByDay = [];
$operationsByDay = [];
foreach ($dailyIncomeRows as $point) {
    $incomeByDay[(string) $point['income_day']] = (float) $point['income_total'];
    $operationsByDay[(string) $point['income_day']] = (int) $point['operations_count'];
}
$makeDailyChart = static function (int $days, array $values, array $operations): array {
    $labels = []; $series = []; $total = 0.0; $count = 0;
    for ($daysAgo = $days - 1; $daysAgo >= 0; $daysAgo--) {
        $timestamp = strtotime('-' . $daysAgo . ' days');
        $key = date('Y-m-d', $timestamp);
        $value = (float) ($values[$key] ?? 0);
        $labels[] = date('d/m', $timestamp);
        $series[] = round($value, 2);
        $total += $value;
        $count += (int) ($operations[$key] ?? 0);
    }
    return ['labels' => $labels, 'values' => $series, 'total' => number_format($total, 0) . ' ر.ي', 'delta' => number_format($count) . ' عملية دخل مسجلة خلال الفترة', 'valueSuffix' => ' ر.ي'];
};
$hourValues = array_fill(0, 24, 0.0); $hourOperations = array_fill(0, 24, 0);
foreach ($hourlyIncomeRows as $point) { $hour = max(0, min(23, (int) $point['income_hour'])); $hourValues[$hour] = round((float) $point['income_total'], 2); $hourOperations[$hour] = (int) $point['operations_count']; }
$hourLabels = []; for ($hour = 0; $hour < 24; $hour++) $hourLabels[] = sprintf('%02d:00', $hour);
$dayTotal = array_sum($hourValues); $dayOperations = array_sum($hourOperations);
$monthlyValues = []; $monthlyOperations = [];
for ($monthsAgo = 11; $monthsAgo >= 0; $monthsAgo--) { $timestamp = strtotime('first day of -' . $monthsAgo . ' months'); $key = date('Y-m', $timestamp); $monthlyValues[$key] = 0.0; $monthlyOperations[$key] = 0; }
foreach ($incomeByDay as $day => $value) { $month = substr($day, 0, 7); if (array_key_exists($month, $monthlyValues)) { $monthlyValues[$month] += (float) $value; $monthlyOperations[$month] += (int) ($operationsByDay[$day] ?? 0); } }
$yearTotal = array_sum($monthlyValues); $yearOperations = array_sum($monthlyOperations);
$chartSets = [
    'day' => ['labels' => $hourLabels, 'values' => $hourValues, 'total' => number_format($dayTotal, 0) . ' ر.ي', 'delta' => number_format($dayOperations) . ' عملية دخل اليوم', 'valueSuffix' => ' ر.ي'],
    'week' => $makeDailyChart(7, $incomeByDay, $operationsByDay),
    'month' => $makeDailyChart(30, $incomeByDay, $operationsByDay),
    'year' => ['labels' => array_map(static fn($key) => date('m/Y', strtotime($key . '-01')), array_keys($monthlyValues)), 'values' => array_values($monthlyValues), 'total' => number_format($yearTotal, 0) . ' ر.ي', 'delta' => number_format($yearOperations) . ' عملية دخل خلال 12 شهرًا', 'valueSuffix' => ' ر.ي'],
];
$incomeTotalFmt = $chartSets['week']['total'];
$incomeOperations = array_sum(array_map(static fn($key) => (int) ($operationsByDay[$key] ?? 0), array_map(static fn($daysAgo) => date('Y-m-d', strtotime('-' . $daysAgo . ' days')), range(6, 0))));
$dashboardClientData = [
    'uptimeSeconds' => $uptimeSeconds,
    'uptimeAvailable' => $uptimeAvailable,
    'rebootCsrfToken' => $rebootCsrfToken,
    'chartSets' => $chartSets,
];
?>

<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#17181b">
  <title>3D Radius | لوحة التحكم</title>
  <meta name="description" content="لوحة 3D Radius لإدارة الشبكة والمستخدمين والجلسات.">
  <link rel="stylesheet" href="../common/static/css/icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="../common/static/portal-ui/app.css">

<link rel="stylesheet"
      href="static/css/nawa-production.css?v=20260813">
  <link rel="stylesheet" href="static/css/tdn-admin.css?v=20260824-modern-shell">
  <link rel="stylesheet" href="static/css/3d-brand-overrides.css?v=20260816-income-reboot">
<style>
  .dashboard-content{max-width:1540px;margin-inline:auto}
  .heading-live{display:inline-flex;align-items:center;gap:7px;padding:7px 10px;border:1px solid #c9efe4;border-radius:999px;background:#effbf7;color:#087a63;font-size:11px;font-weight:800}
  .heading-live i,.live-dot{width:7px;height:7px;border-radius:50%;background:#18b987;box-shadow:0 0 0 5px rgba(24,185,135,.12)}
  .status-strip.dashboard-status{padding:13px 16px;box-shadow:0 8px 25px rgba(31,38,56,.04)}
  .status-strip.dashboard-status.is-warning{border-color:#f8dfb1;background:linear-gradient(90deg,#fff9ec,#fffdf8)}
  .status-strip.dashboard-status.is-warning .status-icon{background:#fff0ce;color:#d68213}
  .real-stat{min-height:157px;overflow:hidden;transition:transform .18s ease,box-shadow .18s ease}
  .real-stat:hover{transform:translateY(-2px);box-shadow:0 14px 32px rgba(38,42,64,.09)}
  .real-stat .stat-top .live-label{display:inline-flex;align-items:center;gap:6px;color:#15936f;font-size:10px;font-weight:800}
  .real-stat .stat-top .live-label:before{content:"";width:6px;height:6px;border-radius:50%;background:#18b987;box-shadow:0 0 0 4px rgba(24,185,135,.1)}
  .real-stat h2{font-size:29px;line-height:1;margin:9px 0 5px}
  .real-stat small{display:block;min-height:16px}
  .metric-bar{height:5px;margin-top:15px;border-radius:999px;background:#eef0f5;overflow:hidden}
  .metric-bar i{display:block;height:100%;border-radius:inherit;background:currentColor}
  .accent-violet .metric-bar{color:#e5221a}.accent-mint .metric-bar{color:#24262a}.accent-cyan .metric-bar{color:#bc1c15}.accent-orange .metric-bar{color:#62666e}
  .essentials-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 14px}
  .essential-card{display:flex;align-items:center;gap:12px;min-width:0;padding:14px 15px;border:1px solid var(--border,#e7e8ef);border-radius:14px;background:var(--panel,#fff);box-shadow:0 7px 24px rgba(38,42,64,.035)}
  .essential-card>i{display:grid;place-items:center;flex:0 0 39px;width:39px;height:39px;border-radius:12px;background:#f2efff;color:#6654db;font-size:17px}
  .essential-card.mint>i{background:#eafaf5;color:#0e9f76}.essential-card.orange>i{background:#fff4e7;color:#de851e}.essential-card.red>i{background:#fff0f1;color:#e74855}
  .essential-card span{display:flex;flex-direction:column;min-width:0}.essential-card small{color:#9297a7;font-size:10px}.essential-card strong{margin-top:3px;color:#181b27;font-size:16px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .dashboard-chart-note{display:flex;align-items:center;gap:7px;color:#14926f;font-size:10px;font-weight:800}
  .dashboard-chart-note i{width:7px;height:7px;border-radius:50%;background:#18b987}
  .income-head-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.period-tabs{display:flex;gap:4px;padding:4px;border:1px solid #e4e6eb;border-radius:10px;background:#f7f8fa}.period-tabs button{min-height:30px;padding:5px 10px;border:0;border-radius:7px;background:transparent;color:#747b8b;font:inherit;font-size:9px;font-weight:900;cursor:pointer}.period-tabs button.active{color:#fff;background:var(--brand-red);box-shadow:0 5px 12px rgba(229,34,26,.18)}
  .health-meta{display:flex;justify-content:space-between;gap:10px;padding-top:12px;margin-top:12px;border-top:1px solid #eeeff4;color:#8b90a1;font-size:10px}.health-meta b{color:#363b4d}
  .table-panel .batch-name{display:flex;align-items:center;gap:9px}.table-panel .batch-name>span{display:grid;place-items:center;width:34px;height:34px;border-radius:10px;background:#fce9e8;color:#e5221a}
  .table-panel td strong{display:block}.table-panel td small{display:block;margin-top:3px;color:#979bab;font-size:9px}
  .empty-table{padding:32px!important;text-align:center;color:#979bab}
  .operations-panel .panel-head{margin-bottom:12px}
  .backup-snapshot{padding:14px;border:1px solid #d7eee7;border-radius:14px;background:linear-gradient(135deg,#f2fbf8,#fbfefd)}
  .backup-snapshot-top{display:flex;align-items:center;justify-content:space-between;gap:10px}.backup-snapshot-top>span{display:grid;place-items:center;width:37px;height:37px;border-radius:11px;background:#def6ef;color:#0b9871;font-size:17px}.backup-snapshot-top b{margin-inline-start:auto;font-size:12px}.backup-snapshot-top em{padding:4px 8px;border-radius:999px;background:#fff;color:#0d8d6b;font-size:9px;font-style:normal;font-weight:800}
  .backup-snapshot dl{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin:12px 0 0}.backup-snapshot dl div{padding:8px 9px;border-radius:9px;background:rgba(255,255,255,.8)}.backup-snapshot dt{color:#9499a8;font-size:9px}.backup-snapshot dd{margin:3px 0 0;color:#292e3e;font-size:10px;font-weight:800}
  .alert-list{display:grid;gap:8px;margin-top:12px}.alert-row{display:flex;align-items:center;gap:9px;padding:10px 11px;border:1px solid #eceef3;border-radius:11px}.alert-row>i{display:grid;place-items:center;width:30px;height:30px;border-radius:9px;background:#fff1e4;color:#e1841e}.alert-row.red>i{background:#fff0f1;color:#df4552}.alert-row.mint>i{background:#eaf9f4;color:#0d9a72}.alert-row div{min-width:0;flex:1}.alert-row b{display:block;color:#282c3b;font-size:11px}.alert-row small{display:block;margin-top:2px;color:#9297a7;font-size:9px}.alert-row strong{font-size:12px;color:#34394a}
  .quick-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:12px}.quick-actions a{display:flex;align-items:center;gap:7px;padding:10px;border:1px solid #e5e7ed;border-radius:10px;color:#4c5264;font-size:10px;font-weight:800;text-decoration:none}.quick-actions a:hover{border-color:#f2a6a2;background:#fef4f3;color:#bc1c15}
  .dashboard-footer{margin-top:18px}
  .dashboard-page.dark .essential-card,.dashboard-page.dark .operations-panel{background:#1c2030;border-color:#303547}.dashboard-page.dark .essential-card strong,.dashboard-page.dark .backup-snapshot dd,.dashboard-page.dark .alert-row b{color:#edf0f8}.dashboard-page.dark .metric-bar{background:#303547}.dashboard-page.dark .backup-snapshot{background:#192c2a;border-color:#28524a}.dashboard-page.dark .backup-snapshot dl div{background:rgba(20,25,37,.65)}.dashboard-page.dark .alert-row,.dashboard-page.dark .quick-actions a{border-color:#303547}.dashboard-page.dark .health-meta{border-color:#303547}
  @media(max-width:1180px){.essentials-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
  @media(max-width:680px){.essentials-grid{grid-template-columns:1fr}.heading-actions{width:100%;flex-wrap:wrap}.heading-actions>*{flex:1;justify-content:center}.status-meta{width:100%;flex-direction:column;align-items:flex-start!important}.quick-actions{grid-template-columns:1fr}.backup-snapshot dl{grid-template-columns:1fr}}
.nawa-generic-table-controls{display:flex;align-items:center;justify-content:flex-start;gap:10px;margin:0 0 12px;color:#64748b;font-size:10px;font-weight:700;direction:rtl}.nawa-generic-table-controls label,.nawa-page-size-control{display:flex!important;align-items:center;gap:8px;padding:5px 6px 5px 10px;border:1px solid #dce5f0;border-radius:10px;background:linear-gradient(135deg,#f8fbff,#fff);box-shadow:0 2px 7px rgba(44,72,112,.05)}.nawa-generic-table-controls label:before,.nawa-page-size-control:before{content:'☷';display:grid;place-items:center;width:20px;height:20px;border-radius:6px;background:#eaf1ff;color:#3569d4;font-size:13px}.nawa-generic-table-controls input,.nawa-generic-table-controls select,.nawa-page-size-control input{width:48px!important;min-height:28px!important;padding:3px 5px!important;border:1px solid #cbd9ee!important;border-radius:7px!important;background:#fff!important;color:#24436b!important;font-weight:900!important;text-align:center!important;outline:0}.nawa-generic-table-controls input:focus,.nawa-page-size-control input:focus{border-color:#4e7fe0!important;box-shadow:0 0 0 3px rgba(78,127,224,.14)}.nawa-generic-table-controls>span{margin-inline-start:0;color:#7b8ca5;white-space:nowrap}.nawa-generic-table-pager{display:flex;justify-content:flex-start;gap:4px;margin:10px 0;direction:rtl}.nawa-generic-table-pager button{border:1px solid #dce4ef;border-radius:6px;background:#fff;color:#52647c;padding:3px 7px;cursor:pointer}.nawa-generic-table-pager button.active{background:#3569d4;border-color:#3569d4;color:#fff}.nawa-card-sort{border:0!important;background:transparent!important;box-shadow:none!important;padding:0!important;font:inherit!important;font-weight:800!important;color:inherit!important;cursor:pointer!important;white-space:nowrap}.nawa-card-sort i{font-size:10px;color:#a3afbf;margin-inline-start:3px}.nawa-card-sort.active{color:#3569d4!important}.nawa-card-page-size{display:flex;align-items:center;gap:6px;padding:4px 7px;border-inline-start:1px solid #e2e8f0;color:#64748b;font-size:10px;font-weight:700}.nawa-card-page-size input{width:46px;height:28px;border:1px solid #cbd9ee;border-radius:7px;text-align:center;font-weight:800;color:#24436b;outline:0}.nawa-card-page-size input:focus{border-color:#4e7fe0;box-shadow:0 0 0 3px rgba(78,127,224,.12)}</style>


<style id="dashboard-reference-design">
/* Dashboard presentation only. Existing content, actions and live bindings are untouched. */
.dashboard-page:not(.dark){--ink:#092253;--muted:#748aba;--line:#e3edfb;--surface:#fff;--canvas:#eff6ff;--brand-red:#ff173d;--brand-red-dark:#dd1235;--brand-red-soft:#ffedf0;--text-main:#092253;--bg-main:#eff6ff;background:radial-gradient(ellipse at 40% 0,#d9eaff 0,transparent 48%),linear-gradient(120deg,#f7fbff,#eaf3ff)!important;color:#092253!important}
.dashboard-page .dashboard-shell{grid-template-columns:218px minmax(0,1fr)}
.dashboard-page .sidebar{width:218px;background:radial-gradient(ellipse at 100% 75%,#213d60 0,transparent 48%),linear-gradient(170deg,#0c182a,#14263e 60%,#091629)!important;border-inline-end:1px solid #28405c;scrollbar-color:#344b68 transparent}
.dashboard-page .sidebar-head{justify-content:center;padding:26px 16px 20px;min-height:140px;border-bottom:1px solid #ffffff0c}
.dashboard-page .brand-3d-logo{max-width:174px;max-height:110px;object-fit:contain}
.dashboard-page .workspace-card{margin:12px 12px 8px;padding:8px;background:#ffffff06;border-color:#ffffff0b}
.dashboard-page .side-nav{padding:0 10px}
.dashboard-page .nav-label{margin:12px 12px 5px;color:#8399b7;font-size:9px}
.dashboard-page .nav-item{min-height:47px;margin:5px 0;padding:0 11px;border-radius:12px;gap:10px;color:#dbe7fa;font-size:12px}
.dashboard-page .nav-item>i{font-size:20px;color:#afc7e9}
.dashboard-page .nav-item.active{background:linear-gradient(110deg,#c21b38,#ff2546)!important;box-shadow:0 6px 22px #ff173d30;color:#fff}
.dashboard-page .nav-item.active>i{color:#fff!important;background:#ffffff19;border-radius:9px;padding:7px;width:35px}
.dashboard-page .nav-item.active:before{display:none}
.dashboard-page .nav-item em{background:#0788ff;border-radius:8px;padding:3px 7px;color:#fff;font-size:10px;font-weight:800}
.dashboard-page .nav-item b{background:#ff2546;border-radius:9px}
.dashboard-page .nav-subitem{color:#bfd0e7}
.dashboard-page .sidebar-footer{padding:18px 14px}
.dashboard-page .support-card{border:1px solid #314b6c;background:linear-gradient(145deg,#213f60,#102139)!important;border-radius:14px}
.dashboard-page .topbar{min-height:68px;padding:0 20px;border-bottom:1px solid #ffffff80;background:#f5faffc9;box-shadow:none}
.dashboard-page .topbar-actions{gap:9px}
.dashboard-page .profile-button{min-width:145px;border-color:#fff;box-shadow:0 4px 16px #28528608}
.dashboard-page .topbar-actions>.icon-button,.dashboard-page .notification-toggle{border-color:#fff;color:#14376b;box-shadow:0 4px 16px #28528608}
.dashboard-page .dashboard-content{max-width:1640px;padding:14px 20px 20px}
.dashboard-page .page-heading{position:relative;isolation:isolate;min-height:152px;align-items:flex-start;padding:23px 24px 52px;margin:0 -10px -34px;overflow:hidden;border:1px solid #d7e6fc;border-radius:14px;background:radial-gradient(ellipse at 25% 0,#95b4d2aa,transparent 62%),linear-gradient(105deg,#183553,#477599 48%,#123866);color:white;box-shadow:inset 0 1px 0 #ffffff65}
.dashboard-page .page-heading:before{content:"";position:absolute;z-index:-1;inset:0;opacity:.48;background:linear-gradient(145deg,transparent 45%,#0b203b 46%);clip-path:polygon(0 70%,8% 14%,13% 44%,22% 22%,32% 73%,43% 32%,53% 67%,61% 18%,74% 72%,83% 33%,100% 79%,100% 100%,0 100%)}
.dashboard-page .page-heading:after{content:"";position:absolute;z-index:-1;inset:0;background:#0a25456b;clip-path:polygon(0 85%,13% 56%,24% 90%,40% 53%,52% 86%,70% 48%,82% 79%,93% 50%,100% 75%,100% 100%,0 100%)}
.dashboard-page .page-heading h1,.dashboard-page .page-heading .brand-title-mark{color:#fff!important;letter-spacing:0}
.dashboard-page .page-heading .eyebrow,.dashboard-page .page-heading>div>p:last-child{color:#e1edff}
.dashboard-page .heading-actions{align-self:center}
.dashboard-page .page-heading .heading-live{background:#0c254ba8;border-color:#b9d9ff40;color:#e4f3ff;font-size:10px;backdrop-filter:blur(8px)}
.dashboard-page .stats-grid{position:relative;z-index:1;gap:12px;margin-bottom:12px}
.dashboard-page .stat-card{border:1px solid #fff;border-radius:14px;background:linear-gradient(145deg,#fff,#fbfdff);box-shadow:0 8px 26px #477fb310;padding:15px 16px;min-height:211px}
.dashboard-page .real-stat .stat-top{flex-direction:row-reverse}
.dashboard-page .stat-icon{height:46px;width:46px;border-radius:13px;color:#fff;background:linear-gradient(145deg,var(--accent),var(--accent));box-shadow:0 5px 14px color-mix(in srgb,var(--accent) 32%,transparent),inset 0 1px 2px #ffffff70;font-size:24px}
.dashboard-page .accent-violet{--accent:#ff742c;--accent-soft:#fff0e6}
.dashboard-page .accent-mint{--accent:#942bff;--accent-soft:#efebff}
.dashboard-page .accent-cyan{--accent:#007aff;--accent-soft:#e5f1ff}
.dashboard-page .accent-orange{--accent:#00bf87;--accent-soft:#e3fff3}
.dashboard-page .real-stat>p{font-size:12px;color:#526faa;margin:5px 0 7px;font-weight:700}
.dashboard-page .real-stat h2{font-size:32px;font-weight:800;letter-spacing:-.5px;color:#071f52}
.dashboard-page .real-stat>small{font-size:9px;color:#758bbc;line-height:1.6}
.dashboard-page .stat-card:after{width:125px;height:125px;bottom:-73px;left:-48px;opacity:.8;pointer-events:none}
.dashboard-page .metric-bar{margin-top:14px;background:#e1e9f8;height:6px}
.dashboard-page .accent-violet .metric-bar{color:#ff284f}.dashboard-page .accent-mint .metric-bar{color:#795aff}.dashboard-page .accent-cyan .metric-bar{color:#168aff}.dashboard-page .accent-orange .metric-bar{color:#00c68b}
.dashboard-page .dashboard-hotspot-networks{gap:3px!important;margin:6px 0!important}
.dashboard-page .dashboard-hotspot-networks>span{padding:2px 5px!important;font-size:10px!important;background:#f3f7ff!important;color:#092253}
.dashboard-page .analytics-grid{grid-template-columns:minmax(0,2.25fr) minmax(270px,1fr);gap:12px;margin-bottom:12px}
.dashboard-page .bottom-grid{grid-template-columns:minmax(0,1.35fr) minmax(300px,1fr);gap:12px}
.dashboard-page .panel{border:1px solid #fff;border-radius:15px;background:#ffffffef;box-shadow:0 8px 30px #477fb309}
.dashboard-page .panel-head{padding:15px 16px;gap:12px}
.dashboard-page .panel-head h2{color:#0a265b;font-size:15px;font-weight:800}
.dashboard-page .panel-head p{color:#7d90b9;font-size:9px;line-height:1.6}
.dashboard-page .chart-summary{padding:0 17px 8px;flex-wrap:wrap}
.dashboard-page .chart-summary strong{font-size:28px;color:#082357}
.dashboard-page .chart-wrap{height:236px;border:0;background:linear-gradient(#fff,#fcfdff);padding:8px 16px 18px}
.dashboard-page .period-tabs{border-color:#dce8ff;background:#f3f7ff}
.dashboard-page .period-tabs button{color:#516da5;min-width:45px;font-size:10px}
.dashboard-page .period-tabs button.active{background:linear-gradient(135deg,#ff3854,#f40732);box-shadow:0 4px 13px #ff173d30;color:#fff}
.dashboard-page .health-score{margin:0 15px 13px;background:linear-gradient(110deg,#f2f5ff,#fff7f8);padding:10px;gap:11px}
.dashboard-page .health-score strong{color:#102d64;font-size:12px}
.dashboard-page .health-score small{font-size:8px}
.dashboard-page .score-ring{width:72px;height:72px}
.dashboard-page .score-ring b{font-size:23px;color:#072456}
.dashboard-page .resource-list{padding:0 16px 10px;gap:12px}
.dashboard-page .resource-list>div>span{font-size:10px;color:#7287b4}
.dashboard-page .resource-list>div:nth-child(1)>div i,.dashboard-page .resource-list>div:nth-child(2)>div i{background:linear-gradient(90deg,#0076ff,#319aff)!important}
.dashboard-page .health-meta{margin:0 16px 10px;padding-top:9px;flex-wrap:wrap;font-size:9px;line-height:1.7;border-color:#e5edfa}
.dashboard-page .health-meta b{color:#355181}
.dashboard-page .server-reboot-button{border-color:#ffc0cc;background:#fff2f5;border-radius:11px}
.dashboard-page .panel-link{height:28px;margin:7px 16px 12px;border:0;font-size:10px}
.dashboard-page .table-panel .panel-head{border:0}
.dashboard-page .table-panel .table-scroll{margin:0 12px 13px;border:1px solid #e5edfa;border-radius:12px}
.dashboard-page .table-panel table{font-size:10px}
.dashboard-page .table-panel th{padding:10px 8px;background:linear-gradient(#f5f8ff,#eff5ff);color:#748cbd}
.dashboard-page .table-panel td{padding:9px 8px;border-color:#e4edfc;color:#637dad}
.dashboard-page .table-panel td strong{font-size:10px;color:#0c285b}
.dashboard-page .table-panel td small{color:#7d92be}
.dashboard-page .table-panel tbody tr:nth-child(even){background:#f8fbff}
.dashboard-page .badge.success{background:#dcfff2;color:#009a70}.dashboard-page .badge.warning{background:#fff0e3;color:#f27526}
.dashboard-page .backup-snapshot{margin:0 12px;padding:12px;border-color:#c6f4e7;background:linear-gradient(130deg,#e9fff7,#f5fffd);border-radius:12px}
.dashboard-page .backup-snapshot-top b{font-size:14px;color:#123366}
.dashboard-page .backup-snapshot-top em{background:#00b987;color:#fff;border-radius:8px}
.dashboard-page .backup-snapshot dl{gap:6px}
.dashboard-page .backup-snapshot dd{color:#0a2a60;font-size:11px}
.dashboard-page .alert-list{margin:9px 12px 0;gap:6px}
.dashboard-page .alert-row{padding:8px;border-color:#e4edfc;background:linear-gradient(100deg,#fff,#f8fbff)}
.dashboard-page .alert-row b,.dashboard-page .alert-row strong{color:#102f66}
.dashboard-page .quick-actions{margin:9px 12px 12px;gap:6px}
.dashboard-page .quick-actions a{justify-content:center;padding:8px 5px;border-color:#d8e6ff;background:#f3f8ff;color:#1674f5;font-size:10px}
.dashboard-page .dashboard-footer{color:#7890ba}
.dashboard-page [data-recent-down-panel]{border-color:#fff}
.dashboard-page [data-recent-down-list]{padding:0 14px 14px!important}
.dashboard-page a:focus-visible,.dashboard-page button:focus-visible{outline:3px solid #3694ff;outline-offset:3px}
/* Retain the existing dark-mode control and mobile drawer behavior. */
.dashboard-page.dark .topbar{background:#14253beF;border-color:#293e58}
.dashboard-page.dark .panel,.dashboard-page.dark .stat-card{background:#172b43;border-color:#29415d}
.dashboard-page.dark .panel-head h2,.dashboard-page.dark .real-stat h2,.dashboard-page.dark .chart-summary strong,.dashboard-page.dark .score-ring b,.dashboard-page.dark .health-score strong,.dashboard-page.dark .alert-row b,.dashboard-page.dark .alert-row strong,.dashboard-page.dark .table-panel td strong{color:#e7f0ff}
.dashboard-page.dark .chart-wrap{background:#172b43}
.dashboard-page.dark .health-score,.dashboard-page.dark .period-tabs,.dashboard-page.dark .quick-actions a,.dashboard-page.dark .alert-row,.dashboard-page.dark .table-panel th,.dashboard-page.dark .table-panel tbody tr:nth-child(even){background:#1d3450;border-color:#304967}
.dashboard-page.dark .score-ring:before{background:#172b43}
.dashboard-page.dark .dashboard-hotspot-networks>span{background:#223c58!important;color:#e7f0ff}
.dashboard-page.dark .table-panel td{border-color:#304967;color:#a6bbda}
@media(min-width:1051px){.dashboard-page .stats-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}
@media(max-width:1200px) and (min-width:861px){.dashboard-page .dashboard-shell{grid-template-columns:195px minmax(0,1fr)}.dashboard-page .sidebar{width:195px}.dashboard-page .dashboard-content{padding-inline:14px}.dashboard-page .real-stat{padding:12px}.dashboard-page .real-stat>p{font-size:11px}.dashboard-page .analytics-grid{grid-template-columns:minmax(0,1.8fr) minmax(245px,1fr)}.dashboard-page .bottom-grid{grid-template-columns:minmax(0,1.25fr) minmax(270px,1fr)}}
@media(max-width:1050px){.dashboard-page .stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.dashboard-page .analytics-grid,.dashboard-page .bottom-grid{grid-template-columns:1fr}}
@media(max-width:860px){.dashboard-page .dashboard-shell{grid-template-columns:minmax(0,1fr)}.dashboard-page .sidebar{width:min(84vw,260px)}.dashboard-page .sidebar-head{justify-content:space-between}.dashboard-page .dashboard-content{padding:12px 15px}.dashboard-page .page-heading{margin-inline:0}}
@media(max-width:600px){.dashboard-page .topbar{padding-inline:12px}.dashboard-page .profile-button{min-width:0}.dashboard-page .page-heading{padding:18px 16px 48px;gap:12px}.dashboard-page .heading-actions{align-self:stretch}.dashboard-page .page-heading .heading-live{white-space:normal}.dashboard-page .stats-grid{gap:9px}.dashboard-page .real-stat{padding:12px;min-height:205px}.dashboard-page .stat-icon{width:36px;height:36px;font-size:19px}.dashboard-page .real-stat h2{font-size:27px}.dashboard-page .real-stat>p{font-size:10px}.dashboard-page .real-stat .stat-top .live-label{font-size:9px}.dashboard-page .panel-head{flex-wrap:wrap}.dashboard-page .chart-summary strong{font-size:24px}.dashboard-page .chart-wrap{height:225px}.dashboard-page .dashboard-footer{gap:12px;flex-wrap:wrap}}
@media(max-width:380px){.dashboard-page .stats-grid{grid-template-columns:1fr}}
@media(prefers-reduced-motion:reduce){.dashboard-page .real-stat,.dashboard-page .nav-item{transition:none}}

</style>
</head>
<body class="dashboard-page">
  <div class="dashboard-shell">
    <aside class="sidebar" id="sidebar" aria-label="القائمة الرئيسية">
      <div class="sidebar-head">
        <a class="brand brand--light brand-3d-official" href="home-modern.php">
          <img
            src="../common/static/images/3d-radius-logo.png"
            alt="3D Radius"
            class="brand-3d-logo"
          >
        </a>
        <button class="icon-button sidebar-close" type="button" aria-label="إغلاق القائمة"><i class="bi bi-x-lg"></i></button>
      </div>

      <div class="workspace-card">
        <span class="workspace-icon">ن</span>
        <span><small>مساحة العمل</small><strong>شبكة 3D</strong></span>
        <i class="bi bi-chevron-down"></i>
      </div>

      <nav class="side-nav">
        <p class="nav-label">الرئيسية</p>
        <a class="nav-item active" href="home-modern.php"><i class="bi bi-grid-1x2"></i><span>لوحة التحكم</span></a>

        <p class="nav-label">الإدارة والتشغيل</p>

        <div class="nav-group">
          <button class="nav-item nav-group-toggle" type="button" aria-expanded="false" aria-controls="users-menu">
            <i class="bi bi-people"></i><span>المستخدمون</span><em data-dashboard-live-total><?= $liveTotalFmt ?></em><i class="bi bi-chevron-left nav-chevron"></i>
          </button>
          <div class="nav-submenu" id="users-menu">
            <a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('nawa-users.php?fragment=1')"><i class="bi bi-person-vcard"></i><span>المشتركين</span></a>
            <a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('nawa-cards.php?fragment=1')"><i class="bi bi-ticket-perforated"></i><span>الكروت</span></a>
            <a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('nawa-card-recharge.php?fragment=1')"><i class="bi bi-arrow-repeat"></i><span>شحن الكروت</span></a>
            <a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('nawa-card-trash.php?fragment=1')"><i class="bi bi-trash3"></i><span>سلة المحذوفات</span></a>
          </div>
        </div>

        <div class="nav-group"><button class="nav-item nav-group-toggle" type="button" aria-expanded="false" aria-controls="pppoe-menu"><i class="bi bi-router"></i><span>البرودباند / PPPoE</span><i class="bi bi-chevron-left nav-chevron"></i></button><div class="nav-submenu" id="pppoe-menu"><a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('nawa-pppoe-users.php?fragment=1')"><i class="bi bi-people"></i><span>مشتركو PPPoE</span></a><a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('nawa-pppoe-log.php?fragment=1')"><i class="bi bi-journal-text"></i><span>سجل البرودباند</span></a></div></div>
        <div class="nav-group">
          <button class="nav-item nav-group-toggle" type="button" aria-expanded="false" aria-controls="devices-menu">
            <i class="bi bi-router"></i><span>الأجهزة</span><i class="bi bi-chevron-left nav-chevron"></i>
          </button>
          <div class="nav-submenu" id="devices-menu">
            <a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('nas-modern.php?fragment=1')"><i class="bi bi-hdd-network"></i><span>أجهزة NAS</span></a>
            <a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('wireless-modern.php?fragment=1')"><i class="bi bi-wifi"></i><span>الأجهزة اللاسلكية</span></a>
          </div>
        </div>

<?php if ($tdnAdminAllowed): ?>
        <div class="nav-group" data-tdn-admin-menu>
          <button class="nav-item nav-group-toggle" type="button" aria-expanded="false" aria-controls="tdn-admin-menu">
            <i class="bi bi-shop"></i><span>نقاط البيع</span><i class="bi bi-chevron-left nav-chevron"></i>
          </button>
          <div class="nav-submenu" id="tdn-admin-menu">
            <a class="nav-subitem" data-section href="tdn-dashboard.php" onclick="loadSection('tdn-dashboard.php?fragment=1');return false;"><i class="bi bi-speedometer2"></i><span>لوحة التحكم</span></a>
            <a class="nav-subitem" data-section href="tdn-dealers.php" onclick="loadSection('tdn-dealers.php?fragment=1');return false;"><i class="bi bi-shop-window"></i><span>البقالات</span></a>
            <a class="nav-subitem" data-section href="tdn-recharges.php" onclick="loadSection('tdn-recharges.php?fragment=1');return false;"><i class="bi bi-arrow-repeat"></i><span>عمليات الشحن</span></a>
            <a class="nav-subitem" data-section href="tdn-ledger.php" onclick="loadSection('tdn-ledger.php?fragment=1');return false;"><i class="bi bi-wallet2"></i><span>حركات المحافظ</span></a>
            <a class="nav-subitem" data-section href="tdn-customers.php" onclick="loadSection('tdn-customers.php?fragment=1');return false;"><i class="bi bi-people"></i><span>المشتركون</span></a>
            <a class="nav-subitem" data-section href="tdn-settings.php" onclick="loadSection('tdn-settings.php?fragment=1');return false;"><i class="bi bi-gear"></i><span>الإعدادات</span></a>
          </div>
        </div>
<?php endif; ?>

<?php if ($pointsAdminAllowed): ?>
        <div class="nav-group" data-points-admin-menu>
          <button class="nav-item nav-group-toggle" type="button" aria-expanded="false" aria-controls="points-admin-menu">
            <i class="bi bi-stars"></i><span>3D Points</span><i class="bi bi-chevron-left nav-chevron"></i>
          </button>
          <div class="nav-submenu" id="points-admin-menu">
            <a class="nav-subitem" data-section href="points-main.php" onclick="loadSection('points-main.php?fragment=1');return false;"><i class="bi bi-speedometer2"></i><span>لوحة التحكم</span></a>
            <a class="nav-subitem" data-section href="points-users.php" onclick="loadSection('points-users.php?fragment=1');return false;"><i class="bi bi-people"></i><span>حسابات النقاط</span></a>
            <a class="nav-subitem" data-section href="points-transactions.php" onclick="loadSection('points-transactions.php?fragment=1');return false;"><i class="bi bi-arrow-left-right"></i><span>حركات النقاط</span></a>
            <a class="nav-subitem" data-section href="points-devices.php" onclick="loadSection('points-devices.php?fragment=1');return false;"><i class="bi bi-phone"></i><span>الأجهزة المرتبطة</span></a>
            <a class="nav-subitem" data-section href="points-rewards.php" onclick="loadSection('points-rewards.php?fragment=1');return false;"><i class="bi bi-gift"></i><span>المكافآت</span></a>
            <a class="nav-subitem" data-section href="points-redemptions.php" onclick="loadSection('points-redemptions.php?fragment=1');return false;"><i class="bi bi-arrow-repeat"></i><span>عمليات الاسترداد</span></a>
            <a class="nav-subitem" data-section href="points-settings.php" onclick="loadSection('points-settings.php?fragment=1');return false;"><i class="bi bi-gear"></i><span>إعدادات النقاط</span></a>
          </div>
        </div>
<?php endif; ?>

        <div class="nav-group">
          <button class="nav-item nav-group-toggle" type="button" aria-expanded="false" aria-controls="reports-menu">
            <i class="bi bi-file-earmark-bar-graph"></i><span>التقارير</span><i class="bi bi-chevron-left nav-chevron"></i>
          </button>
          <div class="nav-submenu" id="reports-menu">
            <a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('reports-overview-modern.php?fragment=1')"><i class="bi bi-grid-1x2"></i><span>نظرة عامة</span></a>
            <a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('reports-logs-modern.php?fragment=1')"><i class="bi bi-journal-text"></i><span>السجلات</span></a>
            <a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('reports-general-modern.php?fragment=1')"><i class="bi bi-clipboard-data"></i><span>التقارير العامة</span></a>
            <a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('reports-sales-modern.php?fragment=1')"><i class="bi bi-graph-up-arrow"></i><span>الدخل والمبيعات</span></a>
          </div>
        </div>

        <div class="nav-group">
          <button class="nav-item nav-group-toggle" type="button" aria-expanded="false" aria-controls="alerts-menu">
            <i class="bi bi-bell"></i><span>الإشعارات والتنبيهات</span><?php if ($alertCount > 0): ?><b><?= number_format($alertCount) ?></b><?php endif; ?><i class="bi bi-chevron-left nav-chevron"></i>
          </button>
          <div class="nav-submenu" id="alerts-menu">
            <a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('alerts-overview-modern.php?fragment=1')"><i class="bi bi-grid-1x2"></i><span>نظرة عامة</span></a>
            <a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('alerts-active-modern.php?fragment=1')"><i class="bi bi-exclamation-diamond"></i><span>التنبيهات النشطة</span></a>
            <a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('alerts-history-modern.php?fragment=1')"><i class="bi bi-clock-history"></i><span>سجل الإشعارات</span></a>
            <a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('alerts-settings-modern.php?fragment=1')"><i class="bi bi-sliders"></i><span>إعدادات التنبيهات</span></a>
          </div>
        </div>

        <div class="nav-group">
          <button class="nav-item nav-group-toggle" type="button" aria-expanded="false" aria-controls="other-menu">
            <i class="bi bi-grid"></i><span>أخرى</span><i class="bi bi-chevron-left nav-chevron"></i>
          </button>
          <div class="nav-submenu" id="other-menu">
            <a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('usage-modern.php?fragment=1')"><i class="bi bi-speedometer2"></i><span>الاستهلاك التفصيلي</span></a>
            <a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('nawa-packages.php?fragment=1')"><i class="bi bi-box-seam"></i><span>الباقات</span></a>
            <a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('nawa-offers.php?fragment=1')"><i class="bi bi-percent"></i><span>العروض</span></a>
            <a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('nawa-card-packages.php?fragment=1')"><i class="bi bi-ticket-detailed"></i><span>باقات الكروت</span></a>
            <a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('nawa-pppoe-packages.php?fragment=1')"><i class="bi bi-router"></i><span>باقات PPPoE</span></a>
            <a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('nawa-card-templates.php?fragment=1')"><i class="bi bi-layout-text-window-reverse"></i><span>قوالب الكروت</span></a>
          </div>
        </div>

        <a class="nav-item" href="javascript:void(0)" onclick="loadSection('nawa-communications.php?fragment=1')"><i class="bi bi-diagram-3"></i><span>الاتصالات</span></a>

        <a class="nav-item" href="javascript:void(0)" onclick="loadSection('nawa-hotspot.php?fragment=1')"><i class="bi bi-broadcast"></i><span>الهوتسبوت</span></a>

        <a class="nav-item" href="javascript:void(0)" onclick="loadSection('nawa-vpn.php?fragment=1')"><i class="bi bi-shield-lock"></i><span>VPN</span></a>

        <a class="nav-item" href="javascript:void(0)" onclick="loadSection('nawa-social-boost.php?fragment=1')"><i class="bi bi-lightning-charge"></i><span>تسريع التواصل</span></a>

        <div class="nav-group nav-group--system">
          <button class="nav-item nav-group-toggle" type="button" aria-expanded="false" aria-controls="system-menu">
            <i class="bi bi-cpu"></i><span>النظام</span><i class="bi bi-chevron-left nav-chevron"></i>
          </button>
          <div class="nav-submenu" id="system-menu">
            <a class="nav-subitem" href="#" onclick="loadSection('nawa-backup-manage.php?fragment=1');return false;">
<i class="bi bi-cloud-arrow-up"></i>
<span>إدارة النسخ الاحتياطية</span>
</a>
            <a class="nav-subitem" href="#" onclick="loadSection('nawa-operators.php?fragment=1');return false;"><i class="bi bi-person-badge"></i><span>المشرفين</span></a>
            <a class="nav-subitem" href="#" onclick="loadSection('nawa-system-settings.php?fragment=1');return false;"><i class="bi bi-gear"></i><span>إعدادات النظام</span></a>
            <a class="nav-subitem" href="javascript:void(0)" onclick="loadSection('nawa-system-status.php?fragment=1')"><i class="bi bi-activity"></i><span>حالة النظام</span><span class="system-online">متصل</span></a>
          </div>
        </div>
      </nav>

      <div class="sidebar-footer">
        <div class="support-card">
          <span><i class="bi bi-headset"></i></span>
          <div><strong>تحتاج مساعدة؟</strong><small>فريقنا متاح على مدار الساعة</small></div>
          <button type="button" data-toast="راجع حالة النظام والتنبيهات من لوحة التحكم">مركز التشغيل</button>
        </div>
      </div>
    </aside>

    <div class="sidebar-overlay" aria-hidden="true"></div>

    <main class="dashboard-main" id="dynamicContent">
      <header class="topbar">
        <div class="topbar-start">
          <button class="icon-button menu-toggle" type="button" aria-label="فتح القائمة"><i class="bi bi-list"></i></button>
        </div>
        <div class="topbar-actions">
          <button class="icon-button theme-toggle" type="button" aria-label="تبديل المظهر"><i class="bi bi-moon-stars"></i><span class="action-label">المظهر</span></button>
          <div class="notification-wrap">
            <button class="icon-button notification-toggle" type="button" aria-label="التنبيهات" aria-expanded="false"><i class="bi bi-bell"></i><?php if ($alertCount > 0): ?><span></span><?php endif; ?></button>
            <div class="notification-popover">
              <div><strong>حالة 3D Radius</strong><button type="button" data-toast="البيانات محدثة من RADIUS">مباشر</button></div>
              <article><i class="bi bi-wifi"></i><span><b>المتصلون الآن</b><small><b data-dashboard-live-total><?= $liveTotalFmt ?></b> مشترك وكرت متصل</small></span><time>الآن</time></article>
              <article><i class="bi bi-router"></i><span><b>نقاط الشبكة</b><small><?= $activeNasFmt ?> من <?= $totalNasFmt ?> تستقبل جلسات حديثة</small></span><time>15 د</time></article>
              <article><i class="bi bi-cloud-check"></i><span><b>آخر نسخة محلية</b><small><?= dashboard_e($lastBackupAt) ?> • <?= dashboard_e($lastBackupSize) ?></small></span><time><?= number_format(count($backupFiles)) ?>/5</time></article>
              <button type="button" onclick="loadSection('alerts-overview-modern.php?fragment=1')" style="width:calc(100% - 24px);margin:8px 12px 12px;padding:9px;border:1px solid #ead5d3;border-radius:9px;background:#fff4f3;color:#d8241c;font-size:10px;font-weight:800;cursor:pointer">عرض مركز الإشعارات <i class="bi bi-arrow-left"></i></button>
            </div>
          </div>
          <span class="topbar-divider"></span>
          <div class="profile-wrap">
            <button class="profile-button" type="button" aria-label="قائمة الحساب" aria-expanded="false">
              <span class="avatar"><?= dashboard_e($operatorInitial) ?></span>
              <span><strong><?= dashboard_e($operator) ?></strong><small>مدير النظام</small></span>
              <i class="bi bi-chevron-down profile-chevron"></i>
            </button>
            <div class="profile-popover">
              <div class="profile-popover-head"><span class="avatar large"><?= dashboard_e($operatorInitial) ?></span><div><strong><?= dashboard_e($operator) ?></strong><small>حساب مشرف 3D Radius</small></div></div>
              <a class="profile-logout" href="logout.php"><i class="bi bi-box-arrow-right"></i><span><b>تسجيل الخروج</b><small>إنهاء جلسة الإدارة الحالية</small></span><i class="bi bi-chevron-left"></i></a>
            </div>
          </div>
        </div>
      </header>

      <div class="dashboard-content">
        <section class="page-heading">
          <div>
            <p class="eyebrow"><span></span> آخر تحديث <?= dashboard_e($todayArabic) ?> بتوقيت اليمن</p>
            <h1><span class="brand-title-mark">3D</span> Radius</h1>
            <p>لوحة التحكم الرئيسية للمشتركين والكروت ونقاط الشبكة.</p>
          </div>
          <div class="heading-actions">
            <span class="heading-live"><i></i><b data-dashboard-live-updated>بيانات مباشرة • جاري التحديث</b></span>
          </div>
        </section>

        <section class="stats-grid" aria-label="المؤشرات الرئيسية">
          <article class="stat-card real-stat accent-violet">
            <div class="stat-top"><span class="stat-icon"><i class="bi bi-wifi"></i></span><span class="live-label">مباشر الآن</span></div>
            <p>المتصلون النشطون الآن</p><h2 data-dashboard-live-total><?= $liveTotalFmt ?></h2><small>عدد الأجهزة الفعلية في Hotspot Active بالشبكات 1–6</small>
            <!-- NAWA_DASHBOARD_HOTSPOT_N1_N6_20260918_V2 -->
            <div class="dashboard-hotspot-networks" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px;margin:9px 0 5px;direction:ltr">
              <span style="display:flex;justify-content:space-between;gap:6px;padding:5px 7px;border-radius:8px;background:rgba(255,255,255,.58);font-size:11px;font-weight:800">N1 <b data-dashboard-hotspot-network="network1">—</b></span>
              <span style="display:flex;justify-content:space-between;gap:6px;padding:5px 7px;border-radius:8px;background:rgba(255,255,255,.58);font-size:11px;font-weight:800">N2 <b data-dashboard-hotspot-network="network2">—</b></span>
              <span style="display:flex;justify-content:space-between;gap:6px;padding:5px 7px;border-radius:8px;background:rgba(255,255,255,.58);font-size:11px;font-weight:800">N3 <b data-dashboard-hotspot-network="network3">—</b></span>
              <span style="display:flex;justify-content:space-between;gap:6px;padding:5px 7px;border-radius:8px;background:rgba(255,255,255,.58);font-size:11px;font-weight:800">N4 <b data-dashboard-hotspot-network="network4">—</b></span>
              <span style="display:flex;justify-content:space-between;gap:6px;padding:5px 7px;border-radius:8px;background:rgba(255,255,255,.58);font-size:11px;font-weight:800">N5 <b data-dashboard-hotspot-network="network5">—</b></span>
              <span style="display:flex;justify-content:space-between;gap:6px;padding:5px 7px;border-radius:8px;background:rgba(255,255,255,.58);font-size:11px;font-weight:800">N6 <b data-dashboard-hotspot-network="network6">—</b></span>
            </div>
            <div class="metric-bar"><i style="width:100%"></i></div>
          </article>
          <article class="stat-card real-stat accent-mint">
            <div class="stat-top"><span class="stat-icon"><i class="bi bi-person-check"></i></span><span class="live-label">من <?= $totalSubscribersFmt ?></span></div>
            <p>المشتركون المتصلون</p><h2 data-dashboard-live-subscribers><?= $liveSubscribersFmt ?></h2><small><?= number_format(dashboard_percent($liveSubscribers, $totalSubscribers), 1) ?>% من المشتركين المسجلين</small>
            <div class="metric-bar"><i style="width:<?= dashboard_percent($liveSubscribers, $totalSubscribers, 2) ?>%"></i></div>
          </article>
          <article class="stat-card real-stat accent-cyan">
            <div class="stat-top"><span class="stat-icon"><i class="bi bi-ticket-perforated"></i></span><span class="live-label">من <?= $totalCardsFmt ?></span></div>
            <p>الكروت المتصلة</p><h2 data-dashboard-live-cards><?= $liveCardsFmt ?></h2><small>كل الكروت القديمة والجديدة</small>
            <div class="metric-bar"><i style="width:<?= dashboard_percent($liveCards, $totalCards, 2) ?>%"></i></div>
          </article>
          <article class="stat-card real-stat accent-orange">
            <div class="stat-top"><span class="stat-icon"><i class="bi bi-router"></i></span><span class="live-label">كل الشبكات</span></div>
            <p style="margin-bottom:2px">الأجهزة المتصلة في كل الشبكات</p><h2 data-dashboard-online-devices style="margin-bottom:8px"><?= $onlineNetworkDevicesFmt ?></h2><div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px;margin:4px 0 8px"><div style="padding:6px 3px;border-radius:10px;background:rgba(255,255,255,.55);text-align:center"><b data-dashboard-general-modems style="display:block;font-size:18px;line-height:1.2">—</b><span style="font-size:10px">مودمات عامة</span></div><div style="padding:6px 3px;border-radius:10px;background:rgba(255,255,255,.55);text-align:center"><b data-dashboard-wireless-devices style="display:block;font-size:18px;line-height:1.2">—</b><span style="font-size:10px">إرسال / استقبال</span></div><div style="padding:6px 3px;border-radius:10px;background:rgba(255,255,255,.55);text-align:center"><b data-dashboard-subscriber-modems style="display:block;font-size:18px;line-height:1.2">—</b><span style="font-size:10px">مودمات المشتركين</span></div></div><small>بيانات مباشرة من Netwatch / RouterOS</small>
            <div class="metric-bar"><i style="width:100%"></i></div>
          </article>
        </section>



        

        <section class="analytics-grid" id="analytics">
          <article class="panel chart-panel">
            <header class="panel-head">
              <div><h2 id="incomeChartTitle">الدخل الأسبوعي</h2><p>إجمالي الشحن والتسديد المسجل فعليًا بالريال اليمني</p></div>
              <div class="income-head-actions"><div class="period-tabs" aria-label="فترة الدخل"><button type="button" data-period="day">يومي</button><button type="button" class="active" data-period="week">أسبوعي</button><button type="button" data-period="month">شهري</button><button type="button" data-period="year">سنوي</button></div><span class="dashboard-chart-note"><i></i> ريال يمني</span></div>
            </header>
            <div class="chart-summary"><strong id="chartTotal"><?= dashboard_e($incomeTotalFmt) ?></strong><span id="chartDelta"><i class="bi bi-cash-coin"></i> <?= number_format($incomeOperations) ?> عملية دخل مسجلة خلال الفترة</span></div>
            <div class="chart-wrap"><canvas id="growthChart" role="img" aria-label="رسم الدخل اليومي بالريال اليمني خلال آخر سبعة أيام"></canvas></div>
          </article>

          <article class="panel health-panel">
            <header class="panel-head"><div><h2>صحة الخادم</h2><p>قراءة مباشرة لموارد الجهاز</p></div><span class="heading-live"><i></i> يعمل</span></header>
            <div class="health-score"><div class="score-ring" style="background:conic-gradient(var(--brand-red) 0 <?= $healthScore ?>%,#e8ebf1 <?= $healthScore ?>% 100%)"><span><b><?= $healthScore ?></b><small><?= dashboard_e($healthLabel) ?></small></span></div><p><strong><?= $healthScore >= 70 ? 'أداء الخادم مستقر' : 'الموارد تحتاج متابعة' ?></strong><small>الحمل محسوب على <?= number_format($cpuCores) ?> أنوية مع مساحة التخزين</small></p></div>
            <div class="resource-list">
              <div><span><i class="bi bi-cpu"></i> حمل المعالج <b><?= $cpuAvailable ? number_format($cpuPercent, 1) . '%' : 'غير متاح' ?></b></span><div><i style="width:<?= $cpuAvailable ? $cpuPercent : 0 ?>%"></i></div></div>
              <div><span><i class="bi bi-device-ssd"></i> التخزين <b><?= $diskAvailable ? number_format($diskPercent, 1) . '%' : 'غير متاح' ?></b></span><div><i style="width:<?= $diskAvailable ? $diskPercent : 0 ?>%"></i></div></div>
            </div>
            <div class="health-meta"><span>الحمل الحالي <b dir="ltr"><?= $cpuAvailable ? number_format((float) ($loadAverage[0] ?? 0), 2) : '—' ?></b></span><span>مدة التشغيل <b><?= dashboard_e($uptimeText) ?></b></span></div>
            <button class="server-reboot-button" type="button" onclick="requestUbuntuReboot(this)"><i class="bi bi-arrow-repeat"></i><span><b>إعادة تشغيل الجهاز</b><small>إعادة تشغيل خادم أوبنتو بالكامل</small></span></button>
            <a class="panel-link" href="#" onclick="loadSection('nawa-system-status.php?fragment=1');return false;">عرض حالة النظام بالتفصيل <i class="bi bi-arrow-left"></i></a>
          </article>
        </section>

        <section class="panel" data-recent-down-panel style="margin:0 0 16px;overflow:hidden">
          <header class="panel-head" style="display:flex;align-items:center;justify-content:space-between;gap:12px">
            <div>
              <h2 style="margin:0">آخر القطع التي فصلت</h2>
              <p style="margin:5px 0 0">آخر 10 دقائق فقط — يظهر الجهاز عندما ينتقل من Online إلى Down، ولا يعرض الأجهزة القديمة غير المستخدمة.</p>
            </div>
            <span data-recent-down-count style="display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:28px;padding:0 9px;border-radius:999px;background:#ffebe9;color:#b42318;font-size:11px;font-weight:900">0</span>
          </header>
          <div data-recent-down-list style="padding:12px 16px 16px">
            <div style="padding:18px;text-align:center;color:#8b92a1;font-size:11px">جاري تحميل آخر القطع التي فصلت...</div>
          </div>
        </section>

        <section class="bottom-grid">
          <article class="panel table-panel">
            <header class="panel-head"><div><h2>أحدث دفعات الكروت</h2><p>بيانات حقيقية من آخر الدفعات المنشأة</p></div><a href="#" onclick="loadSection('nawa-cards.php?fragment=1');return false;">عرض كل الدفعات <i class="bi bi-arrow-left"></i></a></header>
            <div class="table-scroll">
              <table>
                <thead><tr><th>اسم الدفعة</th><th>رقمها</th><th>عدد الكروت</th><th>أنشأها</th><th>الحالة</th><th>التاريخ</th></tr></thead>
                <tbody>
                  <?php if ($recentBatches === []): ?>
                    <tr><td class="empty-table" colspan="6">لا توجد دفعات كروت حتى الآن.</td></tr>
                  <?php else: foreach ($recentBatches as $batch):
                    $batchActive = strtolower((string) $batch['batch_status']) === 'active';
                  ?>
                    <tr>
                      <td><span class="batch-icon"><i class="bi bi-ticket-perforated"></i></span><span><strong><?= dashboard_e($batch['batch_name'] ?: 'دفعة بدون اسم') ?></strong><small><?= number_format((int) $batch['card_count']) ?> كرت محفوظ</small></span></td>
                      <td><strong>#<?= number_format((int) $batch['id']) ?></strong></td>
                      <td><strong><?= number_format((int) $batch['card_count']) ?></strong></td>
                      <td><span class="mini-avatar violet"><?= dashboard_e(mb_substr((string) ($batch['creationby'] ?: 'ن'), 0, 1)) ?></span><?= dashboard_e($batch['creationby'] ?: 'النظام') ?></td>
                      <td><span class="badge <?= $batchActive ? 'success' : 'warning' ?>"><i></i><?= $batchActive ? 'نشطة' : dashboard_e($batch['batch_status']) ?></span></td>
                      <td dir="ltr"><?= dashboard_e(date('Y-m-d H:i', strtotime((string) $batch['creationdate']))) ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </article>

          <article class="panel operations-panel">
            <header class="panel-head"><div><h2>التشغيل والنسخ الاحتياطي</h2><p>أهم الحالات والإجراءات السريعة</p></div><span class="badge <?= $alertCount > 0 ? 'warning' : 'success' ?>"><i></i><?= $alertCount > 0 ? number_format($alertCount) . ' تنبيه' : 'سليم' ?></span></header>
            <div class="backup-snapshot">
              <div class="backup-snapshot-top"><span><i class="bi bi-cloud-check"></i></span><b>النسخ الاحتياطي</b><em><?= dashboard_e($backupMode) ?></em></div>
              <dl>
                <div><dt>آخر نسخة محلية</dt><dd dir="ltr"><?= dashboard_e($lastBackupAt) ?></dd></div>
                <div><dt>الحجم</dt><dd dir="ltr"><?= dashboard_e($lastBackupSize) ?></dd></div>
                <div><dt>النسخ المحلية</dt><dd><?= number_format(count($backupFiles)) ?> من 5</dd></div>
                <div><dt>السحابة</dt><dd><?= number_format($connectedClouds) ?> حسابات • <?= dashboard_e($activeProviderLabel) ?></dd></div>
              </dl>
            </div>
            <div class="alert-list">
              <div class="alert-row red"><i class="bi bi-wifi"></i><div><b>المتصلون الآن</b><small><b data-dashboard-live-subscribers><?= $liveSubscribersFmt ?></b> مشترك و<b data-dashboard-live-cards><?= $liveCardsFmt ?></b> كرت</small></div><strong data-dashboard-live-total><?= $liveTotalFmt ?></strong></div>
              <div class="alert-row mint"><i class="bi bi-router"></i><div><b>نقاط الشبكة</b><small><?= $activeNasFmt ?> نقاط تستقبل جلسات حقيقية</small></div><strong><?= $offlineNas > 0 ? number_format($offlineNas) . ' بلا جلسات' : 'كلها نشطة' ?></strong></div>
              <div class="alert-row orange"><i class="bi bi-cloud-check"></i><div><b>التخزين السحابي</b><small><?= dashboard_e($activeProviderLabel) ?> هو الحساب النشط</small></div><strong><?= number_format($connectedClouds) ?> مرتبطة</strong></div>
            </div>
            <div class="quick-actions">
              <a href="#" onclick="loadSection('nawa-users.php?fragment=1');return false;"><i class="bi bi-person-plus"></i> المشتركين</a>
              <a href="nawa-batch-create.php" target="_blank"><i class="bi bi-ticket-perforated"></i> دفعة جديدة</a>
              <a href="#" onclick="loadSection('nawa-cards.php?fragment=1');return false;"><i class="bi bi-wifi"></i> متصلو الكروت</a>
              <a href="#" onclick="loadSection('nawa-backup-manage.php?fragment=1');return false;"><i class="bi bi-database-check"></i> النسخ الاحتياطية</a>
            </div>
          </article>
        </section>

        <footer class="dashboard-footer"><span>© 2026 3D Radius • البيانات من RADIUS مباشرة</span><nav><a href="#" onclick="loadSection('nawa-system-settings.php?fragment=1');return false;">إعدادات النظام</a><a href="logout.php">تسجيل الخروج</a></nav></footer>
      </div>
    </main>
  </div>
  <div class="toast" role="status" aria-live="polite"><i class="bi bi-check2-circle"></i><span></span></div>
  <script>window.nawaDashboard = <?= json_encode($dashboardClientData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
  <script src="../common/static/portal-ui/app.js?v=20260916-line-chart"></script>

<script>
async function requestUbuntuReboot(button) {
    if (!confirm('سيتم قطع جميع الاتصالات مؤقتًا وإعادة تشغيل جهاز أوبنتو. هل تريد المتابعة؟')) return;
    const token = window.nawaDashboard && window.nawaDashboard.rebootCsrfToken;
    if (!token) {
        alert('تعذر التحقق من جلسة الإدارة. حدّث الصفحة وحاول مجددًا.');
        return;
    }
    const original = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="bi bi-hourglass-split"></i><span><b>جاري جدولة إعادة التشغيل...</b><small>انتظر قليلًا</small></span>';
    try {
        const response = await fetch('nawa-system-reboot.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: new URLSearchParams({csrf_token: token})
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || 'تعذر إعادة التشغيل.');
        button.innerHTML = '<i class="bi bi-check2-circle"></i><span><b>تم طلب إعادة التشغيل</b><small>سيُفصل الجهاز خلال ثوانٍ</small></span>';
        alert('تم طلب إعادة تشغيل الجهاز. انتظر عودة الخادم ثم افتح اللوحة من جديد.');
    } catch (error) {
        button.disabled = false;
        button.innerHTML = original;
        alert(error.message || 'حدث خطأ أثناء طلب إعادة التشغيل.');
    }
}

window.nawaCurrentSectionUrl = window.nawaCurrentSectionUrl || '';
window.nawaSectionRequestController = window.nawaSectionRequestController || null;
window.nawaPendingSearchFocus = window.nawaPendingSearchFocus || null;

function showNawaActionToast(message, isError) {
    if (!message) return;
    const oldToast = document.getElementById('nawaActionToast');
    if (oldToast) oldToast.remove();

    const toast = document.createElement('div');
    toast.id = 'nawaActionToast';
    toast.setAttribute('role', 'status');
    toast.style.cssText = [
        'position:fixed', 'z-index:99999', 'left:24px', 'bottom:24px',
        'max-width:420px', 'padding:14px 18px', 'border-radius:12px',
        'box-shadow:0 16px 40px rgba(20,25,35,.22)', 'font-weight:800',
        'font-size:14px', 'direction:rtl', 'transition:.2s ease',
        isError ? 'background:#fff1f0' : 'background:#ecfdf5',
        isError ? 'color:#b42318' : 'color:#067647',
        isError ? 'border:1px solid #f4b8b2' : 'border:1px solid #a6e8cf'
    ].join(';');
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(function () {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(8px)';
        setTimeout(function () { toast.remove(); }, 220);
    }, 3600);
}

function installNawaLiveSearch(area) {
    const timers = new WeakMap();

    area.querySelectorAll('form').forEach(function (form) {
        const method = (form.getAttribute('method') || 'get').toLowerCase();
        if (method !== 'get') return;
        /* بحث المشتركين متعمد أن يكون يدويًا لتجنب طلب قاعدة بيانات
           مع كل حرف؛ معالجه الخاص ينفذ فقط عند زر بحث أو Enter. */
        if (form.matches('[data-online-search], [data-manual-search], [data-card-search-form], [data-users-search]')) return;

        const searchInputs = form.querySelectorAll('input[type="search"]');
        if (!searchInputs.length) return;

        /* الصفحات التي لا تملك معالج Fragment خاص بها تبقى داخل اللوحة. */
        form.addEventListener('submit', function (event) {
            if (event.defaultPrevented) return;
            event.preventDefault();

            if (form.id === 'fastSubscribersSearch') {
                const q = new FormData(form).get('q') || '';
                loadSection('subscribers-fragment.php?q=' + encodeURIComponent(q));
                return;
            }

            const state = new URL(
                window.nawaCurrentSectionUrl || window.location.href,
                window.location.href
            );
            const actionValue = form.getAttribute('action');
            const target = actionValue
                ? new URL(actionValue, window.location.href)
                : new URL(state.pathname, window.location.href);
            const params = new URLSearchParams(new FormData(form));

            params.set('fragment', '1');
            if (params.has('page')) params.set('page', '1');
            target.search = params.toString();
            loadSection(target.pathname.split('/').pop() + '?' + target.searchParams.toString());
        });

        searchInputs.forEach(function (input) {
            input.addEventListener('input', function (event) {
                if (event.isComposing) return;
                const oldTimer = timers.get(input);
                if (oldTimer) clearTimeout(oldTimer);

                const timer = setTimeout(function () {
                    if (!input.isConnected) return;
                    const focusState = {
                        id: input.id || '',
                        name: input.name || '',
                        value: input.value
                    };
                    window.nawaPendingSearchFocus = focusState;
                    setTimeout(function () {
                        if (window.nawaPendingSearchFocus === focusState) {
                            window.nawaPendingSearchFocus = null;
                        }
                    }, 2500);
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                    }
                }, 220);
                timers.set(input, timer);
            });
        });
    });
}

function nawaDashboardNumber(value) {
    return Number(value || 0).toLocaleString('en-US');
}

async function refreshDashboardLiveCounters() {
    const totalNodes = document.querySelectorAll('[data-dashboard-live-total]');
    if (!totalNodes.length) {
        if (window.nawaDashboardLiveTimer) window.clearInterval(window.nawaDashboardLiveTimer);
        window.nawaDashboardLiveTimer = null;
        return;
    }
    if (window.nawaDashboardLiveBusy) return;
    window.nawaDashboardLiveBusy = true;
    try {
        const response = await fetch('nawa-communications-api.php?action=live_status&_t=' + Date.now(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Cache-Control': 'no-cache' }
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) throw new Error(payload.message || 'Live counter failed');
        const live = payload.data || {};
        totalNodes.forEach(node => node.textContent = nawaDashboardNumber(live.total_connected));
        const hotspotByNetwork = {};
        if (Array.isArray(live.networks)) {
            live.networks.forEach(network => {
                if (!network || typeof network !== 'object') return;
                const key = String(network.network_key || '');
                if (key) {
                    hotspotByNetwork[key] = Number(network.hotspot_connected_count || 0);
                }
            });
        }
        document.querySelectorAll('[data-dashboard-hotspot-network]').forEach(node => {
            const key = node.getAttribute('data-dashboard-hotspot-network') || '';
            node.textContent = nawaDashboardNumber(hotspotByNetwork[key] || 0);
        });
        document.querySelectorAll('[data-dashboard-live-subscribers]').forEach(node => node.textContent = nawaDashboardNumber(live.live_subscribers));
        document.querySelectorAll('[data-dashboard-live-cards]').forEach(node => node.textContent = nawaDashboardNumber(live.live_cards));
        document.querySelectorAll('[data-dashboard-online-devices]').forEach(node => node.textContent = nawaDashboardNumber(live.total_online_devices));
        document.querySelectorAll('[data-dashboard-general-modems]').forEach(node => node.textContent = nawaDashboardNumber(live.general_modems_online)); document.querySelectorAll('[data-dashboard-wireless-devices]').forEach(node => node.textContent = nawaDashboardNumber(live.wireless_devices_online)); document.querySelectorAll('[data-dashboard-subscriber-modems]').forEach(node => node.textContent = nawaDashboardNumber(live.subscriber_modems_online));
        const updatedAt = new Date(Number(live.generated_unix_ms || Date.now())).toLocaleTimeString('ar-YE');
        document.querySelectorAll('[data-dashboard-live-updated]').forEach(node => node.textContent = 'بيانات مباشرة • ' + updatedAt);
    } catch (error) {
        console.warn('Dashboard live counters:', error);
        document.querySelectorAll('[data-dashboard-live-updated]').forEach(node => node.textContent = 'تعذر التحديث اللحظي');
    } finally {
        window.nawaDashboardLiveBusy = false;
    }
}

if (window.nawaDashboardLiveTimer) window.clearInterval(window.nawaDashboardLiveTimer);
refreshDashboardLiveCounters();
window.nawaDashboardLiveTimer = window.setInterval(refreshDashboardLiveCounters, 1000);

window.nawaFragmentCache = window.nawaFragmentCache || new Map();
  const NAWA_FRAGMENT_CACHE_MS = 120000;

function nawaSectionCacheKey(url) {
    return new URL(url, window.location.href).href;
}

function nawaStoreSection(url, html) {
    const cache = window.nawaFragmentCache;
    const key = nawaSectionCacheKey(url);
    cache.delete(key);
    cache.set(key, { html: html, storedAt: Date.now() });
    while (cache.size > 24) cache.delete(cache.keys().next().value);
    return html;
}

function nawaCachedSection(url) {
    const key = nawaSectionCacheKey(url);
    const item = window.nawaFragmentCache.get(key);
    if (!item || Date.now() - item.storedAt > NAWA_FRAGMENT_CACHE_MS) {
        window.nawaFragmentCache.delete(key);
        return null;
    }
    return item.html;
}

function nawaInvalidateSection(url) {
    window.nawaFragmentCache.delete(nawaSectionCacheKey(url));
}

function nawaTdnSectionUrl(value) {
    const raw = String(value || '');
    if (!raw.startsWith('?') && !/^(?:tdn|points)-[a-z0-9-]+\.php(?:[?#]|$)/i.test(raw)) return '';

    const current = window.nawaCurrentSectionUrl || '';
    const base = /^(?:tdn|points)-[a-z0-9-]+\.php(?:[?#]|$)/i.test(current)
        ? new URL(current, window.location.href)
        : window.location.href;
    const target = new URL(raw, base);
    if (target.origin !== window.location.origin) return '';

    const filename = target.pathname.split('/').pop() || '';
    if (!/^(?:tdn-(?:dashboard|dealers|dealer|recharges|recharge|ledger|customers|settings)|points-(?:main|users|transactions|devices|rewards|redemptions|settings))\.php$/i.test(filename)) return '';

    target.hash = '';
    target.searchParams.set('fragment', '1');
    return filename + '?' + target.searchParams.toString();
}

function installGenericTableControls(area) {
    area.querySelectorAll('table.nawa-table, table.tdn-table').forEach(function(table) {
        const tableCard = table.closest('.nawa-card');
        if (tableCard && tableCard.querySelector('[data-users-search], [data-card-page], .cards-pagination')) return;
        if (table.dataset.tableControlsInstalled === '1') return;
        const body = table.tBodies[0];
        const headerRow = table.tHead && table.tHead.rows[0];
        if (!body || !headerRow || body.rows.length < 2) return;
        table.dataset.tableControlsInstalled = '1';
        const rows = Array.from(body.rows);
        const remoteTable = table.classList.contains('tdn-table');
        const wrap = table.closest('.nawa-table-wrap') || table.parentElement;
        const controls = document.createElement('div');
        controls.className = 'nawa-generic-table-controls';
        controls.innerHTML = '<label>عرض <input type="number" min="1" max="500" value="10" inputmode="numeric"> صف</label><span></span>';
        const pager = document.createElement('div');
        pager.className = 'nawa-generic-table-pager';
        wrap.parentNode.insertBefore(controls, wrap);
        wrap.parentNode.insertBefore(pager, wrap.nextSibling);
        const select = controls.querySelector('input'), info = controls.querySelector('span');
        let size = remoteTable ? Math.max(1, Math.min(500, Number(new URLSearchParams((window.nawaCurrentSectionUrl || location.search).split('?')[1] || '').get('per_page')) || rows.length || 25)) : 10, page = 1, sortIndex = -1, descending = false;
        select.value = size;
        if (remoteTable) pager.hidden = true;
        function value(row, index) {
            const text = (row.cells[index] ? row.cells[index].textContent : '').trim();
            const match = text.replace(/,/g, '').match(/-?[0-9]+(?:\.[0-9]+)?/);
            if (match && /(GB|MB|KB|B|يوم|\d)/i.test(text)) {
                let number = Number(match[0]), unit = (text.match(/(GB|MB|KB)/i) || [])[1];
                if (unit === 'GB') number *= 1073741824;
                if (unit === 'MB') number *= 1048576;
                if (unit === 'KB') number *= 1024;
                return { number: number, text: text };
            }
            return { number: null, text: text };
        }
        function render() {
            const ordered = rows.slice().sort(function(a,b) {
                if (sortIndex < 0) return 0;
                const av=value(a,sortIndex), bv=value(b,sortIndex);
                let result = av.number !== null && bv.number !== null ? av.number - bv.number : av.text.localeCompare(bv.text, 'ar', {numeric:true, sensitivity:'base'});
                return descending ? -result : result;
            });
            const pages=Math.max(1,Math.ceil(ordered.length/size)); page=Math.min(page,pages);
            body.textContent='';
            ordered.forEach(function(row,index){ row.hidden=index < (page-1)*size || index >= page*size; body.appendChild(row); });
            info.textContent='إظهار ' + ((page-1)*size+1) + '-' + Math.min(page*size,ordered.length) + ' من ' + ordered.length;
            pager.textContent='';
            if (!remoteTable) for(let i=1;i<=pages;i++){const b=document.createElement('button');b.type='button';b.textContent=i;b.className=i===page?'active':'';b.onclick=function(){page=i;render();};pager.appendChild(b);}
        }
        Array.from(headerRow.cells).forEach(function(cell,index) {
            if (!cell.textContent.trim() || cell.textContent.includes('الإجراءات')) return;
            cell.style.cursor='pointer'; cell.title='اضغط للفرز';
            cell.addEventListener('click',function(){descending=sortIndex===index?!descending:false;sortIndex=index;page=1;render();});
        });
        select.addEventListener('change',function(){size=Math.max(1,Math.min(500,Number(select.value)||10));select.value=size;page=1;if(remoteTable){const url=new URL(window.nawaCurrentSectionUrl || location.href, location.origin);url.searchParams.set('per_page',size);url.searchParams.set('offset','0');loadSection(url.pathname.split('/').pop()+'?'+url.searchParams.toString());return;}render();});
        render();
    });
}

function installTdnFragmentNavigation(area) {
    area.querySelectorAll('a[href]').forEach(function(link) {
        const target = nawaTdnSectionUrl(link.getAttribute('href'));
        if (!target) return;

          link.addEventListener('click', function(event) {
            if (event.defaultPrevented || event.button > 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            loadSection(target);
        });
    });
}

  async function loadSection(url) {
      const navigationStarted = performance.now();
      let requestStarted = navigationStarted;
      let responseStarted = navigationStarted;
      let responseReceived = navigationStarted;
    const area =
        document.querySelector('.dashboard-content') ||
        document.getElementById('dynamicContent');

    if (!area) {
        window.location.href = url;
        return;
    }

    if (window.nawaDashboardLiveTimer) {
        window.clearInterval(window.nawaDashboardLiveTimer);
        window.nawaDashboardLiveTimer = null;
    }

    window.nawaCurrentSectionUrl = url;
    if (window.nawaSectionRequestController) {
        window.nawaSectionRequestController.abort();
    }
    const requestController = new AbortController();
    window.nawaSectionRequestController = requestController;
    const liveSearchRequest = !!window.nawaPendingSearchFocus;

    area.setAttribute('aria-busy', 'true');
    const bypassSectionCache = /(?:^|\/)nawa-vpn\.php(?:\?|$)/i.test(url);
    if (bypassSectionCache && window.nawaFragmentCache) {
        window.nawaFragmentCache.clear();
    }
    let html = bypassSectionCache ? null : nawaCachedSection(url);

    if (html === null) {
        area.innerHTML = `
            <style>
                @keyframes nawa-section-spin {
                    to { transform: rotate(360deg); }
                }
            </style>
            <div style="min-height:196px;display:flex;align-items:center;justify-content:center;padding:28px;">
                <div style="width:100%;min-height:140px;border:1px solid #e5e7eb;border-radius:18px;background:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;box-shadow:0 10px 28px rgba(15,23,42,.04);">
                    <span aria-hidden="true" style="width:30px;height:30px;border:3px solid #fecaca;border-top-color:#e8221c;border-radius:50%;animation:nawa-section-spin .75s linear infinite;"></span>
                    <strong style="color:#64748b;font-size:15px;">جاري فتح الصفحة...</strong>
                </div>
            </div>`;
    }

    try {
          if (html === null) {
              requestStarted = performance.now();
              const response = await fetch(url, {
                credentials: 'same-origin',
                cache: 'no-store',
                signal: requestController.signal,
                headers: {
                    'Cache-Control': 'no-cache'
                  }
              });
              responseStarted = performance.now();

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

              html = await response.text();
              responseReceived = performance.now();
              nawaStoreSection(url, html);
          } else {
              requestStarted = responseStarted = responseReceived = performance.now();
        }
        if (window.nawaCurrentSectionUrl !== url) return;
        area.innerHTML = html;

        area.querySelectorAll('script').forEach(function(oldScript) {
            const script = document.createElement('script');

            Array.from(oldScript.attributes).forEach(function(attr) {
                script.setAttribute(attr.name, attr.value);
            });

            script.textContent = oldScript.textContent;
            document.body.appendChild(script);
            oldScript.remove();
        });

        installTdnFragmentNavigation(area);

        const search = area.querySelector('#fastSubscribersSearch');

        if (search) {
            search.addEventListener('submit', function (event) {
                event.preventDefault();

                const formData = new FormData(search);
                const q = formData.get('q') || '';

                loadSection(
                    'subscribers-fragment.php?q=' +
                    encodeURIComponent(q)
                );
            });
        }

        
        const usersSearch = area.querySelector('[data-users-search]');
        if (usersSearch) {
            usersSearch.addEventListener('submit', function(event) {
                event.preventDefault();

                const params = new URLSearchParams(
                    new FormData(usersSearch)
                );

                params.set('fragment', '1');
                params.set('page', '1');

                loadSection(
                    'nawa-users.php?' + params.toString()
                );
            });
        }

        
        
        /* تنفيذ كل إجراءات الأقسام داخل الـ Fragment مع حفظ القسم والبحث. */
        area.querySelectorAll('form').forEach(function(form) {
            if (!form.querySelector('input[name="action"]')) return;

            form.addEventListener('submit', async function(event) {
                if (event.defaultPrevented) return;
                event.preventDefault();

                const submitButton = form.querySelector('button[type="submit"]');
                const oldText = submitButton ? submitButton.innerHTML : '';

                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.innerHTML = 'جاري التنفيذ...';
                }

                try {
                    const stateUrl = window.nawaCurrentSectionUrl || 'home-modern.php';
                    const state = new URL(stateUrl, window.location.href);
                    const body = new FormData(form);
                    const actionValue = form.getAttribute('action');
                    let postTarget;
                    if (actionValue) {
                        postTarget = actionValue;
                    } else {
                        const filename = state.pathname.split('/').pop();
                        const postParams = new URLSearchParams();
                        postParams.set('fragment', '1');
                        postTarget = filename + '?' + postParams.toString();
                    }

                    const response = await fetch(
                        postTarget,
                        {
                            method: 'POST',
                            body: body,
                            credentials: 'same-origin'
                        }
                    );

                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }

                    const responseHtml = await response.text();
                    const responseDoc = new DOMParser().parseFromString(responseHtml, 'text/html');
                    const resultAlert = responseDoc.querySelector('.tdn-alert, .nawa-alert');
                    const resultMessage = resultAlert ? resultAlert.textContent.trim() : '';
                    const resultIsError = !!(resultAlert && resultAlert.classList.contains('danger'));

                    nawaInvalidateSection(stateUrl);
                    await loadSection(stateUrl);
                    if (resultMessage) showNawaActionToast(resultMessage, resultIsError);

                } catch (error) {
                    console.error(error);
                    alert('تعذر تنفيذ العملية. حاول مرة أخرى.');

                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.innerHTML = oldText;
                    }
                }
            });
        });


        function loadUsersList(page, search, sort, dir, perPage) {
            loadSection('nawa-users.php?fragment=1&page=' + encodeURIComponent(page || '1') +
                '&search=' + encodeURIComponent(search || '') + '&sort=' + encodeURIComponent(sort || 'username') +
                '&dir=' + encodeURIComponent(dir || 'asc') + '&per_page=' + encodeURIComponent(perPage || '50'));
        }
        area.querySelectorAll('[data-users-page]').forEach(function(button) {
            button.addEventListener('click', function() {
                loadUsersList(button.dataset.usersPage, button.dataset.usersSearchValue, button.dataset.usersSortValue, button.dataset.usersDirValue, button.dataset.usersPerPageValue);
            });
        });
        area.querySelectorAll('[data-users-sort]').forEach(function(button) {
            button.addEventListener('click', function() {
                const form = area.querySelector('[data-users-search]');
                loadUsersList('1', form ? (form.querySelector('[name="search"]').value || '') : '', button.dataset.usersSort, button.dataset.usersDir, form ? (form.querySelector('[name="per_page"]').value || '50') : '50');
            });
        });
        area.querySelectorAll('[data-users-per-page]').forEach(function(select) {
            select.addEventListener('change', function() {
                const form = area.querySelector('[data-users-search]');
                loadUsersList('1', form ? (form.querySelector('[name="search"]').value || '') : '', form ? (form.querySelector('[name="sort"]').value || 'username') : 'username', form ? (form.querySelector('[name="dir"]').value || 'asc') : 'asc', select.value);
            });
        });

        function loadCardsList(page, search, sort, dir, perPage) {
            loadSection('nawa-card-recharge.php?fragment=1&page=' + encodeURIComponent(page || '1') +
                '&card=' + encodeURIComponent(search || '') + '&sort=' + encodeURIComponent(sort || 'id') +
                '&dir=' + encodeURIComponent(dir || 'desc') + '&per_page=' + encodeURIComponent(perPage || '50'));
        }
        area.querySelectorAll('[data-card-page]').forEach(function(button) {
            button.addEventListener('click', function() {
                loadCardsList(button.dataset.cardPage, button.dataset.cardSearchValue, button.dataset.cardSortValue, button.dataset.cardDirValue, button.dataset.cardPerPageValue || '50');
            });
        });
        area.querySelectorAll('[data-card-sort]').forEach(function(button) {
            button.addEventListener('click', function() {
                const searchInput = area.querySelector('[data-card-search-form] [name="card"]');
                loadCardsList('1', searchInput ? searchInput.value : '', button.dataset.cardSort, button.dataset.cardDir, (area.querySelector('[data-card-per-page]') || {}).value || '50');
            });
        });

        area.querySelectorAll('[data-card-per-page]').forEach(function(input) {
            input.addEventListener('change', function() {
                const form = area.querySelector('[data-card-search-form]');
                const amount = Math.max(1, Math.min(500, Number(input.value) || 50));
                input.value = amount;
                loadSection('nawa-card-recharge.php?fragment=1&page=1&card=' + encodeURIComponent(form ? (form.querySelector('[name="card"]').value || '') : '') + '&sort=' + encodeURIComponent(form ? (form.querySelector('[name="sort"]').value || 'id') : 'id') + '&dir=' + encodeURIComponent(form ? (form.querySelector('[name="dir"]').value || 'desc') : 'desc') + '&per_page=' + amount);
            });
        });

const clearUsersSearch = area.querySelector('[data-clear-users-search]');
        if (clearUsersSearch) {
            clearUsersSearch.addEventListener('click', function() {
                loadSection('nawa-users.php?fragment=1');
            });
        }

        installNawaLiveSearch(area);
        installGenericTableControls(area);

        const searchFocus = window.nawaPendingSearchFocus;
        window.nawaPendingSearchFocus = null;
        if (searchFocus) {
            setTimeout(function () {
                let input = null;
                if (searchFocus.id) input = area.querySelector('#' + CSS.escape(searchFocus.id));
                if (!input && searchFocus.name) {
                    input = Array.from(area.querySelectorAll('input[type="search"]')).find(function (candidate) {
                        return candidate.name === searchFocus.name;
                    }) || null;
                }
                if (input) {
                    input.focus({ preventScroll: true });
                    const end = input.value.length;
                    if (typeof input.setSelectionRange === 'function') input.setSelectionRange(end, end);
                }
            }, 0);
        } else {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

          area.removeAttribute('aria-busy');
          window.requestAnimationFrame(function () {
              const usableAt = performance.now();
              window.nawaLastNavigationTiming = {
                  url: url,
                  cacheHit: responseReceived === responseStarted && responseStarted === requestStarted,
                  clickToRequestStartMs: +(requestStarted - navigationStarted).toFixed(1),
                  serverAndTtfbMs: +(responseStarted - requestStarted).toFixed(1),
                  responseDownloadMs: +(responseReceived - responseStarted).toFixed(1),
                  responseToUsableDomMs: +(usableAt - responseReceived).toFixed(1),
                  totalClickToUsableMs: +(usableAt - navigationStarted).toFixed(1)
              };
              console.debug('3D Radius navigation timing', window.nawaLastNavigationTiming);
          });

    } catch (error) {
        if (error && error.name === 'AbortError') return;
        area.removeAttribute('aria-busy');
        area.innerHTML = `
            <div class="panel" style="padding:30px">
                تعذر تحميل الصفحة.
            </div>
        `;
    }
}

const tdnInitialSection = new URLSearchParams(window.location.search).get('section');
const tdnInitialUrl = tdnInitialSection ? nawaTdnSectionUrl(tdnInitialSection) : '';
if (tdnInitialUrl) loadSection(tdnInitialUrl);
</script>


<script>
(function () {
  if (window.__recentDownDashboardInstalled) return;
  window.__recentDownDashboardInstalled = true;

  const panel = document.querySelector('[data-recent-down-panel]');
  if (!panel) return;

  const list = panel.querySelector('[data-recent-down-list]');
  const count = panel.querySelector('[data-recent-down-count]');
  const typeLabels = {
    general_modem: 'مودم عام',
    wireless: 'إرسال / استقبال',
    subscriber: 'مودم مشترك'
  };

  function ageText(seconds) {
    seconds = Math.max(0, Number(seconds) || 0);
    if (seconds < 60) return 'منذ ' + seconds + ' ثانية';
    const minutes = Math.floor(seconds / 60);
    return 'منذ ' + minutes + ' دقيقة';
  }

  function networkText(key, fallback) {
    const m = String(key || '').match(/network(\d+)/i);
    return m ? 'الشبكة ' + m[1] : (fallback || key || 'شبكة');
  }

  function node(tag, text) {
    const el = document.createElement(tag);
    if (text !== undefined) el.textContent = text;
    return el;
  }

  function render(data) {
    const items = Array.isArray(data && data.items) ? data.items : [];
    count.textContent = String(items.length);
    list.textContent = '';

    if (!items.length) {
      const empty = node('div', 'لا توجد قطع فصلت خلال آخر 10 دقائق.');
      empty.style.cssText = 'padding:18px;text-align:center;color:#8b92a1;font-size:11px';
      list.appendChild(empty);
      return;
    }

    const wrap = document.createElement('div');
    wrap.style.cssText = 'display:grid;gap:8px';

    items.forEach(function (item) {
      const row = document.createElement('div');
      row.style.cssText = 'display:grid;grid-template-columns:minmax(180px,1.4fr) minmax(100px,.75fr) minmax(110px,.8fr) minmax(115px,.75fr);gap:10px;align-items:center;padding:10px 12px;border:1px solid #eceef2;border-radius:12px;background:#fff';

      const main = document.createElement('div');
      main.style.cssText = 'min-width:0';
      const title = node('b', item.device_name || item.ip_address || 'جهاز');
      title.style.cssText = 'display:block;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis';
      const meta = node('small', (item.ip_address || '') + (item.interface ? ' • ' + item.interface : ''));
      meta.style.cssText = 'display:block;margin-top:3px;color:#8b92a1;font-size:9px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis';
      main.appendChild(title);
      main.appendChild(meta);

      const net = node('div', networkText(item.network_key, item.network_name));
      net.style.cssText = 'font-size:10px;font-weight:800;color:#4c5565';

      const type = node('div', typeLabels[item.device_category] || item.device_category || 'جهاز');
      type.style.cssText = 'font-size:10px;color:#667085';

      const state = document.createElement('div');
      state.style.cssText = 'text-align:left;white-space:nowrap';
      const isDown = item.current_status === 'offline';
      const badge = node('span', isDown ? 'لا يزال مفصول' : (item.current_status === 'online' ? 'رجع للعمل' : item.current_status));
      badge.style.cssText = isDown
        ? 'display:inline-block;padding:4px 7px;border-radius:999px;background:#ffebe9;color:#b42318;font-size:8px;font-weight:900'
        : 'display:inline-block;padding:4px 7px;border-radius:999px;background:#e7f8f1;color:#067647;font-size:8px;font-weight:900';
      const age = node('small', ageText(item.age_seconds));
      age.style.cssText = 'display:block;margin-top:4px;color:#8b92a1;font-size:8px';
      state.appendChild(badge);
      state.appendChild(age);

      row.appendChild(main);
      row.appendChild(net);
      row.appendChild(type);
      row.appendChild(state);
      wrap.appendChild(row);
    });

    list.appendChild(wrap);
  }

  async function refreshRecentDown() {
    try {
      const response = await fetch('netwatch-recent-down.php?_=' + Date.now(), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Accept': 'application/json' }
      });
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const data = await response.json();
      if (!data || data.ok !== true) throw new Error('bad response');
      render(data);
    } catch (error) {
      count.textContent = '!';
      list.textContent = '';
      const msg = node('div', 'تعذر تحديث آخر القطع التي فصلت');
      msg.style.cssText = 'padding:18px;text-align:center;color:#b42318;font-size:11px';
      list.appendChild(msg);
    }
  }

  refreshRecentDown();
  window.setInterval(refreshRecentDown, 10000);
})();
</script>

</body>
</html>
