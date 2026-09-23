from pathlib import Path
import hashlib,subprocess,os,datetime,re
live=Path('/var/www/html/3dradius/operators/login.php')
stage=Path('/home/anwar/login-location-stage.php')
old=live.read_bytes();new=stage.read_bytes()
assert hashlib.sha256(old).hexdigest()=='9e5fe1c43d63bf9b2cdd95dab371959cdaea7c307a0836e8fbd4170fd19b616d', 'Concurrent change; stop'
assert re.findall(rb'<\?(?:php|=).*?\?>',old,re.S)==re.findall(rb'<\?(?:php|=).*?\?>',new,re.S), 'PHP changed; stop'
subprocess.run(['php','-l',str(stage)],check=True)
def state():
 return subprocess.check_output(['systemctl','show','apache2','freeradius','mariadb','--property=Id,ActiveState,MainPID,ActiveEnterTimestampMonotonic']).decode()
before=state()
backup=Path('/home/anwar/ui-backups/login-location-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S'))
backup.mkdir(mode=0o700);(backup/'login.php').write_bytes(old)
st=live.stat();temp=live.with_name('.login-design.tmp')
with temp.open('xb') as f:
 f.write(new);f.flush();os.fsync(f.fileno())
os.chown(temp,st.st_uid,st.st_gid);os.chmod(temp,st.st_mode & 0o777);os.replace(temp,live)
assert live.read_bytes()==new
print('Backup:',backup)
print('PHP authentication blocks unchanged: PASS')
print('Services, PIDs and start times unchanged:',before==state())
print(state())
