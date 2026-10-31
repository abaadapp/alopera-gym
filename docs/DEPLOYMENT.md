# النشر على DigitalOcean

> لم يُنفّذ أي نشر. الملفات في `deploy/` و`scripts/` جاهزة ومراجَعة، لكن لم تُشغّل على خادم حقيقي بعد
> (عدا `restore-drill.sh` الذي جُرّب محليًا على نسخة حقيقية).

## وضع التشغيل

النظام لنادٍ واحد: Alopera Gym. خادم Droplet مخصص، قاعدة PostgreSQL مستقلة، ودومين النادي (مثلًا `alopera.om`).
لا توجد لوحة منصة ولا باقات اشتراك ولا إنشاء أندية من الواجهة: يُعدّ النادي مرة واحدة من الخادم بـ `php artisan gym:install`
(يرفض التشغيل إن وُجد نادٍ). الفروع تُدار من «الإعدادات ← الفروع» كفروع للنادي نفسه.

## المكونات المقترحة

- **Droplet**: Ubuntu 24.04، 2 vCPU / 4GB (يكفي لنادٍ واحد بارتياح)، منطقة قريبة (فرانكفورت `fra1` أو بنغالور).
- **Managed PostgreSQL 16** (نسخ تلقائي يومي من DigitalOcean + `backup-database.sh`).
- **Spaces** لنسخ قاعدة البيانات خارج الخادم.
- اختياري: Managed Redis للطوابير والكاش عند زيادة الحمل.

## الخطوات

```bash
# 1) على الخادم (root): تجهيز النظام — راجعي السكربت أولًا
bash scripts/provision-droplet.sh

# 2) ملف البيئة
sudo -u deploy cp .env.production.example /var/www/alopera-gym/shared/.env
sudo -u deploy nano /var/www/alopera-gym/shared/.env      # DB, APP_URL, APP_KEY, WhatsApp, Spaces…

# 3) Nginx + SSL
cp deploy/nginx/alopera-gym.conf /etc/nginx/sites-available/alopera-gym   # عدّلي server_name والشهادة
ln -s /etc/nginx/sites-available/alopera-gym /etc/nginx/sites-enabled/
certbot --nginx -d app.example.com -d alopera.om -d www.alopera.om
nginx -t && systemctl reload nginx

# 4) العمّال والمجدول
cp deploy/supervisor/alopera-gym-worker.conf /etc/supervisor/conf.d/ && supervisorctl reread && supervisorctl update
crontab -u deploy deploy/crontab

# 5) أول إصدار (deploy)
sudo -u deploy bash scripts/deploy.sh main

# 6) النادي وحساب صاحب النشاط (مرة واحدة)
sudo -u deploy php /var/www/alopera-gym/current/artisan gym:install --name="Alopera Gym" --slug=alopera --owner-name="..." --owner-email=owner@alopera.om

# 7) فحص الجاهزية (يشغّله deploy.sh تلقائيًا قبل تبديل الإصدار)
sudo -u deploy php /var/www/alopera-gym/current/artisan platform:preflight
```

### فحص الجاهزية `platform:preflight`
يفحص قبل كل إصدار: `APP_ENV=production`، إيقاف `APP_DEBUG`، وجود `APP_KEY`، HTTPS في `APP_URL` والكوكيز، تشفير الجلسة،
الطابور في الخلفية، اكتمال الترحيلات، ربط التخزين، وعدم وجود **أي حساب تجريبي نشط** (موظفون بنطاق محجوز).
أي FAIL يوقف `deploy.sh` قبل تبديل الإصدار؛ WARN للتنبيه فقط (البريد، واتساب، إعداد النادي وحساب صاحبه، مستوى السجل).
إن وصلت حسابات تجريبية لقاعدة الإنتاج بالخطأ: `php artisan platform:preflight --deactivate-demo` يعطّلها (لا يحذف شيئًا)
ويسجّل ذلك في سجل العمليات؛ ويرفض العمل خارج `production`.

بعدها: ادخلي بحساب صاحب النشاط، وأكملي «الإعدادات ← إعدادات النادي» والفروع والمستخدمين والأدوار. اضبطي
`GYM_HOME_CLUB=alopera` في `.env` إن أردتِ أن يفتح العنوان المجرد (`/`) موقع النادي دائمًا (يفتحه تلقائيًا ما دام في
القاعدة نادٍ واحد).

## الدومين المخصص للنادي
1. سجل DNS من نوع A لدومين النادي يشير إلى IP الخادم.
2. أضيفي الدومين في `server_name` واستخرجي الشهادة بـ certbot.
3. سجّلي الدومين في عمود `tenants.domain` للنادي (من الخادم: `php artisan tinker`)، ثم يعمل الموقع على الجذر والروابط القانونية (canonical) على الدومين.

## الأمان في الإنتاج
- `APP_DEBUG=false`، `SESSION_SECURE_COOKIE=true`، HTTPS فقط (HSTS في Nginx والتطبيق).
- احتفظي بنسخة آمنة من `APP_KEY`: أسرار التكاملات والملاحظات الصحية مشفّرة به، والنسخ الاحتياطية تحتاجه للاستعادة.
- قاعدة البيانات بـ `DB_SSLMODE=require` ومحصورة على IP الخادم (Trusted sources).
- **الحسابات التجريبية لا تعمل في الإنتاج** (`App\Core\Auth\DemoAccounts`، مختبر في `ProductionSafetyTest`):
  - البذرة التجريبية ترفض العمل في `production`، فلا تُنشأ حسابات `*.test` أصلًا.
  - أي بريد بنطاق محجوز (`.test`، `.example`، `.invalid`، `.localhost`، `example.com/net/org`) يُرفض دخوله للوحة
    والبوابة حتى لو كانت كلمة المرور صحيحة، ويُرفض عند إنشاء حساب جديد، ويُسجّل `auth.demo_blocked` في سجل العمليات.
  - `gym:install` يرفض النطاقات المحجوزة وكلمات المرور الضعيفة (10 أحرف، كبيرة وصغيرة وأرقام، وغير مسرّبة).
  - الأوامر المدمّرة (`migrate:fresh`، `migrate:refresh`، `migrate:reset`، `db:wipe`) ممنوعة في `production`.

## النسخ الاحتياطي والاستعادة
- يوميًا 03:00: أرشيف النادي (`backup:tenants`) في `storage/app/private/backups/tenants/<id>` — آخر 14.
- يوميًا 02:30: `scripts/backup-database.sh` (pg_dump كامل → Spaces، 14 يومًا محليًا).
- **قبل الإطلاق**: نزّلي آخر dump وشغّلي `scripts/restore-drill.sh <dump>` وقارني الأعداد (اختبار القبول 7.3).
- استعادة بيانات النادي: «الإعدادات ← النسخ الاحتياطي ← استعادة» (تأكيد بكتابة معرّف النادي، ونسخة أمان تلقائية قبلها).

## التحديثات
`scripts/deploy.sh <tag|branch>` — إصدار جديد في `releases/`، تشغيل الترحيلات، تخزين الإعدادات مؤقتًا، تبديل الرابط،
إعادة تحميل PHP-FPM وإعادة تشغيل العمّال. الرجوع: أعيدي توجيه `current` إلى الإصدار السابق.

## المراقبة
- `/up` لفحص الصحة (DigitalOcean Uptime).
- سجلات Laravel اليومية في `shared/storage/logs`.
- «سجل بوابات الدفع» و«سجل الرسائل» في اللوحة لمتابعة التكاملات.
