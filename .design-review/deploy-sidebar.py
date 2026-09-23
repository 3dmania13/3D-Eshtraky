from pathlib import Path
import hashlib, subprocess, os, datetime
live=Path('/var/www/html/3dradius/operators/home-modern.php')
stage=Path('/home/anwar/sidebar-design-stage.php')
old=live.read_bytes(); new=stage.read_bytes()
assert hashlib.sha256(old).hexdigest()=='987a4829c9d9c245978c732117f7c907fcc21487eaa2bd21bf90e444654fc807', 'Live file changed; stop'
assert new.replace(b'\n<style id="dashboard-sidebar-collapse">\n@media(min-width:861px){\n .dashboard-sidebar-toggle{position:fixed;top:17px;right:172px;z-index:310;display:grid;place-items:center;width:32px;height:32px;border:1px solid #ffffff35;border-radius:9px;background:#223e60;color:#fff;cursor:pointer;box-shadow:0 4px 12px #071b3320;font-size:17px}\n .dashboard-page .sidebar-head{padding-top:54px}\n .dashboard-page.sidebar-collapsed .dashboard-shell{grid-template-columns:0 minmax(0,1fr)}\n .dashboard-page.sidebar-collapsed .sidebar{width:0;min-width:0;visibility:hidden;overflow:hidden;border:0;pointer-events:none}\n .dashboard-page.sidebar-collapsed .dashboard-sidebar-toggle{right:18px;background:#fff;color:#14376b;border-color:#dce8fa}\n .dashboard-page.sidebar-collapsed .topbar{padding-right:64px}\n}\n@media(min-width:861px) and (max-width:1200px){.dashboard-sidebar-toggle{right:150px}}\n@media(max-width:860px){.dashboard-sidebar-toggle{display:none}}\n</style>\n',b"",1).replace(b'\n<button class="dashboard-sidebar-toggle" type="button" aria-controls="sidebar" aria-expanded="true" aria-label="?? ??????? ????????" title="?? ??????? ????????"><i class="bi bi-layout-sidebar-reverse" aria-hidden="true"></i></button>\n',b"",1).replace(b'\n<script id="dashboard-sidebar-collapse-script">\n(function () {\n  \'use strict\';\n  const toggle = document.querySelector(\'.dashboard-sidebar-toggle\');\n  const sidebar = document.getElementById(\'sidebar\');\n  if (!toggle || !sidebar) return;\n  toggle.addEventListener(\'click\', function () {\n    const collapsed = document.body.classList.toggle(\'sidebar-collapsed\');\n    toggle.setAttribute(\'aria-expanded\', String(!collapsed));\n    const label = collapsed ? \'??? ??????? ????????\' : \'?? ??????? ????????\';\n    toggle.setAttribute(\'aria-label\', label);\n    toggle.title = label;\n    // Existing chart listeners recalculate their width after the layout changes.\n    window.dispatchEvent(new Event(\'resize\'));\n  });\n})();\n</script>\n',b"",1)==old, "Unexpected modification"
subprocess.run(['php','-l',str(stage)],check=True)
services=['apache2','freeradius','mariadb']
def state():
 return subprocess.check_output(['systemctl','show',*services,'--property=Id,ActiveState,MainPID,ActiveEnterTimestampMonotonic']).decode()
before=state()
backup=Path('/home/anwar/ui-backups/sidebar-collapse-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S'))
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
print('Original-content preservation check: PASS')
