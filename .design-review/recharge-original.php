<?php

declare(strict_types=1);

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


$fragmentMode =
    isset($_GET['fragment']) &&
    $_GET['fragment'] === '1';


$successMsg = '';
$failureMsg = '';


function cr_e($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function cr_bytes_to_gb($bytes): float
{
    return round(
        (float)$bytes / 1073741824,
        2
    );
}


/*
|--------------------------------------------------------------------------
| باقات الكروت القديمة
|--------------------------------------------------------------------------
|
| 53 = 200m
| 54 = 300m
| 55 = 500m
| 56 = 1000m
| 57 = 1500m
| 58 = 2000m
|
*/

$cardPackageIds = [];


/*
|--------------------------------------------------------------------------
| تحميل باقات الكروت
|--------------------------------------------------------------------------
*/

$packageResult = $dbSocket->query("
    SELECT
        id,
        name,
        total_data,
        quota_unit,
        total_time,
        time_unit,
        usage_limit,
        usage_unit,
        validity_days,
        description
    FROM packages
    WHERE is_recharge_card=1 OR id IN (53,54,55,56,57,58)
    ORDER BY id ASC
");


$cardPackages = [];


if (!DB::isError($packageResult)) {

    while (
        $row = $packageResult->fetchRow(
            DB_FETCHMODE_ASSOC
        )
    ) {

        /*
         * الباقات القديمة عندك مخزنة:
         * total_data = 2 أو 3 أو 5 أو 10...
         * quota_unit = Giga
         */

        $gb = (float)$row['total_data'];

        if (
            strtolower(
                trim((string)$row['quota_unit'])
            ) === 'mega'
        ) {
            $gb = $gb / 1024;
        }


        $days = (int)$row['usage_limit'];

        if ($days <= 0) {

            $days = (int)$row['total_time'];
        }


        if ($days <= 0) {

            $days = (int)$row['validity_days'];
        }


        if ($days <= 0) {

            $days = 30;
        }


        $row['real_gb'] = $gb;
        $row['real_bytes'] =
            nawa_gb_to_bytes($gb);

        $row['real_days'] = $days;

        $cardPackageIds[] = (int)$row['id'];
        $cardPackages[(int)$row['id']] = $row;
    }
}


/*
|--------------------------------------------------------------------------
| POST - شحن وإصلاح الكرت
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !dalo_check_csrf_token(
            (string)$_POST['csrf_token']
        )
    ) {

        $failureMsg =
            'انتهت صلاحية الجلسة. حدّث الصفحة وحاول مرة أخرى.';

    } else {

        $action =
            (string)($_POST['action'] ?? '');



        if ($action === 'delete_card') {

            $cardId = (int)($_POST['card_id'] ?? 0);

            $cardRow = $dbSocket->getRow(
                sprintf(
                    "SELECT id, username, package_id
                     FROM userinfo
                     WHERE id=%d
                       AND package_id IN (53,54,55,56,57,58)
                     LIMIT 1",
                    $cardId
                ),
                [],
                DB_FETCHMODE_ASSOC
            );

            if (
                DB::isError($cardRow) ||
                !$cardRow ||
                empty($cardRow['username'])
            ) {
                $failureMsg = 'الكرت غير موجود أو ليس من كروت الشحن.';
            } else {

                $deleteUsername =
                    $dbSocket->escapeSimple(
                        (string)$cardRow['username']
                    );

                $deleteUserId =
                    (int)$cardRow['id'];

                $deleteQueries = [

                    sprintf(
                        "DELETE FROM user_packages
                         WHERE user_id=%d",
                        $deleteUserId
                    ),

                    sprintf(
                        "DELETE FROM radcheck
                         WHERE username='%s'",
                        $deleteUsername
                    ),

                    sprintf(
                        "DELETE FROM radreply
                         WHERE username='%s'",
                        $deleteUsername
                    ),

                    sprintf(
                        "DELETE FROM radusergroup
                         WHERE username='%s'",
                        $deleteUsername
                    ),

                    sprintf(
                        "DELETE FROM nawa_user_allowances
                         WHERE username='%s'",
                        $deleteUsername
                    ),

                    sprintf(
                        "DELETE FROM nawa_subscription_cycles
                         WHERE username='%s'",
                        $deleteUsername
                    ),

                    sprintf(
                        "DELETE FROM userinfo
                         WHERE id=%d
                           AND package_id IN (53,54,55,56,57,58)",
                        $deleteUserId
                    ),
                ];

                $dbSocket->query('START TRANSACTION');

                $deleteOk = true;

                foreach ($deleteQueries as $deleteSql) {
                    $deleteResult = $dbSocket->query($deleteSql);

                    if (DB::isError($deleteResult)) {
                        $deleteOk = false;
                        $failureMsg = 'خطأ حذف: ' . $deleteResult->getMessage();
                        break;
                    }
                }

                if (!$deleteOk) {
                    $dbSocket->query('ROLLBACK');
                    $failureMsg =
                        'تعذر حذف الكرت. لم يتم حفظ أي تغيير.';
                } else {
                    $dbSocket->query('COMMIT');

                    $successMsg =
                        'تم حذف الكرت ' .
                        (string)$cardRow['username'] .
                        ' بنجاح.';
                }
            }

        } elseif ($action === 'add_card') {

            $username = trim(
                (string)($_POST['new_card_number'] ?? '')
            );

            $packageId = (int)(
                $_POST['new_card_package_id'] ?? 0
            );

            if (
                $username === '' ||
                !preg_match('/^[0-9]{6,20}$/', $username)
            ) {

                $failureMsg =
                    'رقم الكرت غير صحيح. استخدم أرقام فقط.';

            } elseif (
                !isset($cardPackages[$packageId])
            ) {

                $failureMsg =
                    'باقة الكرت غير صحيحة.';

            } elseif (
                nawa_user_exists(
                    $dbSocket,
                    $username
                )
            ) {

                $failureMsg =
                    'هذا الكرت موجود بالفعل ولا يمكن إضافته مرة أخرى.';

            } else {

                $pkg =
                    $cardPackages[$packageId];

                $quotaBytes =
                    (int)$pkg['real_bytes'];

                $days =
                    max(
                        1,
                        (int)$pkg['real_days']
                    );

                $seconds =
                    $days * 86400;

                $groupName =
                    (string)$pkg['name'];

                $escapedUsername =
                    $dbSocket->escapeSimple(
                        $username
                    );

                $escapedGroup =
                    $dbSocket->escapeSimple(
                        $groupName
                    );

                $expirationTimestamp =
                    time() + $seconds;

                $expirationDate =
                    date(
                        'Y-m-d H:i:s',
                        $expirationTimestamp
                    );

                $creationDate =
                    date('Y-m-d H:i:s');

                $creationBy =
                    $dbSocket->escapeSimple(
                        (string)$operator
                    );

                $low =
                    $quotaBytes % 4294967296;

                $high =
                    intdiv(
                        $quotaBytes,
                        4294967296
                    );

                $queries = [];


                /* -----------------------------
                   USERINFO
                   ----------------------------- */

                $queries[] = sprintf(
                    "INSERT INTO userinfo
                    (
                        username,
                        package_name,
                        status,
                        package_id,
                        is_disabled,
                        creationdate,
                        creationby,
                        total_quota,
                        used_quota,
                        total_days,
                        expiry_mode,
                        expiry_date,
                        expires_at,
                        expiry_after_first
                    )
                    VALUES
                    (
                        '%s',
                        '%s',
                        'active',
                        %d,
                        0,
                        '%s',
                        '%s',
                        %d,
                        0,
                        %d,
                        'use_time',
                        '%s',
                        '%s',
                        0
                    )",
                    $escapedUsername,
                    $escapedGroup,
                    $packageId,
                    $creationDate,
                    $creationBy,
                    $quotaBytes,
                    $days,
                    $expirationDate,
                    $expirationDate
                );


                /* -----------------------------
                   RADCHECK
                   ----------------------------- */

                $queries[] = sprintf(
                    "INSERT INTO radcheck
                    (username,attribute,op,value)
                    VALUES
                    ('%s','Auth-Type',':=','Accept')",
                    $escapedUsername
                );

                $queries[] = sprintf(
                    "INSERT INTO radcheck
                    (username,attribute,op,value)
                    VALUES
                    ('%s','Cleartext-Password',':=','%s')",
                    $escapedUsername,
                    $escapedUsername
                );

                $queries[] = sprintf(
                    "INSERT INTO radcheck
                    (username,attribute,op,value)
                    VALUES
                    ('%s','Expiration',':=','%d')",
                    $escapedUsername,
                    $expirationTimestamp
                );


                /* -----------------------------
                   RADREPLY
                   ----------------------------- */

                $queries[] = sprintf(
                    "INSERT INTO radreply
                    (username,attribute,op,value)
                    VALUES
                    ('%s','Acct-Interim-Interval',':=','60')",
                    $escapedUsername
                );

                $queries[] = sprintf(
                    "INSERT INTO radreply
                    (username,attribute,op,value)
                    VALUES
                    ('%s','Mikrotik-Total-Limit',':=','%d')",
                    $escapedUsername,
                    $low
                );

                $queries[] = sprintf(
                    "INSERT INTO radreply
                    (username,attribute,op,value)
                    VALUES
                    ('%s','Mikrotik-Total-Limit-Gigawords',':=','%d')",
                    $escapedUsername,
                    $high
                );

                $queries[] = sprintf(
                    "INSERT INTO radreply
                    (username,attribute,op,value)
                    VALUES
                    ('%s','Max-All-Session',':=','%d')",
                    $escapedUsername,
                    $seconds
                );

                $queries[] = sprintf(
                    "INSERT INTO radreply
                    (username,attribute,op,value)
                    VALUES
                    ('%s','Session-Timeout',':=','%d')",
                    $escapedUsername,
                    $seconds
                );


                /* -----------------------------
                   GROUP
                   ----------------------------- */

                $queries[] = sprintf(
                    "INSERT INTO radusergroup
                    (username,groupname,priority)
                    VALUES
                    ('%s','%s',1)",
                    $escapedUsername,
                    $escapedGroup
                );

                $queries[] = sprintf(
                    "INSERT INTO nawa_subscription_cycles
                    (username,groupname,starts_at,cleared_previous,created_by)
                    VALUES ('%s','%s','%s',1,'%s')",
                    $escapedUsername,
                    $escapedGroup,
                    $creationDate,
                    $creationBy
                );


                /* -----------------------------
                   TRANSACTION
                   ----------------------------- */

                $dbSocket->query(
                    'START TRANSACTION'
                );

                $createOk = true;

                foreach ($queries as $query) {

                    if (
                        !nawa_db_ok(
                            $dbSocket->query(
                                $query
                            )
                        )
                    ) {

                        $createOk = false;
                        break;
                    }
                }

                if ($createOk) {

                    $dbSocket->query(
                        'COMMIT'
                    );

                    $successMsg =
                        'تم إنشاء الكرت ' .
                        $username .
                        ' بنجاح — ' .
                        $groupName .
                        ' — ' .
                        number_format(
                            (float)$pkg['real_gb'],
                            0
                        ) .
                        ' GB — ' .
                        $days .
                        ' يوم.';

                } else {

                    $dbSocket->query(
                        'ROLLBACK'
                    );

                    $failureMsg =
                        'تعذر إنشاء الكرت. لم يتم حفظ أي تغيير.';
                }
            }

        } elseif ($action === 'recharge') {

            $username =
                trim((string)($_POST['card'] ?? ''));


            $packageId =
                (int)($_POST['package_id'] ?? 0);


            $resetUsed =
                isset($_POST['reset_used']) &&
                $_POST['reset_used'] === '1';


            $enableCard =
                isset($_POST['enable_card']) &&
                $_POST['enable_card'] === '1';


            if ($username === '') {

                $failureMsg = 'رمز الكرت غير صحيح.';

            } elseif (
                !isset($cardPackages[$packageId])
            ) {

                $failureMsg =
                    'اختر باقة كروت صحيحة.';

            } else {

                $escapedUsername =
                    $dbSocket->escapeSimple($username);


                /*
                |----------------------------------------------
                | التأكد أنه كرت فعلي
                |----------------------------------------------
                */

                $cardCheck = $dbSocket->query(
                    sprintf(
                        "SELECT
                            id,
                            package_id,
                        COALESCE(used_quota,0) AS used_quota
                         FROM userinfo
                         WHERE username='%s'
                           AND package_id IN
                               (53,54,55,56,57,58)
                         LIMIT 1",
                        $escapedUsername
                    )
                );


                if (
                    DB::isError($cardCheck) ||
                    $cardCheck->numRows() !== 1
                ) {

                    $failureMsg =
                        'الكرت غير موجود أو ليس من كروت الشبكة.';

                } else {

                    $cardRow =
                    $cardCheck->fetchRow(DB_FETCHMODE_ASSOC);

                $currentUsedBytes =
                    $resetUsed
                    ? 0
                    : max(
                        0,
                        (int)($cardRow['used_quota'] ?? 0)
                    );

                $package =
                        $cardPackages[$packageId];


                    $quotaBytes =
                        (int)$package['real_bytes'];


                    $quotaGb =
                        (float)$package['real_gb'];


                    $days =
                        (int)$package['real_days'];


                    /*
                    |------------------------------------------
                    | تاريخ انتهاء جديد
                    |------------------------------------------
                    */

                    $expiration = date(
                        'Y-m-d H:i:s',
                        strtotime(
                            '+' . $days . ' days'
                        )
                    );


                    $queries = [];


                    /*
                    |------------------------------------------
                    | userinfo
                    |------------------------------------------
                    */

                    $extraUpdates = [];


                    if ($resetUsed) {

                        $extraUpdates[] =
                            "used_quota=0";

                        $extraUpdates[] =
                            "time_used=0";
                    }


                    if ($enableCard) {

                        $extraUpdates[] =
                            "status='active'";

                        $extraUpdates[] =
                            "is_disabled=0";
                    }


                    $extraSql = '';

                    if (count($extraUpdates) > 0) {

                        $extraSql =
                            ', ' .
                            implode(
                                ', ',
                                $extraUpdates
                            );
                    }


                    $queries[] = sprintf(
                        "UPDATE userinfo
                         SET
                            package_id=%d,
                            package_name='%s',
                            total_quota=%d,
                            byte_limit=%d,
                            total_limit=%d,
                            total_days=%d,
                            expiry_date='%s',
                            expires_at='%s',
                            activated_at=NOW()
                            %s
                         WHERE username='%s'",
                        $packageId,
                        $dbSocket->escapeSimple(
                            (string)$package['name']
                        ),
                        $quotaBytes,
                        $quotaBytes,
                        $quotaBytes,
                        $days,
                        $expiration,
                        $expiration,
                        $extraSql,
                        $escapedUsername
                    );


                    /*
                    |------------------------------------------
                    | خصائص RADIUS الخاصة بالقيغات
                    |------------------------------------------
                    */

                    foreach (
                        nawa_quota_attribute_queries(
                        $dbSocket,
                        $username,
                        $quotaBytes,
                        max(
                            0,
                            $quotaBytes - $currentUsedBytes
                        )
                    )
                        as $quotaQuery
                    ) {

                        // الكروت لا تستخدم Nawa-Cycle-Quota
                        if (
                            stripos(
                                $quotaQuery,
                                'Nawa-Cycle-Quota'
                            ) !== false
                        ) {
                            continue;
                        }

                        $queries[] =
                            $quotaQuery;
                    }

                    // تنظيف أي Nawa-Cycle-Quota قديم للكرت
                    $queries[] = sprintf(
                        "DELETE FROM %s
                         WHERE username='%s'
                           AND attribute='Nawa-Cycle-Quota'",
                        $configValues[
                            'CONFIG_DB_TBL_RADCHECK'
                        ],
                        $escapedUsername
                    );


                    /*
                    |------------------------------------------
                    | Expiration
                    |------------------------------------------
                    */

                    $queries[] = sprintf(
                        "DELETE FROM %s
                         WHERE username='%s'
                           AND attribute='Expiration'",
                        $configValues[
                            'CONFIG_DB_TBL_RADCHECK'
                        ],
                        $escapedUsername
                    );


                    $queries[] = sprintf(
                        "INSERT INTO %s
                            (
                                id,
                                username,
                                attribute,
                                op,
                                value
                            )
                         VALUES
                            (
                                0,
                                '%s',
                                'Expiration',
                                ':=',
                                '%s'
                            )",
                        $configValues[
                            'CONFIG_DB_TBL_RADCHECK'
                        ],
                        $escapedUsername,
                        (string) strtotime($expiration)
                    );


                    /*
                    |------------------------------------------
                    | Nawa allowance
                    |------------------------------------------
                    */

                    $queries[] =
                        nawa_allowance_upsert_sql(
                            $dbSocket,
                            $username,
                            $quotaBytes,
                            $expiration,
                            'card-recharge'
                        );

                    foreach (
                        nawa_cycle_change_queries(
                            $dbSocket,
                            $username,
                            nawa_current_group($dbSocket, $username),
                            $resetUsed
                        ) as $cycleQuery
                    ) {
                        $queries[] = $cycleQuery;
                    }


                    /*
                    |------------------------------------------
                    | تشغيل Transaction
                    |------------------------------------------
                    */

                    $dbSocket->query(
                        'START TRANSACTION'
                    );


                    $ok = true;


                    foreach ($queries as $query) {

                        $result =
                            $dbSocket->query($query);


                        if (!nawa_db_ok($result)) {

                            $ok = false;

                            break;
                        }
                    }


                    if ($ok) {

                        $dbSocket->query(
                            'COMMIT'
                        );


                        nawa_audit(
                            $dbSocket,
                            'card.recharge',
                            'card',
                            $username,
                            [
                                'package_id' =>
                                    $packageId,

                                'package_name' =>
                                    $package['name'],

                                'quota_gb' =>
                                    $quotaGb,

                                'days' =>
                                    $days,

                                'reset_used' =>
                                    $resetUsed,

                                'enabled' =>
                                    $enableCard,
                            ]
                        );


                        $successMsg =
                            'تم شحن الكرت ' .
                            $username .
                            ' بنجاح.';

                    } else {

                        $dbSocket->query(
                            'ROLLBACK'
                        );


                        $failureMsg =
                            'تعذر شحن الكرت. لم يتم حفظ أي تغيير.';
                    }
                }
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| البحث
|--------------------------------------------------------------------------
*/

$search =
    trim(
        (string)(
            $_GET['card'] ??
            $_POST['card'] ??
            ''
        )
    );


$page =
    max(
        1,
        (int)($_GET['page'] ?? 1)
    );


$pageSize = (int) ($_GET['per_page'] ?? 50);
if ($pageSize < 1 || $pageSize > 500) $pageSize = 50;
$cardSort = (string) ($_GET['sort'] ?? 'id');
$cardDirection = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
$cardSortColumns = ['code'=>'ui.username','package'=>"COALESCE(NULLIF(ui.package_name,''),p.name,'')",'quota'=>'COALESCE(ui.total_quota,0)','used'=>'COALESCE(ui.used_quota,0)','remaining'=>'GREATEST(COALESCE(ui.total_quota,0)-COALESCE(ui.used_quota,0),0)','created'=>'ui.creationdate','expires'=>'COALESCE(ui.expiry_date,ui.expires_at)','status'=>"CASE WHEN ui.is_disabled=1 OR ui.status='disabled' THEN 0 WHEN EXISTS (SELECT 1 FROM radacct ra WHERE ra.username=ui.username AND ra.acctstoptime IS NULL) THEN 2 ELSE 1 END",'id'=>'ui.id'];
if (!isset($cardSortColumns[$cardSort])) $cardSort = 'id';
$cardOrderBy = $cardSortColumns[$cardSort] . ' ' . $cardDirection . ', ui.id DESC';

$offset =
    ($page - 1) *
    $pageSize;


/*
|--------------------------------------------------------------------------
| إحصائيات جميع الكروت
|--------------------------------------------------------------------------
*/

$stats = [
    'total_cards' => 0,
    'total_quota' => 0,
    'total_used' => 0,
];

$catalogStats = null;
$catalogJson = @file_get_contents('/var/cache/3dradius/cards-catalog.json');
if (is_string($catalogJson) && $catalogJson !== '') {
    $catalogData = json_decode($catalogJson, true);
    if (is_array($catalogData) && isset($catalogData['stats']) && is_array($catalogData['stats'])) {
        $catalogStats = $catalogData['stats'];
    }
}
if ($catalogStats !== null) {
    $stats = [
        'total_cards' => (int)($catalogStats['total_cards'] ?? 0),
        'total_quota' => (int)($catalogStats['total_quota'] ?? 0),
        'total_used' => (int)($catalogStats['total_used'] ?? 0),
    ];
} else {
    /* One-time compatibility fallback while the background rollup creates the
       aggregate cache. Normal page navigation never performs this table scan. */
    $statsResult = $dbSocket->query("
        SELECT COUNT(*) AS total_cards,
               COALESCE(SUM(total_quota),0) AS total_quota,
               COALESCE(SUM(used_quota),0) AS total_used
        FROM userinfo
        WHERE package_id IN (53,54,55,56,57,58)
    ");
    if (!DB::isError($statsResult)) {
        $row = $statsResult->fetchRow(DB_FETCHMODE_ASSOC);
        if ($row) $stats = $row;
    }
}


/* TRASH_LOGICAL_FILTER_V1 */
$trashStatsResult = $dbSocket->query("
    SELECT
        COUNT(*) AS trash_cards,
        COALESCE(SUM(ui.total_quota),0) AS trash_quota,
        COALESCE(SUM(ui.used_quota),0) AS trash_used
    FROM userinfo ui
    WHERE ui.package_id IN (53,54,55,56,57,58)
      AND EXISTS (
          SELECT 1
          FROM nawa_card_trash t
          WHERE t.username=ui.username
            AND t.status='trashed'
      )
");

if (!DB::isError($trashStatsResult)) {
    $trashStats = $trashStatsResult->fetchRow(DB_FETCHMODE_ASSOC);

    $stats['total_cards'] = max(
        0,
        (int)$stats['total_cards'] -
        (int)($trashStats['trash_cards'] ?? 0)
    );

    $stats['total_quota'] = max(
        0,
        (int)$stats['total_quota'] -
        (int)($trashStats['trash_quota'] ?? 0)
    );

    $stats['total_used'] = max(
        0,
        (int)$stats['total_used'] -
        (int)($trashStats['trash_used'] ?? 0)
    );
}


$totalQuotaBytes =
    (float)$stats['total_quota'];


$totalUsedBytes =
    (float)$stats['total_used'];


$totalRemainingBytes =
    max(
        0,
        $totalQuotaBytes -
        $totalUsedBytes
    );

$totalOnlineCards = (int)$dbSocket->getOne(
    "SELECT live_cards FROM nawa_report_state WHERE id=1 LIMIT 1"
);


/*
|--------------------------------------------------------------------------
| شرط البحث
|--------------------------------------------------------------------------
*/

$where = "
    WHERE
        ui.package_id IN
        (53,54,55,56,57,58)

        AND NOT EXISTS (
            SELECT 1
            FROM nawa_card_trash trash_filter
            WHERE trash_filter.username = ui.username
              AND trash_filter.status = 'trashed'
        )
";


if ($search !== '') {

    $cleanSearch =
        str_replace(
            ['%', '_'],
            '',
            $search
        );


    $escapedSearch =
        $dbSocket->escapeSimple(
            $cleanSearch
        );


    $where .= sprintf(
        " AND ui.username
          LIKE '%%%s%%'",
        $escapedSearch
    );
}


/*
|--------------------------------------------------------------------------
| عدد نتائج البحث
|--------------------------------------------------------------------------
*/

$totalFiltered = $search === ''
    ? (int)$stats['total_cards']
    : (int)$dbSocket->getOne(
        "SELECT COUNT(*)
         FROM userinfo ui
         {$where}"
    );


/*
|--------------------------------------------------------------------------
| جلب الكروت
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT

        ui.id,

        ui.username,

        ui.package_id,

        COALESCE(
            NULLIF(ui.package_name,''),
            p.name,
            'بدون باقة'
        ) AS package_name,

        COALESCE(
            ui.total_quota,
            0
        ) AS quota_bytes,

        COALESCE(
            ui.used_quota,
            0
        ) AS used_bytes,

        GREATEST(

            COALESCE(
                ui.total_quota,
                0
            )

            -

            COALESCE(
                ui.used_quota,
                0
            ),

            0

        ) AS remaining_bytes,

        ui.creationdate,

        COALESCE(
            ui.expiry_date,
            ui.expires_at,
            CASE
                WHEN ui.creationdate IS NOT NULL THEN
                    DATE_ADD(
                        ui.creationdate,
                        INTERVAL
                            CASE ui.package_id
                                WHEN 53 THEN 6
                                WHEN 54 THEN 10
                                WHEN 55 THEN 14
                                WHEN 56 THEN 20
                                WHEN 57 THEN 30
                                WHEN 58 THEN 30
                                ELSE 30
                            END DAY
                    )
                ELSE NULL
            END
        ) AS expires_at,

        ui.status,

        ui.is_disabled,

        CASE
            WHEN EXISTS (
                SELECT 1
                FROM radacct ra
                WHERE ra.username = ui.username
                  AND ra.acctstoptime IS NULL
            )
            THEN 1
            ELSE 0
        END AS online

    FROM userinfo ui

    LEFT JOIN packages p
        ON p.id=ui.package_id

    {$where}

    ORDER BY {$cardOrderBy}

    LIMIT {$offset}, {$pageSize}
";


$result =
    $dbSocket->query($sql);


$cards = [];


if (!DB::isError($result)) {

    while (
        $row =
            $result->fetchRow(
                DB_FETCHMODE_ASSOC
            )
    ) {

        $cards[] = $row;
    }
}


$totalPages =
    max(
        1,
        (int)ceil(
            $totalFiltered /
            $pageSize
        )
    );


$csrf =
    dalo_csrf_token();


include '../common/includes/db_close.php';


if (!$fragmentMode) {

    print_html_prologue(
        'شحن وإصلاح الكروت',
        $langCode,
        [
            'static/css/nawa-production.css'
        ]
    );
}

?>


<div class="nawa-page">


    <!-- ===================================================
         رأس الصفحة
         =================================================== -->

    <section class="nawa-page-header">

        <div>

            <h1>
                شحن وإصلاح الكروت
            </h1>

            <p>
                مراقبة جميع الكروت والقيقات
                والاستهلاك وإعادة الشحن.
            </p>

        </div>

    </section>


    <?php if ($successMsg !== ''): ?>

        <div class="nawa-alert success">

            <i class="bi bi-check2-circle"></i>

            <span>
                <?= cr_e($successMsg) ?>
            </span>

        </div>

    <?php endif; ?>


    <?php if ($failureMsg !== ''): ?>

        <div class="nawa-alert danger">

            <i class="bi bi-exclamation-triangle"></i>

            <span>
                <?= cr_e($failureMsg) ?>
            </span>

        </div>

    <?php endif; ?>



    <div class="nawa-page-actions" style="margin-bottom:16px">

        <button
            type="button"
            class="nawa-button primary"
            data-open-add-card
            onclick="var m=document.getElementById('cardAddModal');if(m){m.style.display='flex';m.setAttribute('aria-hidden','false');}"
        >
            <i class="bi bi-plus-circle"></i>
            إضافة كرت
        </button>

    </div>


    <!-- ===================================================
         الإحصائيات
         =================================================== -->

    <section class="nawa-stat-grid">


        <article class="nawa-stat">

            <span>
                إجمالي الكروت
            </span>

            <strong>
                <?= number_format(
                    (int)$stats['total_cards']
                ) ?>
            </strong>

        </article>


        <article class="nawa-stat">

            <span>
                إجمالي القيقات
            </span>

            <strong>

                <?= number_format(
                    cr_bytes_to_gb(
                        $totalQuotaBytes
                    ),
                    2
                ) ?>

                GB

            </strong>

        </article>


        <article class="nawa-stat">

            <span>
                إجمالي المستخدم
            </span>

            <strong>

                <?= number_format(
                    cr_bytes_to_gb(
                        $totalUsedBytes
                    ),
                    2
                ) ?>

                GB

            </strong>

        </article>


        <article class="nawa-stat">

            <span>
                إجمالي المتصلين
            </span>

            <strong>
                <?= number_format($totalOnlineCards) ?>
            </strong>

        </article>


    </section>


    <!-- ===================================================
         قائمة الكروت
         =================================================== -->

    <section class="nawa-card">


        <header class="nawa-card-header">

            <div>

                <h2>
                    جميع الكروت
                </h2>

                <p>

                    <?php if ($search !== ''): ?>

                        نتائج البحث:

                    <?php endif; ?>


                    <?= number_format(
                        $totalFiltered
                    ) ?>

                    كرت

                </p>

            </div>

        </header>


        <!-- =================================================
             البحث
             ================================================= -->

        <form
            class="nawa-filter"
            method="get"
            data-card-search-form
            data-manual-search
        >
            <input type="hidden" name="sort" value="<?= cr_e($cardSort) ?>">
            <input type="hidden" name="dir" value="<?= strtolower($cardDirection) ?>">
            <label class="nawa-card-page-size"><span>عرض</span><input type="number" name="per_page" data-card-per-page min="1" max="500" value="<?= $pageSize ?>" inputmode="numeric"><span>رمز</span></label>


            <div class="nawa-search-field">

                <i class="bi bi-search"></i>

                <input
                    type="search"
                    name="card"
                    value="<?= cr_e($search) ?>"
                    placeholder="ابحث برمز الكرت..."
                    autocomplete="off"
                >

            </div>


            <button
                class="nawa-button primary"
                type="submit"
            >

                <i class="bi bi-search"></i>

                بحث

            </button>


            <?php if ($search !== ''): ?>

                <button
                    class="nawa-button"
                    type="button"
                    data-clear-card-search
                >

                    مسح

                </button>

            <?php endif; ?>


        </form>


        <!-- =================================================
             الجدول
             ================================================= -->

        <?php if (count($cards) === 0): ?>


            <div class="nawa-empty">

                <i class="bi bi-ticket-perforated"></i>

                لا توجد كروت مطابقة.

            </div>


        <?php else: ?>


        <div class="nawa-table-wrap">


            <table class="nawa-table">


                <thead><tr><?php $cardHeader = static function ($key, $label) use ($cardSort, $cardDirection) { $active = $cardSort === $key; $next = $active && $cardDirection === 'ASC' ? 'desc' : 'asc'; return '<th><button class="nawa-card-sort' . ($active ? ' active' : '') . '" type="button" data-card-sort="' . cr_e($key) . '" data-card-dir="' . $next . '">' . cr_e($label) . ' <i class="bi ' . ($active ? ($cardDirection === 'ASC' ? 'bi-sort-up' : 'bi-sort-down') : 'bi-arrow-down-up') . '"></i></button></th>'; }; ?><?= $cardHeader('code','رمز الكرت') ?><?= $cardHeader('package','الباقة') ?><?= $cardHeader('quota','إجمالي القيقات') ?><?= $cardHeader('used','المستخدم') ?><?= $cardHeader('remaining','المتبقي') ?><?= $cardHeader('created','تاريخ الإنشاء') ?><?= $cardHeader('expires','تاريخ الانتهاء') ?><?= $cardHeader('status','الحالة') ?><th>الإجراء</th></tr></thead>


                <tbody>


                <?php foreach ($cards as $card):

                    $quotaGb =
                        cr_bytes_to_gb(
                            $card['quota_bytes']
                        );


                    $usedGb =
                        cr_bytes_to_gb(
                            $card['used_bytes']
                        );


                    $remainingGb =
                        cr_bytes_to_gb(
                            $card[
                                'remaining_bytes'
                            ]
                        );


                    $disabled =
                        (int)$card[
                            'is_disabled'
                        ] === 1
                        ||
                        $card['status']
                        === 'disabled';


                    $creationDate =
                        trim(
                            (string)(
                                $card[
                                    'creationdate'
                                ] ?? ''
                            )
                        );


                    $expiry =
                        trim(
                            (string)(
                                $card[
                                    'expires_at'
                                ] ?? ''
                            )
                        );

                ?>


                    <tr>


                        <!-- رمز الكرت -->

                        <td>

                            <span class="nawa-user">

                                <b>
                                    <?= cr_e(
                                        $card['username']
                                    ) ?>
                                </b>

                                <small>

                                    ID:
                                    <?= number_format(
                                        (int)$card['id']
                                    ) ?>

                                </small>

                            </span>

                        </td>


                        <!-- الباقة -->

                        <td>

                            <span class="nawa-badge">

                                <?= cr_e(
                                    $card[
                                        'package_name'
                                    ]
                                ) ?>

                            </span>

                        </td>


                        <!-- إجمالي القيقات -->

                        <td>

                            <strong>

                                <?= number_format(
                                    $quotaGb,
                                    2
                                ) ?>

                                GB

                            </strong>

                        </td>


                        <!-- المستخدم -->

                        <td>

                            <span
                                class="nawa-badge warning"
                            >

                                <?= number_format(
                                    $usedGb,
                                    2
                                ) ?>

                                GB

                            </span>

                        </td>


                        <!-- المتبقي -->

                        <td>

                            <span
                                class="nawa-badge"
                            >

                                <?= number_format(
                                    $remainingGb,
                                    2
                                ) ?>

                                GB

                            </span>

                        </td>


                        <!-- تاريخ الإنشاء -->

                        <td>

                            <span
                                class="nawa-user"
                            >

                                <?php if (
                                    $creationDate !== ''
                                ): ?>

                                    <b>

                                        <?= cr_e(
                                            date(
                                                'Y-m-d',
                                                strtotime(
                                                    $creationDate
                                                )
                                            )
                                        ) ?>

                                    </b>

                                    <small>

                                        <?= cr_e(
                                            date(
                                                'H:i',
                                                strtotime(
                                                    $creationDate
                                                )
                                            )
                                        ) ?>

                                    </small>

                                <?php else: ?>

                                    <small>
                                        غير محدد
                                    </small>

                                <?php endif; ?>

                            </span>

                        </td>


                        <!-- الصلاحية -->

                        <td>

                            <span
                                class="nawa-badge muted"
                            >

                                <?php

                                if ($expiry !== '') {

                                    $expiryTs = strtotime($expiry);

                                    echo '<b>' .
                                        cr_e(date('Y-m-d', $expiryTs)) .
                                        '</b>';

                                    echo '<small style="display:block;margin-top:3px">' .
                                        cr_e(date('H:i', $expiryTs)) .
                                        '</small>';

                                } else {

                                    echo 'غير محددة';
                                }

                                ?>

                            </span>

                        </td>


                        <!-- الحالة -->

                        <td>

                            <?php if ($disabled): ?>

                                <span
                                    class="nawa-badge warning"
                                >
                                    معطل
                                </span>

                            <?php elseif ((int)($card['online'] ?? 0) === 1): ?>

                                <span
                                    class="nawa-badge"
                                >
                                    متصل
                                </span>

                            <?php else: ?>

                                <span
                                    class="nawa-badge muted"
                                >
                                    غير متصل
                                </span>

                            <?php endif; ?>

                            <?php if (false): ?>

                                <span
                                    class="nawa-badge"
                                >
                                    فعال
                                </span>

                            <?php endif; ?>

                        </td>


                        <!-- الإجراء -->

                        <td>

                            <button
                                type="button"
                                class="nawa-button small"
                                onclick="loadSection('nawa-user-usage.php?fragment=1&amp;username=<?= rawurlencode((string)$card['username']) ?>&amp;source=card&amp;card_search=<?= rawurlencode($search) ?>&amp;card_page=<?= (int)$page ?>')"
                            >
                                <i class="bi bi-bar-chart-line"></i>
                                تفاصيل الاستخدام
                            </button>

                            <button
                                type="button"
                                class="nawa-button success small"
                                data-open-card-recharge
                                data-card-id="<?= (int)$card['id'] ?>"
                                data-card-username="<?= cr_e($card['username']) ?>"
                                data-card-package-id="<?= (int)$card['package_id'] ?>"
                                data-card-package="<?= cr_e($card['package_name']) ?>"
                                data-card-quota="<?= number_format($quotaGb, 2, '.', '') ?>"
                                data-card-used="<?= number_format($usedGb, 2, '.', '') ?>"
                                data-card-remaining="<?= number_format($remainingGb, 2, '.', '') ?>"
                            >
                                <i class="bi bi-arrow-repeat"></i>
                                شحن / إصلاح
                            </button>

                            <form
                                method="post"
                                style="display:inline-block;margin-inline-start:6px"
                                onsubmit="return confirm('هل أنت متأكد من حذف الكرت <?= cr_e($card['username']) ?>؟');"
                            >
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= cr_e($csrf) ?>"
                                >
                                <input
                                    type="hidden"
                                    name="action"
                                    value="delete_card"
                                >
                                <input
                                    type="hidden"
                                    name="card_id"
                                    value="<?= (int)$card['id'] ?>"
                                >

                                <button
                                    type="submit"
                                    class="nawa-button danger small"
                                >
                                    <i class="bi bi-trash3"></i>
                                    حذف
                                </button>
                            </form>

                        </td>


                    </tr>


                <?php endforeach; ?>


                </tbody>


            </table>


        </div>


        <!-- =================================================
             ترقيم الصفحات
             ================================================= -->

        <?php if ($totalPages > 1):

            $fromPage =
                max(
                    1,
                    $page - 4
                );


            $toPage =
                min(
                    $totalPages,
                    $page + 4
                );

        ?>


            <footer
                class="p-3 d-flex gap-2 justify-content-center flex-wrap"
            >


                <?php if ($page > 1): ?>


                    <button
                        type="button"
                        class="nawa-button small"
                        data-card-page="<?= $page - 1 ?>" data-card-sort-value="<?= cr_e($cardSort) ?>" data-card-dir-value="<?= strtolower($cardDirection) ?>" data-card-search-value="<?= cr_e($search) ?>" data-card-per-page-value="<?= $pageSize ?>"
                    >

                        السابق

                    </button>


                <?php endif; ?>


                <?php for (
                    $i = $fromPage;
                    $i <= $toPage;
                    $i++
                ): ?>


                    <button
                        type="button"
                        class="nawa-button small <?= $i === $page ? 'primary' : '' ?>"
                        data-card-page="<?= $i ?>" data-card-sort-value="<?= cr_e($cardSort) ?>" data-card-dir-value="<?= strtolower($cardDirection) ?>" data-card-search-value="<?= cr_e($search) ?>" data-card-per-page-value="<?= $pageSize ?>"
                    >

                        <?= $i ?>

                    </button>


                <?php endfor; ?>


                <?php if (
                    $page < $totalPages
                ): ?>


                    <button
                        type="button"
                        class="nawa-button small"
                        data-card-page="<?= $page + 1 ?>" data-card-sort-value="<?= cr_e($cardSort) ?>" data-card-dir-value="<?= strtolower($cardDirection) ?>" data-card-search-value="<?= cr_e($search) ?>" data-card-per-page-value="<?= $pageSize ?>"
                    >

                        التالي

                    </button>


                <?php endif; ?>


            </footer>


        <?php endif; ?>


        <?php endif; ?>


    </section>


</div>




<div
    class="nawa-modal"
    id="cardAddModal"
    aria-hidden="true"
    style="display:none"
>

    <div class="nawa-modal-dialog nawa-modal-wide">

        <div class="nawa-modal-head">

            <div>

                <h2>
                    <i class="bi bi-plus-circle"></i>
                    إضافة كرت جديد
                </h2>

                <small>
                    استخدمها لتعويض كرت محذوف أو إنشاء كرت يدوي جديد.
                </small>

            </div>

            <button
                type="button"
                data-add-card-close
                onclick="(function(){
                    var m=document.getElementById('cardAddModal');
                    if(!m)return;
                    m.setAttribute('aria-hidden','true');
                    m.classList.remove('active');
                })()"
            >
                <i class="bi bi-x-lg"></i>
            </button>

        </div>


        <form
            method="post"
            action="nawa-card-recharge.php?fragment=1"
            data-add-card-form
        >

            <div class="nawa-modal-body">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= cr_e($csrf) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="add_card"
                >


                <div class="nawa-form-grid">

                    <label class="nawa-field">

                        <span>
                            رقم الكرت
                        </span>

                        <input
                            type="text"
                            name="new_card_number"
                            inputmode="numeric"
                            pattern="[0-9]+"
                            minlength="6"
                            maxlength="20"
                            autocomplete="off"
                            placeholder="مثال: 82018910144100"
                            required
                        >

                        <small>
                            رقم الكرت سيكون أيضًا رمز الدخول وكلمة المرور.
                        </small>

                    </label>


                    <label class="nawa-field">

                        <span>
                            باقة الكرت
                        </span>

                        <select
                            name="new_card_package_id"
                            required
                        >

                            <?php foreach ($cardPackages as $pkg): ?>

                                <option
                                    value="<?= (int)$pkg['id'] ?>"
                                >
                                    <?= cr_e($pkg['name']) ?>
                                    —
                                    <?= number_format(
                                        (float)$pkg['real_gb'],
                                        0
                                    ) ?> GB
                                    —
                                    <?= (int)$pkg['real_days'] ?> يوم
                                </option>

                            <?php endforeach; ?>

                        </select>

                        <small>
                            سيتم تطبيق القيقات والأيام تلقائيًا حسب الباقة.
                        </small>

                    </label>

                </div>


                <div class="nawa-current-summary mt-3">

                    <span>
                        <small>الحالة</small>
                        <b>فعال مباشرة</b>
                    </span>

                    <span>
                        <small>الاستهلاك</small>
                        <b>0 GB</b>
                    </span>

                    <span>
                        <small>كلمة المرور</small>
                        <b>نفس رقم الكرت</b>
                    </span>

                </div>

            </div>


            <div class="nawa-modal-foot">

                <button
                    class="nawa-button primary"
                    type="submit"
                >
                    <i class="bi bi-check2-circle"></i>
                    إنشاء الكرت
                </button>

                <button
                    class="nawa-button"
                    type="button"
                    data-add-card-close
                    onclick="(function(){
                        var m=document.getElementById('cardAddModal');
                        if(!m)return;
                        m.setAttribute('aria-hidden','true');
                        m.classList.remove('active');
                    })()"
                >
                    إلغاء
                </button>

            </div>

        </form>

    </div>

</div>


<div class="nawa-modal" id="cardRechargeModal" aria-hidden="true">

    <div class="nawa-modal-dialog nawa-modal-wide">

        <div class="nawa-modal-head">

            <div>
                <h2>
                    شحن / إصلاح الكرت
                    <span data-card-modal-username></span>
                </h2>

                <small>
                    اختر الباقة الجديدة ثم أكد عملية الشحن.
                </small>
            </div>

            <button type="button" data-card-modal-close>
                <i class="bi bi-x-lg"></i>
            </button>

        </div>


        <form
            method="post"
            action="nawa-card-recharge.php?fragment=1"
            data-card-modal-form
        >

            <div class="nawa-modal-body">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= cr_e($csrf) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="recharge"
                >

                <input
                    type="hidden"
                    name="card"
                    data-card-modal-card
                >


                <div class="nawa-current-summary">

                    <span>
                        <small>الباقة الحالية</small>
                        <b data-card-modal-package>—</b>
                    </span>

                    <span>
                        <small>إجمالي القيقات</small>
                        <b data-card-modal-quota>—</b>
                    </span>

                    <span>
                        <small>المستخدم</small>
                        <b data-card-modal-used>—</b>
                    </span>

                    <span>
                        <small>المتبقي</small>
                        <b data-card-modal-remaining>—</b>
                    </span>

                </div>


                <label class="nawa-field mt-3">

                    <span>باقة الكرت الجديدة</span>

                    <select
                        name="package_id"
                        required
                        data-card-modal-package-select
                    >

                        <?php foreach ($cardPackages as $pkg): ?>

                            <option value="<?= (int)$pkg['id'] ?>">

                                <?= cr_e($pkg['name']) ?>

                                —
                                <?= number_format((float)$pkg['real_gb'], 0) ?> GB

                                —
                                <?= (int)$pkg['real_days'] ?> يوم

                            </option>

                        <?php endforeach; ?>

                    </select>

                </label>


                <div class="nawa-choice-grid mt-3">

                    <label class="nawa-choice-card">

                        <input
                            type="checkbox"
                            name="reset_used"
                            value="1"
                            checked
                        >

                        <span>
                            <i class="bi bi-arrow-counterclockwise"></i>
                            <b>تصفير الاستهلاك</b>
                            <small>يبدأ الكرت من الصفر بعد الشحن.</small>
                        </span>

                    </label>


                    <label class="nawa-choice-card">

                        <input
                            type="checkbox"
                            name="enable_card"
                            value="1"
                            checked
                        >

                        <span>
                            <i class="bi bi-check-circle"></i>
                            <b>إعادة تفعيل الكرت</b>
                            <small>تشغيل الكرت إذا كان متوقفًا.</small>
                        </span>

                    </label>

                </div>

            </div>


            <div class="nawa-modal-foot">

                <button
                    class="nawa-button primary"
                    type="submit"
                >
                    <i class="bi bi-check2-circle"></i>
                    تأكيد الشحن
                </button>

                <button
                    class="nawa-button"
                    type="button"
                    data-card-modal-close
                >
                    إلغاء
                </button>

            </div>

        </form>

    </div>

</div>

<script>


(function () {

    var addCardModal =
        document.getElementById(
            'cardAddModal'
        );

    var openAddCardButton =
        document.querySelector(
            '[data-open-add-card]'
        );

    function openAddCardModal() {

        if (!addCardModal) {
            return;
        }

        addCardModal.setAttribute(
            'aria-hidden',
            'false'
        );

        addCardModal.classList.add(
            'active'
        );

        var input =
            addCardModal.querySelector(
                '[name="new_card_number"]'
            );

        if (input) {

            setTimeout(
                function () {
                    input.focus();
                },
                100
            );
        }
    }

    function closeAddCardModal() {

        if (!addCardModal) {
            return;
        }

        addCardModal.setAttribute(
            'aria-hidden',
            'true'
        );

        addCardModal.classList.remove(
            'active'
        );
    }

    if (openAddCardButton) {

        openAddCardButton.addEventListener(
            'click',
            openAddCardModal
        );
    }

    document
        .querySelectorAll(
            '[data-add-card-close]'
        )
        .forEach(
            function (button) {

                button.addEventListener(
                    'click',
                    closeAddCardModal
                );
            }
        );




    /*
    |--------------------------------------------------------------------------
    | البحث
    |--------------------------------------------------------------------------
    */

    var searchForm =
        document.querySelector(
            '[data-card-search-form]'
        );


    if (searchForm) {

        searchForm.addEventListener(
            'submit',
            function (event) {

                event.preventDefault();


                var input =
                    searchForm.querySelector(
                        '[name="card"]'
                    );


                var value =
                    input
                    ? input.value.trim()
                    : '';


                loadSection(
                    'nawa-card-recharge.php?fragment=1&card='
                    +
                    encodeURIComponent(value)
                );
            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | مسح البحث
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll(
            '[data-clear-card-search]'
        )
        .forEach(
            function (button) {

                button.addEventListener(
                    'click',
                    function () {

                        loadSection(
                            'nawa-card-recharge.php?fragment=1'
                        );
                    }
                );
            }
        );


    /*
    |--------------------------------------------------------------------------
    | فتح Dialog شحن / إصلاح الكرت
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll('[data-open-card-recharge]')
        .forEach(function (button) {

            button.addEventListener('click', function () {

                var modal =
                    document.getElementById(
                        'cardRechargeModal'
                    );

                if (!modal) {
                    return;
                }

                var username =
                    button.dataset.cardUsername || '';

                var packageId =
                    button.dataset.cardPackageId || '';

                var packageName =
                    button.dataset.cardPackage || '—';

                var quota =
                    button.dataset.cardQuota || '0.00';

                var used =
                    button.dataset.cardUsed || '0.00';

                var remaining =
                    button.dataset.cardRemaining || '0.00';


                modal.querySelector(
                    '[data-card-modal-username]'
                ).textContent = username;

                modal.querySelector(
                    '[data-card-modal-card]'
                ).value = username;

                modal.querySelector(
                    '[data-card-modal-package]'
                ).textContent = packageName;

                modal.querySelector(
                    '[data-card-modal-quota]'
                ).textContent = quota + ' GB';

                modal.querySelector(
                    '[data-card-modal-used]'
                ).textContent = used + ' GB';

                modal.querySelector(
                    '[data-card-modal-remaining]'
                ).textContent = remaining + ' GB';


                var select =
                    modal.querySelector(
                        '[data-card-modal-package-select]'
                    );

                if (select && packageId) {
                    select.value = packageId;
                }


                modal.classList.add('show');

                modal.setAttribute(
                    'aria-hidden',
                    'false'
                );
            });
        });


    document
        .querySelectorAll('[data-card-modal-close]')
        .forEach(function (button) {

            button.addEventListener('click', function () {

                var modal =
                    document.getElementById(
                        'cardRechargeModal'
                    );

                if (!modal) {
                    return;
                }

                modal.classList.remove('show');

                modal.setAttribute(
                    'aria-hidden',
                    'true'
                );
            });
        });


    /*
    |--------------------------------------------------------------------------
    | أرقام الصفحات
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll(
            '[data-card-page]'
        )
        .forEach(
            function (button) {

                button.addEventListener(
                    'click',
                    function () {

                        var page =
                            button.getAttribute(
                                'data-card-page'
                            );


                        var search =
                            <?= json_encode($search, JSON_UNESCAPED_UNICODE) ?>;


                        loadSection(
                            'nawa-card-recharge.php?fragment=1&page='
                            +
                            encodeURIComponent(page)
                            +
                            '&card='
                            +
                            encodeURIComponent(search)
                        );
                    }
                );
            }
        );


})();

</script>


<style>

.nawa-card-recharge-row td {

    padding: 22px !important;

    background:
        rgba(108, 92, 231, .035);

    border-top:
        1px solid rgba(108, 92, 231, .12);

    border-bottom:
        1px solid rgba(108, 92, 231, .12);
}


.nawa-card-recharge-row[hidden] {

    display: none !important;
}


.nawa-card-recharge-row:not([hidden]) {

    display: table-row !important;
}

</style>
