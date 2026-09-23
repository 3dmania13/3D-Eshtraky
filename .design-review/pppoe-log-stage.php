<?php

declare(strict_types=1);
date_default_timezone_set('Asia/Aden');

include 'library/checklogin.php';
$operator = $_SESSION['operator_user'] ?? '';
include_once '../common/includes/config_read.php';
$operator_perm_file = 'mng_list_all';
include 'library/check_operator_perm.php';
include_once 'lang/main.php';
include '../common/includes/layout.php';
include_once 'include/nawa/functions.php';
include '../common/includes/db_open.php';

$fragmentMode = isset($_GET['fragment']) && $_GET['fragment'] === '1';
$tab = trim((string)($_GET['tab'] ?? 'all'));
$allowedTabs = ['all', 'auth', 'sessions', 'recharges', 'audit'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'all';
}

$search = trim((string)($_GET['search'] ?? ''));
$hours = (int)($_GET['hours'] ?? 24);
$allowedHours = [24, 72, 168, 720];
if (!in_array($hours, $allowedHours, true)) {
    $hours = 24;
}

$authResult = trim((string)($_GET['auth_result'] ?? 'all'));
if (!in_array($authResult, ['all', 'accept', 'reject'], true)) {
    $authResult = 'all';
}

$perSourceLimit = $tab === 'all' ? 60 : 100;
$events = [];
$errors = [];
$qSql = $dbSocket->escapeSimple(str_replace(['%', '_'], '', $search));

function pppoe_log_db_time_to_aden(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }

    try {
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $dt->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return $value;
    }
}

function pppoe_log_local_time(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }

    try {
        $dt = new DateTimeImmutable($value, new DateTimeZone('Asia/Aden'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return $value;
    }
}

function pppoe_log_epoch(?string $value, bool $alreadyLocal = false): int
{
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }

    try {
        $tz = new DateTimeZone($alreadyLocal ? 'Asia/Aden' : 'UTC');
        return (new DateTimeImmutable($value, $tz))->getTimestamp();
    } catch (Throwable $e) {
        return 0;
    }
}

function pppoe_log_bytes($bytes): string
{
    $bytes = max(0, (float)$bytes);
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return number_format($bytes, 0) . ' B';
}

function pppoe_log_duration($seconds): string
{
    $seconds = max(0, (int)$seconds);
    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = intdiv($seconds, 60);

    $parts = [];
    if ($days > 0) {
        $parts[] = $days . ' يوم';
    }
    if ($hours > 0) {
        $parts[] = $hours . ' ساعة';
    }
    if ($minutes > 0 || !$parts) {
        $parts[] = $minutes . ' دقيقة';
    }
    return implode(' ', $parts);
}

function pppoe_log_action_label(string $action): string
{
    $map = [
        'pppoe.user.create' => 'إنشاء حساب',
        'pppoe.user.recharge' => 'شحن حساب',
        'pppoe.user.disable' => 'تعطيل حساب',
        'pppoe.user.enable' => 'تفعيل حساب',
        'pppoe.user.delete' => 'حذف حساب',
        'pppoe.user.update' => 'تعديل حساب',
        'pppoe.package.create' => 'إنشاء باقة',
        'pppoe.package.update' => 'تعديل باقة',
        'pppoe.package.delete' => 'حذف باقة',
    ];
    return $map[$action] ?? $action;
}

function pppoe_log_audit_details(?string $json): string
{
    $data = json_decode((string)$json, true);
    if (!is_array($data) || !$data) {
        return '—';
    }

    $labels = [
        'previous_status' => 'الحالة السابقة',
        'radius_enabled' => 'RADIUS',
        'package_name' => 'الباقة',
        'package_id' => 'رقم الباقة',
        'quota_gb' => 'الحجم',
        'validity_days' => 'الأيام',
        'rate_limit' => 'السرعة',
        'price' => 'السعر',
        'expiration' => 'الانتهاء',
        'clear_current' => 'إعادة ضبط',
    ];

    $parts = [];
    foreach ($data as $key => $value) {
        if (!array_key_exists($key, $labels)) {
            continue;
        }
        if (is_bool($value)) {
            $value = $value ? 'نعم' : 'لا';
        } elseif ($value === null) {
            $value = '—';
        } elseif (is_array($value)) {
            continue;
        }
        if ($key === 'quota_gb') {
            $value .= ' GB';
        }
        if ($key === 'price') {
            $value .= ' YER';
        }
        $parts[] = $labels[$key] . ': ' . $value;
    }

    return $parts ? implode(' • ', $parts) : '—';
}

$pppoeMembershipAuth = "(
    EXISTS (SELECT 1 FROM nawa_pppoe_users pu WHERE pu.username=pa.username)
    OR EXISTS (SELECT 1 FROM nawa_pppoe_recharges pr WHERE pr.username=pa.username)
)";

$pppoeMembershipAcct = "(
    EXISTS (SELECT 1 FROM nawa_pppoe_users pu WHERE pu.username = ra.username COLLATE utf8mb4_unicode_ci)
    OR EXISTS (SELECT 1 FROM nawa_pppoe_recharges pr WHERE pr.username = ra.username COLLATE utf8mb4_unicode_ci)
)";

if ($tab === 'all' || $tab === 'auth') {
    $where = [
        "pa.authdate >= DATE_SUB(DATE_ADD(NOW(), INTERVAL 3 HOUR), INTERVAL {$hours} HOUR)",
        $pppoeMembershipAuth,
    ];

    if ($search !== '') {
        $where[] = "pa.username LIKE '%{$qSql}%'";
    }
    if ($authResult === 'accept') {
        $where[] = "pa.reply='Access-Accept'";
    } elseif ($authResult === 'reject') {
        $where[] = "pa.reply='Access-Reject'";
    }

    $sql = "
        SELECT pa.id,pa.username,pa.reply,pa.authdate
        FROM radpostauth pa FORCE INDEX (idx_radpostauth_authdate)
        WHERE " . implode(' AND ', $where) . "
        ORDER BY pa.authdate DESC
        LIMIT {$perSourceLimit}
    ";

    $r = $dbSocket->query($sql);
    if (DB::isError($r)) {
        $errors[] = 'تعذر قراءة محاولات الدخول.';
    } else {
        while ($x = $r->fetchRow(DB_FETCHMODE_ASSOC)) {
            $accepted = ((string)$x['reply'] === 'Access-Accept');
            $events[] = [
                'sort' => pppoe_log_epoch((string)$x['authdate'], true),
                'time' => pppoe_log_local_time((string)$x['authdate']),
                'type' => 'auth',
                'type_label' => 'دخول RADIUS',
                'username' => (string)$x['username'],
                'status' => $accepted ? 'مقبول' : 'مرفوض',
                'status_class' => $accepted ? 'success' : 'danger',
                'details' => $accepted
                    ? 'تم قبول بيانات الدخول بواسطة RADIUS.'
                    : 'تم رفض محاولة الدخول بواسطة RADIUS.',
            ];
        }
    }
}

if ($tab === 'all' || $tab === 'sessions') {
    $where = [
        "ra.acctstarttime >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)",
        $pppoeMembershipAcct,
    ];
    if ($search !== '') {
        $where[] = "ra.username LIKE '%{$qSql}%'";
    }

    $sql = "
        SELECT
            ra.radacctid,ra.username,ra.acctstarttime,ra.acctupdatetime,ra.acctstoptime,
            ra.acctsessiontime,ra.nasipaddress,ra.calledstationid,ra.callingstationid,
            ra.framedipaddress,ra.acctterminatecause,
            CASE
                WHEN COALESCE(ra.input_octets64,0) > 0 THEN ra.input_octets64
                ELSE COALESCE(ra.acctinputoctets,0) + (COALESCE(ra.AcctInputGigawords,0) * 4294967296)
            END AS upload_bytes,
            CASE
                WHEN COALESCE(ra.output_octets64,0) > 0 THEN ra.output_octets64
                ELSE COALESCE(ra.acctoutputoctets,0) + (COALESCE(ra.AcctOutputGigawords,0) * 4294967296)
            END AS download_bytes
        FROM radacct ra FORCE INDEX (acctstarttime)
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ra.acctstarttime DESC
        LIMIT {$perSourceLimit}
    ";

    $r = $dbSocket->query($sql);
    if (DB::isError($r)) {
        $errors[] = 'تعذر قراءة جلسات PPPoE.';
    } else {
        while ($x = $r->fetchRow(DB_FETCHMODE_ASSOC)) {
            $active = trim((string)$x['acctstoptime']) === '';
            $details = [];
            if (trim((string)$x['framedipaddress']) !== '') {
                $details[] = 'IP: ' . $x['framedipaddress'];
            }
            if (trim((string)$x['callingstationid']) !== '') {
                $details[] = 'MAC: ' . $x['callingstationid'];
            }
            if (trim((string)$x['nasipaddress']) !== '') {
                $details[] = 'NAS: ' . $x['nasipaddress'];
            }
            if (trim((string)$x['calledstationid']) !== '') {
                $details[] = 'الخدمة: ' . $x['calledstationid'];
            }
            $details[] = 'رفع: ' . pppoe_log_bytes($x['upload_bytes']);
            $details[] = 'تحميل: ' . pppoe_log_bytes($x['download_bytes']);
            $details[] = 'المدة: ' . pppoe_log_duration($x['acctsessiontime']);
            if (!$active && trim((string)$x['acctterminatecause']) !== '') {
                $details[] = 'سبب الفصل: ' . $x['acctterminatecause'];
            }

            $events[] = [
                'sort' => pppoe_log_epoch((string)$x['acctstarttime']),
                'time' => pppoe_log_db_time_to_aden((string)$x['acctstarttime']),
                'type' => 'sessions',
                'type_label' => 'جلسة PPPoE',
                'username' => (string)$x['username'],
                'status' => $active ? 'متصل الآن' : 'منتهية',
                'status_class' => $active ? 'success' : 'muted',
                'details' => implode(' • ', $details),
            ];
        }
    }
}

if ($tab === 'all' || $tab === 'recharges') {
    $where = ["r.created_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)"];
    if ($search !== '') {
        $where[] = "r.username LIKE '%{$qSql}%'";
    }

    $sql = "
        SELECT r.*,p.name AS package_name
        FROM nawa_pppoe_recharges r
        LEFT JOIN nawa_pppoe_packages p ON p.id=r.package_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY r.created_at DESC,r.id DESC
        LIMIT {$perSourceLimit}
    ";

    $r = $dbSocket->query($sql);
    if (DB::isError($r)) {
        $errors[] = 'تعذر قراءة سجل الشحن والتجديد.';
    } else {
        $rechargeLabels = [
            'initial' => 'إنشاء / رصيد أولي',
            'recharge' => 'شحن',
            'renew' => 'تجديد',
            'package_change' => 'تغيير باقة',
            'manual_adjustment' => 'تعديل يدوي',
        ];

        while ($x = $r->fetchRow(DB_FETCHMODE_ASSOC)) {
            $details = [];
            $details[] = 'الباقة: ' . ((string)($x['package_name'] ?? '') !== '' ? $x['package_name'] : ('#' . $x['package_id']));
            $details[] = 'الحجم: ' . pppoe_log_bytes($x['quota_bytes']);
            $details[] = 'المدة: ' . (int)$x['validity_days'] . ' يوم';
            $details[] = 'السعر: ' . number_format((float)$x['price'], 2) . ' YER';
            if (trim((string)$x['new_expires_at']) !== '') {
                $details[] = 'الانتهاء: ' . pppoe_log_local_time((string)$x['new_expires_at']);
            }
            if (trim((string)$x['operator_name']) !== '') {
                $details[] = 'الموظف: ' . $x['operator_name'];
            }

            $events[] = [
                'sort' => pppoe_log_epoch((string)$x['created_at']),
                'time' => pppoe_log_db_time_to_aden((string)$x['created_at']),
                'type' => 'recharges',
                'type_label' => 'شحن / تجديد',
                'username' => (string)$x['username'],
                'status' => $rechargeLabels[(string)$x['action_type']] ?? (string)$x['action_type'],
                'status_class' => 'info',
                'details' => implode(' • ', $details),
            ];
        }
    }
}

if ($tab === 'all' || $tab === 'audit') {
    $where = [
        "a.created_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)",
        "a.action_name LIKE 'pppoe.%'",
    ];
    if ($search !== '') {
        $where[] = "(a.subject_id LIKE '%{$qSql}%' OR a.operator_name LIKE '%{$qSql}%' OR a.action_name LIKE '%{$qSql}%')";
    }

    $sql = "
        SELECT a.id,a.operator_name,a.action_name,a.subject_type,a.subject_id,a.details,a.ip_address,a.created_at
        FROM nawa_audit_log a FORCE INDEX (idx_nawa_audit_created)
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.created_at DESC,a.id DESC
        LIMIT {$perSourceLimit}
    ";

    $r = $dbSocket->query($sql);
    if (DB::isError($r)) {
        $errors[] = 'تعذر قراءة عمليات الموظفين.';
    } else {
        while ($x = $r->fetchRow(DB_FETCHMODE_ASSOC)) {
            $details = [];
            $auditDetails = pppoe_log_audit_details((string)$x['details']);
            if ($auditDetails !== '—') {
                $details[] = $auditDetails;
            }
            if (trim((string)$x['operator_name']) !== '') {
                $details[] = 'الموظف: ' . $x['operator_name'];
            }
            if (trim((string)$x['ip_address']) !== '') {
                $details[] = 'IP الموظف: ' . $x['ip_address'];
            }

            $events[] = [
                'sort' => pppoe_log_epoch((string)$x['created_at']),
                'time' => pppoe_log_db_time_to_aden((string)$x['created_at']),
                'type' => 'audit',
                'type_label' => 'عملية إدارية',
                'username' => (string)$x['subject_id'],
                'status' => pppoe_log_action_label((string)$x['action_name']),
                'status_class' => 'warning',
                'details' => $details ? implode(' • ', $details) : '—',
            ];
        }
    }
}

usort($events, static function (array $a, array $b): int {
    return ($b['sort'] <=> $a['sort']);
});

if ($tab === 'all' && count($events) > 100) {
    $events = array_slice($events, 0, 100);
}

// Presentation counters calculated from the same read-only result set.
$logStats = ['sessions' => 0, 'active' => 0, 'auth' => 0, 'logout' => 0];
foreach ($events as $event) {
    if ((string) $event['type'] === 'sessions') {
        $logStats['sessions']++;
        if ((string) $event['status'] === 'متصل الآن') $logStats['active']++;
    }
    if ((string) $event['type'] === 'auth') $logStats['auth']++;
    if ((string) $event['type'] === 'sessions' && (string) $event['status'] === 'منتهية') $logStats['logout']++;
}

$tabLabels = [
    'all' => 'الكل',
    'auth' => 'محاولات الدخول',
    'sessions' => 'الجلسات',
    'recharges' => 'الشحن والتجديد',
    'audit' => 'عمليات الموظفين',
];

if (!$fragmentMode) {
    print_html_prologue('سجل البرودباند', $langCode, ['static/css/nawa-production.css']);
}
?>

<style>
.pppoe-log-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0}
.pppoe-log-tab{padding:9px 13px;border:1px solid #d9dde5;border-radius:10px;text-decoration:none;color:inherit;background:#fff}
.pppoe-log-tab.active{font-weight:700;border-color:#8b95a7;box-shadow:0 1px 4px rgba(0,0,0,.08)}
.pppoe-log-filters{display:grid;grid-template-columns:minmax(220px,1fr) 180px 180px auto;gap:10px;align-items:end}
.pppoe-log-filters label{display:block;font-size:12px;color:#687080;margin-bottom:5px}
.pppoe-log-filters input,.pppoe-log-filters select{width:100%;min-height:42px}
.pppoe-log-details{min-width:360px;white-space:normal;line-height:1.7}
.pppoe-log-user{font-weight:700;direction:ltr;text-align:right}
.pppoe-log-time{white-space:nowrap;direction:ltr}
@media(max-width:900px){.pppoe-log-filters{grid-template-columns:1fr 1fr}.pppoe-log-details{min-width:280px}}
@media(max-width:600px){.pppoe-log-filters{grid-template-columns:1fr}}
</style>

<link rel="stylesheet" href="static/css/pppoe-log-reference.css?v=20260920">
<div class="nawa-page pppoe-log-reference">

<section class="nawa-page-header">
    <div>
        <i class="bi bi-journal-text pppoe-log-heading-icon" aria-hidden="true"></i>
        <h1>سجل البرودباند</h1>
        <p>دخول RADIUS والجلسات والشحن وعمليات الموظفين لحسابات PPPoE الجديدة فقط.</p>
    </div>
    <div class="nawa-page-actions">
        <a class="nawa-button" href="javascript:void(0)" onclick="loadSection('nawa-pppoe-users.php?fragment=1')">
            <i class="bi bi-people"></i> مشتركو PPPoE
        </a>
    </div>
</section>

<section class="pppoe-log-stats" aria-label="ملخص سجل البرودباند">
    <article class="pppoe-log-stat"><i class="bi bi-clock-history"></i><span>إجمالي الجلسات</span><strong><?=number_format($logStats['sessions'])?></strong></article>
    <article class="pppoe-log-stat"><i class="bi bi-people"></i><span>المستخدمين النشطين</span><strong><?=number_format($logStats['active'])?></strong></article>
    <article class="pppoe-log-stat"><i class="bi bi-box-arrow-in-left"></i><span>عمليات الدخول</span><strong><?=number_format($logStats['auth'])?></strong></article>
    <article class="pppoe-log-stat"><i class="bi bi-power"></i><span>عمليات الخروج</span><strong><?=number_format($logStats['logout'])?></strong></article>
</section>

<?php foreach ($errors as $error): ?>
<div class="nawa-alert danger"><i class="bi bi-exclamation-triangle"></i><span><?=nawa_e($error)?></span></div>
<?php endforeach; ?>

<section class="nawa-card">
    <header class="nawa-card-header">
        <div>
            <h2>البحث والتصفية</h2>
            <p>الافتراضي آخر 24 ساعة، مع حد أقصى 100 حدث في العرض.</p>
        </div>
    </header>

    <form id="pppoeLogFilter" class="pppoe-log-filters">
        <input type="hidden" name="tab" value="<?=nawa_e($tab)?>">

        <div>
            <label>اسم المستخدم / الموظف</label>
            <input type="text" name="search" value="<?=nawa_e($search)?>" placeholder="مثال: asd">
        </div>

        <div>
            <label>الفترة</label>
            <select name="hours">
                <option value="24" <?=$hours===24?'selected':''?>>آخر 24 ساعة</option>
                <option value="72" <?=$hours===72?'selected':''?>>آخر 3 أيام</option>
                <option value="168" <?=$hours===168?'selected':''?>>آخر 7 أيام</option>
                <option value="720" <?=$hours===720?'selected':''?>>آخر 30 يومًا</option>
            </select>
        </div>

        <div>
            <label>نتيجة الدخول</label>
            <select name="auth_result">
                <option value="all" <?=$authResult==='all'?'selected':''?>>الكل</option>
                <option value="accept" <?=$authResult==='accept'?'selected':''?>>مقبول فقط</option>
                <option value="reject" <?=$authResult==='reject'?'selected':''?>>مرفوض فقط</option>
            </select>
        </div>

        <div>
            <button class="nawa-button" type="submit"><i class="bi bi-search"></i> بحث</button>
        </div>
    </form>
</section>

<nav class="pppoe-log-tabs">
<?php foreach ($tabLabels as $key => $label):
    $params = http_build_query([
        'fragment' => 1,
        'tab' => $key,
        'search' => $search,
        'hours' => $hours,
        'auth_result' => $authResult,
    ]);
?>
<a class="pppoe-log-tab <?=$tab===$key?'active':''?>" href="javascript:void(0)" onclick="loadSection('nawa-pppoe-log.php?<?=nawa_e($params)?>')">
    <?=nawa_e($label)?>
</a>
<?php endforeach; ?>
</nav>

<section class="nawa-card">
    <header class="nawa-card-header">
        <div>
            <h2><?=nawa_e($tabLabels[$tab])?></h2>
            <p>المعروض: <?=number_format(count($events))?> حدث</p>
        </div>
    </header>

<?php if (!$events): ?>
    <div class="nawa-empty"><i class="bi bi-journal-text"></i>لا توجد أحداث مطابقة للفلاتر الحالية.</div>
<?php else: ?>
    <div class="nawa-table-wrap" style="overflow-x:auto">
        <table class="nawa-table">
            <thead>
                <tr>
                    <th>الوقت</th>
                    <th>النوع</th>
                    <th>المستخدم</th>
                    <th>الحالة / العملية</th>
                    <th>التفاصيل</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($events as $event): ?>
                <tr>
                    <td class="pppoe-log-time"><?=nawa_e((string)$event['time'])?></td>
                    <td><span class="nawa-badge muted"><?=nawa_e((string)$event['type_label'])?></span></td>
                    <td class="pppoe-log-user"><?=nawa_e((string)$event['username'])?></td>
                    <td><span class="nawa-badge <?=nawa_e((string)$event['status_class'])?>"><?=nawa_e((string)$event['status'])?></span></td>
                    <td class="pppoe-log-details"><?=nawa_e((string)$event['details'])?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</section>

</div>

<script>
(function(){
    var form = document.getElementById('pppoeLogFilter');
    if (!form) return;

    form.addEventListener('submit', function(e){
        e.preventDefault();
        var params = new URLSearchParams(new FormData(form));
        params.set('fragment', '1');
        if (window.loadSection) {
            window.loadSection('nawa-pppoe-log.php?' + params.toString());
        } else {
            window.location.href = 'nawa-pppoe-log.php?' + params.toString();
        }
    });
})();
</script>

<?php
include '../common/includes/db_close.php';
if (!$fragmentMode) {
    print_footer_and_html_epilogue('');
}
?>
