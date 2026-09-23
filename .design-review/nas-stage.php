<?php
@ini_set('pcre.jit', '0');
include("library/checklogin.php");
include_once("../common/includes/config_read.php");
include("../common/includes/db_open.php");

$table = $configValues['CONFIG_DB_TBL_RADNAS'];

function nh($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function ne($db, $v) {
    return $db->escapeSimple(trim((string)$v));
}

function nas_runtime($action, array $payload = []) {
    if (!function_exists('shell_exec')) {
        return ['ok' => false, 'message' => 'تكامل FreeRADIUS غير متاح من PHP.'];
    }
    $command = 'sudo -n /usr/local/sbin/3dradius-nas-helper ' . escapeshellarg($action);
    if ($payload !== []) {
        $encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
        $command .= ' ' . escapeshellarg($encoded);
    }
    $output = trim((string) @shell_exec($command . ' 2>&1'));
    $decoded = json_decode($output, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'message' => 'تعذر قراءة إعدادات FreeRADIUS الفعلية.'];
    }
    return $decoded;
}

function nas_db_row($db, $table, $id) {
    $result = $db->query("SELECT * FROM {$table} WHERE id=" . (int) $id . " LIMIT 1");
    if (DB::isError($result) || !is_object($result)) return [];
    $row = $result->fetchRow(DB_FETCHMODE_ASSOC);
    return is_array($row) ? $row : [];
}

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $nasname = trim($_POST['nasname'] ?? '');
        $shortname = trim($_POST['shortname'] ?? '');
        $type = trim($_POST['type'] ?? 'mikrotik');
        $secret = trim($_POST['secret'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $apiUsername = trim($_POST['api_username'] ?? '');
        $apiPassword = (string) ($_POST['api_password'] ?? '');
        $apiPort = max(1, min(65535, (int) ($_POST['api_port'] ?? 8728)));

        if ($nasname === '' || $shortname === '' || $secret === '') {
            $message = 'يرجى إدخال IP واسم الشبكة وRADIUS Secret.';
            $messageType = 'danger';
        } elseif (!filter_var($nasname, FILTER_VALIDATE_IP)) {
            $message = 'عنوان IP غير صحيح.';
            $messageType = 'danger';
        } else {
            $ip = ne($dbSocket, $nasname);

            $check = $dbSocket->query(
                "SELECT id FROM {$table} WHERE nasname='{$ip}' LIMIT 1"
            );

            if ($check && $check->numRows() > 0) {
                $message = 'هذا الجهاز موجود مسبقًا.';
                $messageType = 'danger';
            } else {
                $sql = sprintf(
                    "INSERT INTO %s
                    (nasname,shortname,type,ports,secret,server,community,description,api_username,api_password,api_port,api_user)
                    VALUES
                    ('%s','%s','%s',0,'%s','','','%s','%s','%s',%d,'%s')",
                    $table,
                    $ip,
                    ne($dbSocket,$shortname),
                    ne($dbSocket,$type),
                    ne($dbSocket,$secret),
                    ne($dbSocket,$description),
                    ne($dbSocket,$apiUsername),
                    ne($dbSocket,$apiPassword),
                    $apiPort,
                    ne($dbSocket,$apiUsername)
                );

                $insert = $dbSocket->query($sql);
                if (!DB::isError($insert)) {
                    $idResult = $dbSocket->query('SELECT LAST_INSERT_ID()');
                    $idRow = !DB::isError($idResult) ? $idResult->fetchRow() : [0];
                    $newId = (int) ($idRow[0] ?? 0);
                    $runtime = nas_runtime('upsert', [
                        'id' => $newId,
                        'nasname' => $nasname,
                        'shortname' => $shortname,
                        'type' => $type,
                        'secret' => $secret,
                        'description' => $description,
                    ]);
                    if (!empty($runtime['ok'])) {
                        $message = 'تمت إضافة جهاز NAS وتفعيله فعليًا في FreeRADIUS.';
                    } else {
                        $dbSocket->query("DELETE FROM {$table} WHERE id={$newId} LIMIT 1");
                        $message = (string) ($runtime['message'] ?? 'تعذر تفعيل الجهاز في FreeRADIUS.');
                        $messageType = 'danger';
                    }
                } else {
                    $message = 'حدث خطأ أثناء إضافة الجهاز.';
                    $messageType = 'danger';
                }
            }
        }
    }

    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $nasname = trim($_POST['nasname'] ?? '');
        $shortname = trim($_POST['shortname'] ?? '');
        $type = trim($_POST['type'] ?? 'mikrotik');
        $secret = trim($_POST['secret'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $apiUsername = trim($_POST['api_username'] ?? '');
        $apiPassword = (string) ($_POST['api_password'] ?? '');
        $apiPort = max(1, min(65535, (int) ($_POST['api_port'] ?? 8728)));

        if ($id <= 0 || $nasname === '' || $shortname === '') {
            $message = 'بيانات التعديل غير مكتملة.';
            $messageType = 'danger';
        } elseif (!filter_var($nasname, FILTER_VALIDATE_IP)) {
            $message = 'عنوان IP غير صحيح.';
            $messageType = 'danger';
        } else {
            $oldRow = nas_db_row($dbSocket, $table, $id);
            if ($oldRow === []) {
                $message = 'الجهاز المطلوب غير موجود.';
                $messageType = 'danger';
            } else {
            $secretSql = '';

            if ($secret !== '') {
                $secretSql = ", secret='" . ne($dbSocket, $secret) . "'";
            }
            $apiPasswordSql = '';
            if ($apiPassword !== '') {
                $apiPasswordSql = ", api_password='" . ne($dbSocket, $apiPassword) . "'";
            }

            $sql = sprintf(
                "UPDATE %s
                 SET nasname='%s',
                     shortname='%s',
                     type='%s',
                     description='%s'
                     ,api_username='%s'
                     ,api_user='%s'
                     ,api_port=%d
                     %s
                     %s
                 WHERE id=%d",
                $table,
                ne($dbSocket, $nasname),
                ne($dbSocket, $shortname),
                ne($dbSocket, $type),
                ne($dbSocket, $description),
                ne($dbSocket, $apiUsername),
                ne($dbSocket, $apiUsername),
                $apiPort,
                $secretSql,
                $apiPasswordSql,
                $id
            );

            $update = $dbSocket->query($sql);
            if (!DB::isError($update)) {
                $runtime = nas_runtime('upsert', [
                    'id' => $id,
                    'nasname' => $nasname,
                    'shortname' => $shortname,
                    'type' => $type,
                    'secret' => $secret !== '' ? $secret : (string) ($oldRow['secret'] ?? ''),
                    'description' => $description,
                ]);
                if (!empty($runtime['ok'])) {
                    $message = 'تم تعديل الجهاز وتحديث FreeRADIUS مباشرة.';
                } else {
                    $rollback = sprintf(
                        "UPDATE %s SET nasname='%s',shortname='%s',type='%s',secret='%s',description='%s',api_username='%s',api_password='%s',api_port=%d,api_user='%s' WHERE id=%d",
                        $table,
                        ne($dbSocket, $oldRow['nasname'] ?? ''),
                        ne($dbSocket, $oldRow['shortname'] ?? ''),
                        ne($dbSocket, $oldRow['type'] ?? 'other'),
                        ne($dbSocket, $oldRow['secret'] ?? ''),
                        ne($dbSocket, $oldRow['description'] ?? ''),
                        ne($dbSocket, $oldRow['api_username'] ?? ''),
                        ne($dbSocket, $oldRow['api_password'] ?? ''),
                        (int) ($oldRow['api_port'] ?? 8728),
                        ne($dbSocket, $oldRow['api_user'] ?? ''),
                        $id
                    );
                    $dbSocket->query($rollback);
                    $message = (string) ($runtime['message'] ?? 'تعذر تحديث FreeRADIUS.');
                    $messageType = 'danger';
                }
            } else {
                $message = 'حدث خطأ أثناء تعديل جهاز NAS.';
                $messageType = 'danger';
            }
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            $runtime = nas_runtime('delete', ['id' => $id]);
            if (!empty($runtime['ok'])) {
                $dbSocket->query("DELETE FROM {$table} WHERE id={$id} LIMIT 1");
                $message = 'تم حذف الجهاز من الصفحة ومن إعدادات FreeRADIUS.';
            } else {
                $message = (string) ($runtime['message'] ?? 'تعذر حذف الجهاز من FreeRADIUS.');
                $messageType = 'danger';
            }
        }
    }
}

$runtimeList = nas_runtime('list');
$rows = !empty($runtimeList['ok']) && isset($runtimeList['rows']) && is_array($runtimeList['rows'])
    ? $runtimeList['rows']
    : [];
$runtimeReady = !empty($runtimeList['ok']);

if (!$runtimeReady) {
    $fallback = $dbSocket->query(
        "SELECT id,nasname,shortname,type,secret,description,api_username,api_password,api_port,api_user FROM {$table} ORDER BY id DESC"
    );
    if (!DB::isError($fallback) && is_object($fallback)) {
        while ($row = $fallback->fetchRow(DB_FETCHMODE_ASSOC)) {
            $row['managed'] = true;
            $row['source'] = 'database';
            $rows[] = $row;
        }
    }
    if ($message === '') {
        $message = (string) ($runtimeList['message'] ?? 'تعذر الاتصال بإعدادات FreeRADIUS، تم عرض نسخة قاعدة البيانات مؤقتًا.');
        $messageType = 'danger';
    }
}

/* بيانات FreeRADIUS الفعلية لا تحتوي حساب RouterOS، لذلك ندمجها من جدول NAS. */
$apiRows = [];
$apiResult = $dbSocket->query("SELECT id,nasname,api_username,api_password,api_port,api_user FROM {$table}");
if (!DB::isError($apiResult) && is_object($apiResult)) {
    while ($apiRow = $apiResult->fetchRow(DB_FETCHMODE_ASSOC)) {
        $apiRows['id:' . (int) $apiRow['id']] = $apiRow;
        $apiRows['ip:' . (string) $apiRow['nasname']] = $apiRow;
    }
}
foreach ($rows as &$row) {
    $apiRow = $apiRows['id:' . (int) ($row['id'] ?? 0)]
        ?? $apiRows['ip:' . (string) ($row['nasname'] ?? '')]
        ?? [];
    $row['api_username'] = (string) ($apiRow['api_username'] ?? $apiRow['api_user'] ?? '');
    $row['api_port'] = (int) ($apiRow['api_port'] ?? 8728);
    $row['api_configured'] = $row['api_username'] !== '' && (string) ($apiRow['api_password'] ?? '') !== '';
}
unset($row);

include("../common/includes/db_close.php");

// Read-only presentation totals from the existing NAS result set.
$nasManagedCount = 0;
$nasApiConfiguredCount = 0;
foreach ($rows as $nasSummaryRow) {
    if (!empty($nasSummaryRow['managed'])) $nasManagedCount++;
    if (!empty($nasSummaryRow['api_configured'])) $nasApiConfiguredCount++;
}
?>

<style>
.nas-page .nas-toolbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    margin-bottom:20px
}

.nas-grid{
    display:grid;
    grid-template-columns:minmax(0,1fr) 360px;
    gap:20px
}

.nas-panel{
    background:#fff;
    border:1px solid #e9ebf2;
    border-radius:20px;
    box-shadow:0 8px 30px rgba(26,36,70,.04)
}

.nas-panel-head{
    padding:20px 22px;
    border-bottom:1px solid #edf0f5;
    display:flex;
    align-items:center;
    justify-content:space-between
}

.nas-panel-head h2{
    margin:0;
    font-size:18px
}

.nas-panel-head p{
    margin:5px 0 0;
    font-size:12px;
    color:#9299aa
}

.nas-search{
    width:270px
}

.nas-search input,
.nas-form input,
.nas-form select,
.nas-form textarea{
    width:100%;
    border:1px solid #e5e8f0;
    border-radius:12px;
    padding:12px 14px;
    background:#fbfcff;
    outline:none
}

.nas-form{
    padding:22px
}

.nas-form label{
    display:block;
    font-size:13px;
    font-weight:700;
    margin-bottom:7px
}

.nas-field{
    margin-bottom:15px
}

.nas-form textarea{
    min-height:90px;
    resize:vertical
}

.nas-submit{
    width:100%;
    border:0;
    border-radius:12px;
    padding:13px;
    background:#6857e5;
    color:#fff;
    font-weight:700;
    cursor:pointer
}

.nas-table-wrap{
    overflow:auto
}

.nas-table{
    width:100%;
    border-collapse:collapse
}

.nas-table th,
.nas-table td{
    padding:14px 16px;
    border-bottom:1px solid #edf0f5;
    text-align:right;
    font-size:13px
}

.nas-table th{
    color:#8a92a4;
    font-size:12px;
    background:#fbfcff
}

.nas-ip{
    direction:ltr;
    display:inline-block;
    font-family:Consolas,monospace
}

.nas-type{
    background:#eef1ff;
    color:#6757dc;
    border-radius:999px;
    padding:5px 9px;
    font-size:11px;
    font-weight:700
}

.nas-action{
    border:1px solid #e7eaf1;
    background:#fff;
    border-radius:9px;
    padding:7px 10px;
    cursor:pointer
}

.nas-delete{
    color:#ef4444
}

.nas-message{
    margin-bottom:18px;
    padding:13px 16px;
    border-radius:12px
}

.nas-message.success{
    background:#ecfdf5;
    color:#047857
}

.nas-message.danger{
    background:#fef2f2;
    color:#b91c1c
}

@media(max-width:1050px){
    .nas-grid{grid-template-columns:1fr}
}
</style>

<link rel="stylesheet" href="static/css/nas-reference.css?v=20260920">
<div class="nas-page nas-reference">

<section class="page-heading">
    <div>
        <p class="eyebrow"><span></span> إدارة الأجهزة</p>
        <h1>أجهزة NAS <span>📡</span></h1>
        <p>إضافة وإدارة راوترات الشبكات المرتبطة بخادم RADIUS.</p>
    </div>

    <div class="heading-actions">
        <button
            class="secondary-button"
            type="button"
            onclick="loadSection('nas-modern.php?fragment=1')">
            <i class="bi bi-arrow-clockwise"></i>
            تحديث
        </button>
    </div>
</section>

<section class="nas-summary" aria-label="ملخص أجهزة NAS">
    <article><i class="bi bi-database"></i><span>إجمالي الأجهزة</span><strong><?=number_format(count($rows))?></strong></article>
    <article><i class="bi bi-wifi"></i><span>الأجهزة المدارة</span><strong><?=number_format($nasManagedCount)?></strong></article>
    <article><i class="bi bi-link-45deg"></i><span>RouterOS API مضبوط</span><strong><?=number_format($nasApiConfiguredCount)?></strong></article>
</section>

<section class="status-strip">
    <div class="status-main">
        <span class="status-icon">
            <i class="bi bi-hdd-network"></i>
        </span>

        <span>
            <strong><?=count($rows)?> جهاز NAS فعلي</strong>
            <small>الأجهزة المفعلة حاليًا داخل FreeRADIUS</small>
        </span>
    </div>
</section>

<?php if ($message !== ''): ?>
<div class="nas-message <?=nh($messageType)?>">
    <?=nh($message)?>
</div>
<?php endif; ?>

<div class="nas-grid">

    <article class="nas-panel">

        <div class="nas-panel-head">
            <div>
                <h2>الشبكات المسجلة</h2>
                <p>قائمة راوترات NAS الحالية</p>
            </div>

            <div class="nas-search">
                <input
                    type="search"
                    placeholder="بحث بالاسم أو IP..."
                    oninput="filterNas(this.value)">
            </div>
        </div>

        <div class="nas-table-wrap">
            <table class="nas-table" id="nasTable">

                <thead>
                <tr>
                    <th>#</th>
                    <th>IP</th>
                    <th>اسم الشبكة</th>
                    <th>النوع</th>
                    <th>Secret</th>
                    <th>RouterOS API</th>
                    <th>الوصف</th>
                    <th>إجراء</th>
                </tr>
                </thead>

                <tbody>

                <?php foreach ($rows as $position => $row):
                    $secretKey = substr(md5((string) ($row['id'] ?? '') . '|' . (string) ($row['nasname'] ?? '')), 0, 14);
                    $isManaged = !empty($row['managed']);
                ?>

                <tr data-search="<?=nh(
                    strtolower(
                        ($row['nasname'] ?? '') . ' ' .
                        ($row['shortname'] ?? '') . ' ' .
                        ($row['description'] ?? '')
                    )
                )?>">

                    <td><?=number_format($position + 1)?></td>

                    <td>
                        <span class="nas-ip">
                            <?=nh($row['nasname'])?>
                        </span>
                    </td>

                    <td>
                        <strong><?=nh($row['shortname'])?></strong>
                    </td>

                    <td>
                        <span class="nas-type">
                            <?=nh($row['type'] ?: 'other')?>
                        </span>
                    </td>

                    <td>
                        <div style="display:flex;align-items:center;gap:8px">
                            <span
                                id="secret-<?=nh($secretKey)?>"
                                data-secret="<?=nh($row['secret'])?>">••••••••</span>

                            <button
                                type="button"
                                class="nas-action"
                                title="عرض أو إخفاء Secret"
                                onclick="toggleSecret(<?=json_encode($secretKey)?>, this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </td>

                    <td>
                        <span class="nas-config-badge" style="<?= !empty($row['api_configured']) ? 'color:#067647;background:#e8f8f1' : 'color:#b42318;background:#fff0ef' ?>">
                            <i class="bi <?= !empty($row['api_configured']) ? 'bi-plug-fill' : 'bi-plug' ?>"></i>
                            <?= !empty($row['api_configured']) ? 'مضبوط' : 'غير مضبوط' ?>
                        </span>
                        <?php if (!empty($row['api_username'])): ?><small style="display:block;margin-top:5px;color:#7c8494" dir="ltr"><?= nh($row['api_username']) ?> : <?= (int) ($row['api_port'] ?? 8728) ?></small><?php endif; ?>
                    </td>

                    <td><?=nh($row['description'])?></td>

                    <td>
                        <div style="display:flex;gap:7px;align-items:center">

                            <?php if ($isManaged): ?>
                            <button
                                class="nas-action"
                                type="button"
                                onclick='editNas(<?=json_encode([
                                    "id" => $row["id"],
                                    "nasname" => $row["nasname"],
                                    "shortname" => $row["shortname"],
                                    "type" => $row["type"],
                                    "description" => $row["description"],
                                    "api_username" => $row["api_username"] ?? "",
                                    "api_port" => $row["api_port"] ?? 8728
                                ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)?>)'>
                                <i class="bi bi-pencil-square"></i>
                                تعديل
                            </button>

                            <form
                                method="post"
                                onsubmit="return nasSubmit(event,this)">

                                <input
                                    type="hidden"
                                    name="action"
                                    value="delete">

                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?=nh($row['id'])?>">

                                <button
                                    class="nas-action nas-delete"
                                    type="submit">
                                    <i class="bi bi-trash"></i>
                                    حذف
                                </button>
                            </form>
                            <?php else: ?>
                            <span class="nas-config-badge"><i class="bi bi-shield-check"></i> فعلي</span>
                            <?php endif; ?>

                        </div>
                    </td>

                </tr>

                <?php endforeach; ?>

                </tbody>
            </table>
        </div>

    </article>

    <article class="nas-panel">

        <div class="nas-panel-head">
            <div>
                <h2>إضافة شبكة جديدة</h2>
                <p>سيُضاف الراوتر فعليًا إلى FreeRADIUS ويعمل مباشرة</p>
            </div>

            <span class="stat-icon">
                <i class="bi bi-router"></i>
            </span>
        </div>

        <form
            class="nas-form"
            method="post"
            onsubmit="return nasSubmit(event,this)">

            <input
                type="hidden"
                name="action"
                value="add">

            <input
                type="hidden"
                name="id"
                id="nasEditId"
                value="">

            <div class="nas-field">
                <label>عنوان IP *</label>

                <input
                    name="nasname"
                    placeholder="192.168.230.115"
                    required>
            </div>

            <div class="nas-field">
                <label>اسم الشبكة *</label>

                <input
                    name="shortname"
                    placeholder="شبكة رقم 8"
                    required>
            </div>

            <div class="nas-field">
                <label>النوع</label>

                <select name="type">
                    <option value="mikrotik">MikroTik</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <div class="nas-field">
                <label>RADIUS Secret *</label>

                <input
                    type="password"
                    name="secret"
                    required>
            </div>

            <div class="nas-field">
                <label>RouterOS API Username</label>
                <input name="api_username" autocomplete="off" placeholder="مثال: 3dradius-read">
                <small>حساب MikroTik لقراءة قائمة Hotspot Active الفعلية.</small>
            </div>

            <div class="nas-field">
                <label>RouterOS API Password</label>
                <input type="password" name="api_password" autocomplete="new-password" placeholder="كلمة مرور حساب API">
                <small>في التعديل اتركها فارغة للإبقاء على الكلمة الحالية.</small>
            </div>

            <div class="nas-field">
                <label>RouterOS API Port</label>
                <input type="number" name="api_port" value="8728" min="1" max="65535" inputmode="numeric">
            </div>

            <div class="nas-field">
                <label>ملاحظات</label>

                <textarea
                    name="description"
                    placeholder="مثال: شبكة رقم 8"></textarea>
            </div>

            <button
                class="nas-submit"
                type="submit"
                id="nasSubmitButton">

                <i class="bi bi-plus-lg"></i>
                إضافة جهاز NAS
            </button>

            <button
                type="button"
                id="nasCancelEdit"
                class="nas-action"
                style="display:none;width:100%;margin-top:9px"
                onclick="cancelNasEdit()">
                إلغاء التعديل
            </button>

        </form>

    </article>

</div>
</div>

<script>
function filterNas(value) {
    const q = value.trim().toLowerCase();

    document.querySelectorAll('#nasTable tbody tr').forEach(function(row) {
        row.style.display =
            row.dataset.search.includes(q) ? '' : 'none';
    });
}

function toggleSecret(id, button) {
    const el = document.getElementById('secret-' + id);
    if (!el) return;

    const hidden = el.textContent.trim() === '••••••••';

    if (hidden) {
        el.textContent = el.dataset.secret || '';
        if (button) button.innerHTML = '<i class="bi bi-eye-slash"></i>';
    } else {
        el.textContent = '••••••••';
        if (button) button.innerHTML = '<i class="bi bi-eye"></i>';
    }
}

function editNas(row) {
    const form = document.querySelector('.nas-form');
    if (!form) return;

    form.querySelector('[name="action"]').value = 'edit';
    document.getElementById('nasEditId').value = row.id || '';

    form.querySelector('[name="nasname"]').value = row.nasname || '';
    form.querySelector('[name="shortname"]').value = row.shortname || '';
    form.querySelector('[name="type"]').value = row.type || 'mikrotik';
    form.querySelector('[name="description"]').value = row.description || '';
    form.querySelector('[name="api_username"]').value = row.api_username || '';
    form.querySelector('[name="api_port"]').value = row.api_port || 8728;

    const secretInput = form.querySelector('[name="secret"]');
    secretInput.value = '';
    secretInput.required = false;
    secretInput.placeholder = 'اتركه فارغًا للإبقاء على Secret الحالي';

    const apiPasswordInput = form.querySelector('[name="api_password"]');
    apiPasswordInput.value = '';
    apiPasswordInput.placeholder = 'اتركها فارغة للإبقاء على كلمة API الحالية';

    const btn = document.getElementById('nasSubmitButton');
    if (btn) {
        btn.innerHTML = '<i class="bi bi-check2-circle"></i> حفظ التعديلات';
    }

    const cancel = document.getElementById('nasCancelEdit');
    if (cancel) cancel.style.display = 'block';

    form.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
    });
}

function cancelNasEdit() {
    const form = document.querySelector('.nas-form');
    if (!form) return;

    form.reset();
    form.querySelector('[name="action"]').value = 'add';

    document.getElementById('nasEditId').value = '';

    const secretInput = form.querySelector('[name="secret"]');
    secretInput.required = true;
    secretInput.placeholder = '';
    form.querySelector('[name="api_password"]').placeholder = 'كلمة مرور حساب API';
    form.querySelector('[name="api_port"]').value = 8728;

    const btn = document.getElementById('nasSubmitButton');
    if (btn) {
        btn.innerHTML = '<i class="bi bi-plus-lg"></i> إضافة جهاز NAS';
    }

    const cancel = document.getElementById('nasCancelEdit');
    if (cancel) cancel.style.display = 'none';
}

async function nasSubmit(event, form) {

    event.preventDefault();

    if (
        form.querySelector('[name="action"]').value === 'delete'
        &&
        !confirm('هل تريد حذف جهاز NAS؟')
    ) {
        return false;
    }

    const button = form.querySelector('button[type="submit"]');

    if (button) {
        button.disabled = true;
    }

    try {

        const response = await fetch(
            'nas-modern.php?fragment=1',
            {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin'
            }
        );

        const html = await response.text();

        const area =
            document.querySelector('.dashboard-content');

        area.innerHTML = html;

        area.querySelectorAll('script').forEach(function(oldScript) {
            const script = document.createElement('script');
            script.textContent = oldScript.textContent;
            document.body.appendChild(script);
            oldScript.remove();
        });

    } catch (e) {

        alert('حدث خطأ أثناء تنفيذ العملية');

        if (button) {
            button.disabled = false;
        }
    }

    return false;
}
</script>
