#!/usr/bin/env python3
"""Архив сайта для hoster.kz (Plesk): всё из public/ + настройки Apache + приём заявок на PHP.

    python3 tools/pack-hoster.py [выходной.zip]      (по умолчанию mip-hoster.zip в текущей папке)

Содержимое архива кладётся прямо в httpdocs. От версии для Cloudflare отличается так: нет _headers (его роль
у .htaccess), адрес отправки заявок — /api/lead.php вместо /api/lead (воркера нет), в api/ лежит скрипт приёма.
config.php в архив не входит — его на сервере создают из config.sample.php (и обновления его не затирают).
"""
import sys, zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PUBLIC, HOSTER = ROOT / 'public', ROOT / 'hoster'
out = Path(sys.argv[1] if len(sys.argv) > 1 else 'mip-hoster.zip').resolve()

files = {}   # путь в архиве → содержимое (bytes)
for p in sorted(PUBLIC.rglob('*')):
    if p.is_file() and p.name != '_headers':
        files[p.relative_to(PUBLIC).as_posix()] = p.read_bytes()

cfg = files['site.config.js'].decode('utf-8')
assert "leadEndpoint: '/api/lead'" in cfg, 'в site.config.js нет leadEndpoint: /api/lead'
files['site.config.js'] = cfg.replace("leadEndpoint: '/api/lead'", "leadEndpoint: '/api/lead.php'").encode('utf-8')

files['.htaccess'] = (HOSTER / '.htaccess').read_bytes()
files['img/.htaccess'] = (HOSTER / 'img.htaccess').read_bytes()
files['fonts/.htaccess'] = (HOSTER / 'img.htaccess').read_bytes()   # шрифты кешируются так же, как картинки
for p in sorted((HOSTER / 'api').rglob('*')):
    if p.is_file():
        files['api/' + p.relative_to(HOSTER / 'api').as_posix()] = p.read_bytes()

with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for name, data in files.items():
        info = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o644 << 16
        z.writestr(info, data)
print(f'{out}: {len(files)} файлов, {out.stat().st_size / 1024:.0f} КБ')
