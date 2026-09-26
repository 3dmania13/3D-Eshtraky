<?php
// Read-only diagnostics: no message bodies, account names, or tokens printed.
$_SERVER['PHP_SELF'] = '/ops/check-broadcast-delivery.php';
chdir('/var/www/html/3dradius/operators');
require '../common/includes/db_open.php';
$queries = [
    'recent_broadcasts' => "SELECT b.id,b.audience,b.ip_prefix,b.status,b.created_at,COUNT(r.username) AS recipients,SUM(r.status='pending') AS pending FROM subscriber_broadcasts b LEFT JOIN subscriber_broadcast_recipients r ON r.broadcast_id=b.id GROUP BY b.id ORDER BY b.id DESC LIMIT 8",
    'notification_columns' => 'SHOW COLUMNS FROM subscriber_notifications',
    'outbox_columns' => 'SHOW COLUMNS FROM subscriber_push_outbox',
    'devices' => 'SELECT active,system_messages_enabled,COUNT(*) AS total FROM subscriber_push_devices GROUP BY active,system_messages_enabled',
];
foreach ($queries as $name => $sql) {
    $result = $dbSocket->query($sql);
    if (DB::isError($result)) { echo "$name: query failed\n"; continue; }
    $rows = [];
    while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) $rows[] = $row;
    echo $name . ': ' . json_encode($rows) . "\n";
}
