<?php

declare(strict_types=1);

@ini_set('pcre.jit', '0');
date_default_timezone_set('Asia/Aden');
include 'library/checklogin.php';
include_once '../common/includes/config_read.php';
include '../common/includes/db_open.php';

$operatorId = (int) ($_SESSION['operator_id'] ?? 0);
$operator = mb_strtolower(trim((string) ($_SESSION['operator_user'] ?? '')));
$canManage = $operatorId > 0 && in_array($operator, ['admin', 'administrator', 'mohammed'], true);
if (!$canManage && $operatorId > 0) {
    $canManage = (int) $dbSocket->getOne(
        "SELECT COUNT(*) FROM operators_acl WHERE operator_id={$operatorId} AND access=1 AND file='config_operators_list'"
    ) > 0;
}
if (empty($_SESSION['communication_csrf'])) {
    $_SESSION['communication_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string) $_SESSION['communication_csrf'];
if (!function_exists('comm_e')) {
    function comm_e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
$fragmentMode = isset($_GET['fragment']) && $_GET['fragment'] === '1';
include '../common/includes/db_close.php';
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
?>
<?php if (!$fragmentMode): ?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>الاتصالات | 3D Radius</title><link rel="stylesheet" href="static/css/bootstrap.min.css"><link rel="stylesheet" href="static/css/icons/bootstrap-icons.min.css"><link rel="stylesheet" href="static/css/nawa-production.css?v=20260817"></head><body style="background:#f5f6f8"><main style="max-width:1600px;margin:auto;padding:24px">
<?php endif; ?>
<style>
.comm-app{--red:#e5221a;--red-dark:#b91d17;--ink:#17191f;--muted:#7d8494;--line:#e5e7ec;--surface:#fff;display:grid;gap:16px;color:var(--ink);direction:rtl}.comm-app *{box-sizing:border-box}.comm-panel{border:1px solid var(--line);border-radius:18px;background:var(--surface);box-shadow:0 10px 32px rgba(21,24,33,.045)}.comm-hero{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;padding:4px 0}.comm-eyebrow{display:flex;align-items:center;gap:7px;margin:0 0 7px;color:var(--red);font-size:10px;font-weight:900}.comm-eyebrow:before{content:'';width:7px;height:7px;border-radius:50%;background:var(--red);box-shadow:0 0 0 4px #fde7e5}.comm-hero h1{margin:0;font-size:27px;font-weight:900}.comm-hero p{margin:7px 0 0;color:var(--muted);font-size:11px}.comm-actions,.comm-tools,.comm-filters{display:flex;align-items:center;gap:7px;flex-wrap:wrap}.comm-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:38px;padding:8px 12px;border:1px solid #dfe2e8;border-radius:10px;background:#fff;color:#3f4553;font:inherit;font-size:10px;font-weight:900;cursor:pointer}.comm-btn:hover{border-color:#f0aaa6;color:var(--red)}.comm-btn.primary{border-color:var(--red);background:var(--red);color:#fff}.comm-btn.danger{border-color:#f3c3c0;color:#b42318;background:#fff5f4}.comm-btn:disabled{opacity:.55;cursor:not-allowed}.comm-live{display:inline-flex;align-items:center;gap:6px;padding:6px 9px;border-radius:999px;color:#067647;background:#e7f8f1;font-size:9px;font-weight:900}.comm-live:before{content:'';width:6px;height:6px;border-radius:50%;background:#12b76a}.comm-readonly{display:flex;align-items:center;gap:10px;padding:11px 14px;border:1px solid #cce7df;border-radius:13px;color:#116c58;background:#f0fbf7;font-size:10px}.comm-readonly i{font-size:17px}.comm-search-wrap{position:relative}.comm-search{display:flex;align-items:center;gap:10px;padding:11px 14px}.comm-search i{color:#9399a8}.comm-search input{width:100%;border:0;outline:0;background:transparent;color:inherit;font:inherit;font-size:12px}.comm-search-results{position:absolute;z-index:70;top:calc(100% + 6px);right:0;left:0;display:none;max-height:360px;overflow:auto;border:1px solid var(--line);border-radius:14px;background:#fff;box-shadow:0 18px 45px rgba(19,22,32,.16)}.comm-search-results.show{display:block}.comm-search-item{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;padding:11px 13px;border:0;border-bottom:1px solid #f0f1f4;background:#fff;text-align:right;cursor:pointer}.comm-search-item:hover{background:#fff7f6}.comm-search-item b,.comm-search-item small{display:block}.comm-search-item small{margin-top:3px;color:var(--muted);font-size:9px}.comm-search-item em{color:var(--red);font-size:9px;font-style:normal;font-weight:900}.comm-tabs{display:flex;gap:5px;padding:6px}.comm-tab{padding:9px 13px;border:0;border-radius:9px;background:transparent;color:#737a8a;font-size:10px;font-weight:900;cursor:pointer}.comm-tab.active{color:#fff;background:var(--red)}.comm-view[hidden]{display:none!important}.comm-network-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px}.comm-network-card{position:relative;overflow:hidden;padding:17px;border:1px solid var(--line);border-radius:16px;background:#fff;text-align:right;cursor:pointer;transition:.18s}.comm-network-card:hover{transform:translateY(-2px);border-color:#efaaa6;box-shadow:0 13px 28px rgba(229,34,26,.08)}.comm-network-card:after{content:'';position:absolute;left:-25px;bottom:-30px;width:95px;height:95px;border-radius:50%;background:#fff0ef}.comm-network-head{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:10px}.comm-network-icon{display:grid;place-items:center;width:38px;height:38px;border-radius:11px;color:var(--red);background:#fff0ef;font-size:17px}.comm-health{display:inline-flex;align-items:center;gap:5px;padding:4px 7px;border-radius:999px;font-size:8px;font-weight:900}.comm-health.healthy{color:#067647;background:#e7f8f1}.comm-health.degraded{color:#95620b;background:#fff4d8}.comm-health.critical{color:#b42318;background:#ffebe9}.comm-health.unknown{color:#667085;background:#f0f1f4}.comm-health:before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor}.comm-network-card h3{position:relative;z-index:1;margin:13px 0 5px;font-size:16px}.comm-network-card>p{position:relative;z-index:1;margin:0;color:var(--muted);font-size:9px}.comm-network-stats{position:relative;z-index:1;display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:14px}.comm-network-stats span{padding:7px;border-radius:8px;background:#f8f9fa}.comm-network-stats small,.comm-network-stats b{display:block}.comm-network-stats small{color:#9298a7;font-size:8px}.comm-network-stats b{margin-top:2px;font-size:12px}.comm-empty{padding:50px;text-align:center;color:var(--muted)}.comm-loading{display:grid;place-items:center;min-height:180px;color:var(--muted);font-size:11px}.comm-spinner{width:25px;height:25px;margin-bottom:10px;border:3px solid #f5c6c3;border-top-color:var(--red);border-radius:50%;animation:comm-spin .75s linear infinite}@keyframes comm-spin{to{transform:rotate(360deg)}}.comm-topology-head{display:flex;align-items:flex-start;justify-content:space-between;gap:15px}.comm-topology-head h2{margin:0;font-size:21px}.comm-topology-head p{margin:5px 0 0;color:var(--muted);font-size:10px}.comm-summary{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px}.comm-summary article{padding:11px;border:1px solid var(--line);border-radius:12px;background:#fff}.comm-summary span,.comm-summary strong{display:block}.comm-summary span{color:#8b92a2;font-size:8px}.comm-summary strong{margin-top:4px;font-size:15px}.comm-summary article.alert strong{color:var(--red)}.comm-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border-bottom:1px solid var(--line)}.comm-tool-icon{display:grid;place-items:center;width:34px;height:34px;padding:0;border:1px solid #e0e3e9;border-radius:9px;background:#fff;color:#575e6d;cursor:pointer}.comm-tool-icon:hover{border-color:#f1a7a2;color:var(--red)}.comm-filter{padding:7px 10px;border:1px solid #e1e3e9;border-radius:999px;background:#fff;color:#737a89;font-size:9px;font-weight:900;cursor:pointer}.comm-filter.active{border-color:#f1aaa6;color:#b91d17;background:#fff0ef}.comm-canvas-wrap{position:relative;height:650px;overflow:hidden}.comm-canvas{width:100%;height:100%;background:radial-gradient(circle at 1px 1px,#e8e9ee 1px,transparent 0);background-size:22px 22px}.comm-legend{position:absolute;z-index:5;right:12px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap;max-width:70%;padding:8px 10px;border:1px solid var(--line);border-radius:10px;background:rgba(255,255,255,.94);font-size:8px}.comm-legend span{display:flex;align-items:center;gap:4px}.comm-legend i{width:7px;height:7px;border-radius:50%}.comm-incident{display:flex;align-items:center;justify-content:space-between;gap:13px;margin-bottom:10px;padding:12px 14px;border:1px solid #f2c8c5;border-radius:12px;color:#89251f;background:#fff6f5}.comm-incident b,.comm-incident small{display:block}.comm-incident small{margin-top:3px;font-size:9px}.comm-drawer{position:fixed;z-index:1500;top:0;bottom:0;left:0;width:min(430px,100%);overflow:auto;border-right:1px solid var(--line);background:#fff;box-shadow:15px 0 45px rgba(16,19,28,.2);transform:translateX(-110%);transition:.22s}.comm-drawer.open{transform:none}.comm-drawer-head{position:sticky;z-index:2;top:0;display:flex;align-items:flex-start;justify-content:space-between;padding:18px;border-bottom:1px solid var(--line);background:#fff}.comm-drawer-head h3{margin:0;font-size:17px}.comm-drawer-head p{margin:5px 0 0;color:var(--muted);font-size:10px}.comm-drawer-body{display:grid;gap:12px;padding:15px}.comm-detail-section{padding:13px;border:1px solid var(--line);border-radius:13px}.comm-detail-section h4{margin:0 0 10px;font-size:11px}.comm-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.comm-detail-grid div{padding:8px;border-radius:8px;background:#f7f8fa}.comm-detail-grid span,.comm-detail-grid b{display:block}.comm-detail-grid span{color:#8b92a2;font-size:8px}.comm-detail-grid b{margin-top:3px;overflow:hidden;text-overflow:ellipsis;font-size:10px}.comm-path{display:flex;align-items:center;gap:5px;overflow:auto;padding-bottom:4px;direction:rtl}.comm-path span{flex:0 0 auto;padding:6px 8px;border-radius:8px;color:#8f2b26;background:#fff0ef;font-size:9px;font-weight:800}.comm-downstream{display:grid;gap:5px;max-height:180px;overflow:auto}.comm-downstream button{display:flex;justify-content:space-between;padding:7px 8px;border:0;border-radius:7px;background:#f7f8fa;font-size:9px;cursor:pointer}.comm-form{display:grid;gap:9px}.comm-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.comm-field{display:grid;gap:4px}.comm-field.full{grid-column:1/-1}.comm-field span{font-size:9px;font-weight:900}.comm-field input,.comm-field select,.comm-field textarea{width:100%;min-height:39px;padding:8px 10px;border:1px solid #dde0e6;border-radius:9px;background:#fff;color:inherit;font:inherit;font-size:10px}.comm-table-wrap{overflow:auto}.comm-table{width:100%;border-collapse:collapse}.comm-table th,.comm-table td{padding:11px 12px;border-bottom:1px solid #eff0f3;text-align:right;white-space:nowrap;font-size:9px}.comm-table th{color:#858c9c;background:#fafbfc;font-size:8px}.comm-table code{direction:ltr}.comm-settings-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.3fr);gap:14px}.comm-settings-head{display:flex;align-items:center;justify-content:space-between;padding:14px 15px;border-bottom:1px solid var(--line)}.comm-settings-head h2{margin:0;font-size:14px}.comm-settings-form{padding:14px}.comm-badge{display:inline-flex;padding:4px 7px;border-radius:999px;background:#f0f1f4;color:#606777;font-size:8px;font-weight:900}.comm-badge.on{color:#067647;background:#e7f8f1}.comm-badge.manual{color:#89251f;background:#fff0ef}.comm-toast{position:fixed;z-index:1700;right:25px;bottom:25px;max-width:370px;padding:12px 15px;border-radius:11px;background:#181a20;color:#fff;box-shadow:0 15px 35px rgba(0,0,0,.25);font-size:10px;font-weight:800;transform:translateY(25px);opacity:0;pointer-events:none;transition:.2s}.comm-toast.show{transform:none;opacity:1}.comm-toast.error{background:#b42318}html[data-theme="dark"] .comm-app,body.dark .comm-app{--ink:#f0f2f7;--muted:#9da4b4;--line:#353846;--surface:#20232e}.dashboard-page.dark .comm-panel,.dashboard-page.dark .comm-network-card,.dashboard-page.dark .comm-summary article,.dashboard-page.dark .comm-btn,.dashboard-page.dark .comm-search-results,.dashboard-page.dark .comm-search-item,.dashboard-page.dark .comm-tool-icon,.dashboard-page.dark .comm-filter,.dashboard-page.dark .comm-drawer,.dashboard-page.dark .comm-drawer-head,.dashboard-page.dark .comm-field input,.dashboard-page.dark .comm-field select,.dashboard-page.dark .comm-field textarea{background:#20232e;color:#f0f2f7;border-color:#353846}.dashboard-page.dark .comm-canvas{background-color:#181b23;background-image:radial-gradient(circle at 1px 1px,#343744 1px,transparent 0)}.dashboard-page.dark .comm-detail-grid div,.dashboard-page.dark .comm-downstream button{background:#292c38;color:#eef0f5}@media(max-width:1100px){.comm-network-grid{grid-template-columns:repeat(2,1fr)}.comm-summary{grid-template-columns:repeat(4,1fr)}.comm-settings-grid{grid-template-columns:1fr}}@media(max-width:700px){.comm-hero,.comm-topology-head,.comm-toolbar{align-items:stretch;flex-direction:column}.comm-network-grid{grid-template-columns:1fr}.comm-summary{grid-template-columns:repeat(2,1fr)}.comm-canvas-wrap{height:540px}.comm-form-grid{grid-template-columns:1fr}.comm-field.full{grid-column:auto}.comm-legend{max-width:94%}}

.comm-network-tree{
    position:relative;
    display:flex;
    flex-direction:column;
    align-items:center;
    width:max-content;
    min-width:100%;
    padding:15px 35px 100px;
}

.comm-gateway-wrap{
    position:relative;
    display:flex;
    justify-content:center;
    padding-bottom:54px;
}

.comm-gateway-wrap:after{
    content:'';
    position:absolute;
    bottom:0;
    left:50%;
    width:2px;
    height:54px;
    background:#12b76a;
    transform:translateX(-50%);
}

.comm-gateway-card{
    display:flex;
    align-items:center;
    gap:11px;
    min-width:230px;
    padding:13px 17px;
    border:3px solid #12b76a;
    border-radius:15px;
    background:#fff;
    color:#17202a;
    text-align:right;
    box-shadow:0 7px 20px rgba(20,35,50,.12);
    cursor:pointer;
}

.comm-gateway-card.offline{
    border-color:#e5221a;
}

.comm-gateway-icon{
    display:grid;
    place-items:center;
    width:45px;
    height:45px;
    border-radius:12px;
    background:#eaf9f2;
    color:#078b55;
    font-size:22px;
}

.comm-gateway-card.offline .comm-gateway-icon{
    background:#fff0ef;
    color:#e5221a;
}

.comm-gateway-card b{
    display:block;
    font-size:14px;
    font-weight:900;
}

.comm-gateway-card code{
    display:block;
    margin-top:3px;
    color:#536174;
    font-size:11px;
    direction:ltr;
}

.comm-gateway-card small{
    display:flex;
    align-items:center;
    gap:5px;
    margin-top:4px;
    color:#667085;
    font-size:9px;
}

.comm-status-dot{
    width:7px;
    height:7px;
    border-radius:50%;
    background:#12b76a;
}

.comm-gateway-card.offline .comm-status-dot{
    background:#e5221a;
}

.comm-port-tree{
    position:relative;
    display:flex !important;
    align-items:flex-start;
    justify-content:center;
    gap:34px;
    padding:45px 0 0 !important;
    margin:0 !important;
    list-style:none;
}

.comm-port-tree:before{
    content:'';
    position:absolute;
    top:0;
    left:8%;
    right:8%;
    height:2px;
    background:#12b76a;
}

.comm-port-branch{
    position:relative;
    display:flex;
    flex-direction:column;
    align-items:center;
    padding:0 !important;
}

.comm-port-branch:before{
    content:'';
    position:absolute;
    top:-45px;
    left:50%;
    width:2px;
    height:45px;
    background:#12b76a;
}

.comm-port-branch:after{
    display:none !important;
}

.comm-port-node{
    position:relative;
    z-index:2;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    min-width:105px;
    padding:8px 10px;
    border:1px solid #cad3df;
    border-radius:9px;
    background:#fff;
    color:#344054;
    box-shadow:0 3px 9px rgba(20,35,50,.07);
}

.comm-port-node i{
    color:#078b55;
}

.comm-port-node b{
    font-size:10px;
    direction:ltr;
}

.comm-port-node small{
    color:#98a2b3;
    font-size:7px;
}

.comm-port-roots{
    display:flex !important;
    justify-content:center;
    padding-top:40px !important;
}

.dashboard-page.dark .comm-gateway-card,
.dashboard-page.dark .comm-port-node{
    background:#20232e;
    color:#eef0f5;
    border-color:#3a3e4b;
}


.comm-tree-viewport{
    position:relative;
    width:100%;
    height:100%;
    overflow:hidden;
}

.comm-tree-stage{
    position:absolute;
    top:0;
    left:50%;
    width:max-content;
    min-width:100%;
    transform-origin:top center;
    will-change:transform;
}

.comm-canvas-wrap,
.comm-canvas{
    overscroll-behavior:contain;
}

.comm-canvas{
    touch-action:none;
}


/* Tree viewport */
.comm-tree-viewport{
    position:relative;
    width:100%;
    height:100%;
    overflow:hidden;
    touch-action:none;
    user-select:none;
}

.comm-tree-stage{
    position:absolute;
    top:0;
    left:50%;
    width:max-content;
    min-width:100%;
    transform-origin:top center;
    will-change:transform;
}

.comm-canvas.fallback-mode{
    cursor:grab;
}

.comm-canvas.fallback-mode.dragging{
    cursor:grabbing;
}

/* Mobile tree controls */
@media(max-width:768px){
    .comm-canvas-wrap{
        height:70vh !important;
        min-height:520px;
        overflow:hidden !important;
    }

    .comm-org-tree.physical-tree{
        padding:12px 12px 80px !important;
    }

    .comm-physical-head{
        position:relative !important;
        top:auto !important;
        min-width:0 !important;
        width:calc(100vw - 34px) !important;
        max-width:420px;
        flex-direction:column;
        align-items:flex-start;
        gap:4px;
        margin-bottom:18px !important;
        text-align:right;
    }

    .comm-gateway-card{
        min-width:190px !important;
        padding:10px 12px !important;
    }

    .physical-tree .comm-org-card{
        width:170px !important;
        min-width:170px !important;
        max-width:170px !important;
        min-height:68px !important;
    }

    .comm-port-tree{
        gap:22px !important;
    }

    .comm-port-node{
        min-width:90px !important;
    }

    /* hide desktop-only density from cards */
    .comm-node-badge.children{
        font-size:6px !important;
    }
}


/* =========================================================
   PHYSICAL TREE CONNECTOR FIX
   Each MikroTik port owns its own subtree.
   Generic device-tree connectors must never connect ports
   to each other or create lines from outside the viewport.
   ========================================================= */

/* MikroTik ports: custom connector layer only */
.physical-tree .comm-port-tree{
    position:relative;
    display:flex !important;
    align-items:flex-start;
    justify-content:center;
    gap:42px;
    margin:0 !important;
    padding:45px 18px 0 !important;
    list-style:none;
}

/* One horizontal backbone connecting gateway to port groups */
.physical-tree .comm-port-tree:before{
    content:'';
    position:absolute;
    top:0;
    left:50%;
    width:calc(100% - 90px);
    height:2px;
    background:#12b76a;
    transform:translateX(-50%);
}

/*
 * IMPORTANT:
 * disable the generic physical-tree LI connectors on the
 * port-level LI elements. These were creating phantom lines.
 */
.physical-tree .comm-port-tree > .comm-port-branch{
    position:relative;
    display:flex;
    flex-direction:column;
    align-items:center;
    padding:0 !important;
}

.physical-tree .comm-port-tree > .comm-port-branch:before{
    content:'';
    display:block !important;
    position:absolute;
    top:-45px;
    left:50%;
    right:auto;
    width:2px;
    height:45px;
    border:0 !important;
    background:#12b76a;
    transform:translateX(-50%);
}

.physical-tree .comm-port-tree > .comm-port-branch:after{
    display:none !important;
    content:none !important;
}

/* Port card */
.physical-tree .comm-port-node{
    position:relative;
    z-index:4;
}

/*
 * Root list beneath ONE MikroTik port.
 * It gets its own local connector system.
 */
.physical-tree .comm-port-roots{
    position:relative;
    display:flex !important;
    justify-content:center;
    align-items:flex-start;
    margin:0 !important;
    padding:42px 0 0 !important;
    list-style:none;
}

/* Vertical line from port card to its root-group backbone */
.physical-tree .comm-port-roots:before{
    content:'';
    position:absolute;
    top:0;
    left:50%;
    right:auto;
    width:2px;
    height:42px;
    border:0 !important;
    background:#12b76a;
    transform:translateX(-50%);
}

/*
 * Immediate physical roots under a port.
 * Draw a horizontal connector ONLY between roots of THIS port.
 */
.physical-tree .comm-port-roots > li{
    position:relative;
    padding:38px 10px 0;
}

.physical-tree .comm-port-roots > li:before,
.physical-tree .comm-port-roots > li:after{
    content:'';
    position:absolute;
    top:0;
    width:50%;
    height:38px;
    border-top:2px solid #12b76a;
    background:transparent;
}

.physical-tree .comm-port-roots > li:before{
    right:50%;
    border-left:2px solid #12b76a;
}

.physical-tree .comm-port-roots > li:after{
    left:50%;
    border-right:2px solid #12b76a;
}

.physical-tree .comm-port-roots > li:only-child:before,
.physical-tree .comm-port-roots > li:only-child:after{
    border-top:0;
}

.physical-tree .comm-port-roots > li:first-child:before{
    border-top:0;
    border-left:0;
}

.physical-tree .comm-port-roots > li:last-child:after{
    border-top:0;
    border-right:0;
}

/*
 * Normal modem -> modem tree begins here.
 * These connectors belong strictly to the parent UL they are in.
 */
.physical-tree .comm-port-roots li > ul{
    position:relative;
    display:flex;
    justify-content:center;
    margin:0;
    padding:38px 0 0;
    list-style:none;
}

.physical-tree .comm-port-roots li > ul:before{
    content:'';
    position:absolute;
    top:0;
    left:50%;
    right:auto;
    width:2px;
    height:38px;
    border:0 !important;
    background:#12b76a;
    transform:translateX(-50%);
}

.physical-tree .comm-port-roots li > ul > li{
    position:relative;
    padding:38px 10px 0;
}

.physical-tree .comm-port-roots li > ul > li:before,
.physical-tree .comm-port-roots li > ul > li:after{
    content:'';
    position:absolute;
    top:0;
    width:50%;
    height:38px;
    border-top:2px solid #12b76a;
    background:transparent;
}

.physical-tree .comm-port-roots li > ul > li:before{
    right:50%;
    border-left:2px solid #12b76a;
}

.physical-tree .comm-port-roots li > ul > li:after{
    left:50%;
    border-right:2px solid #12b76a;
}

.physical-tree .comm-port-roots li > ul > li:only-child:before,
.physical-tree .comm-port-roots li > ul > li:only-child:after{
    border-top:0;
}

.physical-tree .comm-port-roots li > ul > li:first-child:before{
    border-top:0;
    border-left:0;
}

.physical-tree .comm-port-roots li > ul > li:last-child:after{
    border-top:0;
    border-right:0;
}

/* Offline branch = red. No yellow anywhere. */
.physical-tree li.status-offline > .comm-node-row + ul:before{
    background:#e5221a;
}

.physical-tree li.status-offline > ul > li:before,
.physical-tree li.status-offline > ul > li:after{
    border-color:#e5221a;
}

/* Never let old generic rules paint lines on port containers */
.physical-tree .comm-port-tree > li:first-child:before,
.physical-tree .comm-port-tree > li:last-child:after,
.physical-tree .comm-port-tree > li:only-child:before,
.physical-tree .comm-port-tree > li:only-child:after{
    border:0 !important;
}

/*
 * COMPACT PHYSICAL TREE LAYOUT
 *
 * The previous flex-only hierarchy could grow to tens of thousands
 * of pixels because every generation was forced onto one horizontal row.
 *
 * Device-to-device cables are already drawn by the SVG connector layer,
 * so immediate children can safely wrap into several rows.
 */
.physical-tree .comm-port-roots{
    display:flex !important;
    flex-wrap:wrap !important;
    justify-content:center !important;
    align-items:flex-start !important;
    gap:28px 18px !important;
    width:max-content !important;
    max-width:900px !important;
    margin-left:auto !important;
    margin-right:auto !important;
}

/* SVG owns the port -> root edges. */
.physical-tree .comm-port-roots:before,
.physical-tree .comm-port-roots > li:before,
.physical-tree .comm-port-roots > li:after{
    display:none !important;
}

/*
 * Every parent may have several direct children.
 * Keep a maximum readable branch width and wrap excess children
 * onto the next visual row rather than growing the whole topology.
 */
.physical-tree .comm-port-roots li > ul{
    display:flex !important;
    flex-wrap:wrap !important;
    justify-content:center !important;
    align-items:flex-start !important;
    gap:34px 18px !important;

    width:max-content !important;
    max-width:820px !important;

    margin-left:auto !important;
    margin-right:auto !important;

    padding-top:44px !important;
}

/* All nested hierarchy connectors are SVG only. */
.physical-tree .comm-port-roots li > ul:before,
.physical-tree .comm-port-roots li > ul > li:before,
.physical-tree .comm-port-roots li > ul > li:after{
    display:none !important;
}

/* Stable footprint for normal devices. */
.physical-tree .comm-port-roots li{
    box-sizing:border-box;
    flex:0 0 auto;
}

.physical-tree .comm-node-row{
    min-width:178px;
}

/*
 * Towers are taller but should occupy approximately the same horizontal
 * branch width as normal devices.
 */
.physical-tree li:has(> .comm-node-row > .comm-physical-tower-card){
    flex-basis:160px;
}

/*
 * Ports stay in the familiar MikroTik horizontal row.
 * Only downstream trees wrap.
 */
.physical-tree .comm-port-tree{
    flex-wrap:nowrap !important;
    align-items:flex-start !important;
}

/* Cleaner viewport */
.comm-tree-viewport{
    overflow:auto !important;
}

.comm-tree-stage{
    overflow:visible !important;
}

/* =========================================================
   REFERENCE-LAYOUT POLISH
   MikroTik -> horizontal port bus -> real downstream branches
   ========================================================= */

.comm-network-tree{
    position:relative;
    width:max-content;
    min-width:100%;
    padding:10px 42px 120px;
}

/* Main gateway centered above the port bus */
.comm-gateway-wrap{
    position:relative;
    display:flex;
    justify-content:center;
    margin:0 auto 34px !important;
}

.comm-gateway-card{
    position:relative;
    z-index:8;
    display:flex;
    align-items:center;
    gap:11px;
    min-width:235px !important;
    padding:13px 17px !important;
    border:2px solid #344054;
    border-radius:14px;
    background:#fff;
    box-shadow:0 7px 18px rgba(16,24,40,.12);
}

.comm-gateway-card.online{
    border-color:#12b76a;
}

.comm-gateway-card.offline{
    border-color:#e5221a;
}

.comm-gateway-card .comm-gateway-icon{
    display:grid;
    place-items:center;
    width:44px;
    height:44px;
    flex:0 0 44px;
    border-radius:11px;
    background:#eef4ff;
    color:#344054;
    font-size:22px;
}

.comm-gateway-card b{
    display:block;
    font-size:14px;
    font-weight:900;
}

.comm-gateway-card code{
    display:block;
    margin-top:3px;
    direction:ltr;
    text-align:right;
    font-size:11px;
}

.comm-gateway-card small{
    display:flex;
    align-items:center;
    gap:5px;
    margin-top:5px;
    font-size:9px;
}

/*
 * Port bus.
 * Keep each actual MikroTik port as a compact label sitting directly
 * beneath the horizontal backbone, like a wiring diagram.
 */
.physical-tree .comm-port-tree{
    gap:58px !important;
    padding-top:38px !important;
    padding-left:28px !important;
    padding-right:28px !important;
}

.physical-tree .comm-port-tree:before{
    top:0 !important;
    width:calc(100% - 70px) !important;
    height:2px !important;
    background:#344054 !important;
}

.physical-tree .comm-port-tree > .comm-port-branch:before{
    top:-38px !important;
    height:38px !important;
    background:#344054 !important;
}

/* Port is a label, not a large device card */
.physical-tree .comm-port-node{
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:2px;
    min-width:82px !important;
    max-width:118px;
    min-height:38px;
    padding:5px 9px;
    border:1px solid #d0d5dd;
    border-radius:9px;
    background:#fff;
    box-shadow:0 2px 6px rgba(16,24,40,.06);
}

.physical-tree .comm-port-node > i{
    display:none;
}

.physical-tree .comm-port-node b{
    font-family:Consolas,monospace;
    font-size:11px;
    font-weight:900;
    direction:ltr;
}

.physical-tree .comm-port-node small{
    color:#98a2b3;
    font-size:7px;
    line-height:1.2;
}

/* More readable spacing under each physical port */
.physical-tree .comm-port-roots{
    padding-top:34px !important;
}

.physical-tree .comm-port-roots:before{
    height:34px !important;
    background:#344054 !important;
}

/*
 * SVG is the authoritative connector layer for device-to-device edges.
 * Suppress the legacy generic modem connectors so cables are never drawn
 * twice on top of each other.
 */
.physical-tree .comm-port-roots li > ul:before,
.physical-tree .comm-port-roots li > ul > li:before,
.physical-tree .comm-port-roots li > ul > li:after{
    display:none !important;
}

/* Keep only the local root distribution under one MikroTik port */
.physical-tree .comm-port-roots > li:before,
.physical-tree .comm-port-roots > li:after{
    border-color:#344054;
}

/* Cards closer to wiring-diagram proportions */
.physical-tree .comm-org-card{
    width:178px !important;
    min-width:178px !important;
    max-width:178px !important;
    min-height:66px !important;
    padding:8px 10px !important;
    border-radius:11px !important;
}

.physical-tree .comm-org-info b{
    font-size:11px !important;
}

.physical-tree .comm-org-info code{
    font-size:9px !important;
}

/* Wireless tower remains visually distinct */
.comm-physical-tower-card{
    min-width:150px;
}

.comm-tree-wire.physical{
    stroke:#344054;
    stroke-width:2.2;
}

.comm-tree-wire.wireless{
    stroke:#1570ef;
    stroke-width:2.7;
    stroke-dasharray:9 7;
}

/* Outage semantics remain visible on the actual connector */
.comm-tree-wire.physical.offline,
.comm-tree-wire.wireless.offline{
    stroke:#e5221a;
}

/* Dark mode */
.dashboard-page.dark .comm-gateway-card,
.dashboard-page.dark .comm-port-node{
    background:#20232e;
    color:#eef0f5;
    border-color:#475467;
}



/* ===== Centered device details modal ===== */
.comm-drawer{
    position:fixed !important;
    z-index:5000 !important;
    top:50% !important;
    left:50% !important;
    right:auto !important;
    bottom:auto !important;

    width:min(760px, calc(100vw - 32px)) !important;
    max-width:760px !important;
    max-height:calc(100vh - 40px) !important;

    overflow:auto !important;
    border:1px solid var(--line) !important;
    border-radius:18px !important;
    background:#fff !important;

    box-shadow:0 24px 80px rgba(0,0,0,.30) !important;

    transform:translate(-50%,-50%) scale(.96) !important;
    opacity:0 !important;
    visibility:hidden !important;
    pointer-events:none !important;

    transition:
        opacity .18s ease,
        transform .18s ease,
        visibility .18s ease !important;
}

.comm-drawer.open{
    transform:translate(-50%,-50%) scale(1) !important;
    opacity:1 !important;
    visibility:visible !important;
    pointer-events:auto !important;
}

.comm-drawer-head{
    position:sticky !important;
    top:0 !important;
    z-index:5 !important;
    padding:16px 18px !important;
    border-bottom:1px solid var(--line) !important;
    background:#fff !important;
}

.comm-drawer-body{
    padding:16px !important;
    max-width:100% !important;
}

/* خلفية داكنة خلف نافذة التفاصيل */
.comm-device-modal-backdrop{
    position:fixed;
    inset:0;
    z-index:4999;
    background:rgba(15,23,42,.48);
    opacity:0;
    visibility:hidden;
    pointer-events:none;
    transition:opacity .18s ease, visibility .18s ease;
}

.comm-device-modal-backdrop.open{
    opacity:1;
    visibility:visible;
    pointer-events:auto;
}

body.comm-device-modal-open{
    overflow:hidden;
}

/* Mobile */
@media(max-width:700px){
    .comm-drawer{
        width:calc(100vw - 16px) !important;
        max-width:none !important;
        max-height:calc(100vh - 16px) !important;
        border-radius:15px !important;
    }

    .comm-drawer-head{
        padding:13px 14px !important;
    }

    .comm-drawer-body{
        padding:10px !important;
        gap:9px !important;
    }

    .comm-detail-grid{
        grid-template-columns:1fr !important;
    }

    .comm-detail-section{
        padding:11px !important;
    }

    .comm-form-grid{
        grid-template-columns:1fr !important;
    }
}

/* Dark mode */
html[data-theme="dark"] .comm-drawer,
html[data-theme="dark"] .comm-drawer-head,
body.dark .comm-drawer,
body.dark .comm-drawer-head,
.dashboard-page.dark .comm-drawer,
.dashboard-page.dark .comm-drawer-head{
    background:#20232e !important;
}


/* ===== Final all-device topology placement ===== */

.comm-topology-unplaced{
    position:relative;
    z-index:5;
    display:flex;
    justify-content:center;
    align-items:flex-start;
    gap:18px;
    width:100%;
    margin-top:38px;
    padding-top:18px;
    border-top:1px dashed #d0d5dd;
}

/*
 * These collections are metadata placement only.
 * NEVER draw physical SVG cables to them.
 */
.comm-topology-unplaced .comm-observed-wrap{
    width:260px;
    margin:0;
}

.comm-topology-unplaced:before,
.comm-topology-unplaced:after,
.comm-topology-unplaced .comm-observed-wrap:before,
.comm-topology-unplaced .comm-observed-wrap:after{
    display:none !important;
    content:none !important;
}

@media(max-width:768px){
    .comm-topology-unplaced{
        flex-direction:column;
        align-items:center;
        width:100%;
        gap:10px;
    }

    .comm-topology-unplaced .comm-observed-wrap{
        width:min(92vw,320px);
    }
}


/* INLINE PORT OBSERVED DEVICES V3 */

.comm-port-observed-inline{
    position:relative;
    z-index:4;
    width:min(900px,95%);
    margin:22px auto 0;
    padding:10px;
    border:1px dashed #98a2b3;
    border-radius:12px;
    background:#f8fafc;
}

.comm-port-observed-inline:before,
.comm-port-observed-inline:after{
    display:none !important;
    content:none !important;
}

.comm-port-observed-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:4px 5px 9px;
    color:#475467;
}

.comm-port-observed-title span{
    display:flex;
    align-items:center;
    gap:6px;
}

.comm-port-observed-title b{
    font-size:9px;
    font-weight:900;
}

.comm-port-observed-title small{
    color:#667085;
    font-size:8px;
}

.comm-port-observed-note{
    margin-bottom:9px;
    padding:6px 8px;
    border-radius:7px;
    background:#fff;
    color:#667085;
    font-size:7px;
    text-align:center;
}

.comm-port-observed-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(175px,1fr));
    gap:7px;
}

.comm-port-observed-grid .comm-observed-device{
    width:100%;
    min-width:0;
    margin:0;
}

.dashboard-page.dark .comm-port-observed-inline{
    background:#20232e;
    border-color:#667085;
}

.dashboard-page.dark .comm-port-observed-note{
    background:#292d3a;
    color:#98a2b3;
}

@media(max-width:768px){
    .comm-port-observed-inline{
        width:min(94vw,560px);
    }

    .comm-port-observed-grid{
        grid-template-columns:1fr;
    }
}

</style>
<style>
.comm-live-overview{position:relative;overflow:hidden;display:flex;align-items:center;justify-content:space-between;gap:20px;padding:18px 22px;border-color:#f0b4b0;background:linear-gradient(135deg,#fff 0%,#fff8f7 100%)}
.comm-overview-totals{position:relative;z-index:1;display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:28px;flex:1}.comm-overview-metric+.comm-overview-metric{padding-right:28px;border-right:1px solid #efd9d7}.comm-overview-metric.connected .comm-live-total-label i{background:#17191f}.comm-overview-metric.connected strong{color:var(--red)}
.comm-device-controls{display:grid;grid-template-columns:minmax(220px,1fr) repeat(3,minmax(145px,.32fr));gap:9px;padding:13px 15px;border-bottom:1px solid var(--line)}.comm-device-controls input,.comm-device-controls select{width:100%;min-height:40px;padding:8px 10px;border:1px solid #dde0e6;border-radius:9px;background:#fff;color:inherit;font:inherit;font-size:10px}.comm-device-pagination{display:flex;align-items:center;justify-content:center;gap:10px;padding:14px}.comm-device-pagination span{color:var(--muted);font-size:9px;font-weight:900}.comm-device-name{display:flex;align-items:center;gap:7px}.comm-device-name i{color:var(--red);font-size:14px}@media(max-width:850px){.comm-device-controls{grid-template-columns:1fr 1fr}.comm-device-controls input{grid-column:1/-1}}
.comm-live-overview:after{content:'';position:absolute;left:-45px;bottom:-75px;width:180px;height:180px;border-radius:50%;background:rgba(229,34,26,.06)}
.comm-live-total-label{display:flex;align-items:center;gap:8px;color:#6d7483;font-size:10px;font-weight:900}.comm-live-total-label i{display:grid;place-items:center;width:32px;height:32px;border-radius:10px;color:#fff;background:var(--red);font-size:15px}
.comm-live-overview strong{display:block;margin-top:4px;font-size:32px;line-height:1;color:#17191f}.comm-live-overview small{display:block;margin-top:7px;color:#858c9b;font-size:9px}
.comm-live-clock{position:relative;z-index:1;text-align:left}.comm-live-clock b{display:flex;align-items:center;justify-content:flex-end;gap:6px;color:#087a54;font-size:10px}.comm-live-clock b:before{content:'';width:7px;height:7px;border-radius:50%;background:#12b76a;box-shadow:0 0 0 5px rgba(18,183,106,.12);animation:comm-pulse 1s ease-in-out infinite}.comm-live-clock.error b{color:#b42318}.comm-live-clock.error b:before{background:#e5221a}.comm-live-clock span{display:block;margin-top:7px;color:#8b92a2;font-size:9px;direction:ltr}@keyframes comm-pulse{50%{opacity:.4;transform:scale(.82)}}
.comm-network-stats [data-live-source]{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.comm-network-stats [data-live-count]{color:var(--red);font-size:14px}
.comm-network-switch{min-width:180px;min-height:38px;padding:7px 11px;border:1px solid #dfe2e8;border-radius:10px;background:#fff;color:#303541;font:inherit;font-size:10px;font-weight:900;outline:0}.comm-network-switch:focus{border-color:#e5221a;box-shadow:0 0 0 3px rgba(229,34,26,.08)}
.comm-topology-warning{display:flex;align-items:flex-start;gap:9px;padding:11px 13px;border:1px solid #f5d58a;border-radius:12px;color:#855d0b;background:#fff9e8;font-size:9px}.comm-topology-warning[hidden]{display:none}.comm-topology-warning i{font-size:16px}.comm-topology-warning b,.comm-topology-warning span{display:block}.comm-topology-warning span{margin-top:3px}
.comm-canvas.fallback-mode{overflow:auto;overscroll-behavior:contain}.comm-fallback-tree{position:relative;min-width:max-content;min-height:100%;padding:28px 35px 80px;color:#252a34}.comm-fallback-count{position:sticky;z-index:8;top:8px;display:inline-flex;margin-bottom:12px;padding:7px 10px;border:1px solid #b9e8d7;border-radius:999px;color:#087a54;background:rgba(239,252,247,.96);font-size:10px;font-weight:900;box-shadow:0 4px 12px rgba(20,40,30,.08)}.comm-fallback-tree ul{position:relative;margin:0;padding:0 34px 0 0;list-style:none}.comm-fallback-tree ul:before{content:'';position:absolute;top:0;right:13px;bottom:18px;border-right:2px solid #cfd5df}.comm-fallback-tree li{position:relative;margin:12px 0}.comm-fallback-tree li:before{content:'←';position:absolute;top:20px;right:-22px;color:#7b8798;font-weight:900}.comm-fallback-node{display:block;min-width:210px;padding:10px 12px;border:2px solid #98a2b3;border-radius:11px;background:#fff;color:#20252f;text-align:right;cursor:pointer;box-shadow:0 5px 14px rgba(20,28,40,.08)}.comm-fallback-node b,.comm-fallback-node small{display:block}.comm-fallback-node small{margin-top:4px;color:#6f7888;direction:ltr}.comm-fallback-node.online{border-color:#12b76a}.comm-fallback-node.offline{border-color:#e5221a}.comm-fallback-node.degraded{border-color:#f79009}.comm-fallback-unlinked{margin-top:24px;padding-top:16px;border-top:1px dashed #c8ced8}.comm-fallback-unlinked>p{color:#737d8d;font-size:10px;font-weight:900}
.comm-tree-search{display:flex;align-items:center;gap:8px;min-width:260px;min-height:38px;padding:7px 11px;border:1px solid #dfe2e8;border-radius:10px;background:#fff}.comm-tree-search i{color:#8b93a2}.comm-tree-search input{min-width:0;flex:1;border:0;outline:0;background:transparent;font:inherit;font-size:10px}.comm-tree-search span{min-width:24px;color:#e5221a;font-size:9px;font-weight:900;text-align:center}
.comm-canvas.fallback-mode{background:#fbfcfe;cursor:grab;overflow:hidden}.comm-org-tree{position:relative;width:max-content;min-width:100%;min-height:100%;padding:28px 42px 90px;text-align:center;direction:rtl}.comm-org-count{position:sticky;z-index:20;top:8px;right:8px;display:flex;width:max-content;margin:0 0 18px auto;padding:7px 11px;border:1px solid #b6e9d4;border-radius:999px;color:#087a54;background:rgba(240,253,248,.96);font-size:10px;font-weight:900;box-shadow:0 4px 12px rgba(20,40,30,.08)}.comm-org-tree ul{position:relative;display:flex;justify-content:center;margin:0;padding:34px 0 0;list-style:none}.comm-org-tree li{position:relative;padding:34px 12px 0;text-align:center}.comm-org-tree li:before,.comm-org-tree li:after{content:'';position:absolute;top:0;width:50%;height:34px;border-top:2px solid #22b86a}.comm-org-tree li:before{right:50%;border-left:2px solid #22b86a}.comm-org-tree li:after{left:50%;border-right:2px solid #22b86a}.comm-org-tree li:only-child:before,.comm-org-tree li:only-child:after{display:none}.comm-org-tree li:only-child{padding-top:34px}.comm-org-tree li:first-child:before,.comm-org-tree li:last-child:after{border:0}.comm-org-tree li:last-child:before{border-radius:0 7px 0 0}.comm-org-tree li:first-child:after{border-radius:7px 0 0 0}.comm-org-tree ul ul:before{content:'';position:absolute;top:0;right:50%;width:0;height:34px;border-right:2px solid #22b86a}.comm-org-tree li.status-offline:before,.comm-org-tree li.status-offline:after{border-color:#e5221a}.comm-org-card{display:flex;align-items:center;gap:14px;width:270px;min-height:112px;padding:15px 17px;border:2px solid #aab3c1;border-radius:15px;background:#fff;color:#161b25;text-align:right;cursor:pointer;box-shadow:0 8px 22px rgba(27,39,55,.1);transition:.18s}.comm-org-card:hover{transform:translateY(-2px);box-shadow:0 12px 26px rgba(27,39,55,.16)}.comm-org-card.online{border-color:#12b76a}.comm-org-card.offline{border-color:#e5221a}.comm-org-card.degraded{border-color:#f79009}.comm-org-card.unreachable_parent{border-color:#344054}.comm-org-icon{display:grid;place-items:center;flex:0 0 54px;width:54px;height:54px;border-radius:14px;color:#168a55;background:#eaf9f2;font-size:28px}.comm-org-card.offline .comm-org-icon{color:#c5221a;background:#fff0ef}.comm-org-info{min-width:0;flex:1}.comm-org-info b{display:block;overflow:hidden;font-size:17px;line-height:1.25;text-overflow:ellipsis;white-space:nowrap}.comm-org-info code{display:block;margin-top:6px;color:#526174;font-family:Consolas,monospace;font-size:13px;direction:ltr}.comm-org-info small{display:block;margin-top:7px;color:#687587;font-size:10px}.comm-org-card.search-match{border-color:#7c3aed;box-shadow:0 0 0 5px rgba(124,58,237,.14),0 12px 28px rgba(27,39,55,.15);animation:comm-search-pulse 1.1s ease-in-out infinite}.comm-org-card.search-path{box-shadow:0 0 0 4px rgba(34,184,106,.13)}.comm-org-card.search-dim{opacity:.3}.comm-unlinked-section{width:min(1180px,calc(100vw - 390px));min-width:760px;margin:48px auto 0;padding-top:22px;border-top:2px dashed #cbd2dd;text-align:right}.comm-unlinked-section h3{margin:0 0 6px;font-size:14px}.comm-unlinked-section p{margin:0 0 16px;color:#7b8595;font-size:10px}.comm-unlinked-grid{display:grid;grid-template-columns:repeat(4,minmax(240px,1fr));gap:12px}.comm-unlinked-grid .comm-org-card{width:100%}@keyframes comm-search-pulse{50%{transform:translateY(-2px);box-shadow:0 0 0 8px rgba(124,58,237,.08),0 14px 30px rgba(27,39,55,.18)}}
.comm-org-tree>ul:before{content:'';position:absolute;top:0;right:50%;width:0;height:34px;border-right:2px solid #22b86a}.comm-org-card.selected{outline:4px solid rgba(229,34,26,.16);outline-offset:3px}.comm-org-card.disabled{border-color:#667085}.comm-org-card.disabled .comm-org-icon{color:#667085;background:#f2f4f7}
.comm-org-card.drag-target{border-color:#7c3aed;background:#f7f2ff;transform:translateY(-3px)}
.comm-port-legend{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}.comm-port-legend span{display:flex;align-items:center;gap:5px;padding:4px 7px;border-radius:999px;background:#f7f8fa;color:#667085;font-size:8px}.comm-port-legend i{width:8px;height:8px;border-radius:3px}.comm-ports{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.comm-port{padding:10px;border:2px solid #98a2b3;border-radius:10px;background:#f8f9fa}.comm-port.gigabit{border-color:#12b76a;background:#effbf5}.comm-port.fast{border-color:#e5221a;background:#fff2f1}.comm-port.free,.comm-port.disabled{border-color:#98a2b3;background:#f2f4f7;color:#667085}.comm-port-head{display:flex;align-items:center;justify-content:space-between;gap:6px}.comm-port-head b{font-size:11px;direction:ltr}.comm-port-speed{padding:3px 6px;border-radius:999px;background:rgba(255,255,255,.85);font-size:8px;font-weight:900;direction:ltr}.comm-port-meta{display:block;margin-top:5px;color:#667085;font-size:8px}.comm-port-devices{display:grid;gap:4px;margin-top:7px}.comm-port-device{display:flex;justify-content:space-between;gap:5px;width:100%;padding:6px;border:0;border-radius:6px;background:rgba(255,255,255,.82);font:inherit;font-size:8px;text-align:right;cursor:pointer}.comm-port-device code{direction:ltr}.comm-port-empty{display:block;margin-top:7px;color:#7d8494;font-size:8px}
@media(max-width:1000px){.comm-tree-search{min-width:200px}.comm-unlinked-grid{grid-template-columns:repeat(2,minmax(240px,1fr))}.comm-unlinked-section{width:900px}}
.dashboard-page.dark .comm-live-overview{background:linear-gradient(135deg,#20232e 0%,#2b2022 100%)}.dashboard-page.dark .comm-live-overview strong{color:#f4f5f7}
@media(max-width:700px){.comm-live-overview{align-items:stretch;flex-direction:column}.comm-overview-totals{grid-template-columns:1fr;gap:14px}.comm-overview-metric+.comm-overview-metric{padding-top:14px;padding-right:0;border-top:1px solid #efd9d7;border-right:0}.comm-live-clock{text-align:right}.comm-live-clock b{justify-content:flex-start}.comm-ports{grid-template-columns:1fr}}
.comm-canvas-wrap{height:720px;overflow:hidden;background:#fbfcfe;position:relative}.comm-canvas.fallback-mode{background:#fbfcfe;cursor:grab}.comm-org-tree.physical-tree{position:relative;width:max-content;min-width:100%;min-height:100%;padding:24px 40px 100px;text-align:center;direction:rtl;transform-origin:top center}.comm-physical-head{position:sticky;z-index:20;top:8px;right:8px;display:flex;align-items:center;justify-content:space-between;gap:14px;width:max-content;min-width:360px;margin:0 auto 24px;padding:9px 14px;border:1px solid #dce1e8;border-radius:11px;background:rgba(255,255,255,.97);box-shadow:0 4px 14px rgba(20,30,45,.07)}.comm-physical-head b{font-size:11px}.comm-physical-head span{color:#667085;font-size:9px}.comm-physical-components{display:flex;align-items:flex-start;justify-content:center;gap:70px;width:max-content;min-width:100%}.comm-physical-component{position:relative;padding:12px 20px 30px;border:0;background:transparent}.comm-component-title{display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:5px;color:#8a93a2;font-size:8px}.physical-tree ul{position:relative;display:flex;justify-content:center;margin:0;padding:38px 0 0;list-style:none;border:0}.physical-tree li{position:relative;padding:38px 10px 0;text-align:center}.physical-tree li:before,.physical-tree li:after{content:'';position:absolute;top:0;width:50%;height:38px;border-top:2px solid #12b76a}.physical-tree li:before{right:50%;border-left:2px solid #12b76a}.physical-tree li:after{left:50%;border-right:2px solid #12b76a}.physical-tree li:only-child:before,.physical-tree li:only-child:after{display:none}.physical-tree li:first-child:before,.physical-tree li:last-child:after{border:0}.physical-tree li:last-child:before{border-radius:0 7px 0 0}.physical-tree li:first-child:after{border-radius:7px 0 0 0}.physical-tree ul ul:before{content:'';position:absolute;top:0;right:50%;width:0;height:38px;border-right:2px solid #12b76a}.physical-tree li.status-offline:before,.physical-tree li.status-offline:after{border-color:#e5221a}.physical-tree li.status-offline>ul:before{border-color:#e5221a}.comm-node-row{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px}.comm-branch-toggle{position:absolute;z-index:3;bottom:-17px;left:50%;transform:translateX(-50%);display:grid;place-items:center;width:27px;height:27px;padding:0;border:1px solid #d8dde5;border-radius:50%;background:#fff;color:#667085;cursor:pointer;box-shadow:0 3px 8px rgba(20,30,45,.09)}.comm-branch-toggle:hover{border-color:#e5221a;color:#e5221a}.comm-branch-toggle.empty{display:none}.physical-tree .comm-org-card{position:relative;display:flex;align-items:center;gap:9px;width:205px;min-width:205px;max-width:205px;min-height:78px;padding:10px 11px;border:2px solid #12b76a;border-radius:13px;background:#fff;color:#17191f;text-align:right;cursor:pointer;box-shadow:0 5px 14px rgba(27,39,55,.08);transition:.16s}.physical-tree .comm-org-card:hover{transform:translateY(-2px);box-shadow:0 9px 20px rgba(27,39,55,.13)}.physical-tree .comm-org-card.online{border-color:#12b76a}.physical-tree .comm-org-card.offline{border-color:#e5221a}.physical-tree .comm-org-card.degraded{border-color:#12b76a}.physical-tree .comm-org-card.disabled,.physical-tree .comm-org-card.unreachable_parent,.physical-tree .comm-org-card.unknown{border-color:#e5221a}.physical-tree .comm-org-icon{display:grid;place-items:center;flex:0 0 36px;width:36px;height:36px;border-radius:10px;color:#078b55;background:#eaf9f2;font-size:18px}.physical-tree .comm-org-card.offline .comm-org-icon,.physical-tree .comm-org-card.disabled .comm-org-icon,.physical-tree .comm-org-card.unreachable_parent .comm-org-icon,.physical-tree .comm-org-card.unknown .comm-org-icon{color:#c5221a;background:#fff0ef}.physical-tree .comm-org-info{min-width:0;flex:1}.physical-tree .comm-org-info b{display:block;overflow:hidden;font-size:12px;font-weight:900;line-height:1.35;text-overflow:ellipsis;white-space:nowrap}.physical-tree .comm-org-info code{display:block;margin-top:4px;color:#536174;font-family:Consolas,monospace;font-size:10px;direction:ltr;text-align:right}.comm-tree-state{display:flex;align-items:center;gap:5px;margin-top:5px;color:#667085;font-size:8px;font-weight:800}.comm-tree-state i{display:block;width:7px;height:7px;border-radius:50%;background:#12b76a}.comm-org-card.offline .comm-tree-state i,.comm-org-card.disabled .comm-tree-state i,.comm-org-card.unreachable_parent .comm-tree-state i,.comm-org-card.unknown .comm-tree-state i{background:#e5221a}.comm-node-badges{display:flex;align-items:center;gap:4px;margin-top:4px}.comm-node-badge.children{display:inline-flex;padding:2px 5px;border-radius:999px;color:#087a54;background:#eaf9f2;font-size:7px;font-weight:900}
.comm-node-badge.outage-root{display:inline-flex;padding:2px 5px;border-radius:999px;color:#b42318;background:#fee4e2;font-size:7px;font-weight:900}
.comm-node-badge.self-down{display:inline-flex;padding:2px 5px;border-radius:999px;color:#b42318;background:#fff0ef;font-size:7px;font-weight:900}
.comm-node-badge.upstream{display:inline-flex;padding:2px 5px;border-radius:999px;color:#93370d;background:#fffaeb;font-size:7px;font-weight:900}
.comm-node-badge.conflict{display:inline-flex;padding:2px 5px;border-radius:999px;color:#6941c6;background:#f4f3ff;font-size:7px;font-weight:900}
.physical-tree .comm-org-card.upstream-down{border-color:#f79009}
.physical-tree .comm-org-card.upstream-down .comm-org-icon{color:#b54708;background:#fffaeb}
.physical-tree .comm-org-card.upstream-down .comm-tree-state i{background:#f79009}
.physical-tree .comm-org-card.status-conflict{border-color:#7f56d9}
.physical-tree .comm-org-card.status-conflict .comm-org-icon{color:#6941c6;background:#f4f3ff}
.physical-tree .comm-org-card.status-conflict .comm-tree-state i{background:#7f56d9}
.comm-physical-tower-card.upstream-down{border-color:#f79009}
.comm-physical-tower-card.upstream-down .state i{background:#f79009}
.comm-physical-tower-card.status-conflict{border-color:#7f56d9}
.comm-physical-tower-card.status-conflict .state i{background:#7f56d9}

/* Physical topology connector semantics */
.comm-tree-wire{
    fill:none;
    vector-effect:non-scaling-stroke;
    stroke-linecap:round;
    stroke-linejoin:round;
    pointer-events:none;
}
.comm-tree-wire.physical{
    stroke:#344054;
    stroke-width:2.4;
}
.comm-tree-wire.physical.offline{
    stroke:#e5221a;
}
.comm-tree-wire.wireless{
    stroke:#1570ef;
    stroke-width:2.6;
    stroke-dasharray:8 7;
}
.comm-tree-wire.wireless.offline{
    stroke:#e5221a;
    stroke-dasharray:8 7;
}.comm-org-card.selected{outline:3px solid rgba(229,34,26,.18);outline-offset:3px}.comm-org-card.search-match{box-shadow:0 0 0 5px rgba(124,58,237,.14),0 9px 20px rgba(27,39,55,.13)}.comm-org-card.search-path{box-shadow:0 0 0 4px rgba(34,184,106,.13)}.comm-org-card.search-dim{opacity:.25}.dashboard-page.dark .comm-physical-head{background:#20232e;color:#eef0f5;border-color:#3a3e4b}.dashboard-page.dark .physical-tree .comm-org-card{background:#20232e;color:#eef0f5}.dashboard-page.dark .comm-branch-toggle{background:#20232e;color:#eef0f5;border-color:#3a3e4b}@media(max-width:1000px){.comm-org-tree.physical-tree{padding-left:25px;padding-right:25px}.physical-tree .comm-org-card{width:190px;min-width:190px;max-width:190px}.physical-tree li{padding-left:7px;padding-right:7px}}
</style>

<style id="comm-standalone-wifi-style">
.comm-real-tower{
    position:relative;
}

.comm-standalone-wifi-mark{
    position:absolute;
    z-index:20;
    top:-12px;
    left:-12px;
    display:grid;
    place-items:center;
    width:32px;
    height:32px;
    border-radius:50%;
    background:#fff;
    border:2px solid #12b76a;
    color:#087a54;
    font-size:18px;
    box-shadow:0 4px 10px rgba(20,40,30,.18);
}

.comm-real-tower.offline .comm-standalone-wifi-mark{
    border-color:#e5221a;
    color:#c5221a;
}

.comm-real-tower-info small .bi-wifi{
    margin-left:4px;
}
</style>

<style id="comm-svg-tree-connectors">
/* ========================================================
   SVG PHYSICAL TREE CONNECTORS
   Old pseudo-element tree lines are completely disabled.
   ======================================================== */

.physical-tree ul:before,
.physical-tree ul:after,
.physical-tree li:before,
.physical-tree li:after,
.physical-tree .comm-port-tree:before,
.physical-tree .comm-port-tree:after,
.physical-tree .comm-port-branch:before,
.physical-tree .comm-port-branch:after,
.physical-tree .comm-port-roots:before,
.physical-tree .comm-port-roots:after,
.comm-gateway-wrap:before,
.comm-gateway-wrap:after{
    content:none !important;
    display:none !important;
    border:0 !important;
    background:none !important;
}

.comm-network-tree{
    position:relative !important;
}

.comm-tree-connectors{
    position:absolute;
    top:0;
    left:0;
    z-index:1;
    overflow:visible;
    pointer-events:none;
}

.comm-gateway-wrap,
.comm-port-tree,
.comm-port-branch,
.comm-port-node,
.comm-port-roots,
.comm-node-row,
.comm-org-card,
.comm-gateway-card{
    position:relative;
    z-index:3;
}

/* Keep spacing but no CSS-created line */
.physical-tree .comm-port-tree{
    padding-top:55px !important;
}

.physical-tree .comm-port-roots{
    padding-top:48px !important;
}

.physical-tree .comm-port-roots li > ul{
    padding-top:48px !important;
}

.physical-tree li{
    padding-top:48px !important;
}

/* SVG line appearance */
.comm-tree-wire{
    fill:none;
    stroke:#12b76a;
    stroke-width:2;
    stroke-linecap:round;
    stroke-linejoin:round;
    vector-effect:non-scaling-stroke;
}

.comm-tree-wire.offline{
    stroke:#e5221a;
}

/* No yellow state */
.comm-tree-wire.degraded{
    stroke:#12b76a;
}
</style>


<style id="comm-observed-infrastructure-ui">

/* ==========================================================
   OBSERVED INFRASTRUCTURE
   These devices are seen behind a MikroTik port but have no
   proven internal LLDP/STP/FDB position yet.
   ========================================================== */

.comm-observed-wrap{
    position:relative;
    z-index:5;
    width:230px;
    margin:22px auto 0;
    border:1px dashed #98a2b3;
    border-radius:12px;
    background:#f8fafc;
    box-shadow:0 4px 12px rgba(15,23,42,.06);
    overflow:hidden;
}

/*
 * Important:
 * no physical connector is drawn to this group.
 */
.comm-observed-wrap:before,
.comm-observed-wrap:after{
    display:none !important;
    content:none !important;
}

.comm-observed-toggle{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    width:100%;
    padding:9px 10px;
    border:0;
    background:#eef2f6;
    color:#344054;
    cursor:pointer;
    text-align:right;
}

.comm-observed-toggle > span{
    display:flex;
    align-items:center;
    gap:6px;
}

.comm-observed-toggle b{
    font-size:9px;
    font-weight:900;
}

.comm-observed-toggle > span:first-child > i{
    color:#667085;
    font-size:11px;
}

.comm-observed-count{
    color:#667085;
    font-size:8px;
    white-space:nowrap;
}

.comm-observed-count .bi-chevron-down{
    transition:transform .18s ease;
}

.comm-observed-toggle.open .bi-chevron-down{
    transform:rotate(180deg);
}

.comm-observed-note{
    padding:6px 9px;
    border-top:1px dashed #d0d5dd;
    border-bottom:1px dashed #d0d5dd;
    color:#667085;
    background:#fff;
    font-size:7px;
    line-height:1.5;
}

.comm-observed-list{
    display:flex;
    flex-direction:column;
    gap:6px;
    max-height:330px;
    overflow-y:auto;
    padding:8px;
}

.comm-observed-list[hidden]{
    display:none !important;
}

.comm-observed-device{
    position:relative;
    display:grid;
    grid-template-columns:30px minmax(0,1fr) 10px;
    align-items:center;
    gap:7px;
    width:100%;
    min-height:50px;
    padding:7px;
    border:1px solid #d0d5dd;
    border-radius:9px;
    background:#fff;
    color:#344054;
    text-align:right;
    cursor:pointer;
}

.comm-observed-device:hover{
    border-color:#98a2b3;
    box-shadow:0 3px 8px rgba(15,23,42,.08);
}

.comm-observed-device.selected{
    outline:2px solid #667085;
    outline-offset:2px;
}

.comm-observed-device-icon{
    display:grid;
    place-items:center;
    width:30px;
    height:30px;
    border-radius:8px;
    background:#f2f4f7;
    color:#475467;
    font-size:14px;
}

.comm-observed-device-info{
    display:block;
    min-width:0;
}

.comm-observed-device-info b{
    display:block;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    color:#344054;
    font-size:8px;
    font-weight:900;
}

.comm-observed-device-info code{
    display:block;
    margin-top:2px;
    color:#667085;
    direction:ltr;
    text-align:right;
    font-size:7px;
}

.comm-observed-device-info small{
    display:block;
    overflow:hidden;
    margin-top:2px;
    color:#98a2b3;
    text-overflow:ellipsis;
    white-space:nowrap;
    font-size:6px;
}

.comm-observed-state{
    display:flex;
    justify-content:center;
}

.comm-observed-state i{
    display:block;
    width:7px;
    height:7px;
    border-radius:50%;
}

.comm-observed-state.online i{
    background:#12b76a;
}

.comm-observed-state.offline i{
    background:#e5221a;
}

/* No yellow/degraded color */
.comm-observed-device.online{
    border-left:3px solid #12b76a;
}

.comm-observed-device.offline{
    border-left:3px solid #e5221a;
}

/*
 * Keep observed panels outside SVG connector selection.
 * They are visual inventories, not tree nodes.
 */
.comm-observed-wrap ul,
.comm-observed-wrap li{
    all:unset;
}

/* Dark mode */
.dashboard-page.dark .comm-observed-wrap{
    background:#20232e;
    border-color:#667085;
}

.dashboard-page.dark .comm-observed-toggle{
    background:#292d3a;
    color:#eef0f5;
}

.dashboard-page.dark .comm-observed-note,
.dashboard-page.dark .comm-observed-device{
    background:#20232e;
    color:#eef0f5;
    border-color:#3f4655;
}

.dashboard-page.dark .comm-observed-device-info b{
    color:#eef0f5;
}

/* Mobile */
@media(max-width:768px){
    .comm-observed-wrap{
        width:200px;
    }

    .comm-observed-list{
        max-height:270px;
    }

    .comm-observed-device{
        min-height:48px;
    }
}

</style>

<style>
.comm-tower-device{
    position:relative;
    overflow:visible!important;
    min-height:112px;
    border-width:2px!important;
}

.comm-tower-device.tx{
    border-color:#2563eb!important;
}

.comm-tower-device.rx{
    border-color:#7c3aed!important;
}

.comm-tower-symbol{
    width:46px;
    min-width:46px;
    height:58px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    border-radius:14px;
    background:rgba(37,99,235,.10);
    font-size:27px;
}

.comm-tower-device.rx .comm-tower-symbol{
    background:rgba(124,58,237,.10);
}

.comm-tower-waves{
    font-size:10px;
    letter-spacing:-2px;
    line-height:8px;
    transform:rotate(-90deg);
    opacity:.8;
}

.comm-tower-peer{
    display:flex;
    align-items:center;
    gap:7px;
    margin-inline-start:auto;
    padding:6px 9px;
    max-width:230px;
    border-radius:10px;
    font-size:11px;
    line-height:1.35;
    background:rgba(15,23,42,.06);
    border:1px dashed rgba(15,23,42,.25);
}

.comm-tower-peer.tx{
    border-color:rgba(37,99,235,.55);
}

.comm-tower-peer.rx{
    border-color:rgba(124,58,237,.55);
}

.comm-tower-peer-arrow{
    font-size:24px;
    font-weight:900;
}

.comm-tower-peer b,
.comm-tower-peer strong,
.comm-tower-peer code,
.comm-tower-peer small{
    display:block;
}

.comm-tower-peer code{
    direction:ltr;
    text-align:left;
}

@media (max-width:700px){
    .comm-tower-device{
        min-height:96px;
        flex-wrap:wrap;
    }

    .comm-tower-peer{
        width:100%;
        max-width:none;
        margin:6px 0 0;
    }
}
</style>

<style>

/* =========================================================
   REALISTIC WIRELESS TOWER
   ========================================================= */

.comm-port-observed-grid:has(.comm-real-tower-pair){
    display:flex!important;
    flex-direction:column;
    align-items:stretch;
    gap:26px;
}

.comm-real-tower-pair{
    direction:ltr;
    position:relative;
    display:grid;
    grid-template-columns:180px minmax(130px,1fr) 210px;
    align-items:center;
    width:100%;
    min-height:235px;
    padding:12px 18px;
    box-sizing:border-box;
}

.comm-real-tower-pair.single{
    grid-template-columns:180px;
    justify-content:start;
}

.comm-real-tower{
    appearance:none;
    border:0;
    background:transparent;
    cursor:pointer;
    position:relative;
    width:170px;
    min-height:225px;
    padding:0;
    display:flex;
    flex-direction:column;
    align-items:center;
}

.comm-real-tower-svg{
    display:block;
    width:118px;
    height:185px;
    overflow:visible;
    filter:drop-shadow(0 5px 4px rgba(15,23,42,.15));
}

.comm-real-tower-svg .tower-main,
.comm-real-tower-svg .tower-center,
.comm-real-tower-svg .tower-brace,
.comm-real-tower-svg .tower-cross{
    fill:none;
    stroke:#334155;
    stroke-linecap:round;
    stroke-linejoin:round;
}

.comm-real-tower-svg .tower-main{
    stroke-width:5;
}

.comm-real-tower-svg .tower-center{
    stroke-width:3;
}

.comm-real-tower-svg .tower-brace{
    stroke-width:4;
}

.comm-real-tower-svg .tower-cross{
    stroke-width:2.2;
    opacity:.8;
}

.comm-real-tower-svg .tower-antenna{
    fill:#475569;
}

.comm-real-tower-svg .tower-radio-wave{
    fill:none;
    stroke:#2563eb;
    stroke-width:4;
    stroke-linecap:round;
}

.comm-real-tower.offline .comm-real-tower-svg{
    opacity:.45;
    filter:grayscale(1);
}

.comm-real-tower-info{
    direction:rtl;
    display:flex;
    flex-direction:column;
    align-items:center;
    margin-top:-3px;
    line-height:1.35;
}

.comm-real-tower-info b{
    font-size:13px;
    color:#0f172a;
}

.comm-real-tower-info code{
    direction:ltr;
    font-size:11px;
    color:#475569;
}

.comm-real-tower-info small{
    margin-top:3px;
    font-size:10px;
    font-weight:800;
    color:#2563eb;
}

/* Wireless dotted transmission */

.comm-wireless-beam{
    position:relative;
    height:100px;
    min-width:130px;
    align-self:center;
}

.comm-wireless-beam-line{
    position:absolute;
    left:0;
    right:15px;
    top:48px;
    border-top:4px dashed #2563eb;
    transform:rotate(-8deg);
    transform-origin:center;
    filter:drop-shadow(0 1px 1px rgba(37,99,235,.15));
}

.comm-wireless-beam-arrow{
    position:absolute;
    right:0;
    top:34px;
    font-size:24px;
    color:#2563eb;
}

.comm-wireless-beam-meta{
    direction:ltr;
    position:absolute;
    top:5px;
    left:50%;
    transform:translateX(-50%);
    white-space:nowrap;
    background:#fff;
    border:1px solid #bfdbfe;
    border-radius:999px;
    padding:4px 9px;
    font-size:10px;
    font-weight:800;
    color:#1e40af;
    box-shadow:0 2px 7px rgba(15,23,42,.08);
}

/* Receiving radio */

.comm-real-station{
    direction:rtl;
    appearance:none;
    border:2px solid #22c55e;
    background:#fff;
    border-radius:14px;
    min-height:86px;
    padding:10px 12px;
    cursor:pointer;
    display:flex;
    align-items:center;
    gap:10px;
    box-shadow:0 5px 14px rgba(15,23,42,.08);
}

.comm-real-station.offline{
    border-color:#ef4444;
    opacity:.7;
}

.comm-real-station-radio{
    width:48px;
    height:48px;
    flex:0 0 48px;
    border-radius:12px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#eff6ff;
    color:#2563eb;
    font-size:25px;
}

.comm-real-station-info{
    min-width:0;
    display:flex;
    flex-direction:column;
    text-align:right;
}

.comm-real-station-info b{
    color:#0f172a;
    font-size:12px;
}

.comm-real-station-info code{
    direction:ltr;
    text-align:right;
    color:#64748b;
    font-size:10px;
}

.comm-real-station-info small{
    margin-top:4px;
    font-size:10px;
    color:#64748b;
}

/* Mobile */

@media(max-width:700px){

    .comm-real-tower-pair{
        grid-template-columns:105px minmax(80px,1fr) 125px;
        min-height:175px;
        padding:8px 4px;
        gap:2px;
    }

    .comm-real-tower{
        width:100px;
        min-height:165px;
    }

    .comm-real-tower-svg{
        width:80px;
        height:130px;
    }

    .comm-real-tower-info b{
        font-size:10px;
    }

    .comm-real-tower-info code,
    .comm-real-tower-info small{
        font-size:8px;
    }

    .comm-wireless-beam{
        min-width:75px;
        height:80px;
    }

    .comm-wireless-beam-line{
        top:39px;
        border-top-width:3px;
    }

    .comm-wireless-beam-arrow{
        top:28px;
        font-size:18px;
    }

    .comm-wireless-beam-meta{
        top:4px;
        font-size:7px;
        padding:3px 5px;
        max-width:110px;
        overflow:hidden;
        text-overflow:ellipsis;
    }

    .comm-real-station{
        min-height:65px;
        padding:6px;
        gap:5px;
    }

    .comm-real-station-radio{
        width:31px;
        height:31px;
        flex-basis:31px;
        font-size:17px;
    }

    .comm-real-station-info b{
        font-size:9px;
    }

    .comm-real-station-info code,
    .comm-real-station-info small{
        font-size:7px;
    }
}

</style>

<style>

/* ===== LIVE RECEIVERS FROM AP ===== */

.comm-real-tower-pair.single:has(.comm-multi-rx-wrap){
    grid-template-columns:180px minmax(360px,1fr);
    gap:30px;
    align-items:flex-start;
}

.comm-multi-rx-wrap{
    direction:rtl;
    min-width:360px;
    margin-top:10px;
    padding:12px 14px;
    border:1px dashed #94a3b8;
    border-radius:14px;
    background:rgba(248,250,252,.92);
}

.comm-multi-rx-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:12px;
}

.comm-multi-rx-head b{
    font-size:12px;
    color:#0f172a;
}

.comm-multi-rx-head span{
    padding:3px 8px;
    border-radius:999px;
    background:#dbeafe;
    color:#1d4ed8;
    font-size:10px;
    font-weight:900;
}

.comm-multi-rx-tree{
    position:relative;
    padding-right:26px;
}

.comm-multi-rx-trunk{
    position:absolute;
    right:5px;
    top:0;
    bottom:20px;
    border-right:3px dashed #2563eb;
}

.comm-multi-rx-list{
    display:grid;
    grid-template-columns:repeat(2,minmax(190px,1fr));
    gap:9px 28px;
}

.comm-multi-rx-row{
    position:relative;
}

.comm-multi-rx-branch{
    position:absolute;
    right:-20px;
    top:50%;
    width:20px;
    border-top:3px dashed #2563eb;
}

.comm-multi-rx-branch:after{
    content:'▶';
    position:absolute;
    left:-7px;
    top:-10px;
    color:#2563eb;
    font-size:15px;
    transform:rotate(180deg);
}

.comm-live-wireless-receiver{
    width:100%;
    min-height:63px;
    display:flex;
    align-items:center;
    gap:8px;
    padding:7px 9px;
    border:1px solid #22c55e;
    border-radius:11px;
    background:#fff;
    text-align:right;
    box-shadow:0 3px 8px rgba(15,23,42,.05);
}

button.comm-live-wireless-receiver{
    cursor:pointer;
}

.comm-live-wireless-receiver.unknown{
    border-color:#f59e0b;
}

.comm-live-rx-icon{
    display:flex;
    align-items:center;
    justify-content:center;
    flex:0 0 34px;
    width:34px;
    height:34px;
    border-radius:9px;
    color:#2563eb;
    background:#eff6ff;
    font-size:18px;
}

.comm-live-wireless-receiver.unknown .comm-live-rx-icon{
    color:#d97706;
    background:#fffbeb;
}

.comm-live-rx-info{
    min-width:0;
    flex:1;
}

.comm-live-rx-info b,
.comm-live-rx-info code,
.comm-live-rx-info small{
    display:block;
}

.comm-live-rx-info b{
    overflow:hidden;
    color:#0f172a;
    font-size:10px;
    white-space:nowrap;
    text-overflow:ellipsis;
}

.comm-live-rx-info code{
    direction:ltr;
    text-align:right;
    color:#64748b;
    font-size:9px;
}

.comm-live-rx-info small{
    margin-top:3px;
    color:#16a34a;
    font-size:8px;
    font-weight:800;
}

.comm-live-wireless-receiver.unknown small{
    color:#d97706;
}

.comm-live-rx-dot{
    display:inline-block;
    width:6px;
    height:6px;
    margin-left:3px;
    border-radius:50%;
    background:#22c55e;
}

.comm-live-wireless-receiver.unknown .comm-live-rx-dot{
    background:#f59e0b;
}

@media(max-width:700px){

    .comm-real-tower-pair.single:has(.comm-multi-rx-wrap){
        grid-template-columns:90px minmax(260px,1fr);
        gap:8px;
    }

    .comm-multi-rx-wrap{
        min-width:260px;
        padding:8px;
    }

    .comm-multi-rx-list{
        grid-template-columns:1fr;
        gap:6px;
    }

    .comm-live-wireless-receiver{
        min-height:54px;
    }
}

</style>

<style id="comm-final-tree-clean-css">

/* ===== FINAL CLEAN NETWORK TREE ===== */

.comm-port-observed-grid:has(.comm-real-tower-pair){
    display:flex!important;
    flex-direction:column!important;
    align-items:stretch!important;
    gap:22px!important;
}

.comm-real-tower-pair{
    direction:ltr!important;
    display:grid!important;
    grid-template-columns:145px minmax(110px,220px) minmax(155px,210px)!important;
    align-items:center!important;
    justify-content:start!important;
    gap:12px!important;
    width:100%!important;
    min-height:185px!important;
    padding:12px 18px!important;
    box-sizing:border-box!important;
    border-bottom:1px dashed #e2e8f0;
}

.comm-real-tower-pair.single{
    display:grid!important;
    grid-template-columns:145px minmax(360px,1fr)!important;
    align-items:start!important;
    gap:24px!important;
}

.comm-real-tower{
    width:135px!important;
    min-height:170px!important;
}

.comm-real-tower-svg{
    width:92px!important;
    height:145px!important;
}

.comm-real-tower-info b{
    font-size:11px!important;
}

.comm-real-tower-info code{
    font-size:9px!important;
}

.comm-real-tower-info small{
    font-size:8px!important;
}

/* Point to point beam */

.comm-wireless-beam{
    min-width:105px!important;
    height:70px!important;
}

.comm-wireless-beam-line{
    top:34px!important;
    border-top:3px dashed #2563eb!important;
    transform:none!important;
}

.comm-wireless-beam-arrow{
    top:23px!important;
    right:-4px!important;
    font-size:19px!important;
}

.comm-wireless-beam-meta{
    top:0!important;
    font-size:8px!important;
    padding:3px 6px!important;
}

.comm-real-station{
    min-height:66px!important;
    padding:7px 9px!important;
}

.comm-real-station-radio{
    width:35px!important;
    height:35px!important;
    flex-basis:35px!important;
    font-size:18px!important;
}

.comm-real-station-info b{
    font-size:10px!important;
}

.comm-real-station-info code,
.comm-real-station-info small{
    font-size:8px!important;
}

/* Multi receiver AP */

.comm-multi-rx-wrap{
    direction:rtl!important;
    min-width:0!important;
    width:100%!important;
    margin:5px 0 0!important;
    padding:12px!important;
    border:1px solid #dbe3ea!important;
    border-radius:14px!important;
    background:#fbfdff!important;
}

.comm-multi-rx-head{
    margin-bottom:10px!important;
}

.comm-multi-rx-head b{
    font-size:11px!important;
}

.comm-multi-rx-tree{
    position:relative!important;
    padding-right:26px!important;
}

/* One trunk from tower then branches to known receivers */
.comm-multi-rx-trunk{
    position:absolute!important;
    right:4px!important;
    top:8px!important;
    bottom:8px!important;
    border-right:3px dashed #2563eb!important;
}

.comm-multi-rx-list{
    display:grid!important;
    grid-template-columns:repeat(4,minmax(145px,1fr))!important;
    gap:8px 24px!important;
}

.comm-multi-rx-row{
    position:relative!important;
    min-width:0!important;
}

.comm-multi-rx-branch{
    position:absolute!important;
    right:-20px!important;
    top:50%!important;
    width:20px!important;
    border-top:2px dashed #2563eb!important;
}

.comm-multi-rx-branch:after{
    content:'›'!important;
    position:absolute!important;
    left:-3px!important;
    top:-12px!important;
    font-size:21px!important;
    font-weight:900!important;
    color:#2563eb!important;
    transform:none!important;
}

.comm-live-wireless-receiver{
    min-height:54px!important;
    padding:6px 7px!important;
    border:1px solid #22c55e!important;
    border-radius:9px!important;
    box-shadow:none!important;
}

.comm-live-rx-icon{
    width:29px!important;
    height:29px!important;
    flex-basis:29px!important;
    font-size:15px!important;
}

.comm-live-rx-info b{
    font-size:8px!important;
}

.comm-live-rx-info code{
    font-size:7px!important;
}

.comm-live-rx-info small{
    font-size:7px!important;
}

/*
 * Do not show orange/unknown receivers under any circumstances.
 */
.comm-live-wireless-receiver.unknown{
    display:none!important;
}

/*
 * Old tower-card UI is disabled.
 * We only use the realistic tower implementation.
 */
.comm-tower-device .comm-tower-peer,
.comm-tower-symbol{
    display:none!important;
}

/*
 * Prevent extreme widths while keeping the tree readable.
 */
.comm-port-observed-inline{
    max-width:1250px!important;
}

.comm-port-observed-grid{
    max-width:1220px!important;
}

/* Mobile */
@media(max-width:800px){

    .comm-real-tower-pair{
        grid-template-columns:95px 75px 120px!important;
        gap:4px!important;
        padding:8px 3px!important;
        min-height:150px!important;
    }

    .comm-real-tower-pair.single{
        grid-template-columns:95px minmax(270px,1fr)!important;
        gap:5px!important;
    }

    .comm-real-tower{
        width:90px!important;
        min-height:140px!important;
    }

    .comm-real-tower-svg{
        width:70px!important;
        height:110px!important;
    }

    .comm-multi-rx-list{
        grid-template-columns:repeat(2,minmax(120px,1fr))!important;
        gap:6px 20px!important;
    }
}

</style>

<style id="comm-final-readable-tree-v2">

/* ===== FINAL TREE READABILITY V2 ===== */

/*
 * Never shrink the entire topology just because one AP has many clients.
 * Use horizontal scrolling instead.
 */
.comm-tree-viewport{
    overflow:auto!important;
}

.comm-tree-stage{
    min-width:max-content!important;
}

/* Physical modem cards */
.comm-org-card,
.comm-gateway-card{
    min-width:150px!important;
}

/* Port sections */
.comm-port-observed-inline{
    width:auto!important;
    min-width:0!important;
    max-width:900px!important;
}

.comm-port-observed-grid{
    width:auto!important;
    max-width:880px!important;
}

/* One tower = one readable row */
.comm-port-observed-grid:has(.comm-real-tower-pair){
    display:flex!important;
    flex-direction:column!important;
    gap:16px!important;
}

.comm-real-tower-pair{
    width:620px!important;
    max-width:620px!important;
    min-height:155px!important;
    grid-template-columns:125px 210px 190px!important;
    gap:18px!important;
    padding:10px 14px!important;
    align-items:center!important;
}

.comm-real-tower-pair.single{
    width:620px!important;
    max-width:620px!important;
    grid-template-columns:125px 440px!important;
}

/* Tower */
.comm-real-tower{
    width:115px!important;
    min-height:145px!important;
}

.comm-real-tower-svg{
    width:78px!important;
    height:118px!important;
}

.comm-real-tower-info{
    margin-top:0!important;
}

.comm-real-tower-info b{
    font-size:11px!important;
}

.comm-real-tower-info code{
    font-size:9px!important;
}

.comm-real-tower-info small{
    font-size:8px!important;
}

/* PTP dashed beam */
.comm-wireless-beam{
    width:210px!important;
    min-width:210px!important;
}

.comm-wireless-beam-line{
    left:0!important;
    right:12px!important;
    border-top:3px dashed #2563eb!important;
    transform:none!important;
}

.comm-wireless-beam-arrow{
    right:-2px!important;
}

.comm-wireless-beam-meta{
    font-size:8px!important;
}

/* Receiving end */
.comm-real-station{
    width:190px!important;
    min-height:65px!important;
}

/*
 * Multi-client AP:
 * tower -> one clear dotted connection -> receiver group.
 */
.comm-multi-rx-wrap{
    position:relative!important;
    width:430px!important;
    min-width:430px!important;
    margin:35px 0 0!important;
    padding:0!important;
    border:0!important;
    background:transparent!important;
}

.comm-tower-outgoing-line{
    position:absolute;
    right:100%;
    top:28px;
    width:55px;
    border-top:3px dashed #2563eb;
}

.comm-tower-outgoing-line:after{
    content:'▶';
    position:absolute;
    right:-7px;
    top:-11px;
    color:#2563eb;
    font-size:18px;
}

.comm-rx-details{
    width:100%;
    border:1px solid #bfdbfe;
    border-radius:12px;
    background:#fff;
    overflow:hidden;
}

.comm-rx-details > summary{
    direction:rtl;
    list-style:none;
    cursor:pointer;
    min-height:54px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:8px 12px;
    font-size:11px;
    font-weight:800;
    color:#1e3a8a;
    background:#eff6ff;
}

.comm-rx-details > summary::-webkit-details-marker{
    display:none;
}

.comm-rx-details > summary strong{
    display:grid;
    place-items:center;
    min-width:27px;
    height:27px;
    border-radius:999px;
    background:#2563eb;
    color:#fff;
    font-size:10px;
}

.comm-rx-details-body{
    display:grid;
    grid-template-columns:repeat(2,minmax(175px,1fr));
    gap:7px;
    padding:9px;
    max-height:320px;
    overflow:auto;
}

.comm-live-wireless-receiver{
    min-height:52px!important;
}

/*
 * No unknown receiver.
 */
.comm-live-wireless-receiver.unknown{
    display:none!important;
}

/*
 * No old inline tower ornament.
 */
.comm-tower-symbol,
.comm-tower-peer{
    display:none!important;
}

/*
 * Make the unresolved fallback a normal connected tree branch,
 * not a floating panel.
 */
.comm-fallback-linked{
    margin-top:28px!important;
}

/* Phone */
@media(max-width:700px){

    .comm-real-tower-pair,
    .comm-real-tower-pair.single{
        width:440px!important;
        max-width:440px!important;
    }

    .comm-real-tower-pair{
        grid-template-columns:90px 145px 155px!important;
    }

    .comm-real-tower-pair.single{
        grid-template-columns:90px 320px!important;
    }

    .comm-multi-rx-wrap{
        width:310px!important;
        min-width:310px!important;
    }

    .comm-rx-details-body{
        grid-template-columns:1fr!important;
    }
}

</style>

<style id="comm-measured-tree-layout">
/*
 * Final geometry rules for measured subtrees.
 */

.physical-tree .comm-port-tree{
    flex-wrap:nowrap !important;
    align-items:flex-start !important;
}

.physical-tree .comm-port-branch{
    box-sizing:border-box !important;
}

.physical-tree .comm-port-roots{
    flex-wrap:nowrap !important;
    align-items:flex-start !important;
}

.physical-tree .comm-port-roots li{
    box-sizing:border-box !important;
    position:relative !important;
}

.physical-tree .comm-port-roots li > ul{
    box-sizing:border-box !important;
    flex-wrap:nowrap !important;
    align-items:flex-start !important;
    padding-top:82px !important;
}

.physical-tree .comm-node-row{
    position:relative !important;
    z-index:10 !important;
    width:210px !important;
    min-width:210px !important;
    max-width:210px !important;
    margin-left:auto !important;
    margin-right:auto !important;
}

.physical-tree .comm-org-card{
    z-index:20 !important;
}

.physical-tree .comm-physical-tower-card{
    z-index:20 !important;
}

/*
 * Legacy CSS branches are disabled.
 * SVG is the only source of child connectors.
 */
.physical-tree .comm-port-roots li:before,
.physical-tree .comm-port-roots li:after,
.physical-tree .comm-port-roots li > ul:before,
.physical-tree .comm-port-roots li > ul:after,
.physical-tree .comm-port-roots li > ul > li:before,
.physical-tree .comm-port-roots li > ul > li:after{
    display:none !important;
    content:none !important;
    border:0 !important;
    background:none !important;
}

.comm-tree-connectors{
    pointer-events:none !important;
    z-index:1 !important;
}
</style>

<style id="comm-physical-wireless-tower-css">

/* Wireless devices inside the REAL physical tree */

.comm-physical-tower-card{
    appearance:none;
    border:0;
    background:transparent;
    cursor:pointer;
    width:145px;
    min-height:185px;
    padding:4px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:flex-start;
    position:relative;
}

.comm-physical-tower-picture{
    display:block;
    width:95px;
    height:137px;
}

.comm-physical-tower-picture .comm-real-tower-svg{
    width:95px!important;
    height:137px!important;
    display:block!important;
}

.comm-physical-tower-info{
    direction:rtl;
    width:145px;
    display:flex;
    flex-direction:column;
    align-items:center;
    text-align:center;
    margin-top:2px;
}

.comm-physical-tower-info b{
    display:block;
    max-width:140px;
    overflow:hidden;
    white-space:nowrap;
    text-overflow:ellipsis;
    font-size:11px;
    color:#0f172a;
}

.comm-physical-tower-info code{
    direction:ltr;
    display:block;
    font-size:9px;
    color:#64748b;
}

.comm-physical-tower-info .role{
    margin-top:2px;
    font-size:8px;
    font-weight:900;
    color:#2563eb;
}

.comm-physical-tower-card.rx .comm-physical-tower-info .role{
    color:#7c3aed;
}

.comm-physical-tower-info .state{
    margin-top:3px;
    font-size:8px;
    color:#16a34a;
}

.comm-physical-tower-info .state i{
    display:inline-block;
    width:6px;
    height:6px;
    border-radius:50%;
    background:#22c55e;
    margin-left:3px;
}

.comm-physical-tower-card.offline{
    opacity:.55;
}

.comm-physical-tower-card.offline .state{
    color:#dc2626;
}

.comm-physical-tower-card.offline .state i{
    background:#ef4444;
}

.comm-physical-tower-card.offline .comm-real-tower-svg{
    filter:grayscale(1);
}


/* =========================================================
   WIRELESS FLOW DIRECTION
   ========================================================= */

.comm-wireless-flow-badge{
    position:relative;
    z-index:8;

    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:4px;

    max-width:180px;

    padding:3px 8px;
    border-radius:999px;

    white-space:nowrap;

    font-size:8px;
    line-height:1.3;
    font-weight:800;

    box-shadow:0 2px 5px rgba(16,24,40,.06);
}

.comm-wireless-flow-badge b{
    font-family:Consolas,monospace;
    font-size:8px;
}

.comm-wireless-flow-badge.incoming{
    color:#5925dc;
    background:#f4f3ff;
    border:1px solid #d9d6fe;
}

.comm-wireless-flow-badge.outgoing{
    color:#175cd3;
    background:#eff4ff;
    border:1px solid #b2ccff;
}

.comm-wireless-flow-badge.incoming i{
    color:#7f56d9;
}

.comm-wireless-flow-badge.outgoing i{
    color:#1570ef;
}

/*
 * Wireless path direction:
 *
 * Parent / TX
 *      - - - - - - ▶
 *                 Child / RX
 */
.comm-tree-wire.wireless{
    stroke:#1570ef !important;
    stroke-width:3 !important;
    stroke-dasharray:10 8 !important;
}

.comm-tree-wire.wireless.offline{
    stroke:#e5221a !important;
}

/*
 * Physical feed:
 * solid = cable / proven physical hierarchy.
 * Arrow always points toward the device being fed.
 */
.comm-tree-wire.physical{
    stroke:#344054 !important;
    stroke-width:1.7 !important;
    fill:none !important;
    stroke-linecap:round !important;
    stroke-linejoin:round !important;
}

.comm-tree-wire.physical.online{
    stroke:#344054 !important;
}

.comm-tree-wire.physical.offline{
    stroke:#e5221a !important;
    stroke-width:2 !important;
}

/*
 * Wireless stays visually distinct:
 * blue dashed TX -> RX.
 */
.comm-tree-wire.wireless{
    stroke:#1570ef !important;
    stroke-width:2.5 !important;
    stroke-dasharray:9 7 !important;
    fill:none !important;
}

.comm-wireless-edge-label{
    fill:#175cd3;
    font-family:Consolas,monospace;
    font-size:10px;
    font-weight:900;

    paint-order:stroke;
    stroke:#fff;
    stroke-width:4px;
    stroke-linejoin:round;

    pointer-events:none;
}

.dashboard-page.dark .comm-wireless-edge-label{
    fill:#84adff;
    stroke:#20232e;
}

.dashboard-page.dark .comm-wireless-flow-badge.incoming{
    background:#2d2745;
}

.dashboard-page.dark .comm-wireless-flow-badge.outgoing{
    background:#172b4d;
}


/* =========================================================
   PHYSICAL TOWER VISUAL POLISH
   Compact readable TX/RX nodes inside the real topology tree
   ========================================================= */

.comm-physical-tower-card{
    width:168px !important;
    min-width:168px !important;
    max-width:168px !important;

    min-height:154px !important;
    padding:7px 8px 8px !important;

    border:1px solid #d0d5dd !important;
    border-radius:13px !important;

    background:#fff !important;

    box-shadow:
        0 4px 12px rgba(16,24,40,.07) !important;

    display:flex !important;
    flex-direction:column !important;
    align-items:center !important;
    justify-content:flex-start !important;

    opacity:1;
}

/* Tower drawing itself */
.comm-physical-tower-picture{
    width:78px !important;
    height:96px !important;
    display:flex !important;
    align-items:flex-end !important;
    justify-content:center !important;
}

.comm-physical-tower-picture .comm-real-tower-svg{
    width:68px !important;
    height:96px !important;
}

/* Information becomes a real readable caption */
.comm-physical-tower-info{
    width:100% !important;
    margin-top:3px !important;
    padding:0 3px !important;
    box-sizing:border-box !important;
}

.comm-physical-tower-info b{
    max-width:100% !important;
    font-size:11px !important;
    line-height:1.3 !important;
    font-weight:900 !important;
    color:#101828 !important;
}

.comm-physical-tower-info code{
    margin-top:2px !important;
    font-size:9px !important;
    line-height:1.2 !important;
    color:#475467 !important;
}

.comm-physical-tower-info .role{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;

    margin-top:5px !important;
    padding:2px 7px !important;

    border-radius:999px !important;

    font-size:8px !important;
    line-height:1.3 !important;
}

/* TX / AP */
.comm-physical-tower-card.tx{
    border-color:#84adff !important;
}

.comm-physical-tower-card.tx .role{
    color:#175cd3 !important;
    background:#eff4ff !important;
}

/* RX / Station */
.comm-physical-tower-card.rx{
    border-color:#bdb4fe !important;
}

/* TX and RX must be recognizable before reading the device name. */
.comm-physical-tower-card.tx{
    border-width:2px !important;
    box-shadow:0 0 0 3px rgba(21,112,239,.08),
               0 4px 12px rgba(16,24,40,.07) !important;
}

.comm-physical-tower-card.rx{
    border-width:2px !important;
    box-shadow:0 0 0 3px rgba(127,86,217,.08),
               0 4px 12px rgba(16,24,40,.07) !important;
}

.comm-physical-tower-card.tx .role:before{
    content:'📡 ';
}

.comm-physical-tower-card.rx .role:before{
    content:'◀ ';
}

.comm-wireless-edge-label{
    font-size:11px !important;
    font-weight:900 !important;
}

.comm-physical-tower-card.rx .role{
    color:#5925dc !important;
    background:#f4f3ff !important;
}

/* Current health */
.comm-physical-tower-info .state{
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:4px !important;

    margin-top:4px !important;

    font-size:8px !important;
    line-height:1.25 !important;
    font-weight:800 !important;
}

/* Do not fade the entire tower when offline.
   Keep text readable and express failure through border/state/icon. */
.comm-physical-tower-card.offline{
    opacity:1 !important;
    border-color:#f97066 !important;
    background:#fffafa !important;
}

.comm-physical-tower-card.offline .comm-real-tower-svg{
    filter:grayscale(.85) !important;
    opacity:.65 !important;
}

/* Upstream outage */
.comm-physical-tower-card.upstream-down{
    border-color:#f79009 !important;
    background:#fffaf2 !important;
}

/* Status contradiction */
.comm-physical-tower-card.status-conflict{
    border-color:#7f56d9 !important;
    background:#fafaff !important;
}

/* Reduce empty visual spacing around tower nodes */
.physical-tree .comm-node-row:has(.comm-physical-tower-card){
    min-width:168px !important;
    gap:3px !important;
}

.physical-tree li:has(> .comm-node-row > .comm-physical-tower-card){
    flex-basis:168px !important;
}

/*
 * Wireless child branches get slightly more vertical breathing room,
 * but not the huge gap from the old tower layout.
 */
.physical-tree li.wireless-link{
    padding-top:30px !important;
}

/* Dark mode */
.dashboard-page.dark .comm-physical-tower-card{
    background:#20232e !important;
    border-color:#475467 !important;
}

.dashboard-page.dark .comm-physical-tower-info b{
    color:#f2f4f7 !important;
}

.dashboard-page.dark .comm-physical-tower-info code{
    color:#98a2b3 !important;
}


/*
 * If TX has a Station child in the physical graph,
 * visually distinguish that branch as wireless.
 */
li[data-tree-item] > .comm-node-row:has(.comm-physical-tower-card.tx) + ul{
    border-color:#2563eb!important;
    border-style:dashed!important;
}

li[data-tree-item] > .comm-node-row:has(.comm-physical-tower-card.tx) + ul > li:before{
    border-color:#2563eb!important;
    border-style:dashed!important;
}

/* Don't make tower nodes tiny during tree scaling */
.comm-node-row:has(.comm-physical-tower-card){
    min-width:150px;
}

@media(max-width:700px){
    .comm-physical-tower-card{
        width:105px;
        min-height:145px;
    }

    .comm-physical-tower-picture{
        width:70px;
        height:105px;
    }

    .comm-physical-tower-picture .comm-real-tower-svg{
        width:70px!important;
        height:105px!important;
    }

    .comm-physical-tower-info{
        width:105px;
    }

    .comm-physical-tower-info b{
        max-width:100px;
        font-size:9px;
    }

    .comm-physical-tower-info code,
    .comm-physical-tower-info .role,
    .comm-physical-tower-info .state{
        font-size:7px;
    }
}

</style>

<style id="wireless-dashed-tree-lines">

/*
 * Wireless links are radio paths, not cables.
 */

.physical-tree li.wireless-link:before,
.physical-tree li.wireless-link:after{
    border-color:#2563eb !important;
    border-style:dashed !important;
}

.physical-tree li.wireless-link > ul:before{
    border-color:#2563eb !important;
    border-style:dashed !important;
}

/*
 * Add small radio indicator near wireless branches
 */
.physical-tree li.wireless-link > .comm-node-row:before{
    content:'📡';
    position:absolute;
    right:-25px;
    top:15px;
    font-size:16px;
}

/* keep normal cables green */
.physical-tree li.physical-link:before,
.physical-tree li.physical-link:after{
    border-color:#12b76a;
    border-style:solid;
}

</style>

<style id="comm-manual-topology-drag-css">
[data-manual-link-device][draggable="true"]{cursor:grab}
[data-manual-link-device].manual-link-dragging{opacity:.45;cursor:grabbing}
[data-manual-link-device].manual-link-drag-target{outline:5px solid rgba(124,58,237,.28)!important;outline-offset:4px;background:#f7f2ff!important;box-shadow:0 0 0 2px #7c3aed,0 12px 28px rgba(52,32,100,.2)!important}
.comm-manual-drag-help{position:sticky;z-index:22;top:54px;display:flex;align-items:center;justify-content:center;gap:8px;width:max-content;margin:0 auto 12px;padding:7px 11px;border:1px solid #cfc1f6;border-radius:999px;color:#5b21b6;background:rgba(248,245,255,.97);font-size:9px;font-weight:900}
.comm-topology-skeleton{display:grid;place-items:center;min-height:100%;padding:32px}
.comm-topology-skeleton-tree{display:grid;justify-items:center;gap:34px;width:min(760px,92%)}
.comm-topology-skeleton-row{display:flex;justify-content:center;gap:22px;width:100%}
.comm-topology-skeleton-node{width:150px;height:72px;border:1px solid #e7e9ee;border-radius:14px;background:linear-gradient(100deg,#f3f4f6 25%,#fafafa 42%,#f3f4f6 60%);background-size:300% 100%;animation:comm-skeleton 1.35s ease-in-out infinite}
.comm-topology-skeleton-node.root{width:220px;height:82px}
@keyframes comm-skeleton{0%{background-position:100% 0}100%{background-position:0 0}}
.dashboard-page.dark .comm-topology-skeleton-node{border-color:#353846;background:linear-gradient(100deg,#282b36 25%,#333744 42%,#282b36 60%);background-size:300% 100%}

.comm-offline-breakdown{
    display:flex;
    gap:10px;
    margin-top:12px;
}

.comm-offline-breakdown button{
    border:1px solid #f0d7d5;
    background:rgba(255,255,255,.65);
    color:#242733;
    border-radius:12px;
    padding:8px 18px;
    font-size:13px;
    font-weight:800;
    cursor:pointer;
    transition:.2s;
}

.comm-offline-breakdown button:hover{
    background:#fff;
    transform:translateY(-2px);
    box-shadow:0 5px 15px rgba(0,0,0,.08);
}

.comm-offline-breakdown b{
    color:#e5221a;
    margin-right:5px;
}


.network-down-btn{
    display:flex!important;
    align-items:center;
    gap:8px;
    border-radius:14px!important;
    padding:9px 16px!important;
    background:#fff!important;
}

.network-down-btn i{
    font-size:16px;
}

.network-down-btn.modem{
    border-color:#ffd1cc!important;
}

.network-down-btn.wireless{
    border-color:#cfe8ff!important;
}

.network-down-btn b{
    font-size:16px;
}

.comm-offline-breakdown{
    display:flex;
    gap:10px;
    margin-top:12px;
}

.comm-offline-breakdown button{
    border:1px solid #f0d7d5;
    background:rgba(255,255,255,.65);
    color:#242733;
    border-radius:12px;
    padding:8px 18px;
    font-size:13px;
    font-weight:800;
    cursor:pointer;
    transition:.2s;
}

.comm-offline-breakdown button:hover{
    background:#fff;
    transform:translateY(-2px);
    box-shadow:0 5px 15px rgba(0,0,0,.08);
}

.comm-offline-breakdown b{
    color:#e5221a;
    margin-right:5px;
}

</style>

<div class="comm-app" id="communicationsApp" data-csrf="<?= comm_e($csrf) ?>" data-can-manage="<?= $canManage ? '1' : '0' ?>">
    <section class="comm-hero"><div><p class="comm-eyebrow">NETWORK OPERATIONS CENTER</p><h1>الاتصالات</h1><p>شجرة البنية التحتية الفعلية المبنية على LLDP وSTP وFDB والعلاقات الموثقة يدويًا.</p></div><div class="comm-actions"><span class="comm-live">قراءة مباشرة</span><?php if ($canManage): ?><button class="comm-btn primary" type="button" data-discover><i class="bi bi-radar"></i> اكتشاف RouterOS</button><?php endif; ?></div></section>
    <section class="comm-readonly"><i class="bi bi-shield-check"></i><div><b>READ-ONLY NETWORK MODE</b><br><span>الوحدة تقرأ بيانات الشبكة فقط ولا تغيّر إعدادات MikroTik أو RADIUS أو المشتركين.</span></div></section>
    <section class="comm-search-wrap"><div class="comm-search comm-panel"><i class="bi bi-search"></i><input type="search" placeholder="ابحث في كل الشبكات بالاسم أو IP أو MAC أو المشترك أو الشركة..." data-global-search autocomplete="off"></div><div class="comm-search-results" data-search-results></div></section>
    <nav class="comm-tabs comm-panel"><button class="comm-tab active" data-view-tab="overview">نظرة عامة</button><button class="comm-tab" data-view-tab="topology">الشجرة</button><button class="comm-tab" data-view-tab="devices">الأجهزة</button><button class="comm-tab" data-view-tab="history">سجل التغييرات</button><button class="comm-tab" data-view-tab="settings">قواعد IP والشبكات</button></nav>
    <section class="comm-view" data-view="overview"><article class="comm-panel comm-live-overview"><div class="comm-overview-totals"><div class="comm-overview-metric"><span class="comm-live-total-label"><i class="bi bi-diagram-3-fill"></i> إجمالي قطع الشبكة</span>
<strong data-device-total>—</strong>
<small data-device-summary>جاري حساب القطع المتصلة والمقطوعة...</small>
<div class="comm-offline-breakdown" style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">

<button type="button" class="network-down-btn modem" data-offline-type="general_modem">
<i class="bi bi-broadcast-pin"></i>
<span>إرسال DOWN</span>
<b data-offline-modems>0</b>
</button>

<button type="button" class="network-down-btn wireless" data-offline-type="wireless">
<i class="bi bi-wifi"></i>
<span>إرسال DOWN</span>
<b data-offline-wireless>0</b>
</button>
</div></div><div class="comm-overview-metric connected"><span class="comm-live-total-label"><i class="bi bi-people-fill"></i> إجمالي المتصلين الآن في جميع الشبكات</span><strong data-live-total>—</strong><small data-live-summary>جاري قراءة الجلسات النشطة...</small></div></div><div class="comm-live-clock" data-live-clock><b data-live-state>تحديث الحالة كل ثانية</b><span data-live-updated>—</span></div></article><div class="comm-network-grid" data-network-grid style="margin-top:13px"><div class="comm-loading"><div><div class="comm-spinner"></div>جاري قراءة الشبكات...</div></div></div></section>
    <section class="comm-view" data-view="topology" hidden>
        <div class="comm-topology-head"><div><h2 data-topology-title>جاري اختيار الشبكة...</h2><p data-topology-description>سيتم فتح أول شبكة تلقائيًا، ويمكنك التبديل بين الشبكات من القائمة.</p></div><div class="comm-actions"><select class="comm-network-switch" data-network-switch aria-label="اختيار الشبكة"><option value="">اختر شبكة...</option></select><button class="comm-btn" data-back-overview><i class="bi bi-grid"></i> كل الشبكات</button><button class="comm-btn" data-refresh-topology><i class="bi bi-arrow-clockwise"></i> تحديث الحالة</button></div></div>
        <div class="comm-topology-warning" data-topology-warning hidden><i class="bi bi-exclamation-triangle"></i><div><b>توبولوجيا مادية متعددة المكونات</b><span data-topology-warning-text></span></div></div><div class="comm-summary" data-summary></div>
        <article class="comm-panel"><div class="comm-toolbar"><div class="comm-tools"><div class="comm-tree-search"><i class="bi bi-search"></i><input type="search" data-tree-search placeholder="ابحث بالاسم أو IP أو MAC..." autocomplete="off"><span data-tree-search-count></span></div><button class="comm-btn" data-expand-all><i class="bi bi-arrows-expand"></i> فتح الكل</button><button class="comm-btn" data-collapse-all><i class="bi bi-arrows-collapse"></i> طي الكل</button><button class="comm-tool-icon" title="تكبير" data-tool="zoom-in"><i class="bi bi-zoom-in"></i></button><button class="comm-tool-icon" title="تصغير" data-tool="zoom-out"><i class="bi bi-zoom-out"></i></button><button class="comm-tool-icon" title="الحجم الطبيعي" data-tool="fit"><i class="bi bi-arrows-fullscreen"></i></button><button class="comm-tool-icon" title="ملء الشاشة" data-tool="fullscreen"><i class="bi bi-fullscreen"></i></button><button class="comm-btn" data-tool="upstream">المسار للأعلى</button><button class="comm-btn" data-tool="downstream">الفرع للأسفل</button></div><div class="comm-filters" data-filters><button class="comm-filter active" data-filter="infrastructure">كل البنية التحتية</button><button class="comm-filter" data-filter="online">متصل</button><button class="comm-filter" data-filter="offline">غير متصل</button><button class="comm-filter" data-filter="degraded">متدهور</button><button class="comm-filter" data-filter="verified">روابط موثقة فقط</button><button class="comm-filter" data-filter="manual">روابط يدوية فقط</button></div></div><div class="comm-canvas-wrap"><div class="comm-canvas" data-canvas><div class="comm-topology-skeleton" aria-label="جاري تحميل التوبولوجيا"><div class="comm-topology-skeleton-tree"><span class="comm-topology-skeleton-node root"></span><div class="comm-topology-skeleton-row"><span class="comm-topology-skeleton-node"></span><span class="comm-topology-skeleton-node"></span><span class="comm-topology-skeleton-node"></span></div></div></div></div><div class="comm-legend"><span><i style="background:#12b76a"></i> متصل</span><span><i style="background:#e5221a"></i> غير متصل</span><span><i style="background:#667085"></i> غير معروف</span><span>تظهر فقط روابط البنية الفعلية النشطة</span></div></div></article>
    </section>
    <section class="comm-view" data-view="devices" hidden><article class="comm-panel"><div class="comm-settings-head"><h2>كل الأجهزة المصنفة في الشبكات</h2><button class="comm-btn" data-reload-devices><i class="bi bi-arrow-clockwise"></i> تحديث</button></div><div class="comm-device-controls"><input type="search" data-device-search placeholder="ابحث بالاسم أو IP أو MAC..." autocomplete="off"><select data-device-network><option value="0">كل الشبكات</option></select><select data-device-category><option value="">كل الفئات</option><option value="general_modem">المودمات العامة</option><option value="wireless">أجهزة الإرسال والاستقبال</option><option value="subscriber">المشتركون</option></select></div><div data-devices-table class="comm-table-wrap"></div><div class="comm-device-pagination"><button class="comm-btn" data-device-prev>السابق</button><span data-device-page></span><button class="comm-btn" data-device-next>التالي</button></div></article></section>
    <section class="comm-view" data-view="history" hidden><article class="comm-panel"><div class="comm-settings-head"><h2>سجل تغييرات التوبولوجيا</h2><button class="comm-btn" data-reload-history><i class="bi bi-arrow-clockwise"></i> تحديث</button></div><div data-history-table class="comm-table-wrap"></div></article></section>
    <section class="comm-view" data-view="settings" hidden><div class="comm-settings-grid">
        <article class="comm-panel"><div class="comm-settings-head"><h2>الشبكات</h2><?php if ($canManage): ?><button class="comm-btn primary" data-new-network><i class="bi bi-plus"></i> إضافة شبكة</button><?php endif; ?></div><div data-networks-table class="comm-table-wrap"></div><?php if ($canManage): ?><form class="comm-form comm-settings-form" data-network-form hidden><input type="hidden" name="action" value="save_network"><input type="hidden" name="csrf_token" value="<?= comm_e($csrf) ?>"><input type="hidden" name="id" value="0"><div class="comm-form-grid"><label class="comm-field"><span>اسم الشبكة</span><input name="name" required maxlength="120"></label><label class="comm-field"><span>المعرّف</span><input name="network_key" required pattern="[a-z0-9_-]+" dir="ltr"></label><label class="comm-field"><span>الترتيب</span><input name="display_order" type="number" min="0" value="10"></label><label class="comm-field"><span>الحالة</span><select name="enabled"><option value="1">مفعلة</option><option value="">معطلة</option></select></label><label class="comm-field full"><span>الوصف</span><input name="description" maxlength="255"></label></div><div class="comm-actions"><button class="comm-btn primary" type="submit">حفظ الشبكة</button><button class="comm-btn" type="button" data-cancel-network>إلغاء</button></div></form><?php endif; ?></article>
        <article class="comm-panel"><div class="comm-settings-head"><h2>Network IP Rules</h2><?php if ($canManage): ?><button class="comm-btn primary" data-new-rule><i class="bi bi-plus"></i> إضافة قاعدة</button><?php endif; ?></div><div data-rules-table class="comm-table-wrap"></div><?php if ($canManage): ?><form class="comm-form comm-settings-form" data-rule-form hidden><input type="hidden" name="action" value="save_rule"><input type="hidden" name="csrf_token" value="<?= comm_e($csrf) ?>"><input type="hidden" name="id" value="0"><div class="comm-form-grid"><label class="comm-field"><span>الشبكة</span><select name="network_id" required data-rule-network></select></label><label class="comm-field"><span>الفئة</span><select name="device_category"><option value="general_modem">مودم / بنية عامة</option><option value="wireless">جهاز استقبال وإرسال</option><option value="subscriber">مشترك</option><option value="unknown">غير مصنف</option></select></label><label class="comm-field"><span>Subnet CIDR</span><input name="subnet" required placeholder="33.33.1.0/24" dir="ltr"></label><label class="comm-field"><span>الأولوية</span><input name="priority" type="number" min="1" value="100"></label><label class="comm-field full"><span>الدور المتوقع</span><input name="expected_role" maxlength="120"></label><label class="comm-field"><span>الحالة</span><select name="enabled"><option value="1">مفعلة</option><option value="">معطلة</option></select></label></div><div class="comm-actions"><button class="comm-btn primary" type="submit">حفظ القاعدة</button><button class="comm-btn" type="button" data-cancel-rule>إلغاء</button></div></form><?php endif; ?></article>
    </div></section>
</div>
<aside class="comm-drawer" id="communicationDeviceDrawer" aria-hidden="true"><header class="comm-drawer-head"><div><h3 data-detail-name>تفاصيل الجهاز</h3><p data-detail-ip>—</p></div><button class="comm-tool-icon" data-close-drawer><i class="bi bi-x-lg"></i></button></header><div class="comm-drawer-body" data-detail-body></div></aside><div class="comm-toast" id="communicationToast"></div>
<script>
(function(){
const root=document.getElementById('communicationsApp');if(!root||root.dataset.booted==='1')return;root.dataset.booted='1';
const api='nawa-communications-api.php',csrf=root.dataset.csrf,canManage=root.dataset.canManage==='1';
let overview=[],settings={networks:[],rules:[]},topology=null,networkMeta=null,graphNetwork=null,nodeSet=null,edgeSet=null,selectedId=null,activeFilter='infrastructure',expandSubscribers=true,showUnlinked=false,collapsedBranches=new Set(),initializedNetworks=new Set(),searchTimer=null,deviceSearchTimer=null,devicePage=1,devicePages=1,liveRequestBusy=false,overviewRequestBusy=false,topologyRequestBusy=false,topologyStatusBusy=false,topologyWatchBusy=false,pollCycleBusy=false,treeScale=1;
let overviewSignature='',topologyStatusSignature='',topologyRequestController=null,topologyRequestToken=0,lastTopologyWatchAt=0;
const overviewCacheKey='nawa-communications-overview-v3';
const topologyCacheKey='nawa-communications-topology-v2';
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
const fmt=v=>new Intl.NumberFormat('en-US').format(Number(v||0));
const categoryLabels={general_modem:'مودم / بنية عامة',wireless:'جهاز استقبال وإرسال',subscriber:'مشترك',unknown:'غير مصنف'};
const statusLabels={online:'متصل',offline:'غير متصل',disabled:'معطّل في Netwatch',degraded:'متدهور',unreachable_parent:'متعذر عبر الأب',unknown:'غير معروف'};
const confidenceLabels={verified:'موثقة',high:'عالية',medium:'متوسطة',low:'منخفضة'};
const eventLabels={device_discovered:'اكتشاف جهاز',ip_changed:'تغير IP',parent_changed:'تغير الجهاز الأب',network_changed:'تعديل الشبكة',network_created:'إضافة شبكة',device_classification_changed:'تغيير التصنيف',link_offline:'انقطاع الرابط',link_restored:'عودة الرابط',ip_rule_created:'إضافة قاعدة IP',ip_rule_changed:'تعديل قاعدة IP',ip_rule_deleted:'حذف قاعدة IP',ip_rule_toggled:'تغيير حالة قاعدة',discovery_completed:'اكتمال الاكتشاف'};
function toast(message,error=false){const el=document.getElementById('communicationToast');el.textContent=message;el.className='comm-toast show'+(error?' error':'');clearTimeout(el._timer);el._timer=setTimeout(()=>el.className='comm-toast',3800)}
async function get(action,params={},options={}){const u=new URL(api,location.href);u.searchParams.set('action',action);Object.entries(params).forEach(([k,v])=>u.searchParams.set(k,v));const r=await fetch(u,{credentials:'same-origin',cache:'no-store',signal:options.signal});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||('HTTP '+r.status));return j.data}
async function post(action,data={}){const body=new FormData();body.set('action',action);body.set('csrf_token',csrf);Object.entries(data).forEach(([k,v])=>body.set(k,v));const r=await fetch(api,{method:'POST',body,credentials:'same-origin',cache:'no-store'});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||('HTTP '+r.status));if(j.message)toast(j.message);return j.data}
function cacheRead(key){try{return JSON.parse(localStorage.getItem(key)||'null')}catch(_){return null}}
function cacheWrite(key,value){const save=()=>{try{localStorage.setItem(key,JSON.stringify(value))}catch(_){}};if('requestIdleCallback'in window)requestIdleCallback(save,{timeout:1500});else setTimeout(save,0)}
function topologySkeleton(){return '<div class="comm-topology-skeleton" aria-label="جاري تحميل التوبولوجيا"><div class="comm-topology-skeleton-tree"><span class="comm-topology-skeleton-node root"></span><div class="comm-topology-skeleton-row"><span class="comm-topology-skeleton-node"></span><span class="comm-topology-skeleton-node"></span><span class="comm-topology-skeleton-node"></span></div></div></div>'}
function cachedTopology(networkId=0){const cached=cacheRead(topologyCacheKey);if(!cached?.data?.network?.id)return null;if(networkId>0&&Number(cached.data.network.id)!==Number(networkId))return null;return cached.data}
function networkOverviewStats(n){
    const managed=/^network[1-6]$/.test(String(n.network_key||''));

    if(managed){
        return `
            <div class="comm-network-stats">
                <span>
                    <small>مودمات عامة</small>
                    <b>${fmt(n.manual_general_modems)}</b>
                </span>

                <span>
                    <small>إرسال واستقبال</small>
                    <b>${fmt(n.manual_wireless_devices)}</b>
                </span>

                <span>
                    <small>المشتركين</small>
                    <b>${fmt(n.manual_subscribers)}</b>
                </span>
            </div>
        `;
    }

    return `
        <div class="comm-network-stats">
            <span>
                <small>أجهزة البنية</small>
                <b>${fmt(n.general_modems)}</b>
            </span>

            <span>
                <small>الروابط المادية</small>
                <b>${fmt(n.physical_links)}</b>
            </span>

            <span>
                <small>متصل / غير متصل</small>
                <b>${fmt(n.infrastructure_online)} / ${fmt(n.infrastructure_offline)}</b>
            </span>
        </div>
    `;
}

function renderOverviewData(networks){
    const nextSignature=JSON.stringify(networks||[]);
    if(nextSignature===overviewSignature)return;
    overviewSignature=nextSignature;
    overview=networks||[];
    const totals=overview.reduce((a,n)=>{
        a.total+=Number(n.general_modems||0)+Number(n.wireless_devices||0);
        a.online+=(Number(n.general_modems||0)-Number(n.offline_general_modems||0))+(Number(n.wireless_devices||0)-Number(n.offline_wireless||0));
        a.offline+=Number(n.offline_general_modems||0)+Number(n.offline_wireless||0);
        a.wireless+=Number(n.offline_wireless||0);
        a.modems+=Number(n.offline_general_modems||0);
        return a;
    },{total:0,online:0,offline:0,wireless:0,modems:0});
    root.querySelector('[data-device-total]').textContent=fmt(totals.total);
    root.querySelector('[data-device-summary]').textContent=`${fmt(totals.online)} متصل • ${fmt(totals.offline)} مقطوع`;

    const offModems=root.querySelector('[data-offline-modems]');
    const offWireless=root.querySelector('[data-offline-wireless]');
    if(offModems) offModems.textContent=fmt(totals.modems);
    if(offWireless) offWireless.textContent=fmt(totals.wireless);
    const networkSwitch=root.querySelector('[data-network-switch]');
    networkSwitch.innerHTML='<option value="">اختر شبكة...</option>'+overview.map(n=>`<option value="${Number(n.id)}">${esc(n.name)}</option>`).join('');
    const deviceNetwork=root.querySelector('[data-device-network]');
    if(deviceNetwork){const selected=deviceNetwork.value;deviceNetwork.innerHTML='<option value="0">كل الشبكات</option>'+overview.map(n=>`<option value="${Number(n.id)}">${esc(n.name)}</option>`).join('');deviceNetwork.value=selected||'0'}
    if(topology)networkSwitch.value=String(topology.network.id);
    const grid=root.querySelector('[data-network-grid]');
    grid.innerHTML=overview.length?overview.map(n=>{const h=health(n);return`<button class="comm-network-card" data-open-network="${Number(n.id)}" data-network-card-id="${Number(n.id)}"><div class="comm-network-head"><span class="comm-network-icon"><i class="bi bi-diagram-3"></i></span><span class="comm-health ${/^network[1-6]$/.test(String(n.network_key||''))?'healthy':h}">${/^network[1-6]$/.test(String(n.network_key||''))?'إجمالي الأجهزة: '+fmt(Number(n.manual_general_modems||0)+Number(n.manual_wireless_devices||0)+Number(n.manual_subscribers||0)):healthLabel(h)}</span></div><h3>${esc(n.name)}</h3><p>${fmt(n.physical_roots)} جذور/مكونات فعلية</p>${networkOverviewStats(n)}</button>`}).join(''):'<div class="comm-empty">لا توجد شبكات حتى الآن.</div>';
    grid.querySelectorAll('[data-open-network]').forEach(b=>b.onclick=()=>openNetwork(Number(b.dataset.openNetwork)));
}
async function loadOverviewFast(){
    if(overviewRequestBusy||!root.isConnected)return;
    overviewRequestBusy=true;
    try{const d=await get('overview');renderOverviewData(d.networks||[]);cacheWrite(overviewCacheKey,{saved_at:Date.now(),networks:d.networks||[]})}
    catch(e){if(!overview.length)root.querySelector('[data-network-grid]').innerHTML=`<div class="comm-empty">${esc(e.message)}</div>`}
    finally{overviewRequestBusy=false}
}
function applyTopologyData(data,focusId=null,persist=false){
    /*
     * Preserve the operator's visual anchor during automatic refresh.
     *
     * Raw scrollTop/scrollLeft are unreliable because topology dimensions
     * can change after a refresh. Instead, remember a visible device near
     * the viewport center and restore that device to the same screen point.
     */
    const oldViewport=root.querySelector('.comm-tree-viewport');

    let anchor=null;

    if(oldViewport){
        const viewportRect=oldViewport.getBoundingClientRect();
        const centerX=viewportRect.left+(viewportRect.width/2);
        const centerY=viewportRect.top+(viewportRect.height/2);

        const candidates=[
            ...oldViewport.querySelectorAll('[data-tree-node]')
        ].map(el=>{
            const r=el.getBoundingClientRect();

            const visible=(
                r.right>=viewportRect.left &&
                r.left<=viewportRect.right &&
                r.bottom>=viewportRect.top &&
                r.top<=viewportRect.bottom
            );

            if(!visible)return null;

            const x=r.left+(r.width/2);
            const y=r.top+(r.height/2);

            return {
                id:String(el.dataset.deviceId||''),
                dx:x-centerX,
                dy:y-centerY,
                distance:Math.hypot(x-centerX,y-centerY)
            };
        }).filter(Boolean).sort((a,b)=>a.distance-b.distance);

        if(candidates.length){
            anchor=candidates[0];
        }
    }

    const savedScale=Number(treeScale)||1;

    /*
     * Auto-fit is allowed when opening another network,
     * but NEVER during a refresh of the same network.
     */
    const previousNetworkId=Number(topology?.network?.id||0);
    const incomingNetworkId=Number(data?.network?.id||0);

    const preserveZoomOnRender=Boolean(
        oldViewport &&
        previousNetworkId>0 &&
        incomingNetworkId>0 &&
        previousNetworkId===incomingNetworkId
    );

    const canvasBeforeRender=root.querySelector('[data-canvas]');

    if(canvasBeforeRender){
        if(preserveZoomOnRender)
            canvasBeforeRender.dataset.preserveTreeScale='1';
        else
            delete canvasBeforeRender.dataset.preserveTreeScale;
    }

    topology=data;
    networkMeta=data.network;
    topologyStatusSignature=String(data.status_signature||'');
    showUnlinked=false;

    const networkSwitch=root.querySelector('[data-network-switch]');
    if(networkSwitch)networkSwitch.value=String(networkMeta.id);

    root.querySelector('[data-topology-title]').textContent=networkMeta.name;
    root.querySelector('[data-topology-description]').textContent='الروابط المادية تبقى محصورة في LLDP وSTP وFDB والتوثيق اليدوي؛ الأجهزة المرصودة والارتباطات اللاسلكية وغير المعيّنة معروضة كمجموعات منفصلة.';

    renderSummary();

    /*
     * Automatic refresh must not reset zoom.
     * Initial network opening can still start at normal scale.
     */
    treeScale=savedScale;

    renderFallbackTree();

    requestAnimationFrame(()=>{
        requestAnimationFrame(()=>{
            if(!anchor||!anchor.id)return;

            const viewport=root.querySelector('.comm-tree-viewport');
            const target=root.querySelector(
                `[data-device-id="${CSS.escape(anchor.id)}"]`
            );

            if(!viewport||!target)return;

            const vr=viewport.getBoundingClientRect();
            const tr=target.getBoundingClientRect();

            const currentDx=(tr.left+(tr.width/2))-(vr.left+(vr.width/2));
            const currentDy=(tr.top+(tr.height/2))-(vr.top+(vr.height/2));

            viewport.scrollLeft += currentDx-anchor.dx;
            viewport.scrollTop  += currentDy-anchor.dy;
        });
    });

    if(persist)cacheWrite(topologyCacheKey,{saved_at:Date.now(),data});

    if(focusId)focusDevice(focusId);
}
async function openNetworkFast(id,focusId=null,options={}){
    id=Number(id||0);if(id<=0)return;
    showView('topology');
    const networkSwitch=root.querySelector('[data-network-switch]');
    if(networkSwitch)networkSwitch.value=String(id);
    let immediate=topology&&Number(topology.network?.id)===id?topology:cachedTopology(id);
    if(immediate){applyTopologyData(immediate,focusId,false)}
    else{
        root.querySelector('[data-topology-title]').textContent='جاري تحميل الشبكة...';
        root.querySelector('[data-canvas]').innerHTML=topologySkeleton();
        root.querySelector('[data-summary]').innerHTML='';
    }
    if(topologyRequestController)topologyRequestController.abort();
    const controller=new AbortController();
    topologyRequestController=controller;
    topologyRequestBusy=true;
    const token=++topologyRequestToken;
    try{
        const knownRevision=options.force?'':String(immediate?.revision||'');
        const fresh=await get('topology',{network_id:id,revision:knownRevision},{signal:controller.signal});
        if(token!==topologyRequestToken||controller.signal.aborted)return;
        if(fresh.not_modified)return;
        applyTopologyData(fresh,focusId,true);
    }catch(e){
        if(e.name==='AbortError')return;
        if(!immediate){const canvas=root.querySelector('[data-canvas]');canvas.innerHTML=`<div class="comm-empty">تعذر تحميل الشجرة: ${esc(e.message)}<br><button class="comm-btn" data-retry-topology>إعادة المحاولة</button></div>`;canvas.querySelector('[data-retry-topology]')?.addEventListener('click',()=>openNetworkFast(id,focusId,{force:true}));toast(e.message,true)}
        else console.warn('Topology background refresh:',e);
    }finally{
        if(token===topologyRequestToken){topologyRequestBusy=false;topologyRequestController=null}
    }
}
function showView(name){root.querySelectorAll('[data-view]').forEach(v=>v.hidden=v.dataset.view!==name);root.querySelectorAll('[data-view-tab]').forEach(b=>b.classList.toggle('active',b.dataset.viewTab===name));if(name==='settings')loadSettings();if(name==='history')loadHistory();if(name==='devices')loadDevices()}
root.querySelectorAll('[data-view-tab]').forEach(b=>b.onclick=()=>{const name=b.dataset.viewTab;if(name==='topology'){const id=Number(topology?.network?.id||cachedTopology()?.network?.id||overview[0]?.id||0);if(id>0){openNetwork(id);return}}showView(name)});root.querySelector('[data-back-overview]').onclick=()=>showView('overview');root.querySelector('[data-network-switch]').onchange=e=>{const id=Number(e.target.value||0);if(id>0)openNetwork(id)};
function health(n){if(Number(n.infrastructure_offline)>0||Number(n.infrastructure_degraded)>0)return'degraded';if(Number(n.infrastructure_online)>0)return'healthy';return'unknown'}
function healthLabel(h){return{healthy:'سليمة',degraded:'متدهورة',critical:'حرجة',unknown:'غير معروفة'}[h]}
async function loadOverview(){const grid=root.querySelector('[data-network-grid]');try{const d=await get('overview');overview=d.networks||[];const totals=overview.reduce((a,n)=>{
    a.total+=Number(n.general_modems||0)+Number(n.wireless_devices||0);
    a.online+=Number(n.general_modems||0)-Number(n.offline_general_modems||0)+Number(n.wireless_devices||0)-Number(n.offline_wireless||0);
    a.offline+=Number(n.offline_general_modems||0)+Number(n.offline_wireless||0);
    a.modems+=Number(n.offline_general_modems||0);
    a.wireless+=Number(n.offline_wireless||0);
    return a;
},{total:0,online:0,offline:0,modems:0,wireless:0});

root.querySelector('[data-device-total]').textContent=fmt(totals.total);
root.querySelector('[data-device-summary]').textContent=`${fmt(totals.online)} متصل • ${fmt(totals.offline)} مقطوع`;

const offModems=root.querySelector('[data-offline-modems]');
const offWireless=root.querySelector('[data-offline-wireless]');
if(offModems) offModems.textContent=fmt(totals.modems);
if(offWireless) offWireless.textContent=fmt(totals.wireless);const networkSwitch=root.querySelector('[data-network-switch]');networkSwitch.innerHTML='<option value="">اختر شبكة...</option>'+overview.map(n=>`<option value="${Number(n.id)}">${esc(n.name)}</option>`).join('');const deviceNetwork=root.querySelector('[data-device-network]');if(deviceNetwork){const selected=deviceNetwork.value;deviceNetwork.innerHTML='<option value="0">كل الشبكات</option>'+overview.map(n=>`<option value="${Number(n.id)}">${esc(n.name)}</option>`).join('');deviceNetwork.value=selected||'0'}if(topology)networkSwitch.value=String(topology.network.id);grid.innerHTML=overview.length?overview.map(n=>{const h=health(n);return`<button class="comm-network-card" data-open-network="${Number(n.id)}" data-network-card-id="${Number(n.id)}"><div class="comm-network-head"><span class="comm-network-icon"><i class="bi bi-diagram-3"></i></span><span class="comm-health ${/^network[1-6]$/.test(String(n.network_key||''))?'healthy':h}">${/^network[1-6]$/.test(String(n.network_key||''))?'إجمالي الأجهزة: '+fmt(Number(n.manual_general_modems||0)+Number(n.manual_wireless_devices||0)+Number(n.manual_subscribers||0)):healthLabel(h)}</span></div><h3>${esc(n.name)}</h3><p>${fmt(n.physical_roots)} جذور/مكونات فعلية</p>${networkOverviewStats(n)}</button>`}).join(''):'<div class="comm-empty">لا توجد شبكات حتى الآن.</div>';grid.querySelectorAll('[data-open-network]').forEach(b=>b.onclick=()=>openNetwork(Number(b.dataset.openNetwork)));loadLiveStatus();const topologyVisible=!root.querySelector('[data-view="topology"]').hidden;if(topologyVisible&&!topology&&overview.length)openNetwork(Number(overview[0].id))}catch(e){grid.innerHTML=`<div class="comm-empty">${esc(e.message)}</div>`}}
async function loadLiveStatus(){if(liveRequestBusy||!root.isConnected)return;liveRequestBusy=true;const clock=root.querySelector('[data-live-clock]');try{const d=await get('live_status',{_t:Date.now()});root.querySelector('[data-live-total]').textContent=fmt(d.total_connected);root.querySelector('[data-live-summary]').textContent=`${fmt(d.live_subscribers)} مشترك + ${fmt(d.live_cards)} كرت • كل حساب يُحسب مرة واحدة`;root.querySelector('[data-live-state]').textContent='متصل • تحديث لحظي كل ثانيتين';root.querySelector('[data-live-updated]').textContent=new Date().toLocaleTimeString('ar-YE');clock?.classList.remove('error')}catch(e){root.querySelector('[data-live-state]').textContent='تعذر التحديث اللحظي';clock?.classList.add('error')}finally{liveRequestBusy=false}}
async function openNetwork(id,focusId=null){showView('topology');root.querySelector('[data-topology-title]').textContent='جاري تحميل الشبكة...';const canvas=root.querySelector('[data-canvas]');canvas.innerHTML='<div class="comm-loading"><div><div class="comm-spinner"></div>جاري تحميل التوبولوجيا المادية...</div></div>';const networkSwitch=root.querySelector('[data-network-switch]');if(networkSwitch)networkSwitch.value=String(id);try{topology=await get('topology',{network_id:id});networkMeta=topology.network;showUnlinked=false;if(networkSwitch)networkSwitch.value=String(id);root.querySelector('[data-topology-title]').textContent=networkMeta.name;root.querySelector('[data-topology-description]').textContent='الروابط المادية تبقى محصورة في LLDP وSTP وFDB والتوثيق اليدوي؛ الأجهزة المرصودة والارتباطات اللاسلكية وغير المعيّنة معروضة كمجموعات منفصلة.';renderSummary();treeScale=1;renderFallbackTree();if(focusId)focusDevice(focusId)}catch(e){canvas.innerHTML=`<div class="comm-empty">تعذر تحميل الشجرة: ${esc(e.message)}<br><button class="comm-btn" data-retry-topology>إعادة المحاولة</button></div>`;canvas.querySelector('[data-retry-topology]')?.addEventListener('click',()=>openNetwork(id,focusId));toast(e.message,true)}}
function renderSummary(){
    const s=topology.summary||{};
    const warning=root.querySelector('[data-topology-warning]');

    if(warning){
        warning.hidden=true;
    }

    const items=[
        ['كل الأجهزة',fmt(s.total_discovered_devices??s.infrastructure_devices)],
        ['أجهزة البنية',fmt(s.infrastructure_devices)],
        ['مودمات المشتركين',fmt(s.subscriber_devices)],
        ['الروابط المادية',fmt(s.physical_links)],
        ['الجذور المادية',fmt(s.physical_roots??s.component_count)],
        ['مرصود خلف المنافذ',fmt(s.observed_unresolved_devices)],
        ['غير محدد الموقع',fmt(s.completely_unassigned_devices)]
    ];

    root.querySelector('[data-summary]').innerHTML=
        items.map(x=>`<article><span>${x[0]}</span><strong>${x[1]}</strong></article>`).join('');
}
let visLoader=null;function ensureVis(){if(window.vis&&window.vis.Network)return Promise.resolve();if(visLoader)return visLoader;visLoader=new Promise((resolve,reject)=>{const script=document.createElement('script');script.src=new URL('static/js/vis-network.min.js?v=20260818',location.href).href;script.async=true;script.dataset.communicationsVis='1';script.onload=()=>window.vis&&window.vis.Network?resolve():reject(new Error('ملف محرك الشجرة غير صالح.'));script.onerror=()=>reject(new Error('تعذر تحميل محرك رسم الشبكة.'));document.head.appendChild(script)});return visLoader}
function statusColor(s){return{online:'#12b76a',offline:'#e5221a',disabled:'#667085',degraded:'#f79009',unreachable_parent:'#344054',unknown:'#98a2b3'}[s]||'#98a2b3'}
function icon(c){return{general_modem:'📡',wireless:'📡',subscriber:'👤',unknown:'❔'}[c]||'❔'}
function categoryBackground(c){return{general_modem:'#fff5f4',wireless:'#eff8ff',subscriber:'#f0fdf4',unknown:'#f2f4f7'}[c]||'#fff'}
function linkSourceLabel(source,manual=false){source=String(source||'');if(manual||source==='manual_verified')return'يدوي';if(source==='openwrt_lldp')return'LLDP + STP';if(source==='openwrt_stp')return'STP';if(source==='openwrt_bridge_fdb')return'FDB';if(source==='ubiquiti_mca_confirmed')return'رابط لاسلكي مؤكد';return'دليل مادي'}
function filtered(d){if(activeFilter==='infrastructure')return true;if(activeFilter==='online')return d.status==='online';if(activeFilter==='offline')return['offline','disabled','unreachable_parent'].includes(d.status);if(activeFilter==='degraded')return d.status==='degraded';if(activeFilter==='verified')return d.link_manual==1||d.link_confidence==='verified';if(activeFilter==='manual')return d.link_manual==1;return true}
function descendants(id,links){const out=new Set(),q=[String(id)];while(q.length){const p=q.shift();links.filter(l=>String(l.parent_device_id)===p).forEach(l=>{const c=String(l.child_device_id);if(!out.has(c)){out.add(c);q.push(c)}})}return out}
function renderFallbackTree(){
if(!topology)return;graphNetwork=null;

const canvas=root.querySelector('[data-canvas]');
const allDevices=topology.devices||[];
const allLinks=topology.links||[];
const gateway=topology.gateway||null;
const rootUplinks=topology.root_uplinks||{};
const observedBehindPorts=topology.observed_behind_ports||{};
const associationLinks=topology.association_links||[];
const unresolvedDevices=topology.unresolved_devices||[];

const allById=new Map(allDevices.map(d=>[String(d.id),d]));
const children=new Map();
const parentMap=new Map();
const linkByChild=new Map();

canvas.classList.add('fallback-mode');

allLinks.forEach(l=>{
    const p=String(l.parent_device_id),c=String(l.child_device_id);
    if(!allById.has(p)||!allById.has(c)||p===c)return;
    if(!children.has(p))children.set(p,[]);
    children.get(p).push(c);
    parentMap.set(c,p);
    linkByChild.set(c,l);
});

children.forEach(ids=>ids.sort((a,b)=>
    String(allById.get(a)?.device_name||'')
    .localeCompare(String(allById.get(b)?.device_name||''),'ar')
));

/* Only devices that actually participate in the proven physical graph. */
const physicalIds=new Set();
allLinks.forEach(l=>{
    physicalIds.add(String(l.parent_device_id));
    physicalIds.add(String(l.child_device_id));
});

const matches=new Set(
    allDevices
        .filter(d=>physicalIds.has(String(d.id)) && filtered(d))
        .map(d=>String(d.id))
);

const visible=new Set(matches);

/* Preserve ancestors when filtering/searching. */
matches.forEach(startId=>{
    let id=startId,guard=0;
    while(parentMap.has(id)&&guard++<500){
        id=parentMap.get(id);
        visible.add(id);
    }
});

/* Keep complete physical branches in normal infrastructure view. */
if(activeFilter==='infrastructure'){
    physicalIds.forEach(id=>visible.add(id));
}

const devices=allDevices.filter(d=>visible.has(String(d.id)));
const byId=new Map(devices.map(d=>[String(d.id),d]));

/*
 * A visual root is a root of the proven physical graph.
 * Do NOT include every general_modem that simply has no parent.
 */
let physicalRoots=[...physicalIds].filter(id=>
    byId.has(id) && !parentMap.has(id)
);

/* Never duplicate the MikroTik gateway as an OpenWrt root. */
if(gateway?.id){
    physicalRoots=physicalRoots.filter(id=>id!==String(gateway.id));
}

const networkKey=String(networkMeta?.id||0);
if(!initializedNetworks.has(networkKey)){
    const depthQueue=physicalRoots.map(id=>[id,0]);
    const walked=new Set();

    while(depthQueue.length){
        const [id,depth]=depthQueue.shift();
        if(walked.has(id))continue;
        walked.add(id);

        const kids=(children.get(id)||[]).filter(k=>byId.has(k));

        if(depth>=4&&kids.length)
            collapsedBranches.add(id);

        kids.forEach(k=>depthQueue.push([k,depth+1]));
    }

    initializedNetworks.add(networkKey);
}

function cleanTopologyName(value){
    const raw=String(value||'').trim();
    if(!raw.includes('|'))return raw;

    const parts=raw.split('|')
        .map(part=>part.trim())
        .filter(Boolean)
        .filter(part=>!/^(?:ether|lan|wan|br|eth)\d*(?:[._-]\d+)?$/i.test(part))
        .filter(part=>!/^[PNL]$/i.test(part));

    return parts[0]||raw;
}

function card(d){
    const id=String(d.id);
    const rawName=d.device_name||'جهاز غير معروف';
    const name=cleanTopologyName(rawName)||rawName;
    const ip=d.ip_address||'بدون IP';
    const mac=d.mac_address||'';

    /*
     * Wireless devices are now part of the proven physical graph.
     * Therefore they reach card() directly instead of observedPortDevice().
     * Render them here as real towers.
     */
    if(
        String(d?.device_category||'')==='wireless' ||
        String(d?.ip_address||'').startsWith('11.10.')
    ){

        const role=towerRole(d);
        const pathStatus=String(d.path_status||'');
        const affected=Number(d.affected_descendants||0);

        let visualState='online';
        let pathLabel='متصل';

        if(pathStatus==='self_down'){
            visualState='offline';
            pathLabel=affected>0
                ? `سبب العطل • متأثر ${fmt(affected)} جهاز`
                : 'عطل برج منفرد';
        }else if(pathStatus==='upstream_down'){
            visualState='upstream-down';
            pathLabel=d.blocked_by_ip
                ? `متأثر بسبب ${esc(d.blocked_by_ip)}`
                : 'متأثر بعطل في المسار';
        }else if(pathStatus==='status_conflict'){
            visualState='status-conflict';
            pathLabel='تعارض حالة المسار';
        }else if(pathStatus==='upstream_unknown'){
            visualState='status-conflict';
            pathLabel='المسار غير محسوم';
        }else{
            const working=['online','degraded'].includes(String(d.status||''));
            visualState=working?'online':'offline';
            pathLabel=working?'متصل':'غير متصل';
        }

        const cleanName=String(name)
            .replace(/^ether\d+\s*\|\s*/i,'')
            .replace(/\s*\|\s*[PNL]\s*\|.*$/i,'')
            .trim() || name;

        return `<button
            class="comm-physical-tower-card ${visualState} ${role||'tower'}"
            data-tree-node
            data-device-id="${Number(d.id)}"
            draggable="${canManage?'true':'false'}">

            <span class="comm-physical-tower-picture">
                ${realisticTowerSvg()}
            </span>

            <span class="comm-physical-tower-info">
                <b>${esc(cleanName)}</b>
                <code>${esc(ip)}</code>

                <small class="role">
                    ${
                        role==='tx'
                            ? 'برج إرسال • AP'
                            : (
                                role==='rx'
                                    ? 'برج استقبال • Station'
                                    : 'برج اتصالات'
                              )
                    }
                </small>

                <small class="state">
                    <i></i>
                    ${pathLabel}
                </small>
            </span>

        </button>`;
    }

    /*
     * Path-aware visual state.
     *
     * The raw device status is preserved, but the card explains whether the
     * device itself is the outage root or is only affected by an upstream
     * failure.
     */
    const pathStatus=String(d.path_status||'');
    const affected=Number(d.affected_descendants||0);

    let visualState='online';
    let status='متصل';
    let extraBadge='';

    if(pathStatus==='self_down'){
        visualState='offline';

        if(affected>0){
            status=`سبب العطل • متأثر ${fmt(affected)} جهاز`;
            extraBadge='<span class="comm-node-badge outage-root">سبب العطل</span>';
        }else{
            status='عطل جهاز منفرد';
            extraBadge='<span class="comm-node-badge self-down">عطل منفرد</span>';
        }
    }else if(pathStatus==='upstream_down'){
        visualState='upstream-down';
        status=d.blocked_by_ip
            ? `متأثر بسبب ${esc(d.blocked_by_ip)}`
            : 'متأثر بعطل في المسار';
        extraBadge='<span class="comm-node-badge upstream">متأثر بالأب</span>';
    }else if(pathStatus==='status_conflict'){
        visualState='status-conflict';
        status='تعارض حالة المسار';
        extraBadge='<span class="comm-node-badge conflict">يحتاج مراجعة</span>';
    }else if(pathStatus==='upstream_unknown'){
        visualState='status-conflict';
        status='المسار غير محسوم';
        extraBadge='<span class="comm-node-badge conflict">غير محسوم</span>';
    }else{
        const working=['online','degraded'].includes(String(d.status||''));
        visualState=working?'online':'offline';
        status=working?'متصل':'غير متصل';
    }

    const kidCount=(children.get(id)||[]).filter(k=>byId.has(k)).length;
    const search=(rawName+' '+name+' '+ip+' '+mac).toLocaleLowerCase('ar');

    return `<button class="comm-org-card ${visualState}"
        data-tree-node
        data-device-id="${Number(d.id)}"
        data-search="${esc(search)}"
        data-name="${esc(name.toLocaleLowerCase('ar'))}"
        data-ip="${esc(ip.toLocaleLowerCase('ar'))}"
        data-mac="${esc(mac.toLocaleLowerCase('ar'))}"
        draggable="${canManage?'true':'false'}">

        <span class="comm-org-icon">
            <i class="bi bi-router-fill"></i>
        </span>

        <span class="comm-org-info">
            <b>${esc(name)}</b>
            <code>${esc(ip)}</code>

            <span class="comm-tree-state">
                <i></i>${status}
            </span>

            ${(kidCount||extraBadge)?`
                <span class="comm-node-badges">
                    ${kidCount?`
                        <span class="comm-node-badge children">
                            ${fmt(kidCount)} أبناء
                        </span>`:''}
                    ${extraBadge}
                </span>`:''}
        </span>
    </button>`;
}

function branch(id,ancestors=new Set()){
    if(!byId.has(id)||ancestors.has(id))return'';

    const d=byId.get(id);
    const next=new Set(ancestors);
    next.add(id);

    const allKids=(children.get(id)||[])
        .filter(k=>byId.has(k)&&!next.has(k));

    /*
     * Wireless peers are rendered inside realisticTowerPair(),
     * like Network 1, and must not appear again as vertical tree children.
     */
    /*
     * Keep every proven child in the physical tree.
     * Wireless links are real topology edges too.
     */
    const kids=allKids;

    /*
     * Physical graph contains wireless devices too.
     * Render them as towers inside the real tree.
     */
    const childLink=linkByChild.get(String(d.id));
    const wirelessSource=String(childLink?.discovery_source||'');

    const incomingWireless=(
        wirelessSource.includes('ubiquiti') ||
        wirelessSource.includes('wireless')
    );

    const wirelessParentId=String(
        childLink?.parent_device_id||''
    );

    const wirelessParent=wirelessParentId
        ? byId.get(wirelessParentId)
        : null;

    /*
     * Outgoing wireless children are derived ONLY from the actual
     * parent/child topology links. No TX/RX direction is guessed
     * from names or IP addresses.
     */
    const outgoingWirelessKids=kids.filter(childId=>{
        const childPhysicalLink=linkByChild.get(String(childId));
        const src=String(
            childPhysicalLink?.discovery_source||''
        );

        return (
            String(childPhysicalLink?.parent_device_id||'')===String(d.id) &&
            (
                src.includes('ubiquiti') ||
                src.includes('wireless')
            )
        );
    });

    const isTowerDevice =
        String(d?.device_category||'')==='wireless' ||
        String(d?.ip_address||'').startsWith('11.10.');

    /*
     * REAL PHYSICAL TREE:
     *
     * Never embed the RX inside the TX card here.
     * TX and RX already exist as two real topology nodes connected by
     * ubiquiti_mca_confirmed.
     *
     * observedPortDevice() may still use realisticTowerPair() for its
     * metadata/observed presentation, but the physical graph renders
     * exactly one node per real device.
     */
    /*
     * Use the same full realistic tower renderer used by Network 1.
     * Every wireless device in the physical tree gets the large tower card.
     */
    /*
     * Schematic mode: one real device = one real tree node.
     * card() already knows how to render wireless devices.
     */
    const wirelessCard = card(d);

    const collapsed=collapsedBranches.has(id);
    const working=['online','degraded'].includes(String(d.status||''));
    const visualState=working?'online':'offline';

    const parentInterface=String(childLink?.parent_interface||'').trim();
    const childInterface=String(childLink?.child_interface||'').trim();

    return `<li class="status-${visualState} ${
    (()=>{

        const l=linkByChild.get(String(d.id));
        const src=String(l?.discovery_source||'');

        return (
            src.includes('ubiquiti') ||
            src.includes('wireless')
        )
        ? 'wireless-link'
        : 'physical-link';

    })()
}" data-tree-item="${Number(d.id)}"
        data-parent-interface="${esc(parentInterface)}"
        data-child-interface="${esc(childInterface)}">
        <div class="comm-node-row">

            ${
                incomingWireless && wirelessParent
                    ? `<span class="comm-wireless-flow-badge incoming">
                        <i class="bi bi-wifi"></i>
                        يستقبل من
                        <b>${esc(
                            wirelessParent.ip_address ||
                            wirelessParent.device_name ||
                            'الجهاز الأعلى'
                        )}</b>
                       </span>`
                    : ''
            }

            ${wirelessCard}

            ${
                outgoingWirelessKids.length
                    ? `<span class="comm-wireless-flow-badge outgoing">
                        <i class="bi bi-broadcast-pin"></i>
                        إرسال لاسلكي إلى
                        <b>${fmt(outgoingWirelessKids.length)}</b>
                       </span>`
                    : ''
            }

            <button class="comm-branch-toggle ${kids.length?'':'empty'}"
                data-toggle-branch="${Number(d.id)}"
                aria-expanded="${collapsed?'false':'true'}"
                title="${collapsed?'فتح الفرع':'طي الفرع'}">
                <i class="bi ${collapsed?'bi-plus':'bi-dash'}"></i>
            </button>
        </div>

        ${kids.length
            ? `<ul ${collapsed?'hidden':''}>
                ${kids.map(k=>branch(k,next)).join('')}
               </ul>`
            : ''}
    </li>`;
}

/* Group real physical roots by MikroTik port.
 *
 * A device directly linked from the gateway is also a visual port root.
 * This is important for manually verified links such as:
 * MikroTik --ether7--> device.
 */
const groups=new Map();
const groupedRootIds=new Set();

if(gateway?.id){
    const gatewayId=String(gateway.id);

    (allLinks||[]).forEach(link=>{
        if(String(link.parent_device_id)!==gatewayId)return;

        const childId=String(link.child_device_id);
        if(!byId.has(childId))return;

        const port=String(link.parent_interface||'').trim()||'unassigned';

        if(!groups.has(port))
            groups.set(port,[]);

        if(!groups.get(port).includes(childId))
            groups.get(port).push(childId);

        groupedRootIds.add(childId);
    });
}

physicalRoots.forEach(id=>{
    if(groupedRootIds.has(String(id)))return;

    const uplink=rootUplinks[id]||rootUplinks[Number(id)]||null;
    const port=String(uplink?.port||'unassigned');

    if(!groups.has(port))
        groups.set(port,[]);

    if(!groups.get(port).includes(id))
        groups.get(port).push(id);
});

const portOrder=['ether2','ether3','ether4','ether5','ether6','ether7','ether8','ether9','ether10','unassigned'];

/*
 * Include a MikroTik port even if it currently has no proven
 * physical root but RouterOS observed devices behind it.
 */
Object.keys(observedBehindPorts).forEach(port=>{
    if(!groups.has(port))
        groups.set(port,[]);
});

const sortedPorts=[...groups.keys()].sort((a,b)=>{
    const ai=portOrder.indexOf(a);
    const bi=portOrder.indexOf(b);

    if(ai===-1&&bi===-1)return a.localeCompare(b);
    if(ai===-1)return 1;
    if(bi===-1)return -1;
    return ai-bi;
});

function observedDisplayName(d,port){
    const raw=String(d?.device_name||'').trim();

    if(!raw)
        return d?.ip_address||'جهاز غير معروف';

    /*
     * RouterOS can store names like:
     * ether8 | N M2(26) | N |
     * Strip the port name and tiny type tokens for presentation.
     */
    if(raw.includes('|')){
        const parts=raw.split('|')
            .map(x=>x.trim())
            .filter(Boolean)
            .filter(x=>x.toLowerCase()!==String(port).toLowerCase())
            .filter(x=>!/^[NL]$/i.test(x));

        if(parts.length)
            return parts[0];
    }

    return raw;
}

function observedDeviceIcon(d){
    if(String(d?.display_classification||'')==='mikrotik')
        return 'bi-hdd-network-fill';

    if(String(d?.display_classification||'')==='switch')
        return 'bi-diagram-3-fill';

    const text=(
        String(d?.device_name||'')+' '+
        String(d?.vendor||'')+' '+
        String(d?.model||'')+' '+
        String(d?.expected_role||'')
    ).toLowerCase();

    if(text.includes('mikrotik'))
        return 'bi-hdd-network-fill';

    if(text.includes('switch'))
        return 'bi-diagram-3-fill';

    if(String(d?.device_category||'')==='wireless')
        return 'bi-broadcast-pin';

    if(String(d?.device_category||'')==='subscriber')
        return 'bi-house-router-fill';

    return 'bi-router-fill';
}


/*
 * Wireless tower presentation inside the physical MikroTik port.
 * This does not change physical topology.
 * It only presents confirmed wireless associations as TX -> RX.
 */
const towerLinksByDevice=new Map();

/*
 * Confirmed Ubiquiti AP -> Station links.
 * Kept separate from physical STP topology because
 * communication_topology_links permits only one row per child.
 */
const confirmedTowerLinks=[
    {
        parent_device_id:68,
        child_device_id:69,
        frequency_mhz:5775,
        signal_dbm:-56,
        ssid:'send(3)',
        discovery_source:'ubiquiti_mca'
    },
    {
        parent_device_id:70,
        child_device_id:71,
        frequency_mhz:5390,
        signal_dbm:-65,
        ssid:'send(6)',
        discovery_source:'ubiquiti_mca'
    },
    {
        parent_device_id:6,
        child_device_id:7,
        frequency_mhz:5665,
        signal_dbm:-55,
        ssid:'3D_NeT(12)',
        discovery_source:'ubiquiti_mca'
    },
    {
        parent_device_id:105,
        child_device_id:106,
        frequency_mhz:4990,
        signal_dbm:-78,
        ssid:'send(93)',
        discovery_source:'ubiquiti_mca'
    },
    {
        parent_device_id:108,
        child_device_id:107,
        frequency_mhz:5850,
        signal_dbm:-34,
        ssid:'3D_NeT(144)',
        discovery_source:'ubiquiti_mca'
    }
];

/*
 * AP devices confirmed from wlanOpmode=ap / ap-ptp-ac.
 * RX devices confirmed from wlanOpmode=sta / sta-ptp-ac.
 */
const confirmedTowerTxIds=new Set([
    '68','70','6','105','108',
    '103','104','8','9','53','72','73','74'
]);

const confirmedTowerRxIds=new Set([
    '69','71','7','106','107'
]);

const effectiveTowerLinks=[
    ...confirmedTowerLinks
].filter(l=>
    String(l?.parent_device_id||'') &&
    String(l?.child_device_id||'') &&
    String(l?.parent_device_id)!==String(l?.child_device_id)
);

effectiveTowerLinks.forEach(l=>{
    const parentId=String(l?.parent_device_id||'');
    const childId=String(l?.child_device_id||'');

    if(!parentId||!childId)return;

    if(!towerLinksByDevice.has(parentId))
        towerLinksByDevice.set(parentId,[]);

    if(!towerLinksByDevice.has(childId))
        towerLinksByDevice.set(childId,[]);

    towerLinksByDevice.get(parentId).push({
        direction:'tx',
        otherId:childId,
        link:l
    });

    towerLinksByDevice.get(childId).push({
        direction:'rx',
        otherId:parentId,
        link:l
    });
});

function towerRole(d){
    const id=String(d?.id||'');
    const links=towerLinksByDevice.get(id)||[];

    if(links.some(x=>x.direction==='tx'))
        return 'tx';

    if(links.some(x=>x.direction==='rx'))
        return 'rx';

    if(confirmedTowerTxIds.has(id))
        return 'tx';

    if(confirmedTowerRxIds.has(id))
        return 'rx';

    const text=String(d?.device_name||'').toLowerCase();

    if(/\bsend\b|إرسال|ارسال/.test(text))
        return 'tx';

    if(/\bstion\b|\bstation\b|\breceive\b|استقبال/.test(text))
        return 'rx';

    return '';
}

function towerPeerHtml(d){
    const id=String(d?.id||'');
    const links=towerLinksByDevice.get(id)||[];

    if(!links.length)return '';

    return links.map(item=>{
        const peer=allById.get(String(item.otherId));
        if(!peer)return '';

        const peerName=observedDisplayName(
            peer,
            String(peer?.observed_parent_interface||'')
        );

        const peerIp=String(peer?.ip_address||'');

        const freq=
            item.link?.frequency_mhz ??
            item.link?.frequency ??
            item.link?.wireless_frequency ??
            null;

        const signal=
            item.link?.signal_dbm ??
            item.link?.signal ??
            item.link?.wireless_signal ??
            null;

        const arrow=item.direction==='tx'?'إرسال إلى':'استقبال من';

        return `<span class="comm-tower-peer ${item.direction}">
            <span class="comm-tower-peer-arrow">
                ${item.direction==='tx'?'↘':'↖'}
            </span>

            <span>
                <b>${arrow}</b>
                <strong>${esc(peerName)}</strong>
                ${peerIp?`<code>${esc(peerIp)}</code>`:''}

                ${(freq||signal)?`
                    <small>
                        ${freq?`${esc(String(freq))} MHz`:''}
                        ${freq&&signal?' • ':''}
                        ${signal?`${esc(String(signal))} dBm`:''}
                    </small>
                `:''}
            </span>
        </span>`;
    }).join('');
}


function realisticTowerSvg(){
    return `
    <svg class="comm-real-tower-svg"
         viewBox="0 0 120 190"
         aria-hidden="true">

        <!-- tower legs -->
        <path d="M60 8 L18 178"
              class="tower-main"/>
        <path d="M60 8 L102 178"
              class="tower-main"/>

        <!-- center mast -->
        <path d="M60 8 L60 178"
              class="tower-center"/>

        <!-- horizontal braces -->
        <path d="M50 48 L70 48
                 M42 80 L78 80
                 M34 112 L86 112
                 M26 145 L94 145
                 M18 178 L102 178"
              class="tower-brace"/>

        <!-- cross braces -->
        <path d="M50 48 L78 80
                 M70 48 L42 80
                 M42 80 L86 112
                 M78 80 L34 112
                 M34 112 L94 145
                 M86 112 L26 145
                 M26 145 L102 178
                 M94 145 L18 178"
              class="tower-cross"/>

        <!-- antenna -->
        <rect x="55" y="1"
              width="10" height="24" rx="4"
              class="tower-antenna"/>

        <!-- radio waves -->
        <path d="M70 20 Q88 28 92 44
                 M74 10 Q103 22 108 49"
              class="tower-radio-wave"/>
    </svg>`;
}

function towerStationCard(d){
    const id=Number(d?.id||0);

    /*
     * Receiver/station must show the real device identity.
     * Port names such as ether7/ether8 describe the MikroTik path,
     * not the station name.
     */
    const rawDeviceName=String(d?.device_name||'').trim();

    const name=(
        rawDeviceName
            .replace(/^ether\d+\s*\|\s*/i,'')
            .replace(/\s*\|\s*[PNL]\s*\|.*$/i,'')
            .trim()
        || String(d?.identity||'').trim()
        || String(d?.ip_address||'جهاز غير معروف')
    );

    const ip=String(d?.ip_address||'بدون IP');
    const online=String(d?.status||'')==='online';

    return `
    <button type="button"
            class="comm-real-station ${online?'online':'offline'}"
            ${id?`data-observed-device="${id}"`:''}>

        <span class="comm-real-station-radio">
            <i class="bi bi-router-fill"></i>
        </span>

        <span class="comm-real-station-info">
            <b>${esc(name)}</b>
            <code>${esc(ip)}</code>
            <small>
                <i class="comm-status-dot"></i>
                ${online?'متصل':'غير متصل'}
            </small>
        </span>
    </button>`;
}

function towerWirelessMeta(link){
    const freq=
        link?.frequency_mhz ??
        link?.frequency ??
        link?.wireless_frequency ??
        null;

    const signal=
        link?.signal_dbm ??
        link?.signal ??
        link?.wireless_signal ??
        null;

    const ssid=String(link?.ssid||'');

    const parts=[];

    if(ssid)parts.push(ssid);
    if(freq)parts.push(`${freq} MHz`);
    if(signal)parts.push(`${signal} dBm`);

    return parts.join(' • ');
}


/* ===== CONFIRMED MULTI RECEIVERS V1 =====
 *
 * Current live Station Table snapshot.
 * matchedId = communication_devices.id when MAC was found in DB.
 * matchedId = null means wireless receiver is real/live but
 * no managed DB device was matched yet.
 */
const confirmedTowerReceivers={
    '103':[
        {mac:'D6:1E:F5:3C:40:79',matchedId:335},
        {mac:'C6:51:1E:DF:05:BB',matchedId:494}
    ],

    '8':[
        {mac:'14:56:8E:0A:5F:37',matchedId:null},
        {mac:'9A:C4:84:5E:6B:E9',matchedId:342}
    ],

    '9':[
        {mac:'9C:5A:81:C3:D6:48',matchedId:15060},
        {mac:'10:C7:53:EA:99:16',matchedId:5743},
        {mac:'BC:6A:D1:12:5D:B6',matchedId:null}
    ],

    '53':[
        {mac:'14:4D:67:51:CF:F0',matchedId:1803},
        {mac:'42:AE:83:0A:F7:2A',matchedId:null},
        {mac:'AE:C1:26:68:5E:1D',matchedId:null}
    ],

    '73':[
        {mac:'1A:D7:FE:51:01:92',matchedId:null}
    ],

    '74':[
        {mac:'3A:C1:DF:3F:5D:37',matchedId:509},
        {mac:'E6:EE:0B:BD:A5:8B',matchedId:14294},
        {mac:'5E:B9:F0:10:3A:27',matchedId:15245},
        {mac:'66:D6:E5:D8:EF:B3',matchedId:12792},
        {mac:'EA:C5:6C:26:06:EB',matchedId:13220},
        {mac:'EE:70:E1:70:A3:F7',matchedId:14366},
        {mac:'A2:10:57:68:25:F7',matchedId:11170},
        {mac:'C6:05:BF:74:21:00',matchedId:314},
        {mac:'D2:7E:95:B7:CF:67',matchedId:10041},
        {mac:'9A:F6:2B:00:61:B1',matchedId:3793},
        {mac:'4A:10:6C:BA:CA:41',matchedId:4856},
        {mac:'BA:BF:1C:F2:F4:05',matchedId:10049},
        {mac:'0A:2D:4E:8F:99:23',matchedId:1524},
        {mac:'2E:72:9D:C8:F0:93',matchedId:4857},
        {mac:'7E:B1:71:BF:B3:37',matchedId:14463},
        {mac:'2A:A1:68:71:CD:C5',matchedId:10039}
    ]
};

function towerReceiverCard(receiver){
    const id=receiver?.matchedId
        ? String(receiver.matchedId)
        : '';

    const d=id ? allById.get(id) : null;

    if(d){
        const name=String(d.device_name||d.ip_address||'مستقبل');
        const ip=String(d.ip_address||'');

        return `
        <button type="button"
                class="comm-live-wireless-receiver matched"
                data-observed-device="${Number(d.id)}">

            <span class="comm-live-rx-icon">
                <i class="bi bi-router-fill"></i>
            </span>

            <span class="comm-live-rx-info">
                <b>${esc(name)}</b>
                ${ip?`<code>${esc(ip)}</code>`:''}
                <small>
                    <i class="comm-live-rx-dot"></i>
                    متصل لاسلكيًا الآن
                </small>
            </span>
        </button>`;
    }

    return '';
}


function towerMultiReceivers(d){
    const id=String(d?.id||'');

    const rows=(confirmedTowerReceivers[id]||[])
        .filter(r=>r?.matchedId && allById.has(String(r.matchedId)));

    if(!rows.length)return '';

    return `
    <div class="comm-multi-rx-wrap">

        <div class="comm-tower-outgoing-line"></div>

        <details class="comm-rx-details">
            <summary>
                <span>
                    <i class="bi bi-broadcast-pin"></i>
                    المستقبلون من هذا البرج
                </span>

                <strong>${fmt(rows.length)}</strong>
            </summary>

            <div class="comm-rx-details-body">
                ${rows.map(r=>towerReceiverCard(r)).join('')}
            </div>
        </details>

    </div>`;
}

function realisticTowerPair(d,port){
    const id=String(d?.id||'');
    const links=(towerLinksByDevice.get(id)||[])
        .filter(x=>x.direction==='tx');

    /*
     * Show the real device identity on the tower.
     * MikroTik interface (ether7, ether8, ...) belongs to the
     * connection/port and must never replace the device name.
     */
    const rawDeviceName=String(d?.device_name||'').trim();

    const name=(
        rawDeviceName
            .replace(/^ether\d+\s*\|\s*/i,'')
            .replace(/\s*\|\s*[PNL]\s*\|.*$/i,'')
            .trim()
        || String(d?.identity||'').trim()
        || String(d?.ip_address||'جهاز غير معروف')
    );

    const ip=String(d?.ip_address||'بدون IP');
    const online=String(d?.status||'')==='online';

    /*
     * AP with no known managed station:
     * still show it as a real tower.
     */
    if(!links.length){
        return `
        <div class="comm-real-tower-pair single standalone-wifi-ap">

            <button type="button"
                    class="comm-real-tower ${online?'online':'offline'}"
                    data-observed-device="${Number(d?.id||0)}">

                <span class="comm-standalone-wifi-mark"
                      title="نقطة بث Wi-Fi">
                    <i class="bi bi-wifi"></i>
                </span>

                ${realisticTowerSvg()}

                <span class="comm-real-tower-info">
                    <b>${esc(name)}</b>
                    <code>${esc(ip)}</code>
                    <small>
                        <i class="bi bi-wifi"></i>
                        نقطة بث Wi-Fi • AP
                    </small>
                </span>

            </button>

            ${towerMultiReceivers(d)}

        </div>`;
    }

    return links.map(item=>{
        const peer=allById.get(String(item.otherId));

        if(!peer){
            return `
            <div class="comm-real-tower-pair single">
                <button type="button"
                        class="comm-real-tower ${online?'online':'offline'}"
                        data-observed-device="${Number(d?.id||0)}">
                    ${realisticTowerSvg()}
                    <span class="comm-real-tower-info">
                        <b>${esc(name)}</b>
                        <code>${esc(ip)}</code>
                        <small>برج إرسال • AP</small>
                    </span>
                </button>
            </div>`;
        }

        const meta=towerWirelessMeta(item.link);

        return `
        <div class="comm-real-tower-pair">

            <button type="button"
                    class="comm-real-tower ${online?'online':'offline'}"
                    data-observed-device="${Number(d?.id||0)}">

                ${realisticTowerSvg()}

                <span class="comm-real-tower-info">
                    <b>${esc(name)}</b>
                    <code>${esc(ip)}</code>
                    <small>برج إرسال • AP</small>
                </span>

            </button>

            <div class="comm-wireless-beam">

                <span class="comm-wireless-beam-line"></span>

                <span class="comm-wireless-beam-arrow">▶</span>

                ${meta?`
                    <span class="comm-wireless-beam-meta">
                        ${esc(meta)}
                    </span>
                `:''}

            </div>

            ${towerStationCard(peer)}

        </div>`;
    }).join('');
}

function observedPortDevice(d,port){
    /*
     * An observation behind a MikroTik port is not a topology edge.
     * Render every unresolved radio/modem as a compact inventory card;
     * tower artwork is reserved for devices inside the proven graph.
     */
    return observedCard(d,port,'observed');
}

function observedCard(d,port,context='observed'){
    const id=Number(d?.id||0);
    const name=observedDisplayName(d,port);
    const ip=String(d?.ip_address||'بدون IP');

    const vendor=String(d?.model||d?.vendor||'').trim();
    const category=String(d?.device_category||'');

    const working=String(d?.status||'')==='online';
    const state=working?'online':'offline';

    const wireless=category==='wireless';
    const tower=towerRole(d);
    const towerClass=wireless?(tower||'tower'):'';
    const towerPeer=wireless?towerPeerHtml(d):'';

    let typeLabel={mikrotik:'MikroTik',switch:'Switch',modem:'مودم / بنية',wireless:'جهاز إرسال / استقبال',subscriber:'مودم مشترك',unknown:'غير مصنف'}[d?.display_classification]||'جهاز بنية تحتية';

    if(!d?.display_classification&&category==='wireless'){
        if(tower==='tx')
            typeLabel='برج إرسال • AP';
        else if(tower==='rx')
            typeLabel='برج استقبال • Station';
        else
            typeLabel='برج اتصالات';
    }
    else if(!d?.display_classification&&
        /mikrotik/i.test(
            String(d?.vendor||'')+' '+
            String(d?.model||'')+' '+
            String(d?.device_name||'')
        )
    )
        typeLabel='MikroTik';
    else if(!d?.display_classification&&
        /switch/i.test(
            String(d?.expected_role||'')+' '+
            String(d?.vendor||'')+' '+
            String(d?.model||'')+' '+
            String(d?.device_name||'')
        )
    )
        typeLabel='Switch';

    const associationParent=allById.get(String(d?.association_parent_device_id||''));
    const contextTitle=context==='association'
        ? `ارتباط لاسلكي${associationParent?` مع ${associationParent.device_name||associationParent.ip_address||associationParent.id}`:''} — ليس كابلًا ماديًا`
        : (
            context==='unresolved'
                ? (
                    d?.device_category==='subscriber'
                        ? 'مودم مشترك — موقعه داخل الشجرة غير محدد بعد'
                        : 'الموقع غير معيّن ولا يوجد أب مادي موثوق'
                  )
                : `مرصود خلف ${port} — الموقع الداخلي غير محدد`
          );

    return `<button
        type="button"
        class="comm-observed-device ${state} ${wireless?'comm-tower-device':''} ${towerClass}"
        data-observed-device="${id}"
        data-tower-role="${tower}"
        title="${esc(contextTitle)}">

        <span class="comm-observed-device-icon">
            <i class="bi ${observedDeviceIcon(d)}"></i>
        </span>

        <span class="comm-observed-device-info">
            <b>${esc(name)}</b>
            <code>${esc(ip)}</code>
            <small>${esc(typeLabel)}${vendor?` • ${esc(vendor)}`:''}</small>
        </span>

        ${wireless?`
            <span class="comm-tower-symbol" aria-hidden="true">
                <i class="bi bi-broadcast-pin"></i>
                <span class="comm-tower-waves">)))</span>
            </span>
        `:''}

        ${towerPeer}

        <span class="comm-observed-state ${state}">
            <i></i>
        </span>
    </button>`;
}

const associatedDeviceIds=new Set(associationLinks.map(l=>String(l.child_device_id)));
const associatedDevices=allDevices.filter(d=>associatedDeviceIds.has(String(d.id)));
/*
 * Final display placement.
 *
 * A device must appear only once:
 * 1. proven physical graph
 * 2. observed behind MikroTik port
 * 3. wireless association
 * 4. unresolved / unassigned
 */
const physicalDisplayIds=new Set([...physicalIds]);

const observedDisplayIds=new Set();
Object.values(observedBehindPorts).forEach(list=>{
    (list||[]).forEach(d=>{
        if(d?.id) observedDisplayIds.add(String(d.id));
    });
});

const associationDisplayDevices=associatedDevices.filter(d=>{
    const id=String(d.id);
    return !physicalDisplayIds.has(id) &&
           !observedDisplayIds.has(id);
});

const associationDisplayIds=new Set(
    associationDisplayDevices.map(d=>String(d.id))
);

const unresolvedDisplayDevices=(unresolvedDevices||[]).filter(d=>{
    const id=String(d.id);

    return !physicalDisplayIds.has(id) &&
           !observedDisplayIds.has(id) &&
           !associationDisplayIds.has(id);
});

const auxiliaryCollections={
    association:associationDisplayDevices,
    unresolved:unresolvedDisplayDevices
};

function auxiliaryGroup(kind,title,note,devices){
    if(!devices.length)return'';
    return `<div class="comm-observed-wrap" data-aux-group="${kind}">
        <button type="button" class="comm-observed-toggle" data-toggle-aux="${kind}" aria-expanded="false">
            <span><i class="bi ${kind==='association'?'bi-wifi':'bi-question-circle-fill'}"></i><b>${esc(title)}</b></span>
            <span class="comm-observed-count">${fmt(devices.length)} <span>${devices.some(d=>d.device_category==='subscriber')?'جهاز / مودم مشترك':'جهاز'}</span> <i class="bi bi-chevron-down"></i></span>
        </button>
        <div class="comm-observed-note">${esc(note)}</div>
        <div class="comm-observed-list" data-aux-list="${kind}" hidden></div>
    </div>`;
}

function portBlock(port,ids){
    const isUnassigned=port==='unassigned';
    const title=isUnassigned?'منفذ غير معروف':port;

    const observed=(observedBehindPorts[port]||[])
        .filter(d=>!d?.participates_in_physical_graph);

    const physicalHtml=ids.length
        ? `<ul class="comm-port-roots">
            ${ids.map(id=>branch(id)).join('')}
           </ul>`
        : '';

    /*
     * Devices observed by RouterOS behind this MikroTik port.
     *
     * They are displayed inside the same port branch,
     * but are NOT treated as proven physical children.
     */
    let observedHtml='';

    if(observed.length){
        const observedWireless=observed.filter(
            d=>String(d?.device_category||'')==='wireless'
        );
        const observedWired=observed.filter(
            d=>String(d?.device_category||'')!=='wireless'
        );

        const observedSection=(kind,label,rows)=>rows.length?`
            <section class="comm-observed-category ${kind}">
                <h4>
                    <span><i class="bi ${kind==='wireless'?'bi-broadcast-pin':'bi-router-fill'}"></i> ${esc(label)}</span>
                    <b>${fmt(rows.length)}</b>
                </h4>
                <div class="comm-observed-category-grid">
                    ${rows.map(d=>observedPortDevice(d,port)).join('')}
                </div>
            </section>`:'';

        observedHtml=`
            <div class="comm-observed-wrap comm-port-observed-inline">
                <button type="button" class="comm-observed-toggle"
                    data-toggle-observed="${esc(port)}" aria-expanded="false">
                    <span>
                        <i class="bi bi-eye-fill"></i>
                        <b>أجهزة مرصودة خلف ${esc(title)}</b>
                    </span>
                    <span class="comm-observed-count">
                        ${fmt(observed.length)} جهاز
                        <i class="bi bi-chevron-down"></i>
                    </span>
                </button>
                <div class="comm-observed-note">
                    المنفذ معروف، لكن الأب الداخلي غير مثبت؛ لذلك لا تُرسم كابلات تخمينية.
                </div>
                <div class="comm-observed-list comm-port-observed-grid"
                    data-observed-list="${esc(port)}" hidden>
                    ${observedSection('wireless','أجهزة لاسلكية غير مربوطة',observedWireless)}
                    ${observedSection('wired','مودمات وأجهزة شبكة غير مربوطة',observedWired)}
                </div>
            </div>`;
    }

    return `<li class="comm-port-branch ${isUnassigned?'comm-port-unassigned':''}">

        <div class="comm-port-node">
            <i class="bi bi-ethernet"></i>
            <b>${esc(title)}</b>

            <small>
                ${ids.length?`${fmt(ids.length)} فرع مؤكد`:''}
                ${ids.length&&observed.length?' • ':''}
                ${observed.length?`${fmt(observed.length)} جهاز خلف المنفذ`:''}
            </small>
        </div>

        ${physicalHtml}
        ${observedHtml}

    </li>`;
}

const gatewayName=gateway?.device_name||networkMeta?.name||'MikroTik';
const gatewayIp=gateway?.ip_address||'';
const gatewayWorking=['online','degraded'].includes(String(gateway?.status||'online'));
const gatewayState=gatewayWorking?'online':'offline';

const gatewayHtml=`
<div class="comm-gateway-wrap">
    <button class="comm-gateway-card ${gatewayState}"
        ${gateway?.id?`data-tree-node data-device-id="${Number(gateway.id)}"`:''}>
        <span class="comm-gateway-icon">
            <i class="bi bi-hdd-network-fill"></i>
        </span>
        <span>
            <b>${esc(gatewayName)}</b>
            ${gatewayIp?`<code>${esc(gatewayIp)}</code>`:''}
            <small>
                <i class="comm-status-dot"></i>
                ${gatewayWorking?'متصل':'غير متصل'}
            </small>
        </span>
    </button>
</div>`;

const portsHtml=sortedPorts.length
    ? `<ul class="comm-port-tree">
        ${sortedPorts.map(port=>portBlock(port,groups.get(port))).join('')}
       </ul>`
    : `<div class="comm-empty">لا توجد أجهزة لهذه الشبكة.</div>`;

const unplacedHtml=[
    auxiliaryGroup(
        'association',
        'ارتباطات لاسلكية غير مادية',
        'العلاقة اللاسلكية معروفة، لكنها ليست كابلًا ولا تُستخدم كأب مادي.',
        associationDisplayDevices
    ),
    auxiliaryGroup(
        'unresolved',
        'أجهزة تحتاج تحديد موقعها',
        'لا يوجد منفذ أو أب مثبت لهذه الأجهزة؛ تظهر هنا دون وصلات حتى تُوثق العلاقة.',
        unresolvedDisplayDevices
    )
].filter(Boolean).join('');

canvas.innerHTML=`
<div class="comm-tree-viewport">
<div class="comm-tree-stage" data-tree-stage>
<div class="comm-org-tree physical-tree">
    <div class="comm-physical-head">
        <b>${esc(networkMeta.name)} — الشجرة المادية</b>
        <span>
            ${fmt(allDevices.length)} جهاز بالشبكة •
            ${fmt(allLinks.length)} رابط مادي مؤكد •
            ${fmt(physicalRoots.length)} جذر مادي
        </span>
    </div>

    <div class="comm-network-tree">
        ${gatewayHtml}
        ${portsHtml}

        <div class="comm-topology-unplaced" ${unplacedHtml?'':'hidden'}>
            ${unplacedHtml}
        </div>
    </div>
</div>
</div>
</div>`;


function drawPhysicalConnectors(){
    const networkTree=canvas.querySelector('.comm-network-tree');
    if(!networkTree)return;

    let svg=networkTree.querySelector(':scope > .comm-tree-connectors');

    if(!svg){
        svg=document.createElementNS('http://www.w3.org/2000/svg','svg');
        svg.classList.add('comm-tree-connectors');
        svg.setAttribute('aria-hidden','true');
        networkTree.prepend(svg);
    }

    svg.innerHTML='';

    /*
     * Wireless arrow marker.
     * The arrow always points from the real topology parent
     * toward the real topology child.
     */
    const defs=document.createElementNS(
        'http://www.w3.org/2000/svg',
        'defs'
    );

    const marker=document.createElementNS(
        'http://www.w3.org/2000/svg',
        'marker'
    );

    marker.setAttribute('id','comm-wireless-arrow');
    marker.setAttribute('viewBox','0 0 10 10');
    marker.setAttribute('refX','9');
    marker.setAttribute('refY','5');
    marker.setAttribute('markerWidth','7');
    marker.setAttribute('markerHeight','7');
    marker.setAttribute('orient','auto-start-reverse');

    const arrowPath=document.createElementNS(
        'http://www.w3.org/2000/svg',
        'path'
    );

    arrowPath.setAttribute('d','M 0 0 L 10 5 L 0 10 z');
    arrowPath.setAttribute('fill','#1570ef');

    marker.appendChild(arrowPath);
    defs.appendChild(marker);

    /*
     * Wired direction arrows.
     *
     * These make feeding direction explicit:
     * MikroTik -> port -> first device -> downstream device.
     */
    const makePhysicalMarker=(id,color)=>{
        const m=document.createElementNS(
            'http://www.w3.org/2000/svg',
            'marker'
        );

        m.setAttribute('id',id);
        m.setAttribute('viewBox','0 0 10 10');
        m.setAttribute('refX','9');
        m.setAttribute('refY','5');
        m.setAttribute('markerWidth','5');
        m.setAttribute('markerHeight','5');
        m.setAttribute('orient','auto');

        const p=document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );

        p.setAttribute('d','M 0 0 L 10 5 L 0 10 z');
        p.setAttribute('fill',color);

        m.appendChild(p);
        defs.appendChild(m);
    };

    makePhysicalMarker(
        'comm-physical-arrow-online',
        '#12b76a'
    );

    makePhysicalMarker(
        'comm-physical-arrow-offline',
        '#e5221a'
    );

    svg.appendChild(defs);

    /*
     * Everything is inside the transformed stage.
     * getBoundingClientRect() therefore returns scaled coordinates.
     * Divide by treeScale so SVG coordinates remain in the tree's
     * unscaled local coordinate system.
     */
    const treeRect=networkTree.getBoundingClientRect();
    const scale=Math.max(0.01,Number(treeScale)||1);

    const width=Math.max(
        networkTree.scrollWidth,
        networkTree.offsetWidth,
        treeRect.width/scale
    );

    const height=Math.max(
        networkTree.scrollHeight,
        networkTree.offsetHeight,
        treeRect.height/scale
    );

    svg.setAttribute('width',String(width));
    svg.setAttribute('height',String(height));
    svg.setAttribute('viewBox',`0 0 ${width} ${height}`);

    function point(el,edge){
        if(!el)return null;

        const r=el.getBoundingClientRect();

        return {
            x:((r.left+r.width/2)-treeRect.left)/scale,
            y:(
                edge==='top'
                    ? r.top-treeRect.top
                    : r.bottom-treeRect.top
              )/scale
        };
    }

    function deviceWorking(card){
        if(!card)return true;
        return !card.classList.contains('offline');
    }

    function wire(
        fromEl,
        toEl,
        working=true,
        linkType='physical',
        fromLabel='',
        toLabel='',
        portLabel=''
    ){
        if(!fromEl||!toEl)return;

        const a=point(fromEl,'bottom');
        const b=point(toEl,'top');

        if(!a||!b)return;

        /*
         * Orthogonal connector:
         *
         *       parent
         *          |
         *          |
         *    -------+-------
         *          |
         *        child
         *
         * Each line is generated ONLY from a real DOM parent
         * to its actual child.
         */
        /*
         * One visible cable per real parent -> child relationship.
         * No shared horizontal bus between unrelated siblings.
         */
        const path=document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );

        const midY=a.y+(b.y-a.y)/2;

        if(Math.abs(a.x-b.x)<2){
            path.setAttribute(
                'd',
                `M ${a.x} ${a.y} L ${b.x} ${b.y}`
            );
        }else{
            path.setAttribute(
                'd',
                `M ${a.x} ${a.y}
                 V ${midY}
                 H ${b.x}
                 V ${b.y}`
            );
        }

        path.setAttribute(
            'class',
            `comm-tree-wire ${linkType==='wireless'?'wireless':'physical'} ${working?'online':'offline'}`
        );

        if(linkType==='wireless'){
            path.setAttribute(
                'marker-end',
                'url(#comm-wireless-arrow)'
            );
        }

        svg.appendChild(path);

        if(linkType==='wireless'){
            const labelText=[
                String(fromLabel||'').trim(),
                String(toLabel||'').trim()
            ].filter(Boolean).join('  →  ');

            if(labelText){
                const text=document.createElementNS(
                    'http://www.w3.org/2000/svg',
                    'text'
                );

                text.setAttribute(
                    'x',
                    String((a.x+b.x)/2)
                );

                text.setAttribute(
                    'y',
                    String(midY-7)
                );

                text.setAttribute(
                    'text-anchor',
                    'middle'
                );

                text.setAttribute(
                    'class',
                    'comm-wireless-edge-label'
                );

                text.textContent='TX  '+labelText+'  RX';

                svg.appendChild(text);
            }
        }else if(String(portLabel||'').trim()){
            const text=document.createElementNS(
                'http://www.w3.org/2000/svg',
                'text'
            );

            text.setAttribute('x',String(b.x+8));
            text.setAttribute('y',String(Math.max(a.y+14,b.y-14)));
            text.setAttribute('text-anchor','start');
            text.setAttribute('class','comm-port-edge-label');
            text.textContent=String(portLabel).trim();
            svg.appendChild(text);
        }
    }

    /* -----------------------------------------------------
       MikroTik -> physical port
       ----------------------------------------------------- */
    const gateway=networkTree.querySelector('.comm-gateway-card');

    networkTree.querySelectorAll(
        '.comm-port-tree > .comm-port-branch'
    ).forEach(portBranch=>{
        const port=portBranch.querySelector(
            ':scope > .comm-port-node'
        );

        if(gateway&&port)
            wire(gateway,port,true);
    });

    /* -----------------------------------------------------
       MikroTik port -> immediate physical roots of that port
       ----------------------------------------------------- */
    networkTree.querySelectorAll(
        '.comm-port-tree > .comm-port-branch'
    ).forEach(portBranch=>{
        const port=portBranch.querySelector(
            ':scope > .comm-port-node'
        );

        const rootLis=portBranch.querySelectorAll(
            ':scope > .comm-port-roots > li'
        );

        rootLis.forEach(li=>{
            const child=li.querySelector(
                ':scope > .comm-node-row > .comm-org-card, :scope > .comm-node-row > .comm-physical-tower-card'
            );

            if(port&&child)
                wire(port,child,deviceWorking(child));
        });
    });

    /* -----------------------------------------------------
       Modem -> modem.
       ONLY immediate children are connected.
       This is what fixes cards such as 224: its line can only
       originate from its actual parent card in the DOM tree.
       ----------------------------------------------------- */
    networkTree.querySelectorAll(
        '.comm-port-roots li'
    ).forEach(parentLi=>{
        const parentCard=parentLi.querySelector(
            ':scope > .comm-node-row > .comm-org-card, :scope > .comm-node-row > .comm-physical-tower-card'
        );

        if(!parentCard)return;

        const childLis=parentLi.querySelectorAll(
            ':scope > ul > li'
        );

        childLis.forEach(childLi=>{
            const childCard=childLi.querySelector(
                ':scope > .comm-node-row > .comm-org-card, :scope > .comm-node-row > .comm-physical-tower-card'
            );

            if(childCard){
                const linkType=childLi.classList.contains('wireless-link')
                    ? 'wireless'
                    : 'physical';

                let fromLabel='';
                let toLabel='';
                const portLabel=String(childLi.dataset.parentInterface||'').trim();

                if(linkType==='wireless'){
                    const parentId=String(
                        parentLi.dataset.treeItem||''
                    );

                    const childId=String(
                        childLi.dataset.treeItem||''
                    );

                    const parentDevice=byId.get(parentId);
                    const childDevice=byId.get(childId);

                    fromLabel=String(
                        parentDevice?.ip_address ||
                        parentDevice?.device_name ||
                        parentId
                    );

                    toLabel=String(
                        childDevice?.ip_address ||
                        childDevice?.device_name ||
                        childId
                    );
                }

                wire(
                    parentCard,
                    childCard,
                    deviceWorking(childCard),
                    linkType,
                    fromLabel,
                    toLabel,
                    portLabel
                );
            }
        });
    });
}


canvas.querySelectorAll('[data-tree-node]').forEach(b=>{
    b.onclick=()=>{
        selectedId=Number(b.dataset.deviceId);

        canvas.querySelectorAll('.comm-org-card.selected,.comm-gateway-card.selected')
            .forEach(x=>x.classList.remove('selected'));

        b.classList.add('selected');

        if(selectedId)
            loadDetails(selectedId);
    };

    if(canManage && b.classList.contains('comm-org-card')){
        b.ondragstart=e=>{
            e.dataTransfer.setData('text/plain',b.dataset.deviceId);
            e.dataTransfer.effectAllowed='move';
        };

        b.ondragover=e=>{
            e.preventDefault();
            b.classList.add('drag-target');
        };

        b.ondragleave=()=>b.classList.remove('drag-target');

        b.ondrop=e=>{
            e.preventDefault();
            b.classList.remove('drag-target');

            const child=Number(e.dataTransfer.getData('text/plain'));
            const parent=Number(b.dataset.deviceId);

            if(!child||!parent||child===parent)return;

            const childNode=canvas.querySelector(
                `[data-device-id="${CSS.escape(String(child))}"]`
            );

            if(confirm(
                `تأكيد علاقة التغذية؟\n\n`+
                `${b.querySelector('b')?.textContent||parent}\n↓\n`+
                `${childNode?.querySelector('b')?.textContent||child}`
            )){
                saveManualLink(child,parent);
            }
        };
    }
});

canvas.querySelectorAll('[data-toggle-branch]').forEach(toggle=>{
    toggle.onclick=()=>{
        const id=String(toggle.dataset.toggleBranch);

        if(collapsedBranches.has(id))
            collapsedBranches.delete(id);
        else
            collapsedBranches.add(id);

        renderFallbackTree();
    };
});


/* Observed infrastructure group expand/collapse */
canvas.querySelectorAll('[data-toggle-observed]').forEach(toggle=>{
    toggle.onclick=e=>{
        e.stopPropagation();

        const port=String(toggle.dataset.toggleObserved||'');
        const list=canvas.querySelector(
            `[data-observed-list="${CSS.escape(port)}"]`
        );

        if(!list)return;

        const opening=list.hasAttribute('hidden');

        if(opening)
            list.removeAttribute('hidden');
        else
            list.setAttribute('hidden','');

        toggle.setAttribute(
            'aria-expanded',
            opening?'true':'false'
        );

        toggle.classList.toggle('open',opening);

        requestAnimationFrame(()=>{
            if(typeof drawPhysicalConnectors==='function')
                drawPhysicalConnectors();
        });
    };
});

function bindMetadataDeviceCards(scope=canvas){
scope.querySelectorAll('[data-observed-device]').forEach(card=>{
    if(card.dataset.bound==='1')return;
    card.dataset.bound='1';
    card.onclick=e=>{
        e.stopPropagation();

        const id=Number(card.dataset.observedDevice||0);

        if(!id)return;

        selectedId=id;

        canvas.querySelectorAll(
            '.comm-org-card.selected,'+
            '.comm-gateway-card.selected,'+
            '.comm-observed-device.selected'
        ).forEach(x=>x.classList.remove('selected'));

        card.classList.add('selected');

        loadDetails(id);
    };
});
}

/* Observed/association/unassigned details use the same centered modal. */
bindMetadataDeviceCards();

canvas.querySelectorAll('[data-toggle-aux]').forEach(toggle=>{
    toggle.onclick=e=>{
        e.stopPropagation();
        const kind=String(toggle.dataset.toggleAux||'');
        const list=canvas.querySelector(`[data-aux-list="${CSS.escape(kind)}"]`);
        if(!list)return;
        const opening=list.hasAttribute('hidden');
        if(opening&&list.dataset.loaded!=='1'){
            list.innerHTML=(auxiliaryCollections[kind]||[])
                .map(d=>observedCard(d,'',kind))
                .join('');
            list.dataset.loaded='1';
            bindMetadataDeviceCards(list);
        }
        if(opening)list.removeAttribute('hidden');else list.setAttribute('hidden','');
        toggle.setAttribute('aria-expanded',opening?'true':'false');
        toggle.classList.toggle('open',opening);
    };
});

const stage=canvas.querySelector('[data-tree-stage]');

let panX=0;
let panY=0;
let dragging=false;
let dragStartX=0;
let dragStartY=0;
let panStartX=0;
let panStartY=0;

function applyTreeTransform(){
    if(!stage)return;
    stage.style.transform=
        `translateX(calc(-50% + ${panX}px)) translateY(${panY}px) scale(${treeScale})`;
}

function fitTreeToViewport(fullFit=false){
    if(!stage)return;

    const viewport=canvas.querySelector('.comm-tree-viewport');
    if(!viewport)return;

    const tree=stage.querySelector('.comm-org-tree');
    if(!tree)return;

    const vw=viewport.clientWidth;
    const tw=tree.scrollWidth;

    if(vw>0 && tw>0){
        const fit=Math.min(1,(vw-24)/tw);

        if(fullFit){
            /*
             * Explicit Fit button:
             * allow the complete tree to become smaller so all branches
             * can be inspected at once.
             */
            treeScale=Math.max(
                window.innerWidth<=768 ? 0.30 : 0.32,
                fit
            );
        }else{
            /*
             * Initial/open-network view:
             * NEVER auto-shrink the desktop topology.
             * Large trees are navigated with horizontal scroll / pan.
             *
             * Mobile keeps a modest scale reduction for usability.
             */
            if(window.innerWidth<=768){
                treeScale=Math.max(0.70,fit);
                treeScale=Math.min(1,treeScale);
            }else{
                treeScale=1;
            }
        }
    }

    panX=0;
    panY=0;
    applyTreeTransform();
}

applyTreeTransform();

/* Mouse wheel zoom */
canvas.onwheel=e=>{
    if(!stage)return;

    e.preventDefault();

    const delta=e.deltaY<0 ? 0.08 : -0.08;
    treeScale=Math.max(0.30,Math.min(2.2,treeScale+delta));

    applyTreeTransform();
};

/* Mouse drag / pan */
canvas.onmousedown=e=>{
    if(e.button!==0)return;

    /* Do not start panning when clicking an interactive node/control */
    if(e.target.closest(
        '[data-tree-node],[data-toggle-branch],button,input,select,a'
    )) return;

    dragging=true;
    dragStartX=e.clientX;
    dragStartY=e.clientY;
    panStartX=panX;
    panStartY=panY;

    canvas.classList.add('dragging');
    e.preventDefault();
};

window.addEventListener('mousemove',e=>{
    if(!dragging)return;

    panX=panStartX+(e.clientX-dragStartX);
    panY=panStartY+(e.clientY-dragStartY);

    applyTreeTransform();
});

window.addEventListener('mouseup',()=>{
    if(!dragging)return;
    dragging=false;
    canvas.classList.remove('dragging');
});

/* Touch drag / pan */
canvas.ontouchstart=e=>{
    if(!stage || e.touches.length!==1)return;

    if(e.target.closest(
        '[data-tree-node],[data-toggle-branch],button,input,select,a'
    )) return;

    const t=e.touches[0];

    dragging=true;
    dragStartX=t.clientX;
    dragStartY=t.clientY;
    panStartX=panX;
    panStartY=panY;

    canvas.classList.add('dragging');
};

canvas.ontouchmove=e=>{
    if(!dragging || e.touches.length!==1)return;

    const t=e.touches[0];

    panX=panStartX+(t.clientX-dragStartX);
    panY=panStartY+(t.clientY-dragStartY);

    applyTreeTransform();
    e.preventDefault();
};

canvas.ontouchend=()=>{
    dragging=false;
    canvas.classList.remove('dragging');
};

/* Pinch zoom on mobile */
let pinchStartDistance=0;
let pinchStartScale=treeScale;

canvas.addEventListener('touchstart',e=>{
    if(e.touches.length===2){
        const dx=e.touches[0].clientX-e.touches[1].clientX;
        const dy=e.touches[0].clientY-e.touches[1].clientY;

        pinchStartDistance=Math.hypot(dx,dy);
        pinchStartScale=treeScale;
    }
},{passive:false});

canvas.addEventListener('touchmove',e=>{
    if(e.touches.length===2 && pinchStartDistance>0){
        const dx=e.touches[0].clientX-e.touches[1].clientX;
        const dy=e.touches[0].clientY-e.touches[1].clientY;

        const distance=Math.hypot(dx,dy);
        const ratio=distance/pinchStartDistance;

        treeScale=Math.max(
            0.30,
            Math.min(2.2,pinchStartScale*ratio)
        );

        applyTreeTransform();
        e.preventDefault();
    }
},{passive:false});

/*
 * Measured non-crossing layout.
 *
 * Each subtree receives only the width required by its descendants.
 * The parent is centered above that reserved subtree area.
 */
function applyMeasuredTreeLayout(){
    const networkTree=canvas.querySelector('.comm-network-tree');
    if(!networkTree)return;

    const NODE_WIDTH=170;
    const SIBLING_GAP=54;
    const PORT_GAP=80;
    const MAX_BRANCH_WIDTH=1080;
    const ROW_GAP=46;

    const roots=[
        ...networkTree.querySelectorAll(
            '.comm-port-tree > .comm-port-branch'
        )
    ];

    function visibleChildren(li){
        const ul=li.querySelector(':scope > ul');
        if(!ul || ul.hidden)return [];

        return [...ul.children].filter(
            child=>child.tagName==='LI'
        );
    }

    function measureSubtree(li){
        const kids=visibleChildren(li);

        if(!kids.length){
            li.dataset.layoutWidth=String(NODE_WIDTH);
            return NODE_WIDTH;
        }

        const childWidths=kids.map(measureSubtree);

        const childrenWidth=
            childWidths.reduce((a,b)=>a+b,0) +
            SIBLING_GAP*Math.max(0,kids.length-1);

        const widestChild=Math.max(NODE_WIDTH,...childWidths);
        const width=Math.max(
            widestChild,
            Math.min(childrenWidth,MAX_BRANCH_WIDTH)
        );

        li.dataset.layoutWidth=String(width);

        const ul=li.querySelector(':scope > ul');
        if(ul){
            ul.style.width=width+'px';
            ul.style.minWidth=width+'px';
            ul.style.maxWidth=width+'px';

            kids.forEach((kid,i)=>{
                const kidWidth=childWidths[i];

                kid.style.width=kidWidth+'px';
                kid.style.minWidth=kidWidth+'px';
                kid.style.maxWidth=kidWidth+'px';
                kid.style.flex='0 0 '+kidWidth+'px';
            });

            ul.style.display='flex';
            ul.style.flexWrap=childrenWidth>width?'wrap':'nowrap';
            ul.style.alignItems='flex-start';
            ul.style.justifyContent='center';
            ul.style.gap=SIBLING_GAP+'px';
            ul.style.rowGap=ROW_GAP+'px';
        }

        return width;
    }

    roots.forEach(portBranch=>{
        const observedBucket=portBranch.querySelector(
            ':scope > .comm-port-observed-inline'
        );
        const minimumPortWidth=observedBucket?720:NODE_WIDTH;
        const rootList=portBranch.querySelector(
            ':scope > .comm-port-roots'
        );

        if(!rootList){
            portBranch.style.width=minimumPortWidth+'px';
            portBranch.style.minWidth=minimumPortWidth+'px';
            portBranch.style.maxWidth=minimumPortWidth+'px';
            portBranch.style.flex='0 0 '+minimumPortWidth+'px';
            return;
        }

        const portRoots=[
            ...rootList.children
        ].filter(el=>el.tagName==='LI');

        if(!portRoots.length){
            portBranch.style.width=minimumPortWidth+'px';
            portBranch.style.minWidth=minimumPortWidth+'px';
            portBranch.style.maxWidth=minimumPortWidth+'px';
            portBranch.style.flex='0 0 '+minimumPortWidth+'px';
            return;
        }

        const widths=portRoots.map(measureSubtree);

        const naturalWidth=
            widths.reduce((a,b)=>a+b,0) +
            SIBLING_GAP*Math.max(0,widths.length-1);
        const totalWidth=Math.max(
            minimumPortWidth,
            Math.max(NODE_WIDTH,...widths),
            Math.min(naturalWidth,MAX_BRANCH_WIDTH)
        );

        rootList.style.width=totalWidth+'px';
        rootList.style.minWidth=totalWidth+'px';
        rootList.style.maxWidth=totalWidth+'px';

        rootList.style.display='flex';
        rootList.style.flexWrap=naturalWidth>totalWidth?'wrap':'nowrap';
        rootList.style.alignItems='flex-start';
        rootList.style.justifyContent='center';
        rootList.style.gap=SIBLING_GAP+'px';
        rootList.style.rowGap=ROW_GAP+'px';

        portRoots.forEach((rootLi,i)=>{
            rootLi.style.width=widths[i]+'px';
            rootLi.style.minWidth=widths[i]+'px';
            rootLi.style.maxWidth=widths[i]+'px';
            rootLi.style.flex='0 0 '+widths[i]+'px';
        });

        portBranch.style.width=totalWidth+'px';
        portBranch.style.minWidth=totalWidth+'px';
        portBranch.style.maxWidth=totalWidth+'px';
        portBranch.style.flex='0 0 '+totalWidth+'px';
    });

    const portTree=networkTree.querySelector('.comm-port-tree');

    if(portTree){
        portTree.style.display='flex';
        portTree.style.flexWrap='nowrap';
        portTree.style.alignItems='flex-start';
        portTree.style.justifyContent='center';
        portTree.style.gap=PORT_GAP+'px';
    }
}

requestAnimationFrame(()=>{
    applyMeasuredTreeLayout();

    /*
     * Initial/new-network render:
     * fit the tree normally.
     *
     * Same-network automatic refresh:
     * preserve the exact operator-selected zoom.
     */
    const preserveScale=
        canvas.dataset.preserveTreeScale==='1';

    if(preserveScale){
        delete canvas.dataset.preserveTreeScale;
        applyTreeTransform();
    }else{
        /*
         * Do not shrink large physical networks until every card becomes tiny.
         * Start at a readable zoom and let the viewport scroll horizontally.
         */
        treeScale=1;
        applyTreeTransform();
    }

    requestAnimationFrame(()=>{
        applyMeasuredTreeLayout();
        applyTreeTransform();
        drawPhysicalConnectors();
    });
});

if(window.ResizeObserver){
    const treeResizeObserver=new ResizeObserver(()=>{
        requestAnimationFrame(drawPhysicalConnectors);
    });

    const observedTree=canvas.querySelector('.comm-network-tree');
    if(observedTree)
        treeResizeObserver.observe(observedTree);
}

applyTreeSearch(root.querySelector('[data-tree-search]')?.value||'');
}

function treeParentMap(){const map=new Map();(topology?.links||[]).forEach(l=>map.set(String(l.child_device_id),String(l.parent_device_id)));return map}
function applyTreeSearch(value){const canvas=root.querySelector('[data-canvas]'),nodes=[...canvas.querySelectorAll('[data-tree-node]')],count=root.querySelector('[data-tree-search-count]'),q=String(value||'').trim().toLocaleLowerCase('ar');nodes.forEach(n=>n.classList.remove('search-match','search-path','search-dim'));if(!q){if(count)count.textContent='';return}const exact=n=>[n.dataset.name,n.dataset.ip,n.dataset.mac].includes(q),matches=nodes.filter(n=>(n.dataset.search||'').includes(q)).sort((a,b)=>Number(exact(b))-Number(exact(a))),parents=treeParentMap();let expanded=false;matches.forEach(n=>{let id=String(n.dataset.deviceId),guard=0;while(parents.has(id)&&guard++<500){id=parents.get(id);if(collapsedBranches.delete(id))expanded=true}});if(expanded){renderFallbackTree();return}nodes.forEach(n=>n.classList.add('search-dim'));matches.forEach(n=>{n.classList.remove('search-dim');n.classList.add('search-match');let id=String(n.dataset.deviceId),guard=0;while(parents.has(id)&&guard++<500){id=parents.get(id);const p=canvas.querySelector(`[data-device-id="${CSS.escape(id)}"]`);if(!p)break;p.classList.remove('search-dim');p.classList.add('search-path')}});if(count)count.textContent=fmt(matches.length);if(matches[0])matches[0].scrollIntoView({behavior:'smooth',block:'center',inline:'nearest'})}
function renderGraph(){if(!topology)return;if(!(window.vis&&window.vis.Network)){renderFallbackTree();return}const allDevices=topology.devices||[],allLinks=topology.links||[];let devices=allDevices.filter(filtered);const hidden=new Set();collapsedBranches.forEach(id=>descendants(id,allLinks).forEach(x=>hidden.add(x)));devices=devices.filter(d=>!hidden.has(String(d.id)));const visibleIds=new Set(devices.map(d=>String(d.id)));const incoming=new Set(allLinks.filter(l=>visibleIds.has(String(l.parent_device_id))&&visibleIds.has(String(l.child_device_id))).map(l=>String(l.child_device_id)));const subscriberByParent={};devices.filter(d=>d.device_category==='subscriber').forEach(d=>{const link=allLinks.find(l=>String(l.child_device_id)===String(d.id));const p=link?String(link.parent_device_id):'root';(subscriberByParent[p]??=[]).push(d)});const groupedParents=new Set();if(!expandSubscribers)Object.entries(subscriberByParent).forEach(([p,list])=>{if(list.length>12){groupedParents.add(p);list.forEach(d=>visibleIds.delete(String(d.id)))}});const rootUnknown=devices.filter(d=>d.device_category==='unknown'&&!incoming.has(String(d.id))),groupUnknown=!expandSubscribers&&rootUnknown.length>25;if(groupUnknown)rootUnknown.forEach(d=>visibleIds.delete(String(d.id)));devices=devices.filter(d=>visibleIds.has(String(d.id)));root.querySelector('[data-canvas]').classList.remove('fallback-mode');
const nodes=[{id:'network-root',label:`🌐 ${networkMeta.name}\nبداية مسار التغذية`,shape:'box',color:{background:'#32110f',border:'#e5221a'},font:{color:'#fff',size:16,bold:true},borderWidth:3,level:0,fixed:false,raw:null}];
devices.forEach(d=>{const name=d.device_name||'Unknown Device',category=categoryLabels[d.device_category]||d.device_category,state=statusLabels[d.status]||d.status;nodes.push({id:String(d.id),label:`${icon(d.device_category)} ${name}\n${d.ip_address||'بدون IP'}\n${category} • ${state}`,shape:'box',margin:12,borderWidth:d.manual_verified==1?4:2,color:{background:categoryBackground(d.device_category),border:statusColor(d.status),highlight:{background:'#fff5f4',border:'#e5221a'}},font:{color:'#20232b',size:11,face:'Arial',multi:true},shadow:{enabled:true,color:'rgba(16,24,40,.12)',size:9,x:0,y:4},raw:d})});
groupedParents.forEach(p=>{const list=subscriberByParent[p];const id='group-'+p;nodes.push({id,label:`👥 ${list.length} مشترك\nاضغط للتوسيع`,shape:'box',color:{background:'#fff7ed',border:'#f79009'},font:{color:'#7a2e0e',size:11},borderWidth:2,isGroup:true,raw:{groupParent:p}})});if(groupUnknown)nodes.push({id:'group-root-unknown',label:`❔ ${rootUnknown.length} جهاز غير مصنف\nاضغط للتوسيع`,shape:'box',color:{background:'#f2f4f7',border:'#667085'},font:{color:'#344054',size:11},borderWidth:2,isGroup:true,raw:{groupParent:'root'}});
const edges=[];allLinks.forEach(l=>{if(visibleIds.has(String(l.parent_device_id))&&visibleIds.has(String(l.child_device_id))){const child=allDevices.find(d=>String(d.id)===String(l.child_device_id)),offline=child&&['offline','disabled','unreachable_parent'].includes(child.status),evidence=linkSourceLabel(l.discovery_source),speed=Number(l.parent_link_speed_mbps||0),port=l.parent_interface?`\n${l.parent_interface}${speed?' • '+speed+' Mbps':''}`:'';edges.push({from:String(l.parent_device_id),to:String(l.child_device_id),arrows:{to:{enabled:true,scaleFactor:.8}},label:`${evidence} • ${confidenceLabels[l.confidence]||l.confidence}${port}`,color:{color:offline?'#e5221a':(l.manual_verified==1?'#8b1e18':'#3975b9')},width:l.manual_verified==1?3:2,dashes:offline||l.confidence==='low',font:{size:8,color:offline?'#b42318':'#52657d',strokeWidth:4,strokeColor:'#fff'},smooth:{type:'cubicBezier'},raw:l})}});
devices.forEach(d=>{if(!incoming.has(String(d.id))){edges.push({from:'network-root',to:String(d.id),arrows:'to',dashes:true,color:{color:'#c7cad2'},label:'بدون أب موثق',font:{size:7,color:'#9ca1ae',strokeWidth:3,strokeColor:'#fff'}})}});groupedParents.forEach(p=>edges.push({from:p==='root'?'network-root':p,to:'group-'+p,arrows:'to',dashes:true,color:{color:'#f5a256'},label:'تجميع عرض فقط',font:{size:7,color:'#a35b10',strokeWidth:3,strokeColor:'#fff'}}));if(groupUnknown)edges.push({from:'network-root',to:'group-root-unknown',arrows:'to',dashes:true,color:{color:'#98a2b3'},label:'تجميع عرض فقط',font:{size:7,color:'#667085',strokeWidth:3,strokeColor:'#fff'}});
nodeSet=new vis.DataSet(nodes);edgeSet=new vis.DataSet(edges);const canvas=root.querySelector('[data-canvas]');canvas.innerHTML='';graphNetwork=new vis.Network(canvas,{nodes:nodeSet,edges:edgeSet},{layout:{hierarchical:{enabled:true,direction:'UD',sortMethod:'directed',nodeSpacing:165,treeSpacing:220,levelSeparation:120}},physics:false,interaction:{hover:true,multiselect:false,dragNodes:true,zoomView:true},edges:{selectionWidth:3}});
graphNetwork.on('click',p=>{if(!p.nodes.length)return;const n=nodeSet.get(p.nodes[0]);if(n?.isGroup){expandSubscribers=true;renderGraph();return}if(n?.raw){selectedId=Number(n.raw.id);loadDetails(selectedId)}});graphNetwork.on('dragEnd',p=>{if(!canManage||p.nodes.length!==1||String(p.nodes[0]).startsWith('group')||p.nodes[0]==='network-root')return;const child=String(p.nodes[0]),pos=graphNetwork.getPositions()[child];let nearest=null,dist=Infinity;Object.entries(graphNetwork.getPositions()).forEach(([id,xy])=>{if(id===child||id==='network-root'||id.startsWith('group'))return;const d=Math.hypot(xy.x-pos.x,xy.y-pos.y);if(d<dist){dist=d;nearest=id}});if(nearest&&dist<115&&confirm('تأكيد علاقة التوبولوجيا؟\n\n'+(nodeSet.get(nearest)?.raw?.device_name||nearest)+'\n↓\n'+(nodeSet.get(child)?.raw?.device_name||child))){saveManualLink(Number(child),Number(nearest))}})}
function focusDevice(id){selectedId=Number(id);const parents=treeParentMap();let cursor=String(id),changed=false,guard=0;while(parents.has(cursor)&&guard++<500){cursor=parents.get(cursor);if(collapsedBranches.delete(cursor))changed=true}if(changed)renderFallbackTree();const card=root.querySelector(`[data-device-id="${CSS.escape(String(id))}"]`);if(!card){loadDetails(Number(id));return}root.querySelectorAll('.comm-org-card.selected').forEach(x=>x.classList.remove('selected'));card.classList.add('selected');card.scrollIntoView({behavior:'smooth',block:'center',inline:'nearest'});loadDetails(Number(id))}
async function loadDetails(id){try{
const d=await get('details',{device_id:id}),x=d.device,drawer=document.getElementById('communicationDeviceDrawer'),path=[...(d.upstream||[]),x];
drawer.querySelector('[data-detail-name]').textContent=x.device_name||'Unknown Device';drawer.querySelector('[data-detail-ip]').textContent=x.ip_address||'بدون عنوان IP';
const evidenceLabel=x.evidence_label||linkSourceLabel(x.link_source,Number(x.link_manual)===1),physicalChildren=d.physical_children||[],rawEvidence=prettyEvidence(x.link_evidence_json);
const details=`<section class="comm-detail-section"><h4>التعريف</h4><div class="comm-detail-grid">${detail('الاسم',x.device_name||'Unknown Device')}${detail('IP',x.ip_address||'—')}${detail('MAC',x.mac_address||'—')}${detail('الشركة',x.vendor||'—')}${detail('الموديل',x.model||'—')}${detail('النوع',categoryLabels[x.device_category]||x.device_category)}</div></section>
<section class="comm-detail-section"><h4>العلاقة المادية</h4><div class="comm-detail-grid">${detail('الشبكة',x.network_name||'Unknown')}${detail('الجهاز الأب',x.parent_name||'جذر / لا يوجد أب مادي')}${detail('منفذ الجهاز الأب',x.parent_interface||'—')}${detail('منفذ هذا الجهاز',x.child_interface||'—')}${detail('الدليل',x.parent_device_id?evidenceLabel:'جذر')}${detail('درجة الثقة',confidenceLabels[x.link_confidence]||'—')}${detail('موثق يدويًا',x.link_manual==1?'نعم':'لا')}${detail('الأبناء الماديون',physicalChildren.length)}${detail('عملاء Wi-Fi',x.wifi_client_count||0)}</div>${rawEvidence?`<details class="comm-advanced-evidence"><summary>الدليل الخام — متقدم</summary><pre>${esc(rawEvidence)}</pre></details>`:''}</section>
<section class="comm-detail-section"><h4>الحالة الحية</h4><div class="comm-detail-grid">${detail('الحالة',statusLabels[x.status]||x.status)}${detail('آخر ظهور',x.last_seen||'—')}${detail('Uptime',x.uptime||'—')}${detail('RX',bytes(x.rx_bytes))}${detail('TX',bytes(x.tx_bytes))}${detail('مصدر بيانات الجهاز',x.discovery_source||'—')}</div></section>
${portsSection(d)}
<section class="comm-detail-section"><h4>المسار الصاعد</h4><div class="comm-path">${path.map(p=>`<span>${esc(p.device_name||p.ip_address||'Unknown')}</span>`).join('<i class="bi bi-arrow-left"></i>')}</div><button class="comm-btn" data-highlight-upstream>إظهار المسار في الشجرة</button></section>
<section class="comm-detail-section"><h4>الأبناء الماديون (${physicalChildren.length})</h4><div class="comm-downstream">${physicalChildren.map(v=>`<button data-detail-device="${Number(v.id)}"><span>${esc(v.device_name||v.ip_address||'Unknown')}</span><b>${esc(statusLabels[v.status]||v.status)}</b></button>`).join('')||'<small>لا يوجد أبناء ماديون نشطون.</small>'}</div><button class="comm-btn" data-highlight-downstream>تحديد كامل الفرع</button></section>
${canManage?manualForms(d):''}<section class="comm-detail-section"><h4>آخر مصادر الاكتشاف</h4><div class="comm-downstream">${(d.observations||[]).map(o=>`<button><span>${esc(o.source)}</span><b>${esc(o.observed_at)}</b></button>`).join('')||'<small>لا توجد ملاحظات.</small>'}</div></section>`;
drawer.querySelector('[data-detail-body]').innerHTML=details;
let modalBackdrop=document.getElementById('communicationDeviceModalBackdrop');
if(!modalBackdrop){
    modalBackdrop=document.createElement('div');
    modalBackdrop.id='communicationDeviceModalBackdrop';
    modalBackdrop.className='comm-device-modal-backdrop';
    document.body.appendChild(modalBackdrop);
}
drawer.classList.add('open');
drawer.setAttribute('aria-hidden','false');
modalBackdrop.classList.add('open');
document.body.classList.add('comm-device-modal-open');drawer.querySelectorAll('[data-detail-device]').forEach(b=>b.onclick=()=>focusDevice(Number(b.dataset.detailDevice)));drawer.querySelector('[data-highlight-upstream]')?.addEventListener('click',()=>highlightIds(path.map(p=>p.id)));drawer.querySelector('[data-highlight-downstream]')?.addEventListener('click',()=>highlightIds([x.id,...(d.downstream||[]).map(v=>v.id)]));installDetailForms(drawer,x)
}catch(e){toast(e.message,true)}}
function portsSection(d){const ports=d.interfaces||[];if(!ports.length)return`<section class="comm-detail-section"><h4>المنافذ</h4><small>لم تصل بيانات منافذ لهذا الجهاز من الجامع المقروء فقط.</small></section>`;return`<section class="comm-detail-section"><h4>المنافذ (${ports.length})</h4><div class="comm-port-legend"><span><i style="background:#98a2b3"></i> شاغر / معطّل</span><span><i style="background:#e5221a"></i> موصول 100Mbps أو أقل</span><span><i style="background:#12b76a"></i> موصول 1Gbps أو أكثر</span></div><div class="comm-ports">${ports.map(portCard).join('')}</div><small style="display:block;margin-top:9px;color:#7d8494">الأجهزة الظاهرة داخل المنافذ هنا أبناء ماديون نشطون فقط؛ عملاء Wi-Fi يظهر عددهم منفصلًا.</small></section>`}
function portCard(p){const connected=p.status==='connected',speed=Number(p.link_speed_mbps||0),kind=!connected?(p.status==='disabled'?'disabled':'free'):(speed>=1000?'gigabit':'fast'),speedText=!connected?(p.status==='disabled'?'معطّل':'شاغر'):(speed?speed+' Mbps':'سرعة غير معروفة'),devices=p.connected_devices||[];return`<article class="comm-port ${kind}"><div class="comm-port-head"><b>${esc(p.interface_name)}</b><span class="comm-port-speed">${esc(speedText)}</span></div><span class="comm-port-meta">${connected?(p.full_duplex==1?'Full duplex':'Half/unknown duplex'):'لا يوجد Link'}${p.comment?' • '+esc(p.comment):''}</span><div class="comm-port-devices">${devices.map(v=>`<button class="comm-port-device" data-detail-device="${Number(v.device_id)}"><span>${esc(v.device_name||'جهاز')}</span><code>${esc(v.ip_address||'—')}</code></button>`).join('')||'<small class="comm-port-empty">لا يوجد جهاز معروف خلف هذا المنفذ</small>'}</div></article>`}
function detail(k,v){return`<div><span>${esc(k)}</span><b>${esc(v)}</b></div>`}function bytes(v){let n=Number(v||0),u=['B','KB','MB','GB','TB'],i=0;while(n>=1024&&i<u.length-1){n/=1024;i++}return n.toFixed(i>1?2:0)+' '+u[i]}function prettyEvidence(value){if(!value)return'';try{return JSON.stringify(JSON.parse(String(value)),null,2)}catch(e){return String(value)}}
function manualForms(d){const x=d.device,parents=d.possible_parents||[],nets=d.networks||[];return`<section class="comm-detail-section"><h4>توثيق العلاقة يدويًا</h4>${x.network_id?`<form class="comm-form" data-link-form><select name="parent_id" required><option value="">اختر الجهاز الأب</option>${parents.map(p=>`<option value="${p.id}" ${Number(x.parent_device_id)===Number(p.id)?'selected':''}>${esc(p.device_name||p.ip_address||'Unknown')} — ${esc(p.ip_address||'')}</option>`).join('')}</select><input name="parent_interface" placeholder="واجهة الأب (اختياري)"><button class="comm-btn primary" type="submit">حفظ كعلاقة موثقة</button>${x.link_locked==1?'<button class="comm-btn danger" type="button" data-unlock-link>فتح العلاقة للاكتشاف التلقائي</button>':''}</form>`:'<p style="font-size:9px;color:#7d8494">صنّف الجهاز أولًا حتى يمكن اختيار أب من الشبكة نفسها.</p>'}</section><section class="comm-detail-section"><h4>التصنيف اليدوي</h4><form class="comm-form" data-classify-form><select name="network_id" required><option value="">اختر الشبكة</option>${nets.map(n=>`<option value="${n.id}" ${Number(x.network_id)===Number(n.id)?'selected':''}>${esc(n.name)}</option>`).join('')}</select><select name="device_category"><option value="general_modem" ${x.device_category==='general_modem'?'selected':''}>مودم / بنية عامة</option><option value="wireless" ${x.device_category==='wireless'?'selected':''}>جهاز استقبال وإرسال</option><option value="subscriber" ${x.device_category==='subscriber'?'selected':''}>مشترك</option><option value="unknown" ${x.device_category==='unknown'?'selected':''}>غير مصنف</option></select><button class="comm-btn" type="submit">تثبيت التصنيف</button></form></section>`}
function installDetailForms(drawer,x){drawer.querySelector('[data-link-form]')?.addEventListener('submit',async e=>{e.preventDefault();const f=new FormData(e.target);await saveManualLink(Number(x.id),Number(f.get('parent_id')),String(f.get('parent_interface')||''))});drawer.querySelector('[data-unlock-link]')?.addEventListener('click',async()=>{try{await post('unlock_link',{child_id:x.id});await openNetwork(Number(x.network_id),Number(x.id))}catch(e){toast(e.message,true)}});drawer.querySelector('[data-classify-form]')?.addEventListener('submit',async e=>{e.preventDefault();const f=new FormData(e.target);try{await post('classify_device',{device_id:x.id,network_id:f.get('network_id'),device_category:f.get('device_category')});await loadOverview();await openNetwork(Number(f.get('network_id')),Number(x.id))}catch(err){toast(err.message,true)}})}
async function saveManualLink(child,parent,parentInterface=''){try{await post('save_link',{child_id:child,parent_id:parent,parent_interface:parentInterface});await openNetwork(Number(topology.network.id),child)}catch(e){toast(e.message,true);renderFallbackTree()}}
function highlightIds(ids){const wanted=new Set(ids.map(String));if(graphNetwork&&nodeSet){const valid=[...wanted].filter(id=>nodeSet.get(id));graphNetwork.selectNodes(valid);if(valid.length)graphNetwork.fit({nodes:valid,animation:true});return}const cards=[...root.querySelectorAll('[data-tree-node]')];cards.forEach(c=>{c.classList.remove('search-path','search-dim');if(wanted.has(String(c.dataset.deviceId)))c.classList.add('search-path');else c.classList.add('search-dim')});const first=cards.find(c=>wanted.has(String(c.dataset.deviceId)));first?.scrollIntoView({behavior:'smooth',block:'center',inline:'center'})}
function closeCommunicationDeviceModal(){
    const d=document.getElementById('communicationDeviceDrawer');
    if(d){
        d.classList.remove('open');
        d.setAttribute('aria-hidden','true');
    }
    document.getElementById('communicationDeviceModalBackdrop')?.classList.remove('open');
    document.body.classList.remove('comm-device-modal-open');
}

document.querySelector('[data-close-drawer]').onclick=closeCommunicationDeviceModal;

document.addEventListener('click',e=>{
    if(e.target && e.target.id==='communicationDeviceModalBackdrop'){
        closeCommunicationDeviceModal();
    }
});

document.addEventListener('keydown',e=>{
    if(e.key==='Escape' && document.getElementById('communicationDeviceDrawer')?.classList.contains('open')){
        closeCommunicationDeviceModal();
    }
});
root.querySelector('[data-refresh-topology]').onclick=()=>topology&&openNetwork(Number(topology.network.id),selectedId,{force:true});
root.querySelectorAll('[data-filter]').forEach(b=>b.onclick=()=>{activeFilter=b.dataset.filter;root.querySelectorAll('[data-filter]').forEach(x=>x.classList.toggle('active',x===b));renderFallbackTree()});
root.querySelector('[data-expand-all]').onclick=()=>{collapsedBranches.clear();renderFallbackTree()};
root.querySelector('[data-collapse-all]').onclick=()=>{(topology?.links||[]).forEach(l=>collapsedBranches.add(String(l.parent_device_id)));renderFallbackTree()};
root.querySelector('[data-tree-search]').oninput=e=>applyTreeSearch(e.target.value);
root.querySelectorAll('[data-tool]').forEach(b=>b.onclick=()=>{const t=b.dataset.tool;if(t==='fullscreen'){root.querySelector('.comm-canvas-wrap')?.requestFullscreen?.();return}if(!topology)return;if(graphNetwork){if(t==='zoom-in')graphNetwork.moveTo({scale:graphNetwork.getScale()*1.2,animation:true});if(t==='zoom-out')graphNetwork.moveTo({scale:graphNetwork.getScale()*.8,animation:true});if(t==='fit')graphNetwork.fit({animation:true})}else{if(t==='zoom-in')treeScale=Math.min(1.8,treeScale+.1);if(t==='zoom-out')treeScale=Math.max(.45,treeScale-.1);if(t==='fit'){fitTreeToViewport(true);return}}if(t==='upstream'&&selectedId){const ids=[String(selectedId)],parents=treeParentMap();let id=String(selectedId),guard=0;while(parents.has(id)&&guard++<100){id=parents.get(id);ids.push(id)}highlightIds(ids)}if(t==='downstream'&&selectedId){const ids=[selectedId,...descendants(selectedId,topology.links||[])];highlightIds(Array.from(ids))}if((t==='upstream'||t==='downstream')&&!selectedId)toast('اختر جهازًا من الشجرة أولاً.')});
const searchInput=root.querySelector('[data-global-search]'),searchResults=root.querySelector('[data-search-results]');searchInput.oninput=()=>{clearTimeout(searchTimer);const q=searchInput.value.trim();if(!q){searchResults.classList.remove('show');return}searchTimer=setTimeout(async()=>{try{const d=await get('search',{q});searchResults.innerHTML=(d.results||[]).map(r=>`<button class="comm-search-item" data-search-device="${r.id}" data-search-network="${r.network_id??0}"><span><b>${esc(r.device_name||r.radius_username||'Unknown Device')}</b><small>${esc(r.network_name||'Unknown')} • ${esc(r.ip_address||'بدون IP')} • ${esc(r.mac_address||'بدون MAC')}</small></span><em>فتح في الشجرة</em></button>`).join('')||'<div class="comm-empty">لا توجد نتائج.</div>';searchResults.classList.add('show');searchResults.querySelectorAll('[data-search-device]').forEach(b=>b.onclick=()=>{searchResults.classList.remove('show');openNetwork(Number(b.dataset.searchNetwork),Number(b.dataset.searchDevice))})}catch(e){toast(e.message,true)}},280)};document.addEventListener('click',e=>{if(!e.target.closest('.comm-search-wrap'))searchResults.classList.remove('show')});
root.querySelector('[data-discover]')?.addEventListener('click',async e=>{const b=e.currentTarget,old=b.innerHTML;b.disabled=true;b.innerHTML='<i class="bi bi-hourglass-split"></i> جاري القراءة من الشبكات...';try{const d=await post('discover');await loadOverview();if(topology)await openNetwork(Number(topology.network.id));console.info('Communications discovery',d)}catch(err){toast(err.message,true)}finally{b.disabled=false;b.innerHTML=old}});
async function loadSettings(){try{settings=await get('settings');renderSettings()}catch(e){toast(e.message,true)}}
function renderSettings(){const nt=root.querySelector('[data-networks-table]'),rt=root.querySelector('[data-rules-table]');nt.innerHTML=`<table class="comm-table"><thead><tr><th>الشبكة</th><th>المعرّف</th><th>الأجهزة</th><th>الحالة</th>${canManage?'<th>إجراء</th>':''}</tr></thead><tbody>${settings.networks.map(n=>`<tr><td><b>${esc(n.name)}</b></td><td><code>${esc(n.network_key)}</code></td><td>${fmt(n.device_count)}</td><td><span class="comm-badge ${Number(n.enabled)?'on':''}">${Number(n.enabled)?'مفعلة':'معطلة'}</span></td>${canManage?`<td><button class="comm-btn" data-edit-network="${n.id}">تعديل</button></td>`:''}</tr>`).join('')}</tbody></table>`;rt.innerHTML=`<table class="comm-table"><thead><tr><th>الشبكة</th><th>الفئة</th><th>Subnet</th><th>الدور</th><th>الحالة</th>${canManage?'<th>إجراء</th>':''}</tr></thead><tbody>${settings.rules.map(r=>`<tr><td>${esc(r.network_name)}</td><td>${esc(categoryLabels[r.device_category]||r.device_category)}</td><td><code>${esc(r.subnet)}</code></td><td>${esc(r.expected_role||'—')}</td><td><span class="comm-badge ${Number(r.enabled)?'on':''}">${Number(r.enabled)?'مفعلة':'معطلة'}</span></td>${canManage?`<td><div class="comm-actions"><button class="comm-btn" data-edit-rule="${r.id}">تعديل</button><button class="comm-btn" data-toggle-rule="${r.id}">${Number(r.enabled)?'تعطيل':'تفعيل'}</button><button class="comm-btn danger" data-delete-rule="${r.id}"><i class="bi bi-trash"></i></button></div></td>`:''}</tr>`).join('')}</tbody></table>`;const sel=root.querySelector('[data-rule-network]');if(sel)sel.innerHTML=settings.networks.map(n=>`<option value="${n.id}">${esc(n.name)}</option>`).join('');bindSettings()}
function bindSettings(){root.querySelectorAll('[data-edit-network]').forEach(b=>b.onclick=()=>editNetwork(settings.networks.find(n=>Number(n.id)===Number(b.dataset.editNetwork))));root.querySelectorAll('[data-edit-rule]').forEach(b=>b.onclick=()=>editRule(settings.rules.find(r=>Number(r.id)===Number(b.dataset.editRule))));root.querySelectorAll('[data-toggle-rule]').forEach(b=>b.onclick=async()=>{try{await post('toggle_rule',{id:b.dataset.toggleRule});await loadSettings()}catch(e){toast(e.message,true)}});root.querySelectorAll('[data-delete-rule]').forEach(b=>b.onclick=async()=>{if(!confirm('حذف قاعدة العناوين هذه؟ الأجهزة الحالية لن تحذف، لكن التصنيف القادم سيتأثر.'))return;try{await post('delete_rule',{id:b.dataset.deleteRule});await loadSettings()}catch(e){toast(e.message,true)}})}
const nf=root.querySelector('[data-network-form]'),rf=root.querySelector('[data-rule-form]');function editNetwork(n=null){if(!nf)return;nf.hidden=false;nf.reset();nf.elements.id.value=n?.id||0;nf.elements.name.value=n?.name||'';nf.elements.network_key.value=n?.network_key||'';nf.elements.description.value=n?.description||'';nf.elements.display_order.value=n?.display_order||10;nf.elements.enabled.value=!n||Number(n.enabled)?'1':''}function editRule(r=null){if(!rf)return;rf.hidden=false;rf.reset();rf.elements.id.value=r?.id||0;rf.elements.network_id.value=r?.network_id||settings.networks[0]?.id||'';rf.elements.device_category.value=r?.device_category||'general_modem';rf.elements.expected_role.value=r?.expected_role||'';rf.elements.subnet.value=r?.subnet||'';rf.elements.priority.value=r?.priority||100;rf.elements.enabled.value=!r||Number(r.enabled)?'1':''}root.querySelector('[data-new-network]')?.addEventListener('click',()=>editNetwork());root.querySelector('[data-new-rule]')?.addEventListener('click',()=>editRule());root.querySelector('[data-cancel-network]')?.addEventListener('click',()=>nf.hidden=true);root.querySelector('[data-cancel-rule]')?.addEventListener('click',()=>rf.hidden=true);
[nf,rf].forEach(f=>f?.addEventListener('submit',async e=>{e.preventDefault();const data=Object.fromEntries(new FormData(f).entries()),action=data.action;delete data.action;delete data.csrf_token;try{await post(action,data);f.hidden=true;await loadSettings();await loadOverview()}catch(err){toast(err.message,true)}}));
async function loadHistory(){const box=root.querySelector('[data-history-table]');box.innerHTML='<div class="comm-loading">جاري تحميل السجل...</div>';try{const d=await get('history');box.innerHTML=`<table class="comm-table"><thead><tr><th>الوقت</th><th>الشبكة</th><th>الجهاز</th><th>الحدث</th><th>السابق</th><th>الجديد</th><th>المصدر</th><th>المنفذ</th></tr></thead><tbody>${(d.history||[]).map(h=>`<tr><td dir="ltr">${esc(h.created_at)}</td><td>${esc(h.network_name||'—')}</td><td>${esc(h.device_name||h.ip_address||'—')}</td><td><b>${esc(eventLabels[h.event_type]||h.event_type)}</b></td><td>${esc(h.old_value||'—')}</td><td>${esc(h.new_value||'—')}</td><td>${esc(h.source||'—')}</td><td>${esc(h.actor||'—')}</td></tr>`).join('')||'<tr><td colspan="8" class="comm-empty">لا توجد أحداث.</td></tr>'}</tbody></table>`}catch(e){box.innerHTML=`<div class="comm-empty">${esc(e.message)}</div>`}}
async function loadDevices(){const box=root.querySelector('[data-devices-table]');if(!box)return;box.innerHTML='<div class="comm-loading">جاري تحميل الأجهزة...</div>';const params={page:devicePage,per_page:200,network_id:root.querySelector('[data-device-network]')?.value||0,category:root.querySelector('[data-device-category]')?.value||'',q:root.querySelector('[data-device-search]')?.value.trim()||''};try{const d=await get('devices',params);devicePages=Math.max(1,Number(d.pages||1));devicePage=Math.min(devicePage,devicePages);box.innerHTML=`<table class="comm-table"><thead><tr><th>الجهاز</th><th>الشبكة</th><th>الفئة</th><th>IP</th><th>MAC</th><th>الحالة</th><th>آخر ظهور</th><th>تفاصيل</th></tr></thead><tbody>${(d.devices||[]).map(v=>`<tr><td><span class="comm-device-name"><i class="bi ${v.device_category==='subscriber'?'bi-person':(v.device_category==='wireless'?'bi-broadcast-pin':'bi-router')}"></i><b>${esc(v.device_name||v.radius_username||'Unknown Device')}</b></span></td><td>${esc(v.network_name||'—')}</td><td>${esc(categoryLabels[v.device_category]||v.device_category)}</td><td dir="ltr">${esc(v.ip_address||'—')}</td><td dir="ltr">${esc(v.mac_address||'—')}</td><td><span class="comm-badge ${v.status==='online'?'on':''}">${esc(statusLabels[v.status]||v.status)}</span></td><td dir="ltr">${esc(v.last_seen||'—')}</td><td><button class="comm-btn" data-device-open="${Number(v.id)}" data-device-open-network="${Number(v.network_id)}">فتح في الشجرة</button></td></tr>`).join('')||'<tr><td colspan="8" class="comm-empty">لا توجد أجهزة مطابقة.</td></tr>'}</tbody></table>`;root.querySelector('[data-device-page]').textContent=`صفحة ${fmt(devicePage)} من ${fmt(devicePages)} • ${fmt(d.total)} جهاز`;root.querySelector('[data-device-prev]').disabled=devicePage<=1;root.querySelector('[data-device-next]').disabled=devicePage>=devicePages;box.querySelectorAll('[data-device-open]').forEach(b=>b.onclick=()=>openNetwork(Number(b.dataset.deviceOpenNetwork),Number(b.dataset.deviceOpen)))}catch(e){box.innerHTML=`<div class="comm-empty">${esc(e.message)}</div>`}}

/* ===== WIRELESS TOWER TOPOLOGY V1 ===== */

(function installWirelessTowerStyles(){
    if(document.getElementById('comm-wireless-tower-style'))return;

    const style=document.createElement('style');
    style.id='comm-wireless-tower-style';

    style.textContent=`
    .comm-wireless-towers{
        width:max-content;
        min-width:min(100%,1100px);
        margin:55px auto 80px;
        padding:22px;
        border:1px solid #d9e3ea;
        border-radius:18px;
        background:rgba(255,255,255,.97);
        box-shadow:0 10px 30px rgba(20,35,50,.08);
        direction:rtl
    }

    .comm-wireless-towers-head{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:15px;
        margin-bottom:18px
    }

    .comm-wireless-towers-head h3{
        margin:0;
        font-size:16px;
        font-weight:900
    }

    .comm-wireless-towers-head p{
        margin:5px 0 0;
        color:#667085;
        font-size:9px
    }

    .comm-wireless-link{
        display:grid;
        grid-template-columns:230px minmax(180px,300px) 230px;
        align-items:center;
        justify-content:center;
        gap:10px;
        margin:16px 0;
        padding:12px;
        border:1px solid #eef1f4;
        border-radius:15px;
        background:#fafcfd
    }

    .comm-tower{
        position:relative;
        display:flex;
        align-items:center;
        gap:10px;
        min-height:88px;
        padding:12px;
        border:2px solid #12b76a;
        border-radius:14px;
        background:#fff;
        box-shadow:0 5px 14px rgba(20,30,40,.07)
    }

    .comm-tower.offline{
        border-color:#e5221a
    }

    .comm-tower-icon{
        display:grid;
        place-items:center;
        flex:0 0 50px;
        width:50px;
        height:60px;
        border-radius:12px;
        background:#edf8f4;
        font-size:31px
    }

    .comm-tower.offline .comm-tower-icon{
        background:#fff0ef
    }

    .comm-tower-info{
        min-width:0;
        flex:1
    }

    .comm-tower-role{
        display:inline-flex;
        margin-bottom:4px;
        padding:3px 7px;
        border-radius:999px;
        font-size:8px;
        font-weight:900
    }

    .comm-tower-role.tx{
        color:#175cd3;
        background:#eff8ff
    }

    .comm-tower-role.rx{
        color:#087a54;
        background:#eaf9f2
    }

    .comm-tower-info b{
        display:block;
        overflow:hidden;
        font-size:11px;
        text-overflow:ellipsis;
        white-space:nowrap
    }

    .comm-tower-info code{
        display:block;
        margin-top:4px;
        direction:ltr;
        color:#526174;
        font-size:10px
    }

    .comm-tower-info small{
        display:block;
        margin-top:4px;
        color:#667085;
        font-size:8px
    }

    .comm-wireless-air{
        position:relative;
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:center;
        min-width:180px
    }

    .comm-wireless-air-line{
        position:relative;
        width:100%;
        height:2px;
        margin:8px 0;
        border-top:3px dashed #4b87c6
    }

    .comm-wireless-air-line:after{
        content:'▶';
        position:absolute;
        top:-12px;
        left:-3px;
        color:#3975b9;
        font-size:18px
    }

    .comm-wireless-air b{
        color:#175cd3;
        font-size:9px
    }

    .comm-wireless-air small{
        color:#667085;
        font-size:8px
    }

    .comm-wireless-ap-grid{
        display:grid;
        grid-template-columns:repeat(3,minmax(210px,1fr));
        gap:10px;
        margin-top:22px;
        padding-top:18px;
        border-top:1px dashed #ccd5de
    }

    .comm-wireless-ap-only{
        padding:11px 12px;
        border:1px solid #dce5eb;
        border-radius:13px;
        background:#fff
    }

    .comm-wireless-ap-only-head{
        display:flex;
        align-items:center;
        gap:8px
    }

    .comm-wireless-ap-only .tower{
        font-size:25px
    }

    .comm-wireless-ap-only b{
        display:block;
        font-size:10px
    }

    .comm-wireless-ap-only code{
        display:block;
        margin-top:3px;
        direction:ltr;
        color:#526174;
        font-size:9px
    }

    .comm-wireless-ap-only small{
        display:block;
        margin-top:7px;
        color:#667085;
        font-size:8px
    }

    .comm-wireless-client-count{
        display:inline-flex;
        margin-top:7px;
        padding:4px 7px;
        border-radius:999px;
        color:#175cd3;
        background:#eff8ff;
        font-size:8px;
        font-weight:900
    }

    .dashboard-page.dark .comm-wireless-towers,
    .dashboard-page.dark .comm-tower,
    .dashboard-page.dark .comm-wireless-ap-only{
        background:#20232e;
        color:#eef0f5;
        border-color:#3a3e4b
    }

    @media(max-width:700px){
        .comm-wireless-towers{
            min-width:0;
            width:100%;
            margin:30px 0 60px;
            padding:12px
        }

        .comm-wireless-link{
            grid-template-columns:1fr;
            width:100%
        }

        .comm-wireless-air{
            min-height:80px
        }

        .comm-wireless-air-line{
            width:3px;
            height:60px;
            border-top:0;
            border-right:3px dashed #4b87c6
        }

        .comm-wireless-air-line:after{
            content:'▼';
            top:auto;
            bottom:-11px;
            left:-7px
        }

        .comm-wireless-ap-grid{
            grid-template-columns:1fr
        }
    }
    `;

    document.head.appendChild(style);
})();

function renderWirelessTowers(){

    if(!topology)return;

    const canvas=root.querySelector('[data-canvas]');
    if(!canvas)return;

    canvas.querySelector('.comm-wireless-towers')?.remove();

    const all=topology.devices||[];
    const byId=new Map(all.map(d=>[Number(d.id),d]));

    const pairs=[
        {tx:68,rx:69,ssid:'send(3)',freq:5775,signal:-56},
        {tx:70,rx:71,ssid:'send(6)',freq:5390,signal:-65},
        {tx:6,rx:7,ssid:'3D_NeT(12)',freq:5665,signal:-55},
        {tx:105,rx:106,ssid:'send(93)',freq:4990,signal:-78},
        {tx:108,rx:107,ssid:'3D_NeT(144)',freq:5850,signal:-34}
    ];

    const accessPoints=[
        {id:103,clients:2,ssid:'3D_NET(26)',freq:2412},
        {id:8,clients:2,ssid:'3D_NeT (34)',freq:2412},
        {id:9,clients:3,ssid:'3D_NET(44)',freq:2462},
        {id:53,clients:3,ssid:'3D_NET(48)',freq:2427},
        {id:73,clients:1,ssid:'3D_NET(47)',freq:2412},
        {id:74,clients:17,ssid:'3D_NET(71)',freq:2427}
    ];

    function towerName(d){
        if(!d)return 'برج غير معروف';

        let n=String(d.device_name||'')
            .replace(/^ether\d+\s*\|\s*/i,'')
            .replace(/\s*\|\s*[PNL]\s*\|.*$/i,'')
            .trim();

        return n||d.device_name||'برج';
    }

    function towerCard(d,role){
        if(!d)return '';

        const online=['online','degraded'].includes(String(d.status||''));

        return `
        <div class="comm-tower ${online?'':'offline'}" data-device-id="${Number(d.id)}">
            <div class="comm-tower-icon">📡</div>

            <div class="comm-tower-info">
                <span class="comm-tower-role ${role}">
                    ${role==='tx'?'برج إرسال / AP':'برج استقبال / Station'}
                </span>

                <b>${esc(towerName(d))}</b>
                <code>${esc(d.ip_address||'')}</code>

                <small>
                    ${online?'● متصل':'● غير متصل'}
                    ${d.mac_address?' • '+esc(d.mac_address):''}
                </small>
            </div>
        </div>`;
    }

    const validPairs=pairs.filter(x=>byId.has(x.tx)&&byId.has(x.rx));

    const pairHtml=validPairs.map(x=>{
        const tx=byId.get(x.tx);
        const rx=byId.get(x.rx);

        return `
        <div class="comm-wireless-link"
             data-wireless-tx="${x.tx}"
             data-wireless-rx="${x.rx}">

            ${towerCard(tx,'tx')}

            <div class="comm-wireless-air">
                <b>يبث إلى ←</b>

                <div class="comm-wireless-air-line"></div>

                <small>
                    ${esc(x.ssid)}
                    • ${fmt(x.freq)} MHz
                    • ${x.signal} dBm
                </small>
            </div>

            ${towerCard(rx,'rx')}
        </div>`;
    }).join('');

    const apHtml=accessPoints.map(x=>{
        const d=byId.get(x.id);
        if(!d)return '';

        const online=['online','degraded'].includes(String(d.status||''));

        return `
        <div class="comm-wireless-ap-only" data-device-id="${Number(d.id)}">
            <div class="comm-wireless-ap-only-head">
                <span class="tower">📡</span>
                <div>
                    <b>${esc(towerName(d))}</b>
                    <code>${esc(d.ip_address||'')}</code>
                </div>
            </div>

            <small>
                برج إرسال AP
                • ${esc(x.ssid)}
                • ${fmt(x.freq)} MHz
                • ${online?'متصل':'غير متصل'}
            </small>

            <span class="comm-wireless-client-count">
                ${fmt(x.clients)} مستقبل متصل
            </span>
        </div>`;
    }).join('');

    if(!pairHtml&&!apHtml)return;

    const section=document.createElement('section');
    section.className='comm-wireless-towers';

    section.innerHTML=`
        <div class="comm-wireless-towers-head">
            <div>
                <h3>📡 أبراج الإرسال والاستقبال</h3>
                <p>
                    الخط المتقطع = ارتباط لاسلكي وليس كابلًا ماديًا.
                    السهم يوضح اتجاه AP → Station.
                </p>
            </div>

            <span class="comm-live">Live</span>
        </div>

        ${pairHtml}

        ${apHtml?`
            <div class="comm-wireless-ap-grid">
                ${apHtml}
            </div>
        `:''}
    `;

    canvas.appendChild(section);
}

/*
 * Always add wireless towers after the normal physical tree is rendered.
 */
const renderFallbackTreePhysicalOnly=renderFallbackTree;

let manualDragChildId=0;

function bindManualTopologyDrag(){
    if(!canManage||!topology)return;

    const canvas=root.querySelector('[data-canvas]');
    if(!canvas)return;

    const gatewayId=Number(topology.gateway?.id||0);
    const devicesById=new Map((topology.devices||[]).map(d=>[Number(d.id),d]));
    const linksByChild=new Map((topology.links||[]).map(l=>[Number(l.child_device_id),l]));
    const candidates=[...canvas.querySelectorAll('[data-device-id],[data-observed-device]')];

    const elementId=el=>Number(el.dataset.deviceId||el.dataset.observedDevice||0);
    const label=id=>{
        const d=devicesById.get(Number(id));
        return d
            ? `${d.device_name||d.ip_address||('جهاز '+id)}${d.ip_address?` (${d.ip_address})`:''}`
            : `جهاز ${id}`;
    };

    candidates.forEach(el=>{
        const id=elementId(el);
        if(!id)return;

        el.dataset.manualLinkDevice=String(id);
        el.setAttribute('data-tree-node','');
        el.draggable=id!==gatewayId;

        el.ondragstart=e=>{
            if(id===gatewayId){e.preventDefault();return}
            manualDragChildId=id;
            e.dataTransfer.setData('text/plain',String(id));
            e.dataTransfer.effectAllowed='move';
            el.classList.add('manual-link-dragging');
        };

        el.ondragend=()=>{
            manualDragChildId=0;
            canvas.querySelectorAll('.manual-link-dragging,.manual-link-drag-target')
                .forEach(x=>x.classList.remove('manual-link-dragging','manual-link-drag-target'));
        };

        el.ondragover=e=>{
            if(!manualDragChildId||manualDragChildId===id)return;
            e.preventDefault();
            e.dataTransfer.dropEffect='move';
            el.classList.add('manual-link-drag-target');
        };

        el.ondragleave=()=>el.classList.remove('manual-link-drag-target');

        el.ondrop=e=>{
            e.preventDefault();
            el.classList.remove('manual-link-drag-target');

            const child=Number(e.dataTransfer.getData('text/plain')||manualDragChildId||0);
            const parent=id;
            manualDragChildId=0;

            if(!child||!parent||child===parent)return;

            const oldLink=linksByChild.get(child);
            const replacing=oldLink&&Number(oldLink.parent_device_id)!==parent
                ? `\n\nسيتم استبدال الأب الحالي: ${label(oldLink.parent_device_id)}`
                : '';

            if(confirm(
                `تأكيد علاقة التغذية اليدوية؟\n\n`+
                `${label(parent)}\nيغذي ↓\n${label(child)}`+
                replacing+
                `\n\nستُثبت العلاقة ولن يغيرها الاكتشاف التلقائي.`
            )){
                saveManualLink(child,parent);
            }
        };
    });

    const tree=canvas.querySelector('.comm-org-tree');
    if(tree&&!tree.querySelector('.comm-manual-drag-help')){
        tree.insertAdjacentHTML('afterbegin','<div class="comm-manual-drag-help"><i class="bi bi-arrows-move"></i> اسحب الجهاز الذي يستقبل الخدمة وأفلته فوق الجهاز الذي يغذّيه</div>');
    }
}

renderFallbackTree=function(){
    renderFallbackTreePhysicalOnly();
    bindManualTopologyDrag();
};


function applyTopologyStatus(data){
    topologyStatusSignature=String(data.signature||topologyStatusSignature);
    if(data.not_modified||!topology)return false;

    const changes=new Map(
        (data.devices||[]).map(d=>[
            String(d.id),
            String(d.status||'unknown')
        ])
    );

    let changed=false;

    const updateRows=rows=>(rows||[]).forEach(row=>{
        const next=changes.get(String(row?.id));

        if(next!==undefined && String(row.status)!==next){
            row.status=next;
            changed=true;
        }
    });

    updateRows(topology.devices);
    updateRows(topology.unresolved_devices);

    if(topology.gateway){
        updateRows([topology.gateway]);
    }

    Object.values(topology.observed_behind_ports||{}).forEach(updateRows);

    /*
     * Do not render path health from raw status only.
     *
     * path_status / blocked_by / affected_descendants are calculated by the
     * backend from the complete trusted physical graph. When a raw device
     * status changes, watchTopologyStatusFast() immediately fetches one fresh
     * topology snapshot so the whole affected branch is recalculated.
     */
    if(changed){
        renderSummary();
    }

    return changed;
}

async function watchTopologyStatusFast(){
    if(topologyStatusBusy||topologyRequestBusy||!root.isConnected||!topology?.network?.id)return;
    const view=root.querySelector('[data-view="topology"]');if(!view||view.hidden)return;
    topologyStatusBusy=true;
    try{
        const networkId=Number(topology.network.id);

        const data=await get('topology_status',{
            network_id:networkId,
            signature:topologyStatusSignature
        });

        const statusChanged=applyTopologyStatus(data);

        /*
         * A status transition can change the health of an entire downstream
         * branch. Fetch the full topology only on that transition, never on
         * every two-second poll.
         */
        if(statusChanged && !topologyRequestBusy){
            const fresh=await get('topology',{
                network_id:networkId,
                revision:''
            });

            if(
                fresh &&
                !fresh.not_modified &&
                topology &&
                Number(topology.network?.id)===networkId
            ){
                applyTopologyData(fresh,null,true);
            }
        }
    }
    catch(e){console.warn('Topology status poll:',e)}
    finally{topologyStatusBusy=false}
}

async function watchTopologyFast(){
    if(topologyWatchBusy||topologyRequestBusy||!root.isConnected||!topology?.network?.id)return;
    const view=root.querySelector('[data-view="topology"]');if(!view||view.hidden)return;
    topologyWatchBusy=true;
    try{
        const networkId=Number(topology.network.id);
        const fresh=await get('topology',{network_id:networkId,revision:String(topology.revision||'')});
        if(fresh.not_modified||!topology||Number(topology.network?.id)!==networkId)return;
        applyTopologyData(fresh,null,true);
    }catch(e){console.warn('Topology slow refresh:',e)}
    finally{topologyWatchBusy=false}
}

async function communicationsPollCycle(){
    if(pollCycleBusy||overviewRequestBusy||topologyRequestBusy||!root.isConnected)return;
    pollCycleBusy=true;
    try{
        await loadLiveStatus();
        await watchTopologyStatusFast();
        const now=Date.now();
        if(now-lastTopologyWatchAt>=15000){lastTopologyWatchAt=now;await watchTopologyFast()}
    }finally{pollCycleBusy=false}
}

loadOverview=loadOverviewFast;
openNetwork=openNetworkFast;
const cachedOverviewData=cacheRead(overviewCacheKey);
if(Array.isArray(cachedOverviewData?.networks))renderOverviewData(cachedOverviewData.networks);
root.querySelector('[data-reload-history]').onclick=loadHistory;root.querySelector('[data-reload-devices]').onclick=()=>{devicePage=1;loadDevices()};root.querySelector('[data-device-prev]').onclick=()=>{if(devicePage>1){devicePage--;loadDevices()}};root.querySelector('[data-device-next]').onclick=()=>{if(devicePage<devicePages){devicePage++;loadDevices()}};root.querySelectorAll('[data-device-network],[data-device-category]').forEach(el=>el.onchange=()=>{devicePage=1;loadDevices()});root.querySelector('[data-device-search]').oninput=()=>{clearTimeout(deviceSearchTimer);devicePage=1;deviceSearchTimer=setTimeout(loadDevices,250)};
requestAnimationFrame(async()=>{await loadOverview();await communicationsPollCycle()});
const liveTicker=window.setInterval(()=>{if(!root.isConnected){window.clearInterval(liveTicker);return}communicationsPollCycle()},2000);
const inventoryTicker=window.setInterval(()=>{if(!root.isConnected){window.clearInterval(inventoryTicker);return}if(!pollCycleBusy&&!root.querySelector('[data-view="overview"]').hidden)loadOverview()},30000);
})();

// Offline devices popup
async function showOfflineDevices(type, title){
    let modal=document.getElementById('offlineDevicesModal');

    if(!modal){
        modal=document.createElement('div');
        modal.id='offlineDevicesModal';
        modal.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:none;align-items:center;justify-content:center;padding:20px';

        modal.innerHTML=`
        <div style="background:#fff;border-radius:16px;width:min(900px,95%);max-height:80vh;overflow:auto;padding:20px;direction:rtl">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <h3 id="offlineTitle"></h3>
                <button id="offlineClose" style="border:0;background:#eee;border-radius:8px;padding:8px 15px">إغلاق</button>
            </div>
            <div id="offlineBody" style="margin-top:15px"></div>
        </div>`;

        document.body.appendChild(modal);

        modal.querySelector('#offlineClose').onclick=()=>{
            modal.style.display='none';
        };
    }

    modal.style.display='flex';

    modal.querySelector('#offlineTitle').textContent=title;
    modal.querySelector('#offlineBody').innerHTML='جاري التحميل...';

    try{
        const res=await fetch('nawa-communications-api.php?action=offline_devices&type='+type);
        const data=await res.json();

        let rows=data.devices||[];

        if(!rows.length){
            modal.querySelector('#offlineBody').innerHTML='لا توجد أجهزة مقطوعة';
            return;
        }

        modal.querySelector('#offlineBody').innerHTML=`
        <table style="width:100%;border-collapse:collapse">
        <thead>
        <tr>
        <th>IP</th>
        <th>MAC</th>
        <th>الشبكة</th>
        <th>آخر ظهور</th>
        </tr>
        </thead>
        <tbody>
        ${rows.map(d=>`
        <tr>
        <td>${d.ip_address||'-'}</td>
        <td>${d.mac_address||'-'}</td>
        <td>${d.network_name||'-'}</td>
        <td>${d.last_seen||'-'}</td>
        </tr>`).join('')}
        </tbody>
        </table>`;
    }catch(e){
        modal.querySelector('#offlineBody').innerHTML='خطأ في جلب البيانات';
    }
}


// buttons
document.addEventListener('click',e=>{
    let b=e.target.closest('[data-offline-type]');
    if(!b)return;

    let type=b.dataset.offlineType;

    let names={
        subscriber:'المشتركون المقطوعون',
        general_modem:'مودمات الإرسال المقطوعة',
        wireless:'مودمات الاستقبال المقطوعة'
    };

    showOfflineDevices(type,names[type]||'الأجهزة المقطوعة');
});


async function openOfflineList(type,title){

    let modal=document.getElementById('offlineListModal');

    if(!modal){
        modal=document.createElement('div');
        modal.id='offlineListModal';
        modal.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99999;display:none;align-items:center;justify-content:center;padding:20px';

        modal.innerHTML=`
        <div style="background:white;width:min(900px,95%);max-height:80vh;overflow:auto;border-radius:18px;padding:20px;direction:rtl">
            <div style="display:flex;justify-content:space-between">
                <h3 id="offlineListTitle"></h3>
                <button id="offlineListClose">إغلاق</button>
            </div>
            <div id="offlineListBody"></div>
        </div>`;

        document.body.appendChild(modal);

        modal.querySelector('#offlineListClose').onclick=()=>{
            modal.style.display='none';
        };
    }

    modal.style.display='flex';
    modal.querySelector('#offlineListTitle').textContent=title;
    modal.querySelector('#offlineListBody').innerHTML='جاري التحميل...';

    try{

        let r=await fetch('nawa-communications-api.php?action=offline_devices&type='+type);
        let d=await r.json();

        let rows=d.devices||[];

        if(!rows.length){
            modal.querySelector('#offlineListBody').innerHTML='لا توجد أجهزة';
            return;
        }

        modal.querySelector('#offlineListBody').innerHTML=`
        <div style="margin:10px 0;font-weight:800">
            عدد الأجهزة: ${rows.length}
        </div>

        <table style="width:100%;margin-top:15px;border-collapse:collapse">
        <thead>
        <tr>
        <th>الحالة</th>
        <th>IP</th>
        <th>MAC</th>
        <th>الشبكة</th>
        <th>آخر ظهور</th>
        </tr>
        </thead>
        <tbody>
        ${rows.map(x=>`
        <tr>
        <td style="color:#d8241c;font-weight:800">DOWN</td>
        <td>${x.ip_address||'-'}</td>
        <td>${x.mac_address||'-'}</td>
        <td>${x.network_name||'-'}</td>
        <td>${x.last_seen||'-'}</td>
        </tr>`).join('')}
        </tbody>
        </table>`;
    }
    catch(e){
        modal.querySelector('#offlineListBody').innerHTML='خطأ في جلب البيانات';
    }
}


document.addEventListener('click',e=>{

    let btn=e.target.closest('[data-offline-type]');
    if(!btn)return;

    let type=btn.dataset.offlineType;

    if(type==='general_modem')
        openOfflineList(type,'📡 أجهزة الإرسال المقطوعة');

    if(type==='wireless')
        openOfflineList(type,'📥 أجهزة الاستقبال المقطوعة');

});
</script>

<style id="comm-final-physical-map">
/* ===== 3D Communications - Physical Map ===== */

.comm-tree-viewport{
    overflow:auto !important;
    padding:32px 40px 80px !important;
}

.comm-tree-stage{
    width:max-content !important;
    min-width:100% !important;
    transform-origin:top center !important;
}

.comm-network-tree{
    position:relative !important;
    width:max-content !important;
    min-width:1000px !important;
    padding:20px 70px 100px !important;
    margin:0 auto !important;
}

.comm-gateway-wrap{
    display:flex !important;
    justify-content:center !important;
    margin:0 auto 75px !important;
    position:relative !important;
    z-index:5 !important;
}

.comm-gateway-card{
    min-width:250px !important;
    position:relative !important;
    z-index:5 !important;
}

.comm-port-tree{
    display:flex !important;
    flex-wrap:nowrap !important;
    align-items:flex-start !important;
    justify-content:center !important;
    gap:80px !important;
    width:max-content !important;
    margin:0 auto !important;
    padding:0 !important;
}

.comm-port-branch{
    position:relative !important;
    flex:none !important;
    padding-top:0 !important;
}

.comm-port-node{
    width:190px !important;
    min-height:64px !important;
    margin:0 auto 78px !important;
    position:relative !important;
    z-index:5 !important;
}

.physical-tree .comm-port-roots{
    display:flex !important;
    flex-wrap:nowrap !important;
    align-items:flex-start !important;
    justify-content:center !important;
    padding:0 !important;
    margin:0 auto !important;
}

.physical-tree .comm-port-roots li{
    position:relative !important;
    list-style:none !important;
    padding:0 !important;
    margin:0 !important;
}

.physical-tree .comm-port-roots li > ul{
    display:flex !important;
    flex-wrap:nowrap !important;
    align-items:flex-start !important;
    justify-content:center !important;
    padding-top:90px !important;
    margin:0 auto !important;
}

.comm-node-row{
    width:210px !important;
    min-width:210px !important;
    max-width:210px !important;
    margin:0 auto !important;
    position:relative !important;
    z-index:5 !important;
}

.comm-org-card,
.comm-physical-tower-card{
    position:relative !important;
    z-index:5 !important;
    width:210px !important;
    box-sizing:border-box !important;
}

.comm-tree-connectors{
    position:absolute !important;
    inset:0 !important;
    width:100% !important;
    height:100% !important;
    overflow:visible !important;
    pointer-events:none !important;
    z-index:1 !important;
}

/* SVG is the only source of physical connector lines */
.physical-tree .comm-port-roots:before,
.physical-tree .comm-port-roots:after,
.physical-tree .comm-port-roots li:before,
.physical-tree .comm-port-roots li:after,
.physical-tree .comm-port-roots li > ul:before,
.physical-tree .comm-port-roots li > ul:after,
.physical-tree .comm-port-roots li > ul > li:before,
.physical-tree .comm-port-roots li > ul > li:after{
    content:none !important;
    display:none !important;
}

/* Clear visual separation between cards and routes */
.comm-org-card,
.comm-physical-tower-card,
.comm-port-node,
.comm-gateway-card{
    isolation:isolate !important;
}

/* Long physical chains need breathing room */
.physical-tree .comm-port-roots li > ul{
    gap:54px !important;
}

/* Keep auxiliary/unresolved devices visually separate */
.comm-port-observed-inline{
    position:relative !important;
    z-index:5 !important;
    margin-top:50px !important;
}

@media (max-width:900px){
    .comm-tree-viewport{
        padding:20px !important;
    }

    .comm-network-tree{
        min-width:900px !important;
        padding-left:35px !important;
        padding-right:35px !important;
    }
}
</style>



<style id="network2-tower-same-as-network1">
.comm-port-observed-grid{
    display:flex !important;
    flex-wrap:wrap !important;
    align-items:flex-start !important;
    justify-content:center !important;
    gap:28px !important;
    width:max-content !important;
    min-width:100% !important;
}

.comm-port-observed-grid .comm-real-tower-pair{
    flex:0 0 auto !important;
    width:auto !important;
    min-width:260px !important;
    max-width:none !important;
    margin:0 !important;
}

.comm-port-observed-grid .comm-real-tower{
    min-width:250px !important;
    width:250px !important;
}

.comm-port-observed-grid .comm-real-tower-pair:not(.single){
    min-width:560px !important;
}

.comm-port-observed-inline{
    width:max-content !important;
    min-width:100% !important;
}
</style>


<style id="comm-schematic-map-v1">

/* =========================================================
   SCHEMATIC PHYSICAL MAP
   MikroTik -> ether -> devices -> wireless -> downstream
   ========================================================= */

.physical-tree{
    background:#fff !important;
}

.comm-network-tree{
    padding:35px 80px 120px !important;
}

/* ---------- MikroTik root ---------- */

.comm-gateway-wrap{
    margin-bottom:70px !important;
}

.comm-gateway-card{
    width:180px !important;
    min-width:180px !important;
    border:0 !important;
    box-shadow:none !important;
    background:transparent !important;
    flex-direction:column !important;
    gap:4px !important;
    padding:0 !important;
}

.comm-gateway-icon{
    width:94px !important;
    height:60px !important;
    border:3px solid #1763aa !important;
    border-radius:50% / 32% !important;
    background:#fff !important;
    color:#1763aa !important;
    font-size:34px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    margin:auto !important;
}

.comm-gateway-card > span:last-child{
    text-align:center !important;
}

.comm-gateway-card b{
    display:block !important;
    font-size:18px !important;
    color:#111 !important;
    margin-top:7px !important;
}

.comm-gateway-card code{
    font-size:10px !important;
}

/* ---------- MikroTik ports ---------- */

.comm-port-tree{
    gap:95px !important;
}

.comm-port-node{
    width:150px !important;
    min-height:26px !important;
    height:auto !important;
    border:0 !important;
    box-shadow:none !important;
    background:transparent !important;
    padding:0 !important;
    margin-bottom:55px !important;
    text-align:center !important;
}

.comm-port-node > i{
    display:none !important;
}

.comm-port-node b{
    display:block !important;
    font-family:Arial,sans-serif !important;
    font-size:15px !important;
    font-weight:800 !important;
    color:#161616 !important;
}

.comm-port-node small{
    display:none !important;
}

/* ---------- Normal infrastructure devices ---------- */

.physical-tree .comm-org-card{
    width:135px !important;
    min-width:135px !important;
    max-width:135px !important;
    min-height:95px !important;

    border:0 !important;
    box-shadow:none !important;
    background:transparent !important;

    flex-direction:column !important;
    justify-content:flex-start !important;
    align-items:center !important;

    padding:0 !important;
    overflow:visible !important;
}

.physical-tree .comm-org-icon{
    width:82px !important;
    height:48px !important;
    min-width:82px !important;

    border:2px solid #315984 !important;
    border-radius:8px !important;
    background:linear-gradient(#fff,#edf2f7) !important;

    color:#315984 !important;
    font-size:24px !important;

    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
}

.physical-tree .comm-org-info{
    width:150px !important;
    text-align:center !important;
    overflow:visible !important;
}

.physical-tree .comm-org-info b{
    margin-top:7px !important;
    font-size:13px !important;
    color:#111 !important;
    white-space:normal !important;
    overflow:visible !important;
}

.physical-tree .comm-org-info code{
    margin-top:2px !important;
    font-size:9px !important;
    text-align:center !important;
}

.physical-tree .comm-tree-state,
.physical-tree .comm-node-badges{
    display:none !important;
}

/* ---------- Tree spacing ---------- */

.comm-node-row{
    width:155px !important;
    min-width:155px !important;
    max-width:155px !important;
}

.physical-tree .comm-port-roots li > ul{
    padding-top:70px !important;
    gap:60px !important;
}

/* ---------- Branch +/- ---------- */

.comm-branch-toggle{
    width:18px !important;
    height:18px !important;
    min-width:18px !important;
    padding:0 !important;
    margin-top:2px !important;
    font-size:10px !important;
}

/* ---------- Wireless towers ---------- */

.comm-real-tower-pair{
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:22px !important;
    width:auto !important;
    min-width:180px !important;
    background:transparent !important;
    border:0 !important;
    box-shadow:none !important;
}

.comm-real-tower-pair.single{
    min-width:155px !important;
}

.comm-real-tower{
    width:150px !important;
    min-width:150px !important;
    border:0 !important;
    box-shadow:none !important;
    background:transparent !important;
    padding:0 !important;
    overflow:visible !important;
}

.comm-real-tower svg{
    width:65px !important;
    height:85px !important;
}

.comm-real-tower-info{
    display:block !important;
    text-align:center !important;
}

.comm-real-tower-info b{
    font-size:12px !important;
    color:#111 !important;
}

.comm-real-tower-info code{
    display:block !important;
    font-size:9px !important;
}

.comm-real-tower-info small{
    font-size:10px !important;
    font-weight:800 !important;
    color:#195da5 !important;
}

/* wireless beam like reference image */
.comm-wireless-beam{
    width:95px !important;
    min-width:95px !important;
    position:relative !important;
}

.comm-wireless-beam-line{
    display:block !important;
    width:100% !important;
    border-top:3px dashed #1763aa !important;
}

.comm-wireless-beam-arrow{
    color:#1763aa !important;
}

/* ---------- Observed/unresolved: no giant boxes ---------- */

.comm-port-observed-inline{
    border:0 !important;
    background:transparent !important;
    box-shadow:none !important;
}

.comm-port-observed-title,
.comm-port-observed-note{
    display:none !important;
}

.comm-port-observed-grid{
    display:flex !important;
    flex-wrap:wrap !important;
    justify-content:center !important;
    gap:45px !important;
}

/* ---------- SVG physical connectors ---------- */

.comm-tree-wire{
    stroke:#202020 !important;
    stroke-width:2 !important;
}

.comm-tree-wire.wireless,
.comm-tree-wire[data-wireless="1"]{
    stroke:#1763aa !important;
    stroke-dasharray:9 8 !important;
    stroke-width:3 !important;
}

/* ---------- remove card emphasis ---------- */

.physical-tree .online,
.physical-tree .offline,
.physical-tree .degraded{
    box-shadow:none;
}

/* Keep huge networks readable with scrolling */
.comm-tree-viewport{
    overflow:auto !important;
}

.comm-tree-stage{
    width:max-content !important;
    min-width:100% !important;
}

</style>

<style id="comm-real-schematic-wireless-v2">

/* Wireless is a NODE in the topology, not a separate boxed widget */
.physical-tree .comm-physical-tower-card{
    width:155px !important;
    min-width:155px !important;
    max-width:155px !important;
    min-height:135px !important;
    padding:0 !important;
    border:0 !important;
    box-shadow:none !important;
    background:transparent !important;
    overflow:visible !important;
    display:flex !important;
    flex-direction:column !important;
    align-items:center !important;
    justify-content:flex-start !important;
}

.physical-tree .comm-physical-tower-picture{
    width:70px !important;
    height:82px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
}

.physical-tree .comm-physical-tower-picture svg{
    width:62px !important;
    height:78px !important;
}

.physical-tree .comm-physical-tower-info{
    width:170px !important;
    text-align:center !important;
}

.physical-tree .comm-physical-tower-info b{
    display:block !important;
    font-size:13px !important;
    color:#111 !important;
    white-space:normal !important;
}

.physical-tree .comm-physical-tower-info code{
    display:block !important;
    font-size:9px !important;
}

.physical-tree .comm-physical-tower-info .role{
    display:block !important;
    font-size:10px !important;
    font-weight:800 !important;
    color:#1763aa !important;
}

/* Hide old status decoration in schematic view */
.physical-tree .comm-physical-tower-info .state{
    display:none !important;
}

/* Wireless topology edge */
.physical-tree li.wireless-link{
    position:relative !important;
}

/* no old embedded tower-pair presentation inside physical tree */
.physical-tree .comm-real-tower-pair{
    border:0 !important;
    box-shadow:none !important;
    background:transparent !important;
}

</style>

<style id="comm-network2-ether7-v1">

.comm-ether7-schematic{
    display:flex !important;
    flex-direction:column !important;
    align-items:center !important;
    gap:45px !important;
    width:700px !important;
    max-width:700px !important;
    margin:45px auto 0 !important;
    position:relative !important;
}

/* cable from ether7 into wireless section */
.comm-ether7-schematic:before{
    content:"" !important;
    position:absolute !important;
    top:-45px !important;
    left:50% !important;
    height:42px !important;
    border-left:2px solid #222 !important;
}

.comm-wireless-path{
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:20px !important;
    width:max-content !important;
}

.comm-wireless-path-node{
    display:flex !important;
    flex-direction:column !important;
    align-items:center !important;
}

.comm-wireless-path-node .comm-physical-tower-card{
    width:145px !important;
    min-width:145px !important;
    max-width:145px !important;
}

.comm-wireless-role{
    margin-top:5px !important;
    font-size:13px !important;
    font-weight:900 !important;
    color:#111 !important;
}

.comm-wireless-path-beam{
    display:flex !important;
    align-items:center !important;
    width:110px !important;
}

.comm-wireless-path-beam span{
    flex:1 !important;
    border-top:3px dashed #1763aa !important;
}

.comm-wireless-path-beam b{
    color:#1763aa !important;
    font-size:18px !important;
}

/* Remaining ether7 radios: compact rows, not one giant horizontal line */
.comm-ether7-others{
    display:grid !important;
    grid-template-columns:repeat(3, 170px) !important;
    justify-content:center !important;
    align-items:start !important;
    gap:38px 50px !important;

    width:620px !important;
    padding-top:35px !important;
    position:relative !important;
}

.comm-ether7-others:before{
    content:"أجهزة لاسلكية أخرى خلف ether7" !important;
    position:absolute !important;
    top:0 !important;
    left:0 !important;
    right:0 !important;

    text-align:center !important;
    font-size:11px !important;
    font-weight:800 !important;
    color:#667085 !important;
}

.comm-ether7-device{
    display:flex !important;
    justify-content:center !important;
    width:170px !important;
}

.comm-ether7-device .comm-physical-tower-card{
    width:150px !important;
    min-width:150px !important;
    max-width:150px !important;
}

</style>

<style id="comm-topology-schematic-v5">

/* ===== Make the actual physical tree compact ===== */

.physical-tree .comm-port-roots li > ul{
    padding-top:52px !important;
    gap:38px !important;
    flex-wrap:wrap !important;
}

.physical-tree .comm-port-roots{
    gap:45px !important;
    flex-wrap:wrap !important;
}

.physical-tree .comm-node-row{
    position:relative !important;
    z-index:5 !important;
    width:170px !important;
    min-width:170px !important;
    max-width:170px !important;
}

/* ===== Ether7 wireless branch ===== */

.comm-ether7-schematic{
    width:560px !important;
    max-width:560px !important;
    margin:28px auto 0 !important;
    gap:28px !important;
}

/* real visible feed from ether7 */
.comm-ether7-schematic:before{
    top:-29px !important;
    height:29px !important;
    border-left:2px solid #344054 !important;
}

/* SEND -> STATION */
.comm-wireless-path{
    direction:ltr !important;
    gap:12px !important;
}

.comm-wireless-path-node{
    width:145px !important;
}

.comm-wireless-path-node.tx{
    order:1 !important;
}

.comm-wireless-path-beam{
    order:2 !important;
    width:90px !important;
}

.comm-wireless-path-node.rx{
    order:3 !important;
}

.comm-wireless-path-beam span{
    border-top:3px dashed #1769aa !important;
}

.comm-wireless-path-beam b{
    transform:none !important;
}

/* remaining radios: compact instead of giant empty area */
.comm-ether7-others{
    width:520px !important;
    grid-template-columns:repeat(3,150px) !important;
    gap:24px 30px !important;
    padding-top:28px !important;
}

.comm-ether7-device{
    width:150px !important;
}

/* ===== Towers readable ===== */

.comm-ether7-schematic .comm-physical-tower-card{
    width:145px !important;
    min-width:145px !important;
    max-width:145px !important;
    min-height:125px !important;
}

.comm-ether7-schematic .comm-physical-tower-picture{
    width:68px !important;
    height:72px !important;
}

.comm-ether7-schematic .comm-physical-tower-picture svg{
    width:58px !important;
    height:70px !important;
}

.comm-ether7-schematic .comm-physical-tower-info{
    width:145px !important;
}

.comm-ether7-schematic .comm-physical-tower-info b{
    font-size:11px !important;
    line-height:1.2 !important;
}

.comm-ether7-schematic .comm-physical-tower-info code{
    font-size:9px !important;
}

/* ===== SVG lines must remain above background and below devices ===== */

.comm-network-tree{
    position:relative !important;
}

.comm-tree-connectors{
    position:absolute !important;
    inset:0 !important;
    width:100% !important;
    height:100% !important;
    overflow:visible !important;
    pointer-events:none !important;
    z-index:2 !important;
}

.comm-network-tree .comm-node-row,
.comm-network-tree .comm-port-node,
.comm-network-tree .comm-gateway-card,
.comm-network-tree .comm-ether7-schematic{
    position:relative !important;
    z-index:5 !important;
}

/* stronger wired connectors */
.comm-tree-connectors path,
.comm-tree-connectors polyline,
.comm-tree-connectors line{
    stroke-width:2.2px !important;
    stroke-linecap:square !important;
    stroke-linejoin:round !important;
}

.comm-tree-wire.physical{
    stroke:#344054 !important;
    stroke-width:2px !important;
    fill:none !important;
}

.comm-tree-wire.wireless{
    stroke:#1769aa !important;
    stroke-width:3px !important;
    stroke-dasharray:9 7 !important;
    fill:none !important;
}

.comm-port-edge-label,
.comm-wireless-edge-label{
    font-family:Arial,sans-serif !important;
    font-size:11px !important;
    font-weight:800 !important;
    paint-order:stroke !important;
    stroke:#fff !important;
    stroke-width:5px !important;
    stroke-linejoin:round !important;
}

.comm-port-edge-label{
    fill:#344054 !important;
}

.comm-wireless-edge-label{
    fill:#1769aa !important;
}

.physical-tree .comm-tree-state{
    display:flex !important;
    justify-content:center !important;
    margin-top:4px !important;
    font-size:9px !important;
}

.physical-tree .comm-physical-tower-info .state{
    display:flex !important;
    justify-content:center !important;
    margin-top:4px !important;
    font-size:9px !important;
}

.comm-port-observed-inline{
    margin-top:34px !important;
}

.comm-port-observed-note{
    display:block !important;
    max-width:520px !important;
    margin:0 auto 18px !important;
    padding:7px 10px !important;
    border:1px dashed #98a2b3 !important;
    border-radius:8px !important;
    background:#f8fafc !important;
    color:#667085 !important;
    text-align:center !important;
    font-size:10px !important;
}

.dashboard-page.dark .comm-port-edge-label,
.dashboard-page.dark .comm-wireless-edge-label{
    stroke:#181b23 !important;
}

.dashboard-page.dark .comm-port-observed-note{
    background:#20232e !important;
    color:#cbd5e1 !important;
}

/* ===== Honest placement trays for unresolved inventory ===== */
.comm-port-observed-inline.comm-observed-wrap{
    width:720px !important;
    min-width:720px !important;
    max-width:720px !important;
    margin:34px auto 0 !important;
    border:1px dashed #98a2b3 !important;
    border-radius:14px !important;
    background:#f8fafc !important;
    overflow:hidden !important;
}

.comm-port-observed-inline .comm-observed-toggle{
    min-height:42px !important;
    padding:10px 14px !important;
}

.comm-port-observed-inline .comm-observed-toggle b{
    font-size:11px !important;
}

.comm-port-observed-inline .comm-observed-note{
    padding:8px 14px !important;
    color:#667085 !important;
    background:#fff !important;
    text-align:center !important;
    font-size:9px !important;
}

.comm-port-observed-grid{
    display:grid !important;
    grid-template-columns:1fr !important;
    gap:14px !important;
    width:100% !important;
    min-width:0 !important;
    max-width:100% !important;
    max-height:480px !important;
    padding:12px !important;
    overflow:auto !important;
}

.comm-observed-category{
    min-width:0 !important;
    padding:10px !important;
    border:1px solid #e4e7ec !important;
    border-radius:11px !important;
    background:#fff !important;
}

.comm-observed-category h4{
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:10px !important;
    margin:0 0 9px !important;
    color:#344054 !important;
    font-size:10px !important;
}

.comm-observed-category h4 span{
    display:flex !important;
    align-items:center !important;
    gap:6px !important;
}

.comm-observed-category h4 b{
    display:grid !important;
    place-items:center !important;
    min-width:24px !important;
    height:20px !important;
    padding:0 6px !important;
    border-radius:999px !important;
    color:#475467 !important;
    background:#f2f4f7 !important;
    font-size:9px !important;
}

.comm-observed-category.wireless h4 span{
    color:#1769aa !important;
}

.comm-observed-category-grid{
    display:grid !important;
    grid-template-columns:repeat(3,minmax(0,1fr)) !important;
    gap:8px !important;
}

.comm-observed-category-grid .comm-observed-device{
    width:100% !important;
    min-width:0 !important;
    min-height:58px !important;
}

.comm-observed-category-grid .comm-tower-device .comm-observed-device-icon{
    color:#1769aa !important;
    background:#eff8ff !important;
}

.comm-topology-unplaced{
    display:grid !important;
    gap:12px !important;
    width:900px !important;
    max-width:900px !important;
    margin:58px auto 0 !important;
    padding-top:24px !important;
    border-top:1px dashed #d0d5dd !important;
    position:relative !important;
    z-index:5 !important;
}

.comm-topology-unplaced:before{
    content:'أجهزة غير متصلة بالشجرة المثبتة' !important;
    display:block !important;
    color:#667085 !important;
    text-align:center !important;
    font-size:10px !important;
    font-weight:800 !important;
}

.comm-topology-unplaced:empty{
    display:none !important;
}

.comm-topology-unplaced[hidden]{
    display:none !important;
}

.comm-topology-unplaced .comm-observed-wrap{
    width:820px !important;
    margin:0 auto !important;
}

.comm-topology-unplaced .comm-observed-list{
    display:grid !important;
    grid-template-columns:repeat(3,minmax(0,1fr)) !important;
    gap:8px !important;
    max-height:360px !important;
    padding:12px !important;
}

.comm-topology-unplaced .comm-observed-list[hidden],
.comm-port-observed-grid[hidden]{
    display:none !important;
}

@media(max-width:900px){
    .comm-port-observed-inline.comm-observed-wrap{
        width:620px !important;
        min-width:620px !important;
        max-width:620px !important;
    }

    .comm-observed-category-grid,
    .comm-topology-unplaced .comm-observed-list{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    }
}

.dashboard-page.dark .comm-port-observed-inline.comm-observed-wrap,
.dashboard-page.dark .comm-observed-category,
.dashboard-page.dark .comm-port-observed-inline .comm-observed-note{
    background:#20232e !important;
    border-color:#475467 !important;
}

.dashboard-page.dark .comm-observed-category h4{
    color:#e4e7ec !important;
}

</style>
<?php if (!$fragmentMode): ?></main></body></html><?php endif; ?>
