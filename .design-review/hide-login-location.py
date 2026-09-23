from pathlib import Path
import hashlib, re
p = Path('D:/eshtraky/.design-review')
old = (p/'login-location-original.php').read_bytes()
needle = b'<div class="mb-4">'
assert old.count(needle) == 1
new = old.replace(needle, b'<div class="mb-4" hidden>')
assert new.replace(b'<div class="mb-4" hidden>', needle) == old
(p/'login-location-updated.php').write_bytes(new)
s = (p/'deploy-login-ar.py').read_text(encoding='utf-8')
s = re.sub(r"assert hashlib.sha256\(old\).hexdigest\(\)==[\"'][a-f0-9]+[\"']", 'assert hashlib.sha256(old).hexdigest()=='+repr(hashlib.sha256(old).hexdigest()), s)
s = s.replace('/home/anwar/login-ar-stage.php', '/home/anwar/login-location-stage.php').replace('login-ar-', 'login-location-')
(p/'deploy-login-location.py').write_text(s, encoding='utf-8')
print('Only location section visibility changed; original select preserved.')
