#!/usr/bin/env python3
"""Fetch every package of a composer.lock from GitHub with shallow git fetches
(Packagist and zip downloads are not reachable here) and write a lock whose
packages install from those local copies.

usage: vendor_from_lock.py <composer.lock> <packages-dir> <out-lock>
"""
import json, os, subprocess, sys, shutil
from concurrent.futures import ThreadPoolExecutor

lock_path, pkgs, out = sys.argv[1:4]
lock = json.load(open(lock_path))

def fetch(p):
    name = p['name']
    src = p.get('source')
    if src:
        url, ref = src['url'], src['reference']
    else:
        d = p['dist']  # api.github.com/repos/<owner>/<repo>/zipball/<sha>
        parts = d['url'].split('/')
        url, ref = 'https://github.com/%s/%s.git' % (parts[4], parts[5]), d['reference']
    dest = os.path.join(pkgs, name)
    marker = os.path.join(dest, '.fetched-' + ref)
    if os.path.exists(marker):
        return name, 'cached'
    shutil.rmtree(dest, ignore_errors=True)
    os.makedirs(dest)
    run = lambda *a: subprocess.run(['git', '-C', dest, *a], capture_output=True, text=True, timeout=900)
    run('init', '-q')
    run('remote', 'add', 'origin', url)
    for attempt in range(3):
        r = run('fetch', '-q', '--depth', '1', 'origin', ref)
        if r.returncode == 0:
            break
    else:
        return name, 'FAILED fetch: ' + r.stderr.strip()[-300:]
    r = run('checkout', '-q', 'FETCH_HEAD')
    if r.returncode != 0:
        return name, 'FAILED checkout: ' + r.stderr.strip()[-300:]
    shutil.rmtree(os.path.join(dest, '.git'), ignore_errors=True)
    open(marker, 'w').close()
    return name, 'ok'

allp = lock['packages'] + lock['packages-dev']
with ThreadPoolExecutor(max_workers=int(os.environ.get('JOBS', '4'))) as ex:
    results = list(ex.map(fetch, allp))
bad = [r for r in results if r[1].startswith('FAILED')]
print('%d packages: %d ok, %d cached, %d failed' % (len(results), sum(r[1] == 'ok' for r in results), sum(r[1] == 'cached' for r in results), len(bad)))
for b in bad:
    print(' ', *b)

for p in allp:
    ref = (p.get('source') or p['dist'])['reference']
    p.pop('source', None)
    p['dist'] = {'type': 'path', 'url': os.path.join(os.path.abspath(pkgs), p['name']), 'reference': ref}
    p['transport-options'] = {'symlink': False, 'relative': False}
json.dump(lock, open(out, 'w'), indent=4)
sys.exit(1 if bad else 0)
