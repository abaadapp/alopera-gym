# Alopera Gym — منصة إدارة الأندية والمراكز الرياضية (SaaS)

نظام **متعدد الأندية (Multi-Tenant SaaS)** لإدارة الأندية والمراكز الرياضية، النسائية والرجالية والمختلطة: لوحة لمالك المنصة، ولوحة مستقلة لكل
نادٍ، وموقع إلكتروني ومتجر وبوابة أعضاء لكل نادٍ، مع أتمتة واتساب ودفع Paymob وطبقة تكامل لأجهزة الدخول.
**Alopera Gym** هو النادي الأول على المنصة.

> الواجهات عربية RTL افتراضيًا. التقنيات: Laravel 13 · PHP 8.4 · PostgreSQL 16 · Inertia 3 · React 19 · TypeScript · Tailwind 4.

---

## ما الذي يحتويه النظام

| الجزء | المسار | الوصف |
|---|---|---|
| لوحة المنصة (Super Admin) | `/platform` | الأندية، باقات المنصة، اشتراكات الأندية وفواتيرها، الإيقاف/التفعيل، سجل بوابات الدفع، سجل التدقيق |
| لوحة النادي | `/app` | الأعضاء والعضويات، الحصص والحجوزات، التدريب الشخصي، المبيعات ونقطة البيع وطلبات المتجر، المالية، الرواتب، المستندات والتجديدات، التقارير، واتساب، الموقع، التطبيقات التكاملية (Paymob والأجهزة وواتساب)، الإعدادات |
| واجهة الاستقبال | `/app/reception` | بحث، دخول بـ QR، بيع وتجديد، تحصيل، حجز، تحقق من الحالة، واتساب |
| لوحة المدرب | `/app/trainer` | حصصه، قوائم الحجز، تسجيل الحضور والغياب، جلساته الفردية |
| موقع النادي | `/c/{slug}` أو دومين النادي | الرئيسية، الباقات، البرامج، الجدول، فريق التدريب، العروض والأخبار، المتجر، التواصل، الاشتراك أونلاين |
| بوابة الأعضاء | `/c/{slug}/portal` | العضوية وتاريخ الانتهاء وQR الدخول، التجديد والدفع، الفواتير، حجز الحصص، الملف الشخصي |
| Webhooks | `/webhooks/paymob`، `/webhooks/whatsapp` | تحقق توقيع إلزامي |
| API الأجهزة | `/api/devices/v1/*` | قارئات QR/باركود، بوابات، بصمة (انظر [docs/DEVICES.md](docs/DEVICES.md)) |

الوثائق التفصيلية:

- [docs/STATUS.md](docs/STATUS.md) — **التقرير النهائي**: الوظائف المكتملة والتحقق والتكاملات التي تحتاج حسابات حقيقية
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — المعمارية، الوحدات، عزل الأندية، دورة العضوية والمدفوعات
- [docs/FEATURES.md](docs/FEATURES.md) — مطابقة كل بند في مواصفات المزايا مع التنفيذ وحالته
- [docs/PERMISSIONS.md](docs/PERMISSIONS.md) — مصفوفة الأدوار والصلاحيات
- [docs/INTEGRATIONS.md](docs/INTEGRATIONS.md) — إعداد واتساب و Paymob وأجهزة الشبكة
- [docs/DEVICES.md](docs/DEVICES.md) — بروتوكول أجهزة الدخول والبصمة
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — النشر على DigitalOcean (خادم مخصص لـ Alopera أو منصة مشتركة)
- [docs/ACCEPTANCE.md](docs/ACCEPTANCE.md) — اختبارات القبول وطريقة التحقق
- [docs/REMAINING.md](docs/REMAINING.md) — ما لم يكتمل أو لم يُختبر، والمتطلبات الخارجية
- [docs/ABAAD.md](docs/ABAAD.md) — العلاقة مع مشروع أبعاد وخطة الدمج المستقبلي

---

## التشغيل المحلي

المتطلبات: PHP 8.4 (pdo_pgsql, gd, zip, bcmath)، Composer 2، Node 22+، PostgreSQL 16.

```bash
git clone https://github.com/abaadapp/alopera-gym.git && cd alopera-gym
composer install
npm install
cp .env.example .env          # عدّلي DB_USERNAME / DB_PASSWORD
php artisan key:generate

createdb alopera_gym
createdb alopera_gym_testing  # لقاعدة الاختبارات

php artisan migrate --seed    # ينشئ بيانات Alopera التجريبية (مرة واحدة فقط)
php artisan storage:link

composer dev                  # أمر واحد للتطوير: الخادم + Vite + الطابور + المجدول + السجلات — Ctrl+C للإيقاف
```

`composer dev` يشغّل معًا: `php artisan serve` (http://localhost:8000)، `npm run dev` (Vite مع التحديث الفوري)،
عامل الطابور على `notifications,default` (رسائل واتساب والحملات)، `schedule:work` (العضويات، التذكيرات، الحصص، النسخ
الاحتياطي)، و`pail` لعرض السجلات. لتشغيل نسخة مبنية بدل Vite: `npm run build` ثم `php artisan serve`.

### حسابات البيانات التجريبية (محليًا فقط)

كلمة المرور لكل الحسابات: `password`

| الحساب | البريد |
|---|---|
| مدير المنصة | `admin@alopera.test` |
| مالك Alopera | `owner@alopera.test` |
| الإدارة | `manager@alopera.test` |
| الاستقبال | `reception@alopera.test` |
| المالية | `finance@alopera.test` |
| مدرب | `trainer@alopera.test` |
| نادٍ ثانٍ (لاختبار العزل) | `owner@demofit.test` |

- موقع Alopera: `http://localhost:8000/c/alopera`
- بوابة الأعضاء: أول 10 أعضاء تجريبيين لديهم كلمة المرور `password` (الدخول برقم الهاتف من صفحة العضو في اللوحة).
- واتساب في البيانات التجريبية يعمل بمشغّل `log` (تُسجَّل الرسائل في السجل ولا تُرسل فعليًا).
- Paymob غير مُعدّ في البيانات التجريبية (يلزم مفاتيح حساب النادي).

> البذرة التجريبية ترفض العمل في بيئة `production`.

---

## الاختبارات

```bash
php artisan test              # 256 اختبارًا على PostgreSQL (alopera_gym_testing)
php artisan platform:preflight  # فحص جاهزية الإنتاج (يفشل محليًا عمدًا: بيئة تجريبية)
php artisan clubs list          # إدارة الأندية من الخادم (list | create | suspend | activate) بدل لوحة المنصة
php artisan accounting:sync     # بناء الدفاتر المحاسبية للسجلات القديمة (آمن للتكرار)
php artisan documents:alerts    # تنبيهات انتهاء المستندات (يومي، لا يكرر تنبيهًا)
php artisan sales:expire-orders # إلغاء طلبات المتجر غير المدفوعة بعد مهلتها
php artisan gym:class-reminders # تذكير واتساب قبل الحصص المحجوزة (حسب إعداد النادي، متوقف افتراضيًا)
npx tsc --noEmit              # فحص أنواع TypeScript
vendor/bin/pint --test        # تنسيق PHP
```

تغطي الاختبارات: عزل الأندية، مصفوفة الأدوار، دورة العضوية (الدفع، التجميد، التجديد، الترقية، الانتهاء)،
الحجوزات والسعة، أمان Webhook الخاص بـ Paymob ومنع التكرار، واتساب (التوقيع، الموافقات، الحملات، الحالات)، API الأجهزة،
نقطة البيع والمخزون، النسخ والاستعادة، اشتراكات المنصة، الموقع والبوابة و SEO والدومين المخصص، التصدير وسجل التدقيق
وتحديد المعدل. يعمل CI على GitHub Actions ([.github/workflows/ci.yml](.github/workflows/ci.yml)).

### الإصدارات

```bash
scripts/release.sh --dry-run  # يعرض رقم الإصدار التالي والـ commits دون رفع
scripts/release.sh            # يرفع main إلى GitHub مع وسم الإصدار التالي (v0.1.0، v0.2.0، …)
```

رقم الإصدار يُحسب من عناوين الـ commits منذ آخر وسم: `feat` يرفع الرقم الأوسط، وأي نوع آخر يرفع الأخير.
يرفض السكربت الرفع إذا وجد ملف `.env` أو مفاتيح أو رموز وصول في الـ commits الجديدة. الرفع لا ينشر على الخادم؛
النشر يتم يدويًا على الخادم بـ `scripts/deploy.sh v0.1.0` لإصدار محدد.

---

## الأمان باختصار

- عزل البيانات بـ `tenant_id` عبر نطاق عام **يفشل مغلقًا** (لا نادٍ ← لا صفوف)، ومنع الكتابة لنادٍ آخر، وقواعد تحقق خاصة بالنادي.
- صلاحيات فعلية في الخادم لكل مسار، وسجل تدقيق غير قابل للتعديل لكل عملية حساسة.
- أسرار التكاملات مشفّرة في قاعدة البيانات ولا تُرسل إلى المتصفح أبدًا.
- العضوية لا تُفعّل إلا بدفع مؤكد؛ Paymob عبر Webhook موقّع فقط، وصفحة العودة لا تغيّر شيئًا.
- المستندات وإثباتات التحويل على قرص خاص، وتُنزَّل عبر متحكم يتحقق من الصلاحية والنادي.
- Rate limiting على الدخول والنماذج العامة والـ Webhooks والأجهزة والتصدير؛ ترويسات أمان و HSTS.

الترخيص: خاص بمالك المستودع.
