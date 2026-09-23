<?php
/*
 *********************************************************************************************************
 * daloRADIUS - RADIUS Web Platform
 * Copyright (C) 2007 - Liran Tal <liran@lirantal.com> All Rights Reserved.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 *********************************************************************************************************
 *
 * Authors:    Liran Tal <liran@lirantal.com>
 *             Filippo Lauria <filippo.lauria@iit.cnr.it>
 *
 *********************************************************************************************************
 */

    include ("library/checklogin.php");
    $operator = $_SESSION['operator_user'];

    $operator_perm_file = "nawa_backup_manage";
    include('library/check_operator_perm.php');
    include_once('../common/includes/config_read.php');

    // init logging variables
    $logAction = "";
    $logDebugSQL = "";

    include_once("lang/main.php");
    include("../common/includes/validation.php");
    include("../common/includes/layout.php");
    include_once("include/management/functions.php");

    // validate path
    $backup_path_prefix = $configValues['CONFIG_PATH_DALO_VARIABLE_DATA'] . "/backup";
    $backup_file_suffix = ".sql";

    // Keep the page useful on fresh installations where the backup directory
    // has not been created yet.
    $backup_dir_ready = is_dir($backup_path_prefix)
                     || @mkdir($backup_path_prefix, 0775, true);

    $file = "";
    if (array_key_exists('file', $_POST) && !empty(trim($_POST['file']))) {
        $candidate_backup_file = trim($_POST['file']);

        if (
                // this ensures that candidate_backup_file does not contain any ".." sequence
                strpos($candidate_backup_file, "..") === false &&

                // this ensures that candidate_backup_file does not contain any "/" char
                strpos($candidate_backup_file, "/") === false &&

                // Only files created by the backup page are accepted.
                preg_match('/\Abackup-\d{8}-\d{6}\.sql(?:\.gz)?\z/D', $candidate_backup_file) === 1
           ) {

            $file = $candidate_backup_file;
        }

    }

    $backupAction = (array_key_exists('action', $_POST) && isset($_POST['action']) &&
                     in_array($_POST['action'], array_keys($valid_backupActions))) ? $_POST['action'] : "";

    $cols = array(
                    t('all', 'CreationDate'),
                    "filename" => t('all', 'Name'),
                    "Size",
                    t('all', 'Action'),
                 );
    $colspan = count($cols);
    $half_colspan = intval($colspan / 2);

    $param_cols = array();
    foreach ($cols as $k => $v) { if (!is_int($k)) { $param_cols[$k] = $v; } }

    // whenever possible we use a whitelist approach
    $orderBy = (array_key_exists('orderBy', $_GET) && isset($_GET['orderBy']) &&
                in_array($_GET['orderBy'], array_keys($param_cols)))
             ? $_GET['orderBy'] : array_keys($param_cols)[0];

    $orderType = (array_key_exists('orderType', $_GET) && isset($_GET['orderType']) &&
                  in_array(strtolower($_GET['orderType']), array( "desc", "asc" )))
               ? strtolower($_GET['orderType']) : "asc";

    // init backup paths
    $fileName = sprintf("%s/%s", $backup_path_prefix, $file);
    $baseFile = basename($fileName);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if (array_key_exists('csrf_token', $_POST) && isset($_POST['csrf_token']) && dalo_check_csrf_token($_POST['csrf_token'])) {

            if (!empty($file) && !empty($backupAction) && is_dir($backup_path_prefix) && is_readable($fileName)) {

                $fileLen = filesize($fileName);

                switch($backupAction) {

                    default:
                    case "download":
                        if ($fileLen > 0) {
                            header(
                                "Content-type: "
                                . (preg_match('/\.gz\z/i', $baseFile) === 1
                                    ? 'application/gzip'
                                    : 'application/sql')
                            );
                            header(sprintf("Content-Disposition: attachment; filename=%s; size=%d", $baseFile, $fileLen));
                            header(sprintf("Content-Length: %d", $fileLen));
                            header('X-Content-Type-Options: nosniff');

                            // Stream large backups instead of loading the whole
                            // SQL file into PHP's memory.
                            $downloadHandle = fopen($fileName, 'rb');
                            if ($downloadHandle !== false) {
                                while (ob_get_level() > 0) {
                                    ob_end_clean();
                                }
                                fpassthru($downloadHandle);
                                fclose($downloadHandle);
                                exit;
                            }
                        }

                        $failureMsg = sprintf("Cannot %s backup file %s (file is empty)", $backupAction, $baseFile);
                        $logAction .= "$failureMsg on page: ";
                        break;

                    case "delete":
                        if (@unlink($fileName)) {
                            $successMsg = sprintf("تم حذف النسخة %s بنجاح", $baseFile);
                            $logAction .= "Successfully deleted backup file $baseFile on page: ";
                        } else {
                            $failureMsg = sprintf("تعذر حذف النسخة %s", $baseFile);
                            $logAction .= "Failed deleting backup file $baseFile on page: ";
                        }
                        break;

                    case "rollback":

                        if ($fileLen > 0) {
                            @set_time_limit(0);
                            ignore_user_abort(true);
                            $restoreIsGzip = preg_match('/\.gz\z/i', $fileName) === 1;
                            $restoreHandle = $restoreIsGzip
                                ? @gzopen($fileName, 'rb')
                                : @fopen($fileName, 'rb');
                            if ($restoreHandle === false) {
                                $failureMsg = sprintf("تعذر فتح النسخة %s للاستعادة", $baseFile);
                                $logAction .= "$failureMsg on page: ";
                                break;
                            }
                            include('../common/includes/db_open.php');
                            // Database failures must be handled here so AJAX
                            // responses remain valid JSON instead of mixed HTML.
                            $dbSocket->setErrorHandling(PEAR_ERROR_RETURN);
                            $isError = 0;
                            $tables = array();
                            $clearedTables = array();
                            $foreignKeysDisabled = false;
                            $restoreTransactionStarted = false;
                            $allowedRestoreTables = array();
                            foreach ($configValues as $configKey => $configValue) {
                                if (strpos($configKey, 'CONFIG_DB_TBL_') === 0
                                    && is_string($configValue)
                                    && preg_match('/\A[A-Za-z0-9_]+\z/D', $configValue) === 1) {
                                    $allowedRestoreTables[$configValue] = true;
                                }
                            }
                            // Automatic backups also include the installation's
                            // custom application tables. Only tables that already
                            // exist in this database are accepted during restore.
                            $actualTables = $dbSocket->query(
                                "SELECT TABLE_NAME FROM information_schema.TABLES"
                                . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'"
                            );
                            if (!DB::isError($actualTables)) {
                                while ($actualTable = $actualTables->fetchRow(DB_FETCHMODE_ASSOC)) {
                                    $actualName = (string) ($actualTable['TABLE_NAME'] ?? '');
                                    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $actualName) === 1) {
                                        $allowedRestoreTables[$actualName] = true;
                                    }
                                }
                            }

                            // Validate every referenced table before deleting
                            // anything. This prevents a foreign or malformed SQL
                            // file from leaving a partially restored database.
                            $preflightTables = array();
                            while (($preflightLine = ($restoreIsGzip ? gzgets($restoreHandle) : fgets($restoreHandle))) !== false) {
                                $preflightLine = trim($preflightLine);
                                $preflightTable = '';
                                if (preg_match('/\AINSERT\s+INTO\s+`?([A-Za-z0-9_]+)`?\s+/i', $preflightLine, $preflightMatches) === 1) {
                                    $preflightTable = $preflightMatches[1];
                                } elseif (preg_match('/\A--\s+Dumping data for table\s+`?([A-Za-z0-9_]+)`?\s*\z/i', $preflightLine, $preflightMatches) === 1) {
                                    // Standard mysqldump metadata identifies empty
                                    // tables, which otherwise contain no INSERT.
                                    $preflightTable = $preflightMatches[1];
                                } else {
                                    continue;
                                }
                                if (!isset($allowedRestoreTables[$preflightTable])) {
                                    $isError++;
                                    break;
                                }
                                $preflightTables[$preflightTable] = true;
                            }
                            if (!(($restoreIsGzip ? gzeof($restoreHandle) : feof($restoreHandle))) || count($preflightTables) === 0) {
                                $isError++;
                            }
                            $restoreIsGzip ? gzrewind($restoreHandle) : rewind($restoreHandle);

                            if ($isError === 0) {
                                $res = $dbSocket->query("SET FOREIGN_KEY_CHECKS=0");
                                if (DB::isError($res)) {
                                    $isError++;
                                } else {
                                    $foreignKeysDisabled = true;
                                }
                            }
                            if ($isError === 0) {
                                $res = $dbSocket->query("START TRANSACTION");
                                if (DB::isError($res)) {
                                    $isError++;
                                } else {
                                    $restoreTransactionStarted = true;
                                }
                            }

                            if ($isError === 0) {
                                // Empty tables have no INSERT statements, so all
                                // validated tables must be cleared before import.
                                foreach (array_keys($preflightTables) as $table) {
                                    $res = $dbSocket->query(sprintf("DELETE FROM `%s`", $table));
                                    if (DB::isError($res)) {
                                        $isError++;
                                        break;
                                    }
                                    $clearedTables[$table] = true;
                                    $tables[] = $table;
                                }
                            }

                            // mysqldump writes each extended INSERT on one line.
                            // Reading one line at a time keeps memory usage flat,
                            // even for multi-gigabyte backup files.
                            while ($isError === 0 && ($query = ($restoreIsGzip ? gzgets($restoreHandle) : fgets($restoreHandle))) !== false) {
                                $query = trim($query);
                                if ($query === '') {
                                    continue;
                                }
                                if (preg_match('/\AINSERT\s+INTO\s+`?([A-Za-z0-9_]+)`?\s+/i', $query, $matches) !== 1) {
                                    continue;
                                }
                                $table = $matches[1];
                                if (!isset($allowedRestoreTables[$table])) {
                                    $isError++;
                                    break;
                                }

                                $res = $dbSocket->query($query);
                                if (DB::isError($res)) {
                                    $isError++;
                                    break;
                                }
                            }

                            if (!(($restoreIsGzip ? gzeof($restoreHandle) : feof($restoreHandle))) || count($tables) === 0) {
                                $isError++;
                            }
                            $restoreIsGzip ? gzclose($restoreHandle) : fclose($restoreHandle);
                            if ($restoreTransactionStarted) {
                                $dbSocket->query($isError === 0 ? "COMMIT" : "ROLLBACK");
                            }
                            if ($foreignKeysDisabled) {
                                $dbSocket->query("SET FOREIGN_KEY_CHECKS=1");
                            }
                            include('../common/includes/db_close.php');

                            if ($isError > 0) {
                                $failureMsg = sprintf("تعذرت استعادة النسخة %s. راجع قاعدة البيانات والصلاحيات.", $baseFile);
                                $logAction .= "$failureMsg on page: ";
                            } else {
                                $successMsg = sprintf("تمت استعادة النسخة %s بنجاح (%d جدول)", $baseFile, count($tables));
                                $logAction .= "$successMsg on page: ";
                            }
                        } else {
                            $failureMsg = sprintf("Cannot %s backup file %s (file is empty)", $backupAction, $baseFile);
                            $logAction .= "$failureMsg on page: ";
                        }

                        break;
                }

            } else {
                $failureMsg = "تعذر تنفيذ العملية المطلوبة";
                $logAction .= "$failureMsg on page: ";
            }


        } else {
            // csrf
            $failureMsg = "انتهت صلاحية الطلب. حدّث الصفحة وحاول مرة أخرى.";
            $logAction .= "$failureMsg on page: ";
        }
    }

    // AJAX mutations return a small machine-readable response. Downloads keep
    // using the regular form submission so the browser can save the SQL file.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax'])) {
        include('include/config/logging.php');
        $ok = isset($successMsg) && !empty($successMsg);
        if (!$ok) {
            http_response_code(422);
        }
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array(
            'ok' => $ok,
            'message' => $ok ? $successMsg : ($failureMsg ?? 'تعذر تنفيذ العملية'),
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // print HTML prologue
    $title = t('Intro','configbackupmanagebackups.php');
    $help = t('helpPage','configbackupmanagebackups');

    $is_fragment = isset($_GET['fragment']);
    if (!$is_fragment) {
        print_html_prologue($title, $langCode);
        print_title_and_help($title, $help);
    }

    include_once('include/management/actionMessages.php');

    // Provides the shared byte-size formatter used by the legacy backup tools.
    include('include/management/pages_common.php');

    // get backup info
    $backupInfo = array();
    $totalBackupSize = 0;

    if ($backup_dir_ready && is_readable($backup_path_prefix)) {
        $files = scandir($backup_path_prefix);
        foreach ($files as $this_file) {
            $matches = array();
            $fullPath = sprintf("%s/%s", $backup_path_prefix, $this_file);
            if (!is_file($fullPath)
                || preg_match('/\Abackup-(\d{8})-(\d{6})\.sql(?:\.gz)?\z/D', $this_file, $matches) !== 1) {
                continue;
            }

            $fileDate = substr($matches[1], 0, 4) . "-" . substr($matches[1], 4, 2) . "-" . substr($matches[1], 6, 2);
            $fileTime = substr($matches[2], 0, 2) . ":" . substr($matches[2], 2, 2) . ":" . substr($matches[2], 4, 2);
            $fileSize = filesize($fullPath);
            $totalBackupSize += $fileSize;

            $backupInfo[] = array(
                                    'date' => $fileDate,
                                    'time' => $fileTime,
                                    'filename' => $this_file,
                                    'size' => toxbyte($fileSize),
                                    'timestamp' => $matches[1] . $matches[2],
                                 );
        }
    }

    usort($backupInfo, function ($a, $b) use ($orderType) {
        $comparison = strcmp($b['timestamp'], $a['timestamp']);
        return ($orderType === 'asc') ? -$comparison : $comparison;
    });

    $numrows = count($backupInfo);
    $latestBackup = $numrows > 0
                  ? htmlspecialchars($backupInfo[0]['date'] . '، ' . $backupInfo[0]['time'], ENT_QUOTES, 'UTF-8')
                  : 'لا توجد نسخة بعد';
    $csrf_token = dalo_csrf_token();

    echo '<style>
      .nawa-backup-page{display:grid;gap:1rem;color:var(--nawa-ink,#1f2433)}
      .nawa-backup-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem}
      .nawa-backup-stat{display:flex;align-items:center;gap:.85rem;padding:1rem 1.1rem;border:1px solid #e7e8ef;border-radius:1rem;background:var(--nawa-surface,#fff);box-shadow:0 .55rem 1.5rem rgba(23,28,49,.05)}
      .nawa-backup-stat>i,.nawa-backup-empty>i{display:grid;place-items:center;width:2.8rem;height:2.8rem;border-radius:.8rem;color:#5b49c6;background:#f0edff;font-size:1.25rem}
      .nawa-backup-stat span{display:block;color:#7c8295;font-size:.78rem}.nawa-backup-stat strong{display:block;margin-top:.2rem;font-size:1rem}
      .nawa-backup-empty{display:flex;min-height:260px;flex-direction:column;align-items:center;justify-content:center;padding:2rem;text-align:center}
      .nawa-backup-empty>i{width:4rem;height:4rem;border-radius:1.2rem;font-size:1.8rem}.nawa-backup-empty h3{margin:1rem 0 .35rem;font-size:1.05rem}.nawa-backup-empty p{margin:0 0 1.1rem;color:#7c8295;font-size:.86rem}
      .nawa-backup-filename{direction:ltr;text-align:right;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:.78rem}
      .nawa-backup-actions{display:flex;flex-wrap:wrap;gap:.4rem}.nawa-backup-actions form{display:flex;flex-wrap:wrap;gap:.4rem}
      .nawa-backup-header-actions,.nawa-backup-empty-actions{display:flex;align-items:center;gap:.55rem;flex-wrap:wrap}
      .backup-upload-modal[hidden]{display:none}.backup-upload-modal{position:fixed;inset:0;z-index:1200;display:grid;place-items:center;padding:1rem;background:rgba(16,18,29,.58);backdrop-filter:blur(5px)}
      .backup-upload-dialog{width:min(620px,100%);max-height:calc(100vh - 2rem);overflow:auto;border:1px solid #e2e3eb;border-radius:1.15rem;background:var(--nawa-surface,#fff);box-shadow:0 1.5rem 4rem rgba(14,16,29,.24)}
      .backup-upload-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;padding:1.15rem 1.25rem;border-bottom:1px solid #ececf2}.backup-upload-head h3{margin:0;font-size:1.08rem}.backup-upload-head p{margin:.25rem 0 0;color:#7d8497;font-size:.78rem}.backup-upload-close{display:grid;place-items:center;width:2.2rem;height:2.2rem;border:1px solid #e1e2e9;border-radius:.65rem;background:transparent;color:#6f7588;cursor:pointer}
      .backup-upload-body{display:grid;gap:1rem;padding:1.2rem 1.25rem}.backup-upload-warning{display:flex;align-items:flex-start;gap:.7rem;padding:.85rem .9rem;border:1px solid #f1d7a7;border-radius:.8rem;color:#7a5514;background:#fff9ed;font-size:.76rem;line-height:1.7}.backup-upload-warning i{font-size:1rem}
      .backup-upload-drop{display:flex;min-height:190px;flex-direction:column;align-items:center;justify-content:center;padding:1.4rem;border:2px dashed #c9c4e8;border-radius:1rem;background:#faf9ff;text-align:center;cursor:pointer;transition:.16s ease}.backup-upload-drop:hover,.backup-upload-drop.is-dragging{border-color:#6a55d7;background:#f4f1ff}.backup-upload-drop>i{display:grid;place-items:center;width:3.7rem;height:3.7rem;border-radius:1rem;color:#5b49c6;background:#eae5ff;font-size:1.55rem}.backup-upload-drop strong{margin-top:.8rem;font-size:.9rem}.backup-upload-drop span{margin-top:.25rem;color:#858b9d;font-size:.73rem}.backup-upload-drop input{position:absolute;width:1px;height:1px;opacity:0}
      .backup-upload-file[hidden]{display:none}.backup-upload-file{display:flex;align-items:center;gap:.75rem;padding:.8rem .9rem;border:1px solid #e4e5ec;border-radius:.8rem}.backup-upload-file>i{display:grid;place-items:center;flex:0 0 2.4rem;height:2.4rem;border-radius:.7rem;color:#087f62;background:#e9f8f3}.backup-upload-file div{min-width:0}.backup-upload-file strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.8rem;direction:ltr;text-align:right}.backup-upload-file span{display:block;margin-top:.2rem;color:#858b9d;font-size:.7rem}
      .backup-upload-progress[hidden]{display:none}.backup-upload-progress{display:grid;gap:.5rem}.backup-upload-progress-copy{display:flex;align-items:center;justify-content:space-between;gap:1rem;color:#737a8e;font-size:.72rem}.backup-upload-track{height:.65rem;overflow:hidden;border-radius:999px;background:#ececf3}.backup-upload-bar{width:0;height:100%;border-radius:inherit;background:linear-gradient(90deg,#6a55d7,#8c78ee);transition:width .18s ease}.backup-upload-progress.is-indeterminate .backup-upload-bar{width:40%!important;animation:backup-progress 1.1s ease-in-out infinite alternate}@keyframes backup-progress{from{transform:translateX(120%)}to{transform:translateX(-170%)}}
      .backup-upload-confirm{display:flex;align-items:flex-start;gap:.65rem;padding:.8rem .9rem;border:1px solid #e5e6ed;border-radius:.8rem;font-size:.76rem;line-height:1.65;cursor:pointer}.backup-upload-confirm input{margin-top:.25rem;accent-color:#5b49c6}
      .backup-upload-foot{display:flex;justify-content:flex-end;gap:.55rem;padding:1rem 1.25rem;border-top:1px solid #ececf2}.backup-upload-foot .nawa-button.primary{min-width:185px}.backup-upload-foot button:disabled{opacity:.55;cursor:not-allowed}
      html[data-theme="dark"] .backup-upload-warning,body.dark .backup-upload-warning{border-color:#665333;color:#f0cb83;background:#302a21}html[data-theme="dark"] .backup-upload-drop,body.dark .backup-upload-drop{border-color:#4d4869;background:#242538}html[data-theme="dark"] .backup-upload-dialog,body.dark .backup-upload-dialog{border-color:#3b3d53;background:#202233}
      @media(max-width:800px){.nawa-backup-stats{grid-template-columns:1fr}.nawa-card-header{align-items:flex-start;flex-direction:column}.nawa-card-header .nawa-button,.nawa-backup-header-actions,.nawa-backup-header-actions .nawa-button{width:100%}.backup-upload-foot{flex-direction:column-reverse}.backup-upload-foot .nawa-button{width:100%}}
    </style>';

    echo '<div class="nawa-backup-page" dir="rtl">';
    echo '<div class="nawa-backup-stats">';
    printf('<div class="nawa-backup-stat"><i class="bi bi-archive"></i><div><span>عدد النسخ</span><strong>%d</strong></div></div>', $numrows);
    printf('<div class="nawa-backup-stat"><i class="bi bi-device-ssd"></i><div><span>الحجم الإجمالي</span><strong>%s</strong></div></div>', htmlspecialchars(toxbyte($totalBackupSize), ENT_QUOTES, 'UTF-8'));
    printf('<div class="nawa-backup-stat"><i class="bi bi-clock-history"></i><div><span>آخر نسخة</span><strong>%s</strong></div></div>', $latestBackup);
    echo '</div>';

    echo '<section class="nawa-card">';
    echo '<header class="nawa-card-header"><div><h2>إدارة النسخ الاحتياطية</h2><p>إنشاء نسخ قاعدة البيانات وتحميلها أو استعادتها عند الحاجة.</p></div>';
    echo '<div class="nawa-backup-header-actions"><button type="button" class="nawa-button" onclick="loadSection(\'nawa-backup-settings.php?fragment=1\')"><i class="bi bi-gear"></i> إعدادات النسخ</button><button type="button" class="nawa-button" onclick="nawaOpenUploadRestore()"><i class="bi bi-laptop"></i> استعادة من الجهاز</button><button type="button" class="nawa-button primary" onclick="loadSection(\'nawa-backup-create.php?fragment=1\')"><i class="bi bi-cloud-arrow-up"></i> أخذ نسخة احتياطية الآن</button></div></header>';

    if (!$backup_dir_ready) {
        echo '<div class="nawa-backup-empty"><i class="bi bi-exclamation-triangle"></i><h3>تعذر تجهيز مجلد النسخ</h3><p>تحقق من صلاحيات مجلد البيانات ثم أعد المحاولة.</p></div>';
    } elseif ($numrows === 0) {
        echo '<div class="nawa-backup-empty"><i class="bi bi-cloud-arrow-up"></i><h3>لا توجد نسخ احتياطية بعد</h3><p>أنشئ أول نسخة أو ارفع نسخة موجودة على جهازك.</p><div class="nawa-backup-empty-actions"><button type="button" class="nawa-button" onclick="nawaOpenUploadRestore()"><i class="bi bi-laptop"></i> استعادة من الجهاز</button><button type="button" class="nawa-button primary" onclick="loadSection(\'nawa-backup-create.php?fragment=1\')"><i class="bi bi-plus-lg"></i> إنشاء أول نسخة</button></div></div>';
    } else {
        echo '<div class="nawa-table-wrap"><table class="nawa-table"><thead><tr><th>#</th><th>اسم النسخة</th><th>تاريخ الإنشاء</th><th>الوقت</th><th>الحجم</th><th>الإجراءات</th></tr></thead><tbody>';
        foreach ($backupInfo as $index => $row) {
            $file = htmlspecialchars($row['filename'], ENT_QUOTES, 'UTF-8');
            $date = htmlspecialchars($row['date'], ENT_QUOTES, 'UTF-8');
            $time = htmlspecialchars($row['time'], ENT_QUOTES, 'UTF-8');
            $size = htmlspecialchars($row['size'], ENT_QUOTES, 'UTF-8');
            printf('<tr><td>%d</td><td class="nawa-backup-filename">%s</td><td>%s</td><td>%s</td><td>%s</td><td class="nawa-backup-actions">', $index + 1, $file, $date, $time, $size);
            printf('<form method="post" action="nawa-backup-manage.php?fragment=1"><input type="hidden" name="csrf_token" value="%s"><input type="hidden" name="file" value="%s"><input type="hidden" name="action" value="">', htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'), $file);
            echo '<button type="button" class="nawa-button small" onclick="nawaBackupAction(this,\'download\')"><i class="bi bi-download"></i> تحميل</button>';
            echo '<button type="button" class="nawa-button small" onclick="nawaBackupAction(this,\'rollback\')"><i class="bi bi-arrow-repeat"></i> استعادة</button>';
            echo '<button type="button" class="nawa-button danger small" onclick="nawaBackupAction(this,\'delete\')"><i class="bi bi-trash"></i> حذف</button>';
            echo '</form></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
    printf('<div class="backup-upload-modal" id="backup-upload-modal" hidden><div class="backup-upload-dialog" role="dialog" aria-modal="true" aria-labelledby="backup-upload-title"><header class="backup-upload-head"><div><h3 id="backup-upload-title">استعادة نسخة من الجهاز</h3><p>ارفع ملف SQL أو SQL.GZ من اللابتوب ثم استعد بياناته.</p></div><button type="button" class="backup-upload-close" onclick="nawaCloseUploadRestore()" aria-label="إغلاق"><i class="bi bi-x-lg"></i></button></header><div class="backup-upload-body"><div class="backup-upload-warning"><i class="bi bi-exclamation-triangle"></i><div><strong>تنبيه مهم:</strong> الاستعادة تستبدل بيانات الجداول الموجودة بمحتوى النسخة. لا تغلق الصفحة أثناء الرفع أو الاستعادة.</div></div><label class="backup-upload-drop" id="backup-upload-drop"><i class="bi bi-file-earmark-arrow-up"></i><strong>اختر ملف النسخة الاحتياطية</strong><span>ملفات .sql أو .sql.gz حتى 20 GB — يمكنك أيضاً سحب الملف إلى هنا</span><input type="file" id="backup-upload-input" accept=".sql,.gz,application/sql,application/gzip,application/x-gzip,text/plain"></label><div class="backup-upload-file" id="backup-upload-file" hidden><i class="bi bi-file-earmark-code"></i><div><strong id="backup-upload-name"></strong><span id="backup-upload-size"></span></div></div><div class="backup-upload-progress" id="backup-upload-progress" hidden><div class="backup-upload-progress-copy"><span id="backup-upload-status">جاري رفع الملف...</span><strong id="backup-upload-percent">0%%</strong></div><div class="backup-upload-track"><div class="backup-upload-bar" id="backup-upload-bar"></div></div></div><label class="backup-upload-confirm"><input type="checkbox" id="backup-upload-confirm"><span>أفهم أن الاستعادة ستستبدل بيانات الجداول الموجودة، وأريد متابعة الرفع والاستعادة.</span></label><input type="hidden" id="backup-upload-csrf" value="%s"></div><footer class="backup-upload-foot"><button type="button" class="nawa-button" onclick="nawaCloseUploadRestore()">إلغاء</button><button type="button" class="nawa-button primary" id="backup-upload-start" disabled><i class="bi bi-arrow-repeat"></i><span>رفع واستعادة النسخة</span></button></footer></div></div>', htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'));
    echo '</div>';

    include('include/config/logging.php');

    $inline_extra_js = <<<'EOF'
window.nawaBackupToast = function(message, isError) {
    var toast = document.querySelector('.toast');
    if (!toast) { alert(message); return; }
    var icon = toast.querySelector('i');
    var text = toast.querySelector('span');
    if (icon) icon.className = isError ? 'bi bi-exclamation-circle' : 'bi bi-check2-circle';
    if (text) text.textContent = message;
    toast.classList.add('show');
    window.setTimeout(function(){ toast.classList.remove('show'); }, 3200);
};

window.nawaBackupAction = async function(button, action) {
    var form = button.form;
    var actionInput = form.querySelector('input[name="action"]');
    actionInput.value = action;

    if (action === 'download') {
        form.target = '_blank';
        form.submit();
        window.setTimeout(function(){ form.removeAttribute('target'); }, 250);
        return;
    }

    var filename = form.querySelector('input[name="file"]').value;
    var prompt = action === 'delete'
        ? 'هل تريد حذف النسخة ' + filename + ' نهائياً؟'
        : 'سيتم استبدال بيانات الجداول بمحتوى النسخة ' + filename + '. هل تريد المتابعة؟';
    if (!window.confirm(prompt)) return;

    var buttons = form.querySelectorAll('button');
    buttons.forEach(function(item){ item.disabled = true; });
    try {
        var response = await fetch('nawa-backup-manage.php?fragment=1&ajax=1', {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            cache: 'no-store'
        });
        var responseText = await response.text();
        var result;
        try { result = JSON.parse(responseText); }
        catch (parseError) { throw new Error('لم يكتمل رد الخادم. حاول مرة أخرى.'); }
        if (!response.ok || !result.ok) throw new Error(result.message || 'تعذر تنفيذ العملية');
        await loadSection('nawa-backup-manage.php?fragment=1');
        window.nawaBackupToast(result.message, false);
    } catch (error) {
        window.nawaBackupToast(error.message || 'تعذر تنفيذ العملية', true);
        buttons.forEach(function(item){ item.disabled = false; });
    }
};

window.nawaBackupUploadState = {
    file: null,
    uploadId: null,
    running: false
};

window.nawaBackupFormatBytes = function(bytes) {
    if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    return (bytes / Math.pow(1024, index)).toFixed(index === 0 ? 0 : 2) + ' ' + units[index];
};

window.nawaBackupUploadId = function() {
    var bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    return Array.from(bytes).map(function(value){ return value.toString(16).padStart(2, '0'); }).join('');
};

window.nawaBackupUploadRequest = async function(formData) {
    var response = await fetch('nawa-backup-upload.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        cache: 'no-store'
    });
    var text = await response.text();
    var result;
    try { result = JSON.parse(text); }
    catch (error) { throw new Error('لم يكتمل رد الخادم أثناء رفع الملف.'); }
    if (!response.ok || !result.ok) throw new Error(result.message || 'تعذر رفع ملف النسخة.');
    return result;
};

window.nawaBackupSyncUploadButton = function() {
    var state = window.nawaBackupUploadState;
    var confirmation = document.getElementById('backup-upload-confirm');
    var start = document.getElementById('backup-upload-start');
    if (start) start.disabled = state.running || !state.file || !confirmation || !confirmation.checked;
};

window.nawaBackupChooseUploadFile = function(file) {
    var state = window.nawaBackupUploadState;
    if (!file) return;
    if (!/\.sql(?:\.gz)?$/i.test(file.name)) {
        window.nawaBackupToast('اختر ملفاً بصيغة SQL أو SQL.GZ.', true);
        return;
    }
    if (file.size <= 0 || file.size > (20 * 1024 * 1024 * 1024)) {
        window.nawaBackupToast('حجم الملف غير صالح أو يتجاوز 20 GB.', true);
        return;
    }
    state.file = file;
    state.uploadId = null;
    var fileBox = document.getElementById('backup-upload-file');
    var name = document.getElementById('backup-upload-name');
    var size = document.getElementById('backup-upload-size');
    if (name) name.textContent = file.name;
    if (size) size.textContent = window.nawaBackupFormatBytes(file.size);
    if (fileBox) fileBox.hidden = false;
    window.nawaBackupSyncUploadButton();
};

window.nawaOpenUploadRestore = function() {
    var modal = document.getElementById('backup-upload-modal');
    if (!modal) return;
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    window.setTimeout(function(){ document.getElementById('backup-upload-input')?.focus(); }, 50);
};

window.nawaCloseUploadRestore = function() {
    var state = window.nawaBackupUploadState;
    if (state.running) {
        window.nawaBackupToast('انتظر حتى يكتمل الرفع والاستعادة.', true);
        return;
    }
    var modal = document.getElementById('backup-upload-modal');
    if (modal) modal.hidden = true;
    document.body.style.overflow = '';
};

window.nawaStartUploadRestore = async function() {
    var state = window.nawaBackupUploadState;
    var csrf = document.getElementById('backup-upload-csrf')?.value || '';
    var start = document.getElementById('backup-upload-start');
    var progress = document.getElementById('backup-upload-progress');
    var bar = document.getElementById('backup-upload-bar');
    var percent = document.getElementById('backup-upload-percent');
    var status = document.getElementById('backup-upload-status');
    if (!state.file || state.running) return;
    if (!window.confirm('سيتم رفع النسخة ثم استبدال بيانات الجداول الموجودة. هل تريد المتابعة؟')) return;

    state.running = true;
    state.uploadId = window.nawaBackupUploadId();
    window.nawaBackupSyncUploadButton();
    if (progress) progress.hidden = false;
    if (start) start.innerHTML = '<i class="bi bi-cloud-arrow-up"></i><span>جاري رفع النسخة...</span>';

    var chunkSize = 4 * 1024 * 1024;
    var offset = 0;
    var storedFilename = null;
    try {
        while (offset < state.file.size) {
            var chunk = state.file.slice(offset, Math.min(offset + chunkSize, state.file.size));
            var chunkForm = new FormData();
            chunkForm.append('action', 'chunk');
            chunkForm.append('csrf_token', csrf);
            chunkForm.append('upload_id', state.uploadId);
            chunkForm.append('original_name', state.file.name);
            chunkForm.append('total_size', String(state.file.size));
            chunkForm.append('offset', String(offset));
            chunkForm.append('chunk', chunk, 'chunk.bin');
            var chunkResult = await window.nawaBackupUploadRequest(chunkForm);
            offset = Number(chunkResult.received);
            var value = Math.min(100, (offset / state.file.size) * 100);
            if (bar) bar.style.width = value.toFixed(2) + '%';
            if (percent) percent.textContent = value.toFixed(1) + '%';
            if (status) status.textContent = 'جاري الرفع — ' + window.nawaBackupFormatBytes(offset) + ' من ' + window.nawaBackupFormatBytes(state.file.size);
        }

        var finishForm = new FormData();
        finishForm.append('action', 'finish');
        finishForm.append('csrf_token', csrf);
        finishForm.append('upload_id', state.uploadId);
        finishForm.append('original_name', state.file.name);
        finishForm.append('total_size', String(state.file.size));
        var finishResult = await window.nawaBackupUploadRequest(finishForm);
        storedFilename = finishResult.filename;

        if (progress) progress.classList.add('is-indeterminate');
        if (status) status.textContent = 'اكتمل الرفع — جاري استعادة قاعدة البيانات...';
        if (percent) percent.textContent = 'استعادة';
        if (start) start.innerHTML = '<i class="bi bi-arrow-repeat"></i><span>جاري استعادة البيانات...</span>';

        var restoreForm = new FormData();
        restoreForm.append('csrf_token', csrf);
        restoreForm.append('file', storedFilename);
        restoreForm.append('action', 'rollback');
        var restoreResponse = await fetch('nawa-backup-manage.php?fragment=1&ajax=1', {
            method: 'POST',
            body: restoreForm,
            credentials: 'same-origin',
            cache: 'no-store'
        });
        var restoreText = await restoreResponse.text();
        var restoreResult;
        try { restoreResult = JSON.parse(restoreText); }
        catch (error) { throw new Error('رُفع الملف، لكن لم يكتمل رد الخادم أثناء الاستعادة. ستجده في قائمة النسخ.'); }
        if (!restoreResponse.ok || !restoreResult.ok) {
            throw new Error(restoreResult.message || 'رُفع الملف، لكن تعذرت استعادته. ستجده في قائمة النسخ.');
        }

        state.running = false;
        document.body.style.overflow = '';
        await loadSection('nawa-backup-manage.php?fragment=1');
        window.nawaBackupToast('تم رفع النسخة واستعادة البيانات بنجاح.', false);
    } catch (error) {
        // A finalized upload remains in the backup list so it can be retried.
        if (!storedFilename && state.uploadId) {
            var cancelForm = new FormData();
            cancelForm.append('action', 'cancel');
            cancelForm.append('csrf_token', csrf);
            cancelForm.append('upload_id', state.uploadId);
            cancelForm.append('original_name', state.file.name);
            cancelForm.append('total_size', String(state.file.size));
            try { await window.nawaBackupUploadRequest(cancelForm); } catch (ignore) {}
        }
        state.running = false;
        if (progress) progress.classList.remove('is-indeterminate');
        if (start) start.innerHTML = '<i class="bi bi-arrow-repeat"></i><span>إعادة المحاولة</span>';
        window.nawaBackupSyncUploadButton();
        window.nawaBackupToast(error.message || 'تعذر رفع واستعادة النسخة.', true);
    }
};

(function () {
    var input = document.getElementById('backup-upload-input');
    var drop = document.getElementById('backup-upload-drop');
    var confirmation = document.getElementById('backup-upload-confirm');
    var start = document.getElementById('backup-upload-start');
    if (input) input.addEventListener('change', function(){ window.nawaBackupChooseUploadFile(input.files[0]); });
    if (confirmation) confirmation.addEventListener('change', window.nawaBackupSyncUploadButton);
    if (start) start.addEventListener('click', window.nawaStartUploadRestore);
    if (drop) {
        ['dragenter','dragover'].forEach(function(eventName){ drop.addEventListener(eventName, function(event){ event.preventDefault(); drop.classList.add('is-dragging'); }); });
        ['dragleave','drop'].forEach(function(eventName){ drop.addEventListener(eventName, function(event){ event.preventDefault(); drop.classList.remove('is-dragging'); }); });
        drop.addEventListener('drop', function(event){ window.nawaBackupChooseUploadFile(event.dataTransfer.files[0]); });
    }
})();
EOF;

    echo '<script>' . $inline_extra_js . '</script>';
    if (!$is_fragment) {
        print_footer_and_html_epilogue();
    }
?>
