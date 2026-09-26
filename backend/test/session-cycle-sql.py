"""Print an isolated MariaDB regression fixture using the real repository SQL.

Run against a scratch connection; TEMPORARY tables shadow production tables
and disappear when the connection closes. Expected output: new,boundary;
new; fallback; EMPTY (one result per line).
"""
from pathlib import Path

source = (Path(__file__).parents[1] / 'src/database/mysql-subscriber-repository.ts').read_text(encoding='utf-8')
method = source.split('async getSessions(', 1)[1].split('async getDevices(', 1)[0]
query = method.split('`', 2)[1]
print('''
CREATE TEMPORARY TABLE radacct (
 radacctid INT, username VARCHAR(64), acctuniqueid VARCHAR(64), acctsessionid VARCHAR(64),
 acctstarttime DATETIME, acctstoptime DATETIME, acctupdatetime DATETIME,
 acctsessiontime INT, input_octets64 BIGINT, acctinputoctets BIGINT,
 output_octets64 BIGINT, acctoutputoctets BIGINT, framedipaddress VARCHAR(64),
 callingstationid VARCHAR(64), nasipaddress VARCHAR(64), calledstationid VARCHAR(64),
 INDEX username(username));
CREATE TEMPORARY TABLE nawa_audit_log (
 id INT, subject_type VARCHAR(32), subject_id VARCHAR(64), action_name VARCHAR(64), created_at DATETIME);
INSERT INTO radacct (radacctid,username,acctuniqueid,acctstarttime) VALUES
 (1,'alice','old-open','2026-09-01 01:00:00'),
 (2,'alice','boundary','2026-09-25 10:00:00'),
 (3,'alice','new','2026-09-25 11:00:00'),
 (4,'bob','fallback','2026-09-01 01:00:00'),
 (5,'carol','previous','2026-09-01 01:00:00');
INSERT INTO nawa_audit_log VALUES
 (1,'user','alice','user.package_settle','2026-09-01 00:00:00'),
 (2,'user','alice','user.package_settle','2026-09-25 10:00:00'),
 (3,'user','alice','card.recharge','2026-09-25 12:00:00'),
 (4,'user','carol','user.settle','2026-09-25 10:00:00'),
 (5,'user','someone-else','user.package_settle','2026-09-25 12:00:00');
''')
for username, limit in [('alice', 100), ('alice', 1), ('bob', 100), ('carol', 100)]:
    sql = query
    for value in [f"'{username}'", f"'{username}'", str(limit)]:
        sql = sql.replace('?', value, 1)
    print("SELECT COALESCE(GROUP_CONCAT(session_id ORDER BY acctstarttime DESC),'EMPTY') FROM (" + sql + ') sessions;')
