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
$csrfToken = dalo_csrf_token();
$search = trim((string)($_GET['search'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 50;
$offset = ($page - 1) * $pageSize;

$where = '';
if ($search !== '') {
    $s = $dbSocket->escapeSimple(str_replace(['%', '_'], '', $search));
    $where = sprintf(
        "WHERE u.username LIKE '%%%s%%' OR COALESCE(u.full_name,'') LIKE '%%%s%%' OR COALESCE(u.mobilephone,'') LIKE '%%%s%%'",
        $s, $s, $s
    );
}

$count = $dbSocket->getOne("SELECT COUNT(*) FROM nawa_pppoe_users u $where");
$totalUsers = DB::isError($count) ? 0 : (int)$count;
$totalPages = max(1, (int)ceil($totalUsers / $pageSize));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $pageSize;
}

$sql = sprintf("
SELECT
 u.username,u.full_name,u.mobilephone,u.status,u.total_quota,u.used_quota,
 u.expires_at,u.radius_enabled,p.name package_name,p.rate_limit,
 p.upload_speed,p.download_speed
FROM nawa_pppoe_users u
LEFT JOIN nawa_pppoe_packages p ON p.id=u.package_id
%s
ORDER BY u.id DESC
LIMIT %d,%d
", $where, $offset, $pageSize);

$result = $dbSocket->query($sql);
$users = [];
if (nawa_db_ok($result)) {
    while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) $users[] = $row;
}

$active = $dbSocket->getOne("SELECT COUNT(*) FROM nawa_pppoe_users WHERE status='active'");
$disabled = $dbSocket->getOne("SELECT COUNT(*) FROM nawa_pppoe_users WHERE status IN ('disabled','expired')");
$radiusEnabled = $dbSocket->getOne("SELECT COUNT(*) FROM nawa_pppoe_users WHERE radius_enabled=1");
$active = DB::isError($active) ? 0 : (int)$active;
$disabled = DB::isError($disabled) ? 0 : (int)$disabled;
$radiusEnabled = DB::isError($radiusEnabled) ? 0 : (int)$radiusEnabled;

if (!$fragmentMode) print_html_prologue('مشتركو PPPoE', $langCode, ['static/css/nawa-production.css']);
?>
<link rel="stylesheet" href="static/css/pppoe-reference.css?v=20260920">
<div class="nawa-page pppoe-reference">
<section class="nawa-page-header">
 <div><i class="bi bi-wifi pppoe-hero-icon" aria-hidden="true"></i><h1>إدارة مستخدمي PPPoE</h1><p>تحكم كامل بمستخدمي البرودباند وسرعاتهم وحالاتهم في مكان واحد.</p></div>
 <div class="pppoe-router-art" aria-hidden="true"><i></i><i></i></div>
 <div class="nawa-page-actions"><span class="nawa-badge muted">RADIUS حسب كل حساب</span><a class="nawa-button primary" href="javascript:void(0)" onclick="loadSection('nawa-pppoe-user-new.php?fragment=1')"><i class="bi bi-person-plus"></i> إضافة مستخدم</a></div>
</section>

<section class="nawa-stat-grid">
 <article class="nawa-stat"><i class="bi bi-people pppoe-stat-icon" aria-hidden="true"></i><span>إجمالي PPPoE</span><strong><?=number_format($totalUsers)?></strong></article>
 <article class="nawa-stat"><i class="bi bi-clock-history pppoe-stat-icon" aria-hidden="true"></i><span>فعالة</span><strong><?=number_format($active)?></strong></article>
 <article class="nawa-stat"><i class="bi bi-pause-circle pppoe-stat-icon" aria-hidden="true"></i><span>معطلة / منتهية</span><strong><?=number_format($disabled)?></strong></article>
 <article class="nawa-stat"><i class="bi bi-router pppoe-stat-icon" aria-hidden="true"></i><span>مفعلة على RADIUS</span><strong><?=number_format($radiusEnabled)?></strong></article>
</section>

<section class="nawa-card">
<header class="nawa-card-header"><div><h2>قائمة مستخدمي البرودباند</h2><p>الصفحة تقرأ فقط من جداول PPPoE الجديدة.</p></div></header>

<form class="nawa-filter nawa-users-search" method="get">
 <div class="nawa-search-field"><i class="bi bi-search"></i><input type="search" name="search" value="<?=nawa_e($search)?>" placeholder="ابحث باسم المستخدم أو العميل أو رقم الجوال..." autocomplete="off"></div>
 <button class="nawa-button primary nawa-search-button" type="submit"><i class="bi bi-search"></i> بحث</button>
</form>

<?php if (!$users): ?>
<div class="nawa-empty"><i class="bi bi-router"></i>لا توجد حسابات PPPoE حتى الآن.</div>
<?php else: ?>
<div class="nawa-table-wrap" style="max-height:none!important;overflow-x:auto!important">
<table class="nawa-table" id="pppoeUsersTable">
<thead><tr><th>المستخدم</th><th>الباقة</th><th>السرعة</th><th>الإجمالي</th><th>المستهلك</th><th>المتبقي</th><th>الانتهاء</th><th>الحالة</th><th>RADIUS</th><th>الإجراءات</th></tr></thead>
<tbody>
<?php foreach ($users as $u):
$q=(float)($u['total_quota']??0);
$used=(float)($u['used_quota']??0);
$left=max(0,$q-$used);
$qText=$q>0?number_format($q/1073741824,2).' GB':'غير محدود';
$usedText=$used>=1073741824?number_format($used/1073741824,2).' GB':number_format($used/1048576,1).' MB';
$leftText=$q>0?number_format($left/1073741824,2).' GB':'غير محدود';
$speed=trim((string)($u['rate_limit']??''));
if($speed===''){
 $up=trim((string)($u['upload_speed']??''));
 $down=trim((string)($u['download_speed']??''));
 $speed=($up!==''||$down!=='')?$up.'/'.$down:'—';
}
$exp=trim((string)($u['expires_at']??''));
$ts=$exp!==''?strtotime($exp):false;
$expText=$ts!==false?date('d/m/Y H:i',$ts):'بدون تاريخ';
$status=(string)($u['status']??'active');
$statusText=$status==='disabled'?'معطل':($status==='expired'?'منتهي':'فعال');
?>
<tr>
<td><span class="nawa-user"><b><?=nawa_e($u['username'])?></b><small><?=trim((string)$u['full_name'])!==''?nawa_e($u['full_name']):'—'?><?=trim((string)$u['mobilephone'])!==''?' · '.nawa_e($u['mobilephone']):''?></small></span></td>
<td><span class="nawa-user"><b><?=nawa_e((string)($u['package_name']??'بدون باقة'))?></b><small><?=nawa_e($qText)?></small></span></td>
<td><span class="nawa-badge muted"><?=nawa_e($speed)?></span></td>
<td><?=nawa_e($qText)?></td>
<td><span class="nawa-badge warning"><?=nawa_e($usedText)?></span></td>
<td><span class="nawa-badge"><?=nawa_e($leftText)?></span></td>
<td><?=nawa_e($expText)?></td>
<td><span class="nawa-badge <?=$status==='active'?'':'warning'?>"><?=nawa_e($statusText)?></span></td>
<td><?=(int)$u['radius_enabled']===1?'<span class="nawa-badge warning">مفعّل</span>':'<span class="nawa-badge muted">غير مفعّل</span>'?></td>
<td>
<div style="display:flex;gap:6px;flex-wrap:wrap;min-width:390px">

<a
 class="nawa-button small"
 href="javascript:void(0)"
 onclick="loadSection('nawa-pppoe-user-details.php?fragment=1&username=<?=urlencode($u['username'])?>')"
>
 <i class="bi bi-bar-chart"></i>
 تفاصيل الاستخدام
</a>

<a
 class="nawa-button small"
 href="javascript:void(0)"
 onclick="loadSection('nawa-pppoe-recharge.php?fragment=1&username=<?=urlencode($u['username'])?>')"
>
 <i class="bi bi-arrow-repeat"></i>
 شحن / تجديد
</a>

<?php if($status==='active'): ?>

<button
 type="button"
 class="nawa-button small"
 data-pppoe-action="disable"
 data-username="<?=nawa_e($u['username'])?>"
>
 <i class="bi bi-pause-circle"></i>
 تعطيل
</button>

<?php elseif($status==='disabled'): ?>

<button
 type="button"
 class="nawa-button small"
 data-pppoe-action="enable"
 data-username="<?=nawa_e($u['username'])?>"
>
 <i class="bi bi-play-circle"></i>
 تفعيل
</button>

<?php else: ?>

<span class="nawa-badge warning">
 جدد أولًا
</span>

<?php endif; ?>

<button
 type="button"
 class="nawa-button small"
 style="color:#b42318"
 data-pppoe-action="delete"
 data-username="<?=nawa_e($u['username'])?>"
>
 <i class="bi bi-trash"></i>
 حذف
</button>

</div>
</td>
</tr>
<?php endforeach; ?>
</tbody></table></div>

<?php if($totalPages>1): ?>
<div style="display:flex;gap:8px;justify-content:center;align-items:center;padding:18px">
<?php if($page>1): ?><a class="nawa-button" href="?page=<?=$page-1?>&search=<?=urlencode($search)?>">السابق</a><?php endif; ?>
<span class="nawa-badge muted">صفحة <?=number_format($page)?> من <?=number_format($totalPages)?></span>
<?php if($page<$totalPages): ?><a class="nawa-button" href="?page=<?=$page+1?>&search=<?=urlencode($search)?>">التالي</a><?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
</section>

<input
 type="hidden"
 id="pppoeUsersCsrf"
 value="<?=nawa_e($csrfToken)?>"
>

<script>
(function(){

    var table = document.getElementById('pppoeUsersTable');

    if (!table) {
        return;
    }

    table.addEventListener('click', function(event){

        var button =
            event.target.closest('[data-pppoe-action]');

        if (!button) {
            return;
        }

        var action =
            button.getAttribute('data-pppoe-action');

        var username =
            button.getAttribute('data-username');

        if (!action || !username) {
            return;
        }

        if (action === 'delete') {

            var typed = prompt(
                'لحذف الحساب اكتب اسم المستخدم بالضبط:\n' +
                username
            );

            if (typed !== username) {
                return;
            }

        } else {

            var label =
                action === 'disable'
                ? 'تعطيل'
                : 'تفعيل';

            if (
                !confirm(
                    'تأكيد ' +
                    label +
                    ' حساب ' +
                    username +
                    '؟'
                )
            ) {
                return;
            }
        }

        var csrf =
            document.getElementById('pppoeUsersCsrf');

        if (!csrf) {
            alert('تعذر قراءة رمز الحماية.');
            return;
        }

        var formData = new FormData();

        formData.append(
            'csrf_token',
            csrf.value
        );

        formData.append(
            'username',
            username
        );

        formData.append(
            'action',
            action
        );

        button.disabled = true;

        fetch(
            'nawa-pppoe-user-action.php',
            {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: formData,
                credentials: 'same-origin',
                cache: 'no-store'
            }
        )
        .then(function(response){

            return response.text().then(function(text){

                var data;

                try {
                    data = JSON.parse(text);
                } catch (e) {

                    console.error(
                        'PPPoE action response:',
                        text
                    );

                    throw new Error(
                        'السيرفر أعاد استجابة غير متوقعة.'
                    );
                }

                return {
                    response: response,
                    data: data
                };
            });

        })
        .then(function(result){

            if (
                !result.response.ok ||
                !result.data.ok
            ) {
                throw new Error(
                    result.data.message ||
                    'تعذر تنفيذ العملية.'
                );
            }

            if (window.showToast) {

                window.showToast(
                    result.data.message,
                    'success'
                );

            } else {

                alert(result.data.message);
            }

            if (window.loadSection) {

                window.loadSection(
                    'nawa-pppoe-users.php?fragment=1'
                );

            } else {

                window.location.reload();
            }

        })
        .catch(function(error){

            if (window.showToast) {

                window.showToast(
                    error.message,
                    'error'
                );

            } else {

                alert(error.message);
            }

        })
        .finally(function(){

            button.disabled = false;

        });

    });

})();
</script>

</div>
<?php
include '../common/includes/db_close.php';
if (!$fragmentMode) print_footer_and_html_epilogue('');
?>
