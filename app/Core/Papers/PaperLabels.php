<?php

namespace App\Core\Papers;

/**
 * The fixed words printed on papers, in Arabic with their English. A paper set to «Arabic and English» prints each
 * caption as two lines, Arabic first; one set to Arabic prints the Arabic line alone. Values the club or a person
 * typed (names, items, notes) are printed as they are, never translated.
 */
final class PaperLabels
{
    public const EN = [
        // titles
        'فاتورة' => 'Invoice',
        'فاتورة ضريبية' => 'Tax invoice',
        'فاتورة بيع' => 'Sales invoice',
        'فاتورة بيع ضريبية' => 'Tax sales invoice',
        'سند قبض' => 'Receipt voucher',
        'سند رد مبلغ' => 'Refund voucher',
        'إشعار دائن' => 'Credit note',
        'أمر شراء' => 'Purchase order',
        'سند استلام بضاعة' => 'Goods received note',
        'فاتورة مورد' => 'Supplier bill',
        'كشف راتب' => 'Payslip',
        'كشف مسيرة الرواتب' => 'Payroll sheet',
        'سند صرف رواتب' => 'Salary payment voucher',
        // parties
        'العميل' => 'Customer',
        'العضو' => 'Member',
        'البائع' => 'Seller',
        'الجهة المصدرة' => 'Issued by',
        'المورد' => 'Supplier',
        'الدافع' => 'Received from',
        'المستفيد' => 'Paid to',
        'الموظف' => 'Employee',
        'المنشأة' => 'Company',
        // fields
        'رقم الفاتورة' => 'Invoice No',
        'رقم السند' => 'Voucher No',
        'رقم الإشعار' => 'Note No',
        'رقم الأمر' => 'Order No',
        'رقم العملية' => 'Sale No',
        'رقم المسيرة' => 'Run No',
        'تاريخ الإصدار' => 'Issue date',
        'تاريخ الاستحقاق' => 'Due date',
        'التاريخ' => 'Date',
        'تاريخ القبض' => 'Date received',
        'تاريخ الصرف' => 'Payment date',
        'تاريخ الاستلام' => 'Date received',
        'تاريخ التسليم المتوقع' => 'Expected delivery',
        'الشهر' => 'Month',
        'الفرع' => 'Branch',
        'أصدرها' => 'Issued by',
        'الكاشير' => 'Cashier',
        'استلمه' => 'Received by',
        'وسيلة الدفع' => 'Payment method',
        'الحساب' => 'Account',
        'المرجع' => 'Reference',
        'الفاتورة الأصلية' => 'Original invoice',
        'عن الفاتورة' => 'For invoice',
        'شروط الدفع' => 'Payment terms',
        'مرجع المورد' => 'Supplier reference',
        'أمر الشراء' => 'Purchase order',
        'الحالة' => 'Status',
        'نوع الفاتورة' => 'Invoice type',
        'المسمى الوظيفي' => 'Job title',
        'طريقة الصرف' => 'Payment method',
        'البنك' => 'Bank',
        'الحساب البنكي' => 'Bank account',
        'أيام الحضور' => 'Days present',
        'ساعات العمل' => 'Hours worked',
        'عدد الموظفين' => 'Employees',
        'اعتمدها' => 'Approved by',
        'الرقم الضريبي' => 'Tax number',
        'السجل التجاري' => 'CR number',
        // figure
        'الإجمالي' => 'Total',
        'الرصيد المستحق' => 'Balance due',
        'المبلغ المستلم' => 'Amount received',
        'المبلغ المردود' => 'Amount refunded',
        'إجمالي الإشعار' => 'Credit total',
        'صافي الراتب' => 'Net salary',
        'صافي المسيرة' => 'Net payroll',
        'إجمالي الصرف' => 'Total paid',
        // columns
        'البيان' => 'Description',
        'الكمية' => 'Quantity',
        'السعر' => 'Price',
        'سعر الوحدة' => 'Unit price',
        'الخصم' => 'Discount',
        'المبلغ' => 'Amount',
        'المطلوبة' => 'Ordered',
        'المستلمة' => 'Received',
        'الأساسي' => 'Basic',
        'البدلات' => 'Allowances',
        'الإضافي' => 'Overtime',
        'الخصومات' => 'Deductions',
        'الصافي' => 'Net',
        'المستحقات' => 'Earnings',
        // totals
        'المجموع الفرعي' => 'Subtotal',
        'ضريبة القيمة المضافة' => 'VAT',
        'الشحن' => 'Shipping',
        'الضريبة' => 'Tax',
        'الإشعارات الدائنة' => 'Credit notes',
        'صافي الفاتورة' => 'Net invoice',
        'المدفوع' => 'Paid',
        'المتبقي' => 'Balance',
        'المسدد' => 'Paid',
        'إجمالي المستحقات' => 'Total earnings',
        'الباقي للعميل' => 'Change',
        // panels
        'ملاحظات' => 'Notes',
        'المدفوعات' => 'Payments',
        'تفاصيل المستحقات' => 'Earnings',
        'تفاصيل الخصومات' => 'Deductions',
        'ملاحظات الخصم' => 'Deduction notes',
        'التوقيع' => 'Signature',
        'المستلم' => 'Received by',
        'المحاسب' => 'Accountant',
        'المدير' => 'Manager',
        'توقيع الموظف' => 'Employee signature',
        'المعتمد' => 'Approved by',
        'أمين المخزن' => 'Storekeeper',
    ];

    public static function en(string $arabic): string
    {
        return self::EN[$arabic] ?? '';
    }

    /**
     * A caption as it prints: [first line, second line]. The second is empty in Arabic-only papers and when the
     * caption has no English.
     *
     * @return array{0: string, 1: string}
     */
    public static function pair(string $arabic, string $lang): array
    {
        return [$arabic, $lang === 'ar_en' ? self::en($arabic) : ''];
    }
}
