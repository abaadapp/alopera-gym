# بروتوكول أجهزة الدخول والبصمة

طبقة تكامل عامة (JSON عبر HTTPS) تخدم: قارئات QR/الباركود، بطاقات RFID، أجهزة البصمة، والبوابات الدوّارة.
الجهاز — أو جسر صغير بجانبه (Raspberry Pi/حاسوب صغير) إن كان الجهاز لا يرسل HTTP — يرسل ما قرأه، والنظام يرد
بالسماح أو الرفض ويسجّل القرار. **فتح المرحّل مسؤولية الجهاز/الجسر** عند `allowed: true`.

> لم يُختبر مع جهاز فعلي. الـ API مختبر آليًا (`tests/Feature/DevicesTest.php`). التوافق يعتمد على موديل الجهاز.

## التسجيل
1. **الإعدادات ← أجهزة الدخول ← إضافة جهاز**: الاسم، النوع، الاتجاه (دخول/خروج)، الاستخدام (مشتركات/موظفات/كلاهما).
2. يظهر **رمز الجهاز** مرة واحدة فقط (يُخزّن الهاش فقط). يمكن إصدار رمز جديد في أي وقت (يُبطل القديم).
3. لكل مشتركة QR تلقائي في بوابتها. للبطاقة/البصمة: «ملف المشتركة ← وسائل الدخول» (المعرّف كما يقرؤه الجهاز).
4. للموظفات: «أجهزة الدخول ← بصمات/بطاقات الموظفات».

## الطلبات

كل الطلبات: `Authorization: Bearer <device-token>`، `Content-Type: application/json`، الحد 120 طلبًا/دقيقة/جهاز.

### `POST /api/devices/v1/access` — قرار دخول
```json
{ "credential_type": "qr", "credential": "<محتوى QR أو رقم البطاقة أو معرّف البصمة>" }
```
`credential_type`: `qr` | `card` | `fingerprint` | `pin` | `barcode`

رد السماح:
```json
{
  "allowed": true, "reason": "ok", "message": "مسموح",
  "member": { "id": 12, "name": "سارة", "member_no": "M-00012", "photo_url": null },
  "membership": { "plan": "الشهرية", "ends_on": "2026-11-27", "days_left": 30, "sessions_left": null }
}
```
رد الرفض (HTTP 200):
```json
{ "allowed": false, "reason": "membership_expired", "message": "العضوية منتهية", "member": {…}, "membership": {…} }
```
أسباب الرفض: `unknown_credential`، `member_blocked`، `member_inactive`، `no_membership`، `membership_expired`،
`membership_frozen`، `membership_not_started`، `pending_payment`، `dues_outstanding` (إن فُعّل في الإعدادات)،
`device_not_for_members`.

إذا كان المعرّف لموظفة والجهاز مخصص للموظفات، يُسجَّل حضور/انصراف ويُرد `{"allowed": true, "reason": "staff", …}`.

### `POST /api/devices/v1/staff-punch` — حضور/انصراف موظفة
```json
{ "credential_type": "fingerprint", "credential": "FP-17" }
```
```json
{ "ok": true, "type": "in", "name": "هند", "at": "2026-10-28T08:01:00+04:00" }
```
البصمة الأولى في اليوم = حضور، والتالية = انصراف.

### `POST /api/devices/v1/heartbeat`
يحدّث «آخر ظهور» للجهاز في اللوحة.

## السجل
كل قرار يُحفظ في «سجل الدخول» (الطريقة، الجهاز، النتيجة، السبب). المعرّفات المجهولة تُحفظ مقنّعة (آخر 4 خانات).
الدخول المسموح يسجّل حضور حجز المشتركة في الحصة القريبة تلقائيًا.

## أجهزة شائعة
- **قارئ QR/باركود USB (Keyboard wedge)**: يعمل مباشرة مع شاشة الاستقبال (حقل المسح يبقى نشطًا) دون أي تكامل.
- **ZKTeco وما يماثلها**: تحتاج جسرًا يقرأ حدث البطاقة/البصمة (SDK أو بروتوكول ADMS) ويستدعي `/access` ثم يفتح المرحّل.
  جسر ZKTeco غير منفّذ؛ يُضاف كمشغّل (`gym_access_devices.driver`) عند تحديد الموديل.
