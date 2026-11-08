# النشر الآلي

```
طلب ← تنفيذ في فرع وطلب دمج ← موافقة صاحب النظام ← دمج في main
   ← CI: الاختبارات ← وسم vX.Y.Z ← إصدار GitHub بملفات الواجهة المبنية
   ← الخادم (كل دقيقتين): نسخة قاعدة البيانات ← النشر ← فحص الدومينين ← أو الرجوع تلقائيًا
```

الموافقة على الدمج هي الموافقة على النشر. بعد الدمج يصبح الإصدار حيًّا خلال 5 إلى 10 دقائق تقريبًا، دون أي خطوة أخرى.

## كيف يعمل

الخادم هو الذي يسحب الإصدارات بنفسه. لا يُخزَّن على GitHub أي مفتاح دخول إلى الخادم، والمستودع عام.

- **المؤقت:** `alopera-auto-deploy.timer` يشغّل `/usr/local/sbin/alopera-auto-deploy` كل دقيقتين، أي `scripts/auto-deploy.sh` المنسوخ عند التثبيت. والسكربت يحدّث نفسه بعد كل نشر ناجح.
- **ما يُنشر:** أحدث وسم `vX.Y.Z` على `main` فقط، وفقط بعد أن يُنشر إصداره على GitHub بملف `build.tar.gz`. لا يحدث ذلك إلا بعد نجاح الاختبارات. ولا يُنشر إصدار أقدم من الحالي أبدًا.
- **قبل كل نشر:**
  - نسخة `pg_dump -Fc` في `shared/backups/predeploy-<الوسم>-<الوقت>.dump`، تُفحص بـ`pg_restore --list`؛
  - تُحفظ آخر 10 نسخ؛
  - بلا نسخة سليمة، لا نشر.
- **النشر نفسه:**
  - `scripts/deploy.sh` من الوسم نفسه، بحساب `deploy`؛
  - يشمل: الترحيلات، وفحص الجاهزية، وتبديل الإصدار، وإعادة تشغيل عامل الطوابير؛
  - ثم يعيد root تحميل PHP-FPM، فلا حاجة لصلاحيات sudo إضافية لحساب `deploy`.
- **بعد النشر:**
  - يجب أن تجيب العناوين في `CHECK_URLS`، مع ثلاث محاولات؛
  - إن لم تجب، يرجع الإصدار السابق تلقائيًا، ويُعاد تحميل PHP-FPM، ويُعاد تشغيل الطوابير؛
  - قاعدة البيانات تبقى مُرحَّلة، والسجل يذكر مسار النسخة المأخوذة قبل النشر.
- **الوسم الفاشل:** لا يُعاد تجريبه. الإصلاح يأتي بوسم أحدث.

## التثبيت (مرة واحدة، بحساب root)

```bash
TAG=<أحدث وسم فيه scripts/auto-deploy.sh>
RAW=https://raw.githubusercontent.com/<owner>/alopera-gym/$TAG
curl -fsSL $RAW/scripts/auto-deploy.sh -o /usr/local/sbin/alopera-auto-deploy && chmod 755 /usr/local/sbin/alopera-auto-deploy
curl -fsSL $RAW/deploy/systemd/alopera-auto-deploy.service -o /etc/systemd/system/alopera-auto-deploy.service
curl -fsSL $RAW/deploy/systemd/alopera-auto-deploy.timer -o /etc/systemd/system/alopera-auto-deploy.timer
curl -fsSL $RAW/deploy/alopera-deploy.conf.example -o /etc/alopera-deploy.conf && chmod 600 /etc/alopera-deploy.conf
nano /etc/alopera-deploy.conf                     # REPO و CHECK_URLS
alopera-auto-deploy --dry-run                     # ماذا سيفعل، دون تغيير
systemctl daemon-reload && systemctl enable --now alopera-auto-deploy.timer
```

## المتابعة والتحكم

| الأمر | ما يفعله |
|---|---|
| `tail -f /var/log/alopera-deploy.log` | ما نُشر، ومتى، والنسخة، وأي فشل |
| `alopera-auto-deploy --dry-run` | ماذا سيفعل الآن |
| `systemctl status alopera-auto-deploy.timer` / `.service` | آخر تشغيل ونتيجته |
| `touch /var/www/alopera-gym/shared/auto-deploy.off` | إيقاف مؤقت، ويُلغى بحذف الملف |
| `cat /var/www/alopera-gym/shared/deploy-state/deployed` | الوسم الحي |
| `cat /var/www/alopera-gym/shared/deploy-state/failed` | الوسوم التي فشلت |

## الرجوع يدويًا

```bash
touch /var/www/alopera-gym/shared/auto-deploy.off           # أوقف النشر الآلي أولًا
ls -1t /var/www/alopera-gym/releases                         # الإصدارات المحفوظة (آخر 5)
sudo -u deploy env RELOAD_FPM=0 bash /var/www/alopera-gym/current/scripts/rollback.sh <الإصدار> && systemctl reload php8.4-fpm
# القاعدة، عند الحاجة فقط: من shared/backups/predeploy-*.dump (pg_restore)
```

## ما لا يفعله

- لا ينشر فرعًا ولا وسمًا خارج `main`.
- لا يعدّل Nginx ولا `.env` ولا الشهادات.
- لا يستعيد قاعدة البيانات تلقائيًا.
- لا يرسل تنبيهات. المتابعة من السجل، أو بطلب فحص من Claude.
