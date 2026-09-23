from pathlib import Path
import hashlib, subprocess, os, datetime
live=Path('/var/www/html/3dradius/operators/home-modern.php')
stage=Path('/home/anwar/home-modern-design-stage.php')
old=live.read_bytes(); new=stage.read_bytes()
assert hashlib.sha256(old).hexdigest()=='3e7714004e3f7ad47cabf0bd9aea739e8b0f672f7c2df0f512eb7b04165ecee9', 'Live file changed; stop'
start=new.index(b'\n<style id="dashboard-reference-design">')
end=new.index(b'</style>\n', start)+len(b'</style>\n')
assert new[:start]+new[end:]==old, 'Non-CSS change; stop'
subprocess.run(['php','-l',str(stage)],check=True)
services=['apache2','freeradius','mariadb']
def state():
 return subprocess.check_output(['systemctl','show',*services,'--property=Id,ActiveState,MainPID,ActiveEnterTimestampMonotonic']).decode()
before=state()
backup=Path('/home/anwar/ui-backups/dashboard-design-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S'))
backup.mkdir(mode=0o700)
(backup/'home-modern.php').write_bytes(old)
stat=live.stat()
temp=live.with_name('.home-modern-design.tmp')
with temp.open('xb') as f:
 f.write(new); f.flush(); os.fsync(f.fileno())
os.chown(temp,stat.st_uid,stat.st_gid); os.chmod(temp,stat.st_mode & 0o777)
os.replace(temp,live)
assert live.read_bytes()==new
print('Backup:',backup)
print('Live PHP:',subprocess.check_output(['php','-l',str(live)]).decode().strip())
after=state()
print(after)
print('Service states, PIDs and start timestamps unchanged:',before==after)
print('CSS-only preservation check: PASS')
