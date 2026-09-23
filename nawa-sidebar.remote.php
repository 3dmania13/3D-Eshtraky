<?php

if (strpos($_SERVER['PHP_SELF'] ?? '', '/include/menu/sidebar/nawa/default.php') !== false) {
    header('Location: ../../../../index.php');
    exit;
}

$descriptors = [
    ['type' => 'link', 'label' => 'المستخدمون والتسديد', 'href' => 'nawa-users.php', 'icon' => 'people'],
    ['type' => 'link', 'label' => 'إضافة مستخدم', 'href' => 'nawa-user-new.php', 'icon' => 'person-plus'],
    ['type' => 'link', 'label' => 'العروض', 'href' => 'nawa-offers.php', 'icon' => 'percent'],
    ['type' => 'link', 'label' => 'إرسال رسائل', 'href' => 'nawa-notifications.php', 'icon' => 'send'],
    ['type' => 'link', 'label' => 'عرض رسائل المستخدمين', 'href' => 'nawa-user-messages.php', 'icon' => 'inbox'],
    ['type' => 'link', 'label' => 'إنشاء دفعة كروت', 'href' => 'nawa-card-new.php', 'icon' => 'ticket-perforated'],
    ['type' => 'link', 'label' => 'دفعات الكروت', 'href' => 'nawa-card-batches.php', 'icon' => 'collection'],
    ['type' => 'link', 'label' => 'النسخ الاحتياطي', 'href' => 'nawa-backups.php', 'icon' => 'cloud-arrow-up'],
    ['type' => 'link', 'label' => 'المظهر والألوان', 'href' => 'nawa-appearance.php', 'icon' => 'palette'],
];

$menu = [
    'title' => '3D Radius',
    'sections' => [
        ['title' => 'التشغيل', 'descriptors' => $descriptors],
    ],
];
