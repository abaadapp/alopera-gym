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

### 0) قبل البدء: نسخة احتياطية من الإعداد الحالي (يدوي ومعدَّل، فلا نفترض أنه يطابق المستودع)

```bash
sudo tar -czf /root/nginx-before-domains-$(date +%F-%H%M).tar.gz /etc/nginx /etc/letsencrypt/renewal
sudo nginx -T > ~/nginx-T-before.txt          # الإعداد الفعّال كاملًا للمقارنة
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

```bash
cd /var/www/alopera-gym/current
sudo -u deploy bash scripts/deploy.sh vX.Y.Z      # وسم الإصدار الذي صنعه CI بعد الدمج
```

ما يفعله `deploy.sh`:

- يتأكد من وجود `shared/.env` و`shared/storage`، ولا ينشئهما ولا يستبدلهما.
- يأخذ ملفات الواجهة المبنية من إصدار GitHub (بناها CI)، فلا يشغّل npm على خادم 1GB. إن لم يجدها يبنيها كما كان.
- يأخذ نسخة من قاعدة البيانات إلى `shared/backups` قبل الترحيلات.
- يشغّل الترحيلات وفحص الجاهزية، ثم يبدّل الإصدار.
- يحتفظ بآخر 5 إصدارات.

### 2) Nginx: تطبيق الملفات الجديدة بعد مقارنتها بالحالية

```bash
cd /var/www/alopera-gym/current
sudo cp deploy/nginx/snippets/alopera-laravel.conf /etc/nginx/snippets/
sudo cp deploy/nginx/aloperagym.om.conf /etc/nginx/sites-available/aloperagym.om
diff <(sudo cat /etc/nginx/sites-enabled/*) /etc/nginx/sites-available/aloperagym.om   # راجع الفروق
# صحّح مسارات الشهادات حسب `certbot certificates`، وانقل أي إعداد يدوي ما زال لازمًا
sudo ln -sfn /etc/nginx/sites-available/aloperagym.om /etc/nginx/sites-enabled/aloperagym.om
sudo rm /etc/nginx/sites-enabled/<الملف القديم>     # بعد التأكد أنه منسوخ في الخطوة 0
sudo nginx -t && sudo systemctl reload nginx
```

إن فشل `nginx -t` فلا تُعِد التحميل. أرجع الرابط القديم إلى `sites-enabled`.

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

## الرجوع عند حدوث مشكلة

| المشكلة | الأمر |
|---|---|
| الكود | `sudo -u deploy bash /var/www/alopera-gym/current/scripts/rollback.sh` (الإصدار السابق) أو `rollback.sh <ts>` |
| Nginx | `sudo tar -xzf /root/nginx-before-domains-*.tar.gz -C / && sudo nginx -t && sudo systemctl reload nginx` |
| قاعدة البيانات (نادرًا: الترحيلات لا تُعكس تلقائيًا) | استعادة آخر نسخة في `shared/backups/db-*.sql.gz` بعد إيقاف الطوابير، وبموافقة صاحب النظام |

الرجوع بالكود لا يمس قاعدة البيانات ولا `.env` ولا الملفات المرفوعة.

تعطيل الفصل مؤقتًا دون رجوع: إفراغ `tenants.domain` يعيد السلوك القديم، أي روابط `/c/alopera` على عنوان واحد. لا تفعله إلا للضرورة.
