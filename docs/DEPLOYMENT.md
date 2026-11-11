# النشر على DigitalOcean

> دليل الإعداد الأول للخادم. **الإنتاج اليوم:** Droplet واحد، وPostgreSQL 16 على الخادم نفسه (`127.0.0.1`)، والنشر من
> GitHub Actions بعد كل دمج في `main` (`docs/DEPLOY.md`). **لا نسخ احتياطي لقاعدة البيانات بقرار المالك** (القسم أدناه).

## وضع التشغيل

النظام لنادٍ واحد: Alopera Gym. خادم Droplet مخصص، قاعدة PostgreSQL مستقلة، ودومين النادي (مثلًا `alopera.om`).
لا توجد لوحة منصة ولا باقات اشتراك ولا إنشاء أندية من الواجهة: يُعدّ النادي مرة واحدة من الخادم بـ `php artisan gym:install`
(يرفض التشغيل إن وُجد نادٍ). الفروع تُدار من «الإعدادات ← الفروع» كفروع للنادي نفسه.

## المكونات المقترحة

- **Droplet**: Ubuntu 24.04، 2 vCPU / 4GB (يكفي لنادٍ واحد بارتياح)، منطقة قريبة (فرانكفورت `fra1` أو بنغالور).
- **PostgreSQL 16**: المقترح في الأصل Managed PostgreSQL؛ الإنتاج يعمل على PostgreSQL محلي على الـDroplet.
- **Spaces**: غير مستخدم (لا نسخ احتياطي، بقرار المالك).
- اختياري: Managed Redis للطوابير والكاش عند زيادة الحمل.

## الخطوات

```bash
# 1) على الخادم (root): تجهيز النظام — راجعي السكربت أولًا
bash scripts/provision-droplet.sh

# 2) ملف البيئة
sudo -u deploy cp .env.production.example /var/www/alopera-gym/shared/.env
sudo -u deploy nano /var/www/alopera-gym/shared/.env      # DB, APP_URL, APP_KEY, WhatsApp, Spaces…

# 3) Nginx + SSL (الملفات والتحقق: docs/DOMAINS.md، الخطوة 2)
bash scripts/test-nginx-config.sh                     # ملفات deploy/nginx تُختبر على نسخة مؤقتة أولًا
cp deploy/nginx/snippets/alopera-laravel.conf /etc/nginx/snippets/
cp deploy/nginx/00-reject-unknown-hosts.conf /etc/nginx/sites-available/00-reject-unknown-hosts
cp deploy/nginx/aloperagym.om.conf /etc/nginx/sites-available/aloperagym.om
ln -s /etc/nginx/sites-available/00-reject-unknown-hosts /etc/nginx/sites-enabled/
ln -s /etc/nginx/sites-available/aloperagym.om /etc/nginx/sites-enabled/
rm /etc/nginx/sites-enabled/default                   # موقع أوبونتو الافتراضي: 00-reject-unknown-hosts يحل محله
# الشهادات: أوامر certbot في رأس deploy/nginx/aloperagym.om.conf. على خادم جديد تُستخرج قبل تفعيل aloperagym.om،
# فـ nginx -t يرفض ملفًا يشير إلى شهادة غير موجودة.
nginx -t && systemctl reload nginx

# 4) العمّال والمجدول
cp deploy/supervisor/alopera-gym-worker.conf /etc/supervisor/conf.d/ && supervisorctl reread && supervisorctl update
crontab -u deploy deploy/crontab

# 5) أول إصدار (deploy)
sudo -u deploy bash /var/www/alopera-gym/app/scripts/deploy.sh   # بعد الإعداد في docs/DEPLOY.md

# 6) النادي وحساب صاحب النشاط (مرة واحدة)
sudo -u deploy php /var/www/alopera-gym/current/artisan gym:install --name="Alopera Gym" --slug=alopera --owner-name="..." --owner-email=owner@alopera.om

# 7) فحص الجاهزية (يشغّله deploy.sh بعد كل نشر ويبلّغ عن الموانع)
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

## النسخ الاحتياطي
- **قرار المالك (نهائي، 2026-11-08، أُكّد 2026-11-09): لا نسخ احتياطي لقاعدة البيانات حاليًا.** لا DigitalOcean Backups ولا
  Spaces ولا `pg_dump`، ولا تغيير لإعدادات النسخ الحالية. المالك قبل المخاطرة: ما يضيع من قاعدة البيانات لا يُستعاد.
  ليس شرطًا للدمج ولا للإطلاق.
- `scripts/backup-database.sh` و`scripts/restore-drill.sh` في المستودع لكن غير مثبّتين على الخادم (لا cron ولا Spaces).
- أرشيف النادي اليومي (`backup:tenants`، 03:00) يُكتب على الخادم نفسه كما كان، و«الإعدادات ← النسخ الاحتياطي» في اللوحة
  كما هي؛ لم يتغير فيهما شيء.
- لذلك: النشر يرحّل بلا نسخة قبله، وفحص الترحيلات في `scripts/deploy.sh` يوقف قبل السحب كل ترحيل يحذف أو يغيّر البنية
  ولم يُراجَع (`docs/DEPLOY.md`).

## التحديثات
النشر يبدأ بنفسه بعد الدمج في `main`: راجع `docs/DEPLOY.md` (الخطوات، والإعداد على الخادم مرة واحدة، والرجوع اليدوي بـ`scripts/rollback-code.sh`).

## المراقبة
- `/up` لفحص الصحة (DigitalOcean Uptime).
- سجلات Laravel اليومية في `shared/storage/logs`.
- «سجل بوابات الدفع» و«سجل الرسائل» في اللوحة لمتابعة التكاملات.
