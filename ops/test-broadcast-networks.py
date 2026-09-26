"""Exercise the PHP recipient SQL against isolated synthetic accounts."""
import json
import sqlite3
import subprocess
from pathlib import Path

source = (Path(__file__).resolve().parent.parent / 'nawa-notifications.php.remote').read_text(encoding='utf-8')
function = source.split('function broadcastSubscribersSql', 1)[1].split("if ($_SERVER", 1)[0]
php = '''class TestDb { function escapeSimple($value) { return $value; } }
''' + 'function broadcastSubscribersSql' + function + '''
$queries = [];
foreach (['11.', '22.', '33.', '44.', '55.', '66.', '', '1', '11', '%', '77.'] as $prefix) {
    $queries[$prefix] = broadcastSubscribersSql('ip_prefix', $prefix, new TestDb());
}
$queries['invalidAudience'] = broadcastSubscribersSql('invalid', '', new TestDb());
echo json_encode($queries);
'''
queries = json.loads(subprocess.check_output(['php', '-r', php], text=True))
db = sqlite3.connect(':memory:')
db.executescript('''CREATE TABLE subscriber_push_known_devices(username TEXT);
CREATE TABLE radacct(username TEXT, framedipaddress TEXT, acctstarttime INTEGER, radacctid INTEGER);''')
def add(name, ip, known=True, timestamp=1):
    if known:
        db.execute('INSERT INTO subscriber_push_known_devices VALUES (?)', (name,))
    db.execute('INSERT INTO radacct VALUES (?,?,?,?)', (name, ip, timestamp, timestamp))

for n in range(1, 7):
    add(f'network{n}', f'{n * 11}.2.3.4')
add('wrong110', '110.2.3.4')
add('wrongOtherOctet', '192.168.11.1')
add('unknownAppUser', '11.2.3.4', known=False)
add('moved', '11.2.3.4')
add('moved', '22.2.3.4', timestamp=2)
add('missingIp', None)
for prefix, query in queries.items():
    sqlite_query = query.replace(' USE INDEX (username)', '').replace(
        'CONVERT(app_user.username USING utf8mb4) COLLATE utf8mb4_general_ci', 'app_user.username')
    rows = {row[0] for row in db.execute('SELECT app_user.username' + sqlite_query)}
    expected = {f'network{int(prefix[:2]) // 11}'} if prefix in ['11.', '22.', '33.', '44.', '55.', '66.'] else set()
    if prefix == '22.':
        expected.add('moved')
    assert rows == expected, (prefix, rows, expected)
assert "$prefix = $networks[$network] ?? '';" in source
assert "!isset($networks[$network])" in source
assert 'name="ip_prefix"' not in source
print('PASS: six networks, latest IP, duplicate devices, missing IP, non-app users, and invalid targeting.')
