<?php
// Read-only targeting checks. Print counts/errors, never account identifiers.
$_SERVER['PHP_SELF'] = '/ops/check-broadcast-targeting.php';
chdir('/var/www/html/3dradius/operators');
require '../common/includes/db_open.php';
$dbSocket->setErrorHandling(PEAR_ERROR_RETURN);
$source = file_get_contents($argv[1] ?? 'nawa-notifications.php');
$function = explode("if (\$_SERVER", explode('function broadcastSubscribersSql', $source, 2)[1], 2)[0];
eval('function broadcastSubscribersSql' . $function);
foreach (['11.', '22.', '33.', '44.', '55.', '66.'] as $prefix) {
    $result = $dbSocket->getOne('SELECT COUNT(*)' . broadcastSubscribersSql('ip_prefix', $prefix, $dbSocket));
    echo $prefix . ': ' . (DB::isError($result) ? $result->getMessage() . ' ' . $result->getUserInfo() : $result) . "\n";
}
foreach (['radacct', 'subscriber_push_known_devices', 'subscriber_broadcast_recipients'] as $table) {
    $rows = $dbSocket->getAll("SHOW FULL COLUMNS FROM $table WHERE Field IN ('username','framedipaddress')", [], DB_FETCHMODE_ASSOC);
    echo $table . ': ' . json_encode($rows) . "\n";
}
