# nginx على الخادم الآن (ليس نفس ملفات هذا المجلد)

الملفات في `deploy/nginx/` هي التصميم المستهدف، ويختبرها CI (`scripts/test-nginx-config.sh`). أما الخادم فما زال يعمل بملفاته القديمة، كل مضيف في ملف منفصل. النشر (`scripts/deploy.sh`) لا يلمس nginx، فأي تغيير في إعدادات nginx الحية يطبّقه المالك يدويًا بموافقته.

## الملفات الحية (قُرئت بأمر قراءة فقط، 2026-11-18)

| الملف | ماذا يفعل |
|---|---|
| `/etc/nginx/sites-enabled/00-reject-unknown-hosts` | المنفذ 80 يعيد 444، والمنفذ 443 يرفض المصافحة (`ssl_reject_handshake on`) لأي اسم غير معروف |
| `/etc/nginx/sites-enabled/alopera-gym` | `app.aloperagym.om`: اللوحة. `/` يحوّل إلى `/login`. التطبيق عبر `/index.php` وحده |
| `/etc/nginx/sites-enabled/alopera-website` | `aloperagym.om`: الموقع. مسارات اللوحة (`/app` و`/login` وغيرهما) تعيد 404 |
| `/etc/nginx/sites-enabled/www-aloperagym.om` | `www` يحوّل إلى `https://aloperagym.om` |
| `/etc/nginx/nginx.conf` | إعداد Ubuntu العام |

## ما تغيّر في 2026-11-18 08:11 UTC (طبّقه المالك، وتُحقق منه من الخارج)

1. **`nginx.conf`:** فُكّ تعليق `gzip_vary on` و`gzip_proxied any` و`gzip_comp_level 6` و`gzip_types` (قائمة Ubuntu)، وأُضيف لها `image/svg+xml`. قبل ذلك كان `gzip on` وحده، فلم يُضغط إلا HTML.
2. **`alopera-gym` و`alopera-website`، في كتلة 443، بعد `client_max_body_size 20m;`:**

   ```nginx
       location ^~ /build/ {
           expires 1y;
           add_header Cache-Control "public, immutable" always;
           add_header X-Content-Type-Options "nosniff" always;
           try_files $uri =404;
       }
   ```

**النتيجة على الإنتاج (GET من الخارج، على المضيفين):**
- `app.js`: من 380,903 إلى 117,471 بايت.
- `app.css`: من 132.8 KB إلى 23,023 بايت.
- أول فتح: من نحو 503 KB إلى نحو 137 KB، أي أقل بـ73%.
- الترويسات: `Cache-Control: max-age=31536000, public, immutable`، و`Vary: Accept-Encoding`.
- لم يتغير شيء آخر:
  - الصفحات 200.
  - `/app` يحوّل إلى `/login`.
  - `www` يعيد 301.
  - ملف غير موجود تحت `/build` يعيد 404.
  - `/storage/.env` يعيد 404.

## إعادة التطبيق على خادم جديد (أو بعد استبدال الملفات)

هذا هو الأمر نفسه الذي شُغّل في 2026-11-18. يأخذ نسخة احتياطية أولًا، ولا يعدّل ملفًا إلا إذا وجد نقطة التعديل مرة واحدة بالضبط. إن فشل `nginx -t` أعاد الملفات كما كانت دون إعادة تحميل.

```
( set -e; TS=$(date +%Y%m%d-%H%M%S); cd /etc/nginx; cp -a nginx.conf nginx.conf.bak-$TS; cp -a sites-available/alopera-gym /root/alopera-gym.nginx.bak-$TS; cp -a sites-available/alopera-website /root/alopera-website.nginx.bak-$TS
python3 - <<'PY'
import re
files={}
p='/etc/nginx/nginx.conf'; s=open(p).read()
s,n=re.subn(r'(?m)^(\s*)# (gzip_(?:vary|proxied|comp_level|types)\b[^\n]*)$', r'\1\2', s)
assert n==4, f'nginx.conf: expected 4 commented gzip lines, found {n}'
s=re.sub(r'(?m)^(\s*gzip_types[^;\n]*)(;)', lambda m: m.group(1)+('' if 'image/svg+xml' in m.group(1) else ' image/svg+xml')+m.group(2), s)
files[p]=s
block='''    location ^~ /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable" always;
        add_header X-Content-Type-Options "nosniff" always;
        try_files $uri =404;
    }
'''
for p in ('/etc/nginx/sites-available/alopera-gym','/etc/nginx/sites-available/alopera-website'):
    s=open(p).read(); a='    client_max_body_size 20m;\n'
    assert s.count(a)==1 and '/build/' not in s, f'{p}: anchor not found once, or /build/ already there'
    files[p]=s.replace(a, a+block)
for p,s in files.items(): open(p,'w').write(s)
print('edited', len(files), 'files')
PY
if nginx -t; then systemctl reload nginx; echo "RELOADED · backups: /etc/nginx/nginx.conf.bak-$TS /root/*.nginx.bak-$TS"; else cp -a nginx.conf.bak-$TS nginx.conf; cp -a /root/alopera-gym.nginx.bak-$TS sites-available/alopera-gym; cp -a /root/alopera-website.nginx.bak-$TS sites-available/alopera-website; nginx -t; echo "ROLLED BACK, nothing reloaded"; fi )
```

**الرجوع:** النسخ الاحتياطية من 2026-11-18 هي `/etc/nginx/nginx.conf.bak-20261118-081127` و`/root/alopera-gym.nginx.bak-20261118-081127` و`/root/alopera-website.nginx.bak-20261118-081127`. أعدها إلى أماكنها، ثم `nginx -t && systemctl reload nginx`.

## ما زال الخادم يختلف فيه عن `deploy/nginx` (لم يُغيَّر، وكل بند يحتاج موافقة المالك)

- `server_tokens off`: الخادم يذكر رقم نسخة nginx.
- ترويسات `X-Frame-Options` و`Referrer-Policy` و`Strict-Transport-Security` و`X-Robots-Tag` للوحة، على مستوى nginx. يضيف Laravel بعضها لردود PHP فقط.
- قواعد الحظر الإضافية في `snippets/alopera-laravel.conf`، مثل `.env` و`.log` و`.sql` و`.bak` في أي مكان.
- `http2` معلن في كتلة `www` وحدها. لكنه يعمل على المضيفين، لأن nginx 1.24 يطبّقه على المنفذ كله.

نقل الخادم إلى ملفات هذا المجلد مهمة مستقلة، تُختبر قبلها بـ `scripts/test-nginx-config.sh` وتُطبّق بموافقة المالك.
