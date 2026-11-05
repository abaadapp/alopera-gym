<?php

namespace App\Core\Auth;

/**
 * Every permission a club role can hold, grouped for the role editor.
 *
 * Keys are checked server-side through the Gate (`can:members.view` route
 * middleware, `$this->authorize()` and policies). Hiding a button in the UI is
 * never the only protection.
 */
final class PermissionRegistry
{
    /** @var array<string, array{label: string, permissions: array<string, string>}> */
    public const GROUPS = [
        'dashboard' => ['label' => 'لوحة التحكم', 'permissions' => [
            'dashboard.view' => 'عرض لوحة التحكم والمؤشرات',
        ]],
        'members' => ['label' => 'الأعضاء', 'permissions' => [
            'members.view' => 'عرض الأعضاء',
            'members.manage' => 'إضافة وتعديل الأعضاء',
            'members.delete' => 'حذف الأعضاء',
            'members.documents' => 'مستندات الأعضاء وعقودهم',
            'members.export' => 'تصدير بيانات الأعضاء',
        ]],
        'memberships' => ['label' => 'العضويات', 'permissions' => [
            'memberships.view' => 'عرض العضويات',
            'memberships.sell' => 'بيع وتجديد العضويات',
            'memberships.freeze' => 'تجميد واستئناف العضويات',
            'memberships.cancel' => 'إلغاء العضويات',
            'memberships.upgrade' => 'ترقية العضويات',
            'plans.manage' => 'إدارة الباقات والأسعار والخصومات',
        ]],
        'training' => ['label' => 'التدريب والحصص', 'permissions' => [
            'classes.view' => 'عرض الجداول والحصص',
            'classes.manage' => 'إدارة البرامج والجداول والحصص',
            'trainers.manage' => 'إدارة فريق التدريب ومواعيدهم',
            'bookings.manage' => 'حجز الحصص للأعضاء وإلغاؤها',
            'attendance.mark' => 'تسجيل الحضور والغياب في الحصص',
            'pt.manage' => 'إدارة الجلسات الفردية',
            'pt.sell' => 'بيع باقات التدريب الشخصي',
            'pt.close_early' => 'إغلاق الجلسات الفردية قبل موعدها',
        ]],
        'access' => ['label' => 'الدخول والحضور', 'permissions' => [
            'checkin.perform' => 'تسجيل دخول الأعضاء',
            'checkin.view' => 'عرض سجل الدخول',
            'devices.manage' => 'أجهزة البصمة والبوابات',
        ]],
        'finance' => ['label' => 'المالية', 'permissions' => [
            'invoices.view' => 'عرض الفواتير',
            'invoices.manage' => 'إصدار الفواتير',
            'invoices.void' => 'إلغاء الفواتير',
            'invoices.credit' => 'إصدار الإشعارات الدائنة',
            'payments.record' => 'تسجيل الدفعات',
            'payments.confirm' => 'تأكيد التحويلات البنكية',
            'payments.refund' => 'رد المبالغ',
            'payments.cancel' => 'إلغاء الدفعات المسجّلة بالخطأ',
            'cash_shifts.manage' => 'فتح ورديات الصندوق وإقفالها ونقل النقد من الدرج وإليه',
            'cash_shifts.view' => 'عرض ورديات الصندوق وتقارير إقفالها',
            'expenses.manage' => 'المصروفات',
            'dues.view' => 'المستحقات والمتأخرات',
        ]],
        'accounting' => ['label' => 'المحاسبة', 'permissions' => [
            'accounting.view' => 'الملخص المالي والحسابات والقيود والتقارير المالية',
            'accounting.manage' => 'الحسابات البنكية والقيود اليدوية والإعدادات المالية',
            'accounting.close' => 'إقفال الفترات المحاسبية',
            'accounting.reopen' => 'إعادة فتح الفترات المقفلة',
            'accounting.all_branches' => 'مالية النادي كله لحساب مرتبط بفرع: الملخص والحسابات البنكية والقيود والفترات ومسيرات الرواتب',
        ]],
        'payroll' => ['label' => 'الرواتب والموظفون', 'permissions' => [
            'employees.view' => 'عرض ملفات الموظفين (دون الرواتب)',
            'employees.create' => 'إضافة الموظفين',
            'employees.manage' => 'تعديل بيانات الموظفين الأساسية والمسميات الوظيفية',
            'employees.access' => 'حسابات دخول الموظفين وأدوارهم وصلاحياتهم',
            'payroll.salaries' => 'عرض الرواتب والبيانات البنكية (سري)',
            'payroll.compensation' => 'تعديل الرواتب والبدلات والبيانات البنكية',
            'payroll.prepare' => 'إعداد مسيرات الرواتب',
            'payroll.edit' => 'تعديل المسيرات غير المعتمدة',
            'payroll.approve' => 'اعتماد مسيرات الرواتب',
            'payroll.pay' => 'صرف الرواتب',
            'payroll.payslips' => 'عرض كشوف الرواتب وسندات الصرف وطباعتها',
            'payroll.reports' => 'تقارير الرواتب وتصديرها',
            'staff_attendance.view' => 'عرض حضور وانصراف الموظفين',
            'staff_attendance.manage' => 'تسجيل الحضور يدويًا وتصحيحه',
        ]],
        'sales' => ['label' => 'المبيعات والمتجر', 'permissions' => [
            'sales.view' => 'صفحة المبيعات وعملياتها وتقاريرها',
            'pos.sell' => 'البيع من نقطة البيع',
            'sales.discount' => 'تطبيق الخصومات عند البيع',
            'sales.refund' => 'إلغاء عمليات البيع ورد المبالغ',
            'products.manage' => 'المنتجات والتصنيفات والإضافات والأسعار والمخزون',
            'orders.manage' => 'طلبات الموقع: المراجعة والتجهيز والتسليم',
            'pos_devices.manage' => 'أجهزة نقاط البيع وملحقاتها وتفعيلها',
        ]],
        'inventory' => ['label' => 'المخزون والمشتريات', 'permissions' => [
            'inventory.view' => 'عرض المخزون والأرصدة وسجل الحركة',
            'inventory.adjust' => 'الجرد وتسجيل التالف والفاقد والتسويات',
            'inventory.transfer' => 'طلب نقل المخزون وصرفه واستلامه وطلبات تزويد الفروع',
            'inventory.approve' => 'اعتماد الجرد ونقل المخزون وطلبات التزويد',
            'purchases.view' => 'عرض المشتريات وأوامر الشراء وسندات الموردين',
            'purchases.manage' => 'إنشاء أوامر الشراء وسندات الموردين',
            'purchases.approve' => 'اعتماد أوامر الشراء وسندات الاستلام وسندات الموردين',
            'purchases.receive' => 'استلام البضاعة',
            'purchases.pay' => 'سداد الموردين',
            'suppliers.view' => 'عرض الموردين وملفاتهم',
            'suppliers.create' => 'إضافة الموردين',
            'suppliers.manage' => 'تعديل الموردين وإيقافهم وحذفهم',
        ]],
        'documents' => ['label' => 'مستندات النشاط', 'permissions' => [
            'documents.view' => 'عرض المستندات (العامة، والسرية المسندة إليه)',
            'documents.create' => 'إضافة المستندات',
            'documents.renew' => 'تجديد المستندات',
            'documents.manage' => 'تعديل المستندات وحذفها، وأنواعها وتنبيهاتها',
            'documents.confidential' => 'الاطلاع على كل المستندات السرية',
        ]],
        'reports' => ['label' => 'التقارير', 'permissions' => [
            'reports.view' => 'عرض التقارير',
            'reports.financial' => 'عرض البيانات المالية: إيرادات لوحة التحكم والتقارير المالية',
            'reports.export' => 'تصدير التقارير',
        ]],
        'printing' => ['label' => 'الطباعة', 'permissions' => [
            'papers.print' => 'طباعة المستندات وتنزيلها PDF: الفواتير والإيصالات والسندات والكشوف',
        ]],
        'messaging' => ['label' => 'واتساب والتسويق', 'permissions' => [
            'whatsapp.inbox' => 'صندوق المحادثات',
            'whatsapp.send' => 'إرسال الرسائل',
            'campaigns.manage' => 'الحملات التسويقية',
            'templates.manage' => 'القوالب والردود الآلية',
        ]],
        'website' => ['label' => 'الموقع الإلكتروني', 'permissions' => [
            'website.manage' => 'إدارة الموقع الإلكتروني: المحتوى والأقسام والهوية والأخبار والرسائل',
        ]],
        'settings' => ['label' => 'الإعدادات والإدارة', 'permissions' => [
            'settings.manage' => 'إعدادات النادي والهوية',
            'papers.manage' => 'قوالب الأوراق (الفواتير والسندات والكشوف المطبوعة)',
            'branches.manage' => 'الفروع',
            'users.manage' => 'المستخدمون',
            'roles.manage' => 'الأدوار والصلاحيات',
            'integrations.manage' => 'التطبيقات التكاملية: مفاتيح Paymob وواتساب',
            'audit.view' => 'سجل التدقيق',
            'backups.manage' => 'النسخ الاحتياطي والاستعادة',
        ]],
    ];

    /**
     * Permissions that open nothing without another. Every payroll step shows salaries, so each takes
     * payroll.salaries: a list holding one without it is read, and stored, without it.
     */
    public const REQUIRES = [
        'payroll.compensation' => 'payroll.salaries',
        'payroll.prepare' => 'payroll.salaries',
        'payroll.edit' => 'payroll.salaries',
        'payroll.approve' => 'payroll.salaries',
        'payroll.pay' => 'payroll.salaries',
        'payroll.payslips' => 'payroll.salaries',
        'payroll.reports' => 'payroll.salaries',
    ];

    /** Permission groups that belong to an optional module (config gym.modules.<module>). */
    /**
     * Sensitive: salaries and bank data, preparing, approving and paying salaries (and undoing a payout), the books and
     * the bank accounts, undoing money, closed months, stock approvals, supplier purchases, exports, accounts and roles,
     * branches, integrations, backups. Whoever holds one uses it; only the owner gives or takes it (Grants::decides).
     */
    public const SENSITIVE = [
        'payroll.salaries', 'payroll.compensation', 'payroll.prepare', 'payroll.edit', 'payroll.approve', 'payroll.pay', 'payroll.reports',
        'accounting.manage', 'accounting.close', 'accounting.reopen', 'accounting.all_branches',
        'invoices.void', 'invoices.credit', 'payments.refund', 'payments.cancel', 'sales.refund',
        'inventory.adjust', 'inventory.approve', 'purchases.manage', 'purchases.approve', 'purchases.pay',
        'members.export', 'reports.export',
        'users.manage', 'roles.manage', 'employees.access', 'branches.manage', 'integrations.manage', 'backups.manage',
    ];

    /** What each permission lets its holder do, in a line under its name in the editors. */
    public const DESCRIPTIONS = [
        'dashboard.view' => 'يفتح لوحة التحكم ومؤشرات الأعضاء والحضور اليومية.',
        'members.view' => 'يبحث في الأعضاء ويفتح ملفاتهم وبيانات التواصل.',
        'members.manage' => 'يضيف عضوًا جديدًا ويعدّل بياناته وصورته.',
        'members.delete' => 'يحذف ملف عضو لا سجلات مالية عليه.',
        'members.documents' => 'يرفع مستندات العضو وعقوده ويطّلع عليها.',
        'memberships.view' => 'يرى عضويات الأعضاء وتواريخها وحالتها.',
        'memberships.sell' => 'يبيع عضوية جديدة أو يجدّد عضوية قائمة بفاتورة.',
        'memberships.freeze' => 'يجمّد العضوية مدةً ثم يستأنفها فتمتد نهايتها.',
        'memberships.cancel' => 'يلغي عضوية قائمة قبل نهايتها.',
        'memberships.upgrade' => 'ينقل العضو إلى باقة أعلى ويحسب الفرق.',
        'plans.manage' => 'ينشئ الباقات ويحدد أسعارها ومددها والخصومات عليها.',
        'classes.view' => 'يرى جدول الحصص والبرامج ومواعيدها.',
        'classes.manage' => 'ينشئ البرامج والحصص ويعدّل مواعيدها وسعتها.',
        'trainers.manage' => 'يضيف المدربين ويحدد مواعيد عملهم.',
        'bookings.manage' => 'يحجز مقعدًا للعضو في حصة أو يلغي حجزه.',
        'attendance.mark' => 'يسجّل حضور الأعضاء وغيابهم في الحصة.',
        'pt.manage' => 'ينظّم جلسات التدريب الشخصي ومواعيدها ومدربيها.',
        'pt.sell' => 'يبيع باقة جلسات تدريب شخصي بفاتورة.',
        'pt.close_early' => 'يغلق باقة الجلسات الفردية قبل انتهاء موعدها.',
        'checkin.perform' => 'يسجّل دخول العضو إلى النادي بالبحث أو الرمز.',
        'checkin.view' => 'يرى سجل دخول الأعضاء وأوقاته.',
        'devices.manage' => 'يضيف أجهزة البصمة والبوابات ويربط بطاقات الدخول.',
        'invoices.view' => 'يرى الفواتير ومبالغها وحالة سدادها.',
        'invoices.manage' => 'يصدر فاتورة جديدة لعضو أو بيع.',
        'payments.record' => 'يسجّل دفعة نقدية أو بالبطاقة أو تحويلًا على فاتورة.',
        'payments.confirm' => 'يؤكد وصول تحويل بنكي فتُحتسب الدفعة.',
        'cash_shifts.manage' => 'يفتح وردية الصندوق ويقفلها وينقل النقد من الدرج وإليه.',
        'cash_shifts.view' => 'يرى ورديات الصندوق وتقارير إقفالها.',
        'expenses.manage' => 'يسجّل المصروفات ويعدّلها ويرفق إيصالاتها.',
        'dues.view' => 'يرى المبالغ المستحقة على الأعضاء والمتأخرة منها.',
        'accounting.view' => 'يرى الملخص المالي والحسابات والقيود والتقارير المالية.',
        'employees.view' => 'يفتح ملفات الموظفين دون رواتبهم.',
        'employees.create' => 'يضيف موظفًا جديدًا إلى الفريق.',
        'employees.manage' => 'يعدّل بيانات الموظف الأساسية ومسمّاه الوظيفي.',
        'payroll.payslips' => 'يعرض كشوف الرواتب وسندات الصرف ويطبعها.',
        'staff_attendance.view' => 'يرى حضور الموظفين وانصرافهم.',
        'staff_attendance.manage' => 'يسجّل حضور موظف يدويًا أو يصحّحه مع ذكر السبب.',
        'sales.view' => 'يفتح صفحة المبيعات وعملياتها وتقاريرها.',
        'pos.sell' => 'يبيع المنتجات والخدمات من نقطة البيع.',
        'sales.discount' => 'يطبّق خصمًا على عملية البيع.',
        'products.manage' => 'يضيف المنتجات وتصنيفاتها ويحدد أسعارها ومخزونها.',
        'orders.manage' => 'يراجع طلبات الموقع ويجهّزها ويسلّمها.',
        'pos_devices.manage' => 'يضيف أجهزة نقاط البيع وملحقاتها ويفعّلها.',
        'inventory.view' => 'يرى أرصدة المخزون وسجل حركته.',
        'inventory.transfer' => 'يطلب نقل مخزون بين الفروع ويصرفه ويستلمه.',
        'purchases.view' => 'يرى أوامر الشراء وسندات الموردين.',
        'purchases.receive' => 'يستلم البضاعة الواردة ويسجّل كمياتها.',
        'suppliers.view' => 'يرى الموردين وملفاتهم ومستحقاتهم.',
        'suppliers.create' => 'يضيف موردًا جديدًا.',
        'suppliers.manage' => 'يعدّل بيانات الموردين ويوقفهم أو يحذفهم.',
        'documents.view' => 'يرى المستندات العامة والسرية المسندة إليه.',
        'documents.create' => 'يضيف مستندًا جديدًا ويرفق ملفه.',
        'documents.renew' => 'يجدّد المستند ويحدّث تاريخ انتهائه.',
        'documents.manage' => 'يعدّل المستندات ويحذفها ويدير أنواعها وتنبيهاتها.',
        'documents.confidential' => 'يطّلع على كل المستندات السرية في النادي.',
        'reports.view' => 'يفتح التقارير التشغيلية دون أرقامها المالية.',
        'reports.financial' => 'يرى الإيرادات في لوحة التحكم والتقارير المالية.',
        'papers.print' => 'يطبع الفواتير والإيصالات والسندات والكشوف أو ينزّلها PDF.',
        'whatsapp.inbox' => 'يقرأ محادثات واتساب ويرد عليها.',
        'whatsapp.send' => 'يرسل رسائل واتساب للأعضاء.',
        'campaigns.manage' => 'ينشئ الحملات التسويقية ويرسلها لشرائح الأعضاء.',
        'templates.manage' => 'يعدّل قوالب الرسائل والردود الآلية.',
        'website.manage' => 'يعدّل محتوى الموقع وأقسامه وهويته وأخباره ويرد على رسائله.',
        'settings.manage' => 'يغيّر إعدادات النادي وهويته وشعاره.',
        'papers.manage' => 'يعدّل قوالب الفواتير والسندات والكشوف المطبوعة.',
        'audit.view' => 'يرى سجل التدقيق: من غيّر ماذا ومتى.',
        // Sensitive
        'payroll.salaries' => 'يرى رواتب الموظفين وبدلاتهم وأرقام حساباتهم البنكية.',
        'payroll.compensation' => 'يغيّر الراتب الأساسي والبدلات والحساب البنكي في ملف الموظف.',
        'payroll.prepare' => 'يفتح مسيّر رواتب الشهر ويحسب بنوده.',
        'payroll.edit' => 'يعدّل بنود المسيّر قبل اعتماده: الإضافي والخصومات والملاحظات.',
        'payroll.approve' => 'يعتمد المسيّر فيُقفل للتعديل ويصبح جاهزًا للصرف.',
        'payroll.pay' => 'يصرف الرواتب من حساب النقد أو البنك بقيد، ويلغي الصرف بقيد عكسي.',
        'payroll.reports' => 'يفتح تقارير الرواتب ويصدّرها، وفيها أرقام الرواتب.',
        'accounting.manage' => 'ينشئ الحسابات البنكية والقيود اليدوية ويغيّر الإعدادات المالية.',
        'accounting.close' => 'يقفل شهرًا محاسبيًا فلا يُسجَّل فيه شيء بعده.',
        'accounting.reopen' => 'يعيد فتح شهر مقفل للتسجيل فيه من جديد.',
        'accounting.all_branches' => 'يرى مالية النادي كله وهو مرتبط بفرع واحد.',
        'invoices.void' => 'يلغي فاتورة صادرة ويعكس قيدها.',
        'invoices.credit' => 'يصدر إشعارًا دائنًا يُنقص مبلغ فاتورة.',
        'payments.refund' => 'يرد مبلغًا للعضو نقدًا أو تحويلًا.',
        'payments.cancel' => 'يلغي دفعة سُجّلت بالخطأ ويعيد الفاتورة مستحقة.',
        'sales.refund' => 'يلغي عملية بيع من نقطة البيع ويرد مبلغها.',
        'inventory.adjust' => 'يسجّل الجرد والتالف والفاقد ويعدّل الكميات.',
        'inventory.approve' => 'يعتمد الجرد ونقل المخزون وطلبات تزويد الفروع فتتغير الأرصدة.',
        'purchases.manage' => 'ينشئ أوامر الشراء وسندات الموردين.',
        'purchases.approve' => 'يعتمد أوامر الشراء وسندات الاستلام وسندات الموردين.',
        'purchases.pay' => 'يسدّد مستحقات الموردين من النقد أو البنك.',
        'members.export' => 'ينزّل بيانات الأعضاء وأرقام هواتفهم في ملف.',
        'reports.export' => 'ينزّل التقارير في ملفات PDF وExcel وCSV.',
        'users.manage' => 'ينشئ حسابات الدخول ويوقفها ويغيّر كلمات مرورها.',
        'roles.manage' => 'ينشئ الأدوار ويعدّل صلاحياتها.',
        'employees.access' => 'يغيّر دور الموظف وفرعه وصلاحياته ويوقف حسابه.',
        'branches.manage' => 'ينشئ الفروع ويعدّلها ويوقفها.',
        'integrations.manage' => 'يغيّر مفاتيح Paymob وواتساب التي يستخدمها النادي.',
        'backups.manage' => 'ينزّل نسخة من بيانات النادي ويستعيد نسخة سابقة.',
    ];

    private const MODULE_GROUPS = ['sales' => 'sales', 'inventory' => 'inventory'];

    /** @return list<array{key: string, label: string, permissions: list<array{key: string, label: string, hint: ?string}>}> the editors' sections */
    public static function editorPayload(): array
    {
        return collect(self::editorGroups())->map(fn ($g, $key) => ['key' => $key, 'label' => $g['label'], 'permissions' => collect($g['permissions'])
            ->map(fn ($label, $perm) => ['key' => $perm, 'label' => $label, 'hint' => self::DESCRIPTIONS[$perm] ?? null])->values()->all()])->values()->all();
    }

    /** @return list<string> the sensitive permissions this club can be given now */
    public static function sensitive(): array
    {
        return array_values(array_intersect(self::SENSITIVE, self::all()));
    }

    public static function moduleEnabled(string $module): bool
    {
        return (bool) config("gym.modules.{$module}", false);
    }

    /**
     * The groups of the modules that are switched on. A switched-off module's permissions are not
     * granted to anyone (the owner's "*" included) and do not appear in the role editor.
     *
     * @return array<string, array{label: string, permissions: array<string, string>}>
     */
    public static function groups(): array
    {
        return array_filter(
            self::GROUPS,
            fn (string $key) => ! isset(self::MODULE_GROUPS[$key]) || self::moduleEnabled(self::MODULE_GROUPS[$key]),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @return list<string> */
    public static function all(): array
    {
        $keys = [];

        foreach (self::groups() as $group) {
            array_push($keys, ...array_keys($group['permissions']));
        }

        return $keys;
    }

    /**
     * Permissions of switched-off modules found in a stored list: kept when a role is edited, so they come
     * back if the module is switched on again.
     *
     * @param  array<int, mixed>  $stored
     * @return list<string>
     */
    public static function dormant(array $stored): array
    {
        $dormant = [];

        foreach (array_diff_key(self::GROUPS, self::groups()) as $group) {
            array_push($dormant, ...array_keys($group['permissions']));
        }

        return array_values(array_intersect($dormant, $stored));
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /**
     * Drop unknown keys so a crafted request cannot store a permission the
     * system does not define, and any permission whose prerequisite is missing (REQUIRES).
     *
     * @param  array<int, mixed>  $permissions
     * @return list<string>
     */
    public static function sanitize(array $permissions): array
    {
        $known = array_values(array_intersect(self::all(), $permissions));

        return array_values(array_filter($known, fn (string $p) => ! isset(self::REQUIRES[$p]) || in_array(self::REQUIRES[$p], $known, true)));
    }

    /**
     * The groups as the role editor shows them.
     *
     * @return array<string, array{label: string, permissions: array<string, string>}>
     */
    public static function editorGroups(): array
    {
        return self::groups();
    }
}
