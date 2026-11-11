# الموقع ولوحة الإدارة على دومينين

| العنوان | ما يعرضه |
|---|---|
| `https://aloperagym.om` | الموقع الرسمي وبوابة الأعضاء: الرئيسية، الباقات، البرامج والمدربون، الحصص، الأخبار، التسجيل، المتجر، الدفع، الفواتير، البوابة |
| `https://app.aloperagym.om` | لوحة الإدارة: دخول المالك والموظفين، العضويات، المالية، نقطة البيع، المخزون، الموظفون، التقارير، إدارة محتوى الموقع |

تطبيق Laravel واحد وقاعدة بيانات واحدة على الخادم نفسه.

## كيف يفصل التطبيق بينهما

الفصل في الكود نفسه (`App\Core\Tenancy\SiteDomains` و`App\Http\Middleware\ResolveCustomDomain`)، لا في Nginx. يعمل تلقائيًا عندما:

- يكون `tenants.domain` للنادي مضبوطًا (`aloperagym.om`)؛
- و`APP_URL` دومينًا آخر غير محلي (`https://app.aloperagym.om`)؛
- و`GYM_HOME_CLUB` يسمّي النادي (أو هو النادي الوحيد).

على جهاز التطوير والاختبارات (`localhost`) يبقى كل شيء كما كان: عنوان واحد وروابط `/c/<club>/…`.

| الطلب | النتيجة |
|---|---|
| `aloperagym.om/plans` | صفحة الباقات، والعنوان في المتصفح يبقى `/plans` |
| `aloperagym.om/en/plans` | صفحة الباقات بالإنجليزية (#68)، والعنوان يبقى `/en/plans`؛ وكل صفحة للموقع والبوابة كذلك تحت `/en` |
| `aloperagym.om/c/alopera/plans` (رابط قديم، GET) | 301 إلى `aloperagym.om/plans` مع الاستعلام نفسه |
| نموذج قديم يُرسل POST إلى `aloperagym.om/c/alopera/...` | يُنفَّذ كما هو، دون تحويل يُضيع بياناته |
| `www.aloperagym.om/...` (GET) | 301 إلى `aloperagym.om/...` |
| `aloperagym.om/app/...` أو `/login` (GET) | 301 إلى المسار نفسه على `app.aloperagym.om` |
| POST إلى `aloperagym.om/login` أو `/logout` أو `/app/...` | 404 (لا تحويل لطلب فيه كلمة مرور أو رمز نموذج) |
| `app.aloperagym.om/` | صفحة دخول الموظفين (ومن كان داخلًا يذهب إلى لوحته) |
| `app.aloperagym.om/c/alopera/...` (GET) | 301 إلى المسار النظيف على `aloperagym.om` |
| `/webhooks/*`، `/api/*`، `/up`، `/build/*`، `/storage/*` | تعمل على الدومينين، فلا يتعطل أي تكامل مسجَّل بأيٍّ منهما |

الروابط تُبنى بعنوانها الصحيح أينما صُنعت:

- **في Laravel:** `route()`، والبريد، ورسائل واتساب من الطوابير، وsitemap، وروابط الدفع والعودة من Paymob.
- **في صفحات React:** Ziggy، عبر `App\Support\ZiggyScript`.
- **روابط الموقع والبوابة:** على `aloperagym.om` بمسارات نظيفة.
- **بقية الروابط:** على `APP_URL`.
- **رابط canonical في SEO:** كان يُبنى على دومين النادي من قبل.

الجلسات منفصلة بطبيعتها:

- كوكي جلسة الموظف لـ`app.aloperagym.om` وحده، وكوكي العضو لـ`aloperagym.om` وحده.
- يبقى `SESSION_DOMAIN` فارغًا في `.env`. لا تضعه `.aloperagym.om`، فذلك يجمع الجلستين.

## النشر الآمن على الخادم

### 0) قبل البدء: قراءة الإعداد الحالي (يدوي ومعدَّل، فلا نفترض أنه يطابق المستودع)

لا تُنشأ نسخ ولا أرشيفات، بقرار المالك: لا نسخ احتياطي. ملفات المواقع الحالية تبقى في `/etc/nginx/sites-available`،
فالرجوع يكون بإعادة ربطها.

```bash
ls -la /etc/nginx/sites-enabled/              # ما هو مفعّل الآن: روابط إلى sites-available
sudo nginx -t                                 # الإعداد الحالي سليم قبل أي تغيير
sudo certbot certificates                     # أسماء الشهادات ومساراتها الحقيقية
readlink -f /var/www/alopera-gym/current      # الإصدار الحالي (للرجوع)
grep -E '^(APP_URL|GYM_HOME_CLUB|SESSION_DOMAIN)=' /var/www/alopera-gym/shared/.env
```

يجب أن تكون القيم:

- `APP_URL=https://app.aloperagym.om`
- `GYM_HOME_CLUB=alopera`
- `SESSION_DOMAIN` غير موجود أو فارغ.

لا تغيّر `APP_KEY`.

### 1) نشر الكود (يحتاج موافقة صاحب النظام)

الدمج في `main` هو النشر: يشغّل Deploy Main السكربت على الخادم (docs/DEPLOY.md). يدويًا، بموافقة صاحب النظام فقط:

```bash
sudo -u deploy bash /var/www/alopera-gym/app/scripts/deploy.sh
```

ما يفعله `deploy.sh`، بالترتيب (رأس السكربت):

- يفحص الترحيلات الجديدة قبل أي تغيير. ترحيل يحذف أو يعيد التسمية دون علامة مراجعة (`@deploy-destructive-reviewed`) يوقف
  النشر قبل السحب.
- يسحب `main` إلى نسخة العمل الوحيدة `/var/www/alopera-gym/app`، و`current` يشير إليها. ثم يثبّت حزم PHP.
- يبني الواجهة جانبًا في `public/build.new`، ثم يبدّلها. فشل البناء لا يمس الصفحات الحالية.
- يشغّل الترحيلات **دون نسخة من قاعدة البيانات قبلها**، بقرار المالك. لا ينشئ نسخًا ولا أرشيفات.
- يحدّث ذاكرة الإعداد، ويعيد تحميل PHP-FPM والعمّال، ويشغّل فحص الجاهزية (يُبلغ ولا يوقف)، ويشترط أن يرد الموقع بـ 200.
- لا يلمس nginx ولا `shared/.env` ولا الملفات المرفوعة.

### 2) Nginx: تطبيق الملفات الجديدة بعد مقارنتها بالحالية

```bash
cd /var/www/alopera-gym/current
bash scripts/test-nginx-config.sh                     # الملفات نفسها على nginx محلي مؤقت (يشغّله CI أيضًا)
sudo cp deploy/nginx/snippets/alopera-laravel.conf /etc/nginx/snippets/
sudo cp deploy/nginx/00-reject-unknown-hosts.conf /etc/nginx/sites-available/00-reject-unknown-hosts
sudo cp deploy/nginx/aloperagym.om.conf /etc/nginx/sites-available/aloperagym.om
diff <(sudo cat /etc/nginx/sites-enabled/*) /etc/nginx/sites-available/aloperagym.om   # راجع الفروق
# صحّح مسارات الشهادات حسب `certbot certificates`، وانقل أي إعداد يدوي ما زال لازمًا
sudo ln -sfn /etc/nginx/sites-available/aloperagym.om /etc/nginx/sites-enabled/aloperagym.om
sudo ln -sfn /etc/nginx/sites-available/00-reject-unknown-hosts /etc/nginx/sites-enabled/00-reject-unknown-hosts
sudo rm -f /etc/nginx/sites-enabled/default           # موقع أوبونتو الافتراضي: لا يبقى إلا default_server واحد
sudo rm /etc/nginx/sites-enabled/<الملف القديم>     # الرابط فقط: الملف يبقى في sites-available للرجوع
sudo nginx -t && sudo systemctl reload nginx
```

إن فشل `nginx -t` فلا تُعِد التحميل. أرجع الرابط القديم إلى `sites-enabled`.

`00-reject-unknown-hosts` هو الخادم الافتراضي الوحيد على 80 و443. أي Host لا تسميه كتلة (عنوان مزيف، عنوان IP،
بلا Host) يُغلق اتصاله دون رد، ودون اسم SNI معروف تُرفض مصافحة TLS. لا تحويل إلى عنوان مزيف، ولا صفحة nginx الافتراضية.

القواعد اليدوية التي كانت تفصل المسارات لم تعد لازمة، لأن التطبيق يفصل بنفسه. يمكن إبقاؤها مؤقتًا إن كانت لا تحجب `/webhooks` أو `/api` أو `/build` أو `/storage`.

### 3) تجديد الشهادات

```bash
sudo mkdir -p /var/www/letsencrypt
echo 'systemctl reload nginx' | sudo tee /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh && sudo chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
sudo certbot renew --dry-run
systemctl list-timers | grep certbot
```

## التحقق بعد النشر

```bash
curl -sI https://aloperagym.om/ | head -1                                  # 200
curl -sI https://aloperagym.om/plans | head -1                             # 200
curl -sI https://aloperagym.om/c/alopera/plans | grep -iE '^(HTTP|location)' # 301 → https://aloperagym.om/plans
curl -sI https://www.aloperagym.om/ | grep -i '^location'                  # https://aloperagym.om/
curl -sI https://aloperagym.om/login | grep -i '^location'                 # https://app.aloperagym.om/login
curl -sI https://app.aloperagym.om/ | grep -i '^location'                  # https://app.aloperagym.om/login
curl -sI https://app.aloperagym.om/c/alopera/plans | grep -i '^location'   # https://aloperagym.om/plans
curl -s https://aloperagym.om/sitemap.xml | grep -c '/c/'                  # 0
curl -sI https://aloperagym.om/up | head -1; curl -sI https://app.aloperagym.om/up | head -1   # 200 و200
curl -s https://aloperagym.om/plans | grep -o '"domain":"[a-z.]*"' | sort -u                   # الدومينان فقط
curl -s -o /dev/null -H 'Host: evil.example' https://aloperagym.om/; echo $?         # 52 أو 92: يُغلق دون رد
curl -s -o /dev/null -H 'Host: evil.example' http://aloperagym.om/; echo $?          # 52
curl -sI https://aloperagym.om/up | grep -i '^server'                              # server: nginx (دون رقم إصدار)
```

ثم في المتصفح:

- دخول المالك على `app.aloperagym.om`.
- زر «موقع النادي» في الشريط العلوي يفتح `aloperagym.om`.
- تسجيل عضو تجريبي ودخول البوابة على `aloperagym.om/portal`.
- صفحة دفع (دون دفع فعلي).
- صور الموقع وشعار النادي تظهر.

وفي **Paymob**:

- عنوان الإشعار يبقى يعمل على الدومينين.
- رابط العودة يُبنى الآن على `aloperagym.om/pay/return/...`.
- الروابط القديمة تُحوَّل بـ301 مع الاستعلام، وهي طلبات GET.

## الخادم والمستودع (2026-11-11)

الخادم لا يستعمل ملفات المستودع حرفيًا. الملفات فيه: `alopera-gym` (اللوحة)، و`alopera-website` (الموقع)،
و`www-aloperagym.om`، و`00-reject-unknown-hosts`. الحماية نفسها في الجهتين:

- الخادم الافتراضي الوحيد يغلق أي Host مجهول.
- لا يشغّل PHP إلا `index.php`، ولا شيء تحت `/storage/`.
- لا يظهر رقم إصدار nginx.
- TLS 1.2 و1.3 فقط.

على الخادم يأتي إخفاء الإصدار وTLS من `nginx.conf`، وفي المستودع من كل كتلة.

ما يختلف، ولا يمس الحماية:

- قواعد مسارات يدوية على الخادم:
  - `/` → `/login` على اللوحة.
  - `/c/*` → 404 على اللوحة.
  - مسارات اللوحة و`/webhooks` و`/api` → 404 على الموقع.
- المستودع يضيف ترويسات الأمان وHSTS من nginx أيضًا. على الخادم يضيفها Laravel وحده (`SecurityHeaders`).

أي إعادة بناء تتبع هذا الملف ولا تُنقص من حماية الخادم.

## الرجوع عند حدوث مشكلة

| المشكلة | الأمر |
|---|---|
| الكود | `sudo -u deploy bash /var/www/alopera-gym/app/scripts/rollback-code.sh <commit>` (الـcommit في `shared/deploy-state/ROLLBACK.txt`؛ docs/DEPLOY.md) |
| Nginx | أعد ربط الملفات القديمة: `sudo ln -s /etc/nginx/sites-available/<الملف> /etc/nginx/sites-enabled/`. واحذف روابط الجديدة، ثم `sudo nginx -t && sudo systemctl reload nginx` |
| قاعدة البيانات (الترحيلات لا تُعكس تلقائيًا) | لا توجد نسخ تُستعاد، لا من النشر ولا من غيره، بقرار المالك. ما غيّرته الترحيلات يُصلح بترحيل جديد، وبموافقة صاحب النظام |

الرجوع بالكود لا يمس قاعدة البيانات ولا `.env` ولا الملفات المرفوعة.

لا يُفرَّغ `tenants.domain` لتعطيل الفصل. منذ #142 هو ما يجعل `aloperagym.om` نطاقًا معتمدًا عند Laravel
(`SiteDomains::trustedHosts`)، فإفراغه يجعل الموقع يرد 400. وأي تعديل على بيانات الإنتاج بموافقة صاحب النظام.
