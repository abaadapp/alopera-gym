# النشر

```
طلب دمج ← CI على الطلب (PHP · JS · TypeScript · Pint · البناء) ← إثبات الشجرة المختبَرة
   ← دمج في main ← Main Gate: شجرة main هي ما اجتاز CI الطلب؟
   ← Deploy Main: GitHub Actions يدخل الخادم بـ SSH ويشغّل scripts/deploy.sh
   ← فحص الموقع من الخارج ← الوسم vX.N على ما نُشر فعلًا
```

الدمج في `main` هو النشر. بعد الدمج يصبح الإصدار حيًّا خلال دقائق، دون أي خطوة أخرى. سجل كل نشر كاملًا في GitHub ← Actions ← «Deploy Main».

## الخطوات بالترتيب

1. **CI على طلب الدمج** (`.github/workflows/ci.yml`):
   - الاختبارات كلها: PHP على PostgreSQL، وJS، وTypeScript، وPint، والبناء.
   - في آخرها `pr-proof`، وهو إثبات الشجرة التي نجحت.
   - لا تُعاد الاختبارات على `main` بعد الدمج.
2. **Main Gate** (`.github/workflows/main-gate.yml`) عند كل دفع إلى `main`:
   - يثبت أن شجرة `main` هي نفس الشجرة التي اختُبرت في الطلب.
   - يفشل في حالتين: دفع مباشر بلا طلب، أو دمج تحرّك قبله `main` فصار المزيج غير مختبَر.
3. **Deploy Main** (`.github/workflows/deploy-main.yml`):
   - يبدأ بعد نجاح البوابة، أو يدويًا من Actions ← «Deploy Main» ← Run workflow.
   - يتأكد أن رأس `main` هو ما أثبتته البوابة.
   - يدخل الخادم بـ SSH بمفتاح من GitHub Secrets، بحساب `deploy` لا root.
   - المفتاح مقيَّد بـ `command=`، فلا يشغّل إلا سكربت النشر.
4. **`scripts/deploy.sh` على الخادم**، في مجلد العمل الواحد `/var/www/alopera-gym/app`:
   1. سحب `main` (`git pull --ff-only`).
   2. اعتماديات Composer للإنتاج.
   3. بناء الواجهة على الخادم في مجلد جانبي، ثم تبديلها بالقديمة.
   4. الترحيلات، **بلا نسخة احتياطية قبلها**.
   5. الكاش: config، route، view، event.
   6. إعادة تحميل PHP-FPM، وإعادة تشغيل عامل الطوابير.
   7. فحص الجاهزية `platform:preflight`: يبلّغ عن الموانع ولا يوقف النشر.
   8. الموقع يجب أن يجيب 200، وإلا يتوقف مع تعليمات الرجوع.
   9. السطر الأخير: «تمّ: <من> → <إلى> (<الإصدار>)».
5. **بعد النشر في Actions:**
   - فحص من الخارج بعنوان الخادم: `aloperagym.om/up`، و`aloperagym.om/`، و`app.aloperagym.om/up`، و`app.aloperagym.om/login`. الصفحة الرئيسية للموقع تقرأ قاعدة البيانات.
   - مطابقة الـCommit الذي يعمل على الخادم مع ما أثبتته البوابة.
6. **الوسم:**
   - يوضع بعد نجاح النشر والفحص فقط، على ما يعمل فعلًا.
   - الترقيم: `v0.24` ثم `v0.25` وهكذا، رقم واحد لكل نشر.
   - نشر ثانٍ للـCommit نفسه لا يصنع رقمًا جديدًا.

## الرجوع (يدويًا)

لا رجوع تلقائي. إذا فشل فحص الموقع يتوقف النشر ويطبع أمر الرجوع. الـCommit السابق محفوظ في `shared/deploy-state/ROLLBACK.txt`.

```bash
sudo -u deploy bash /var/www/alopera-gym/app/scripts/rollback-code.sh <commit>
```

- الرجوع للكود فقط، وقاعدة البيانات لا تُمَس.
- إن كانت بين الإصدارين ترحيلات يتوقف الرجوع، إلا إذا سمّيتها في `ALLOW_MIGRATIONS` بعد التأكد أنها إضافية فقط.
- بعده أرجِع التغيير في `main` نفسه (Revert في GitHub)، وإلا أعاد النشر التالي الكود نفسه.

## الإعداد على الخادم (مرة واحدة، بحساب root)

```bash
# 1) ذاكرة إضافية لبناء الواجهة على خادم 1 جيجا
fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
node -v && npm -v                       # Node 22 مطلوب لبناء الواجهة

# 2) إيقاف النظام القديم (مؤقّت كل دقيقتين)
systemctl disable --now alopera-auto-deploy.timer

# 3) مجلد العمل الواحد، يُبنى مرة ثم يشير إليه current (Nginx لا يتغيّر)
A=/var/www/alopera-gym
sudo -u deploy git clone https://github.com/abaadapp/alopera-gym.git $A/app
cd $A/app && sudo -u deploy ln -sfn $A/shared/.env .env
sudo -u deploy rm -rf storage && sudo -u deploy ln -sfn $A/shared/storage storage
sudo -u deploy composer install --no-dev --optimize-autoloader --no-interaction
sudo -u deploy npm ci && sudo -u deploy npm run build
sudo -u deploy php artisan storage:link
sudo -u deploy php artisan config:cache && sudo -u deploy php artisan route:cache && sudo -u deploy php artisan view:cache && sudo -u deploy php artisan event:cache
ln -sfn $A/app $A/current && systemctl reload php8.4-fpm && sudo -u deploy php $A/app/artisan queue:restart

# مجلد حالة النشر (ROLLBACK.txt وDEPLOYED.txt وسجلات الترحيل) يملكه deploy: النظام القديم كان ينشئه بحساب root
install -d -o deploy -g deploy $A/shared/deploy-state && chown -R deploy:deploy $A/shared/deploy-state

# 4) حساب deploy يعيد تحميل PHP-FPM وحده
echo 'deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm' > /etc/sudoers.d/alopera-deploy
chmod 440 /etc/sudoers.d/alopera-deploy && visudo -c

# 5) مفتاح النشر: لا يشغّل إلا السكربت (المفتاح العام من صاحب المستودع)
install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
echo 'command="/var/www/alopera-gym/app/scripts/deploy.sh",no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-pty <المفتاح العام>' >> /home/deploy/.ssh/authorized_keys
chown deploy:deploy /home/deploy/.ssh/authorized_keys && chmod 600 /home/deploy/.ssh/authorized_keys
```

وفي GitHub ← Settings ← Secrets ← Actions:
- `DEPLOY_SSH_KEY`: المفتاح الخاص المقابل.
- `DEPLOY_KNOWN_HOSTS`: ناتج `ssh-keyscan 165.245.218.225`.

مجلدات `releases/` القديمة تبقى كما هي للرجوع إليها عند الحاجة، ولا يستخدمها النشر الجديد.

## المتابعة

| أين | ماذا |
|---|---|
| GitHub ← Actions ← Deploy Main | سجل كل نشر، وفحص الموقع، والوسم |
| `shared/deploy-state/DEPLOYED.txt` | الإصدار والـCommit والوقت على الخادم |
| `shared/deploy-state/ROLLBACK.txt` | الـCommit السابق وأمر الرجوع |
| `shared/deploy-state/migrate-*.log` | ناتج كل ترحيل |
