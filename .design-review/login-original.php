<?php
/*
 *********************************************************************************************************
 * daloRADIUS - RADIUS Web Platform
 * Copyright (C) 2007 - Liran Tal <liran@lirantal.com> All Rights Reserved.
 *
 * 3D Radius operator login skin. Authentication, sessions and CSRF validation remain handled by daloRADIUS.
 *********************************************************************************************************
 */

include_once("library/sessions.php");
dalo_session_start();

if (array_key_exists('daloradius_logged_in', $_SESSION)
    && $_SESSION['daloradius_logged_in'] !== false) {
    header('Location: index.php');
    exit;
}

// Exports $langCode, $configValues and the t() translation helper.
include("lang/main.php");

$onlyDefaultLocation = !(array_key_exists('CONFIG_LOCATIONS', $configValues)
                        && is_array($configValues['CONFIG_LOCATIONS'])
                        && count($configValues['CONFIG_LOCATIONS']) > 0);

$dir = (strtolower($langCode) === 'ar') ? "rtl" : "ltr";
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($langCode, ENT_QUOTES, 'UTF-8') ?>" dir="<?= $dir ?>">
<head>
    <title>3D Radius :: <?= htmlspecialchars(t('text', 'LoginRequired'), ENT_QUOTES, 'UTF-8') ?></title>
    <meta charset="utf-8">
    <meta http-equiv="content-type" content="text/html; charset=utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">

    <link rel="apple-touch-icon" href="static/images/3d-radius-logo.png">
    <link rel="icon" type="image/png" href="static/images/3d-radius-logo.png">
    <link rel="manifest" href="static/images/favicon/site.webmanifest">

    <link rel="stylesheet" href="static/css/bootstrap.min.css">
    <link rel="stylesheet" href="static/css/icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="static/css/nawa-theme.css">
    <link rel="stylesheet" href="theme.php">
</head>

<body class="nawa-login-page">
    <div class="nawa-login-shell">
        <section class="nawa-login-panel">
            <main class="nawa-login-card">
                <a class="nawa-brand text-decoration-none" href="login.php" aria-label="3D Radius">
                    <span class="nawa-brand-mark"><img src="static/images/3d-radius-logo.png" alt=""></span>
                    <span class="nawa-brand-copy"><strong>3D Radius</strong><small>إدارة الشبكات بوضوح</small></span>
                </a>

                <h1><?= t('text', 'LoginRequired') ?></h1>
                <p>أدخل بيانات مشغّل النظام للوصول إلى لوحة إدارة الشبكة.</p>

                <form action="dologin.php" method="POST" autocomplete="on">
                    <div class="mb-3">
                        <label class="form-label" for="operator_user"><?= t('all', 'Username') ?></label>
                        <input type="text" class="form-control" id="operator_user" name="operator_user"
                               autocomplete="username" required autofocus>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="operator_pass"><?= t('all', 'Password') ?></label>
                        <input type="password" class="form-control" id="operator_pass" name="operator_pass"
                               autocomplete="current-password" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="location">Location</label>
                        <select class="form-select" id="location" name="location" <?= $onlyDefaultLocation ? "disabled" : "" ?>>
<?php
                            $locationOptionFormat = '<option value="%s">%s</option>' . "\n";
                            if ($onlyDefaultLocation) {
                                printf($locationOptionFormat, "default", "default");
                            } else {
                                foreach (array_keys($configValues['CONFIG_LOCATIONS']) as $location) {
                                    $safeLocation = htmlspecialchars($location, ENT_QUOTES, 'UTF-8');
                                    printf($locationOptionFormat, $safeLocation, $safeLocation);
                                }
                            }
?>
                        </select>
                    </div>

                    <input name="csrf_token" type="hidden" value="<?= dalo_csrf_token() ?>">
                    <button class="btn btn-primary w-100" type="submit">
                        <span><?= t('text', 'LoginPlease') ?></span>
                        <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    </button>
                </form>

                <small class="d-block mt-4 text-center text-muted">daloRADIUS 2.3 · 3D Radius</small>
            </main>
        </section>

        <aside class="nawa-login-visual" aria-label="منصة 3D Radius">
            <div class="nawa-login-visual-top"><img class="nawa-login-logo" src="static/images/3d-radius-logo.png" alt="شعار 3D Radius"><span class="nawa-login-status"><i class="bi bi-shield-check"></i> اتصال آمن بلوحة المشغّلين</span></div>
            <div>
                <h2>شبكتك كلها،<br>في مكان واحد.</h2>
                <p>إدارة المستخدمين ونقاط الاتصال والجلسات والتقارير من واجهة واحدة مبنية فوق daloRADIUS وFreeRADIUS.</p>
            </div>
            <small>© <?= date('Y') ?> 3D Radius لإدارة الشبكات</small>
        </aside>
    </div>

<?php
    if (isset($_SESSION['operator_login_error']) && $_SESSION['operator_login_error'] !== false) {
        $message = t('messages', 'loginerror');
        echo <<<EOF
    <div class="toast-container position-fixed bottom-0 end-0 p-3">
        <div id="error-toast" class="toast align-items-start text-bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body">{$message}</div>
                <button type="button" class="btn-close btn-close-white m-2" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    </div>
EOF;
        unset($_SESSION['operator_login_error']);
    }
?>

    <script src="static/js/bootstrap.bundle.min.js"></script>
    <script>
        var errorToast = document.getElementById('error-toast');
        if (errorToast) {
            bootstrap.Toast.getOrCreateInstance(errorToast).show();
        }
    </script>
</body>
</html>
