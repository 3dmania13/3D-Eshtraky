"""Server-side smoke check. Prints no account identifiers or tokens."""
import json
import subprocess
import urllib.request
import urllib.error

username = subprocess.check_output([
    'docker', 'exec', 'radius-old-db', 'mariadb', '-uroot', '-N', 'radius', '-e',
    'SELECT username FROM nawa_pppoe_users ORDER BY id LIMIT 1'
], text=True).strip()
assert username, 'No broadband account available'

def call(base, path, method='GET', payload=None, token=None):
    headers = {'Content-Type': 'application/json'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    request = urllib.request.Request(base + path, method=method, headers=headers,
        data=None if payload is None else json.dumps(payload).encode())
    try:
        with urllib.request.urlopen(request, timeout=35) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        return error.code, json.load(error)

for base in ['http://192.168.230.111:3085', 'http://192.168.230.111:3088',
             'https://possess-defense-validity-generating.trycloudflare.com']:
    status, session = call(base, '/api/v1/auth/broadband-login', 'POST', {'username': username})
    assert status == 200, (base, status)
    token = session['accessToken']
    for route in ['dashboard', 'profile', 'usage/summary', 'usage/daily', 'sessions',
                  'devices', 'recharges', 'notifications', 'speed', 'connection-limit']:
        status, result = call(base, '/api/v1/broadband/' + route, token=token)
        assert status == 200, (base, route, status, result)
        if route == 'dashboard':
            assert result['username'] == username and 'subscription' in result
        print(base, route, status)
    status, _ = call(base, '/api/v1/subscriber/dashboard', token=token)
    assert status == 403
    status, refreshed = call(base, '/api/v1/auth/broadband-refresh', 'POST', {'refreshToken': session['refreshToken']})
    assert status == 200
    status, _ = call(base, '/api/v1/auth/broadband-refresh', 'POST', {'refreshToken': session['refreshToken']})
    assert status == 401
    status, _ = call(base, '/api/v1/auth/broadband-logout', 'POST', {'refreshToken': refreshed['refreshToken']})
    assert status == 200
    print(base, 'role isolation, refresh rotation and logout OK')
