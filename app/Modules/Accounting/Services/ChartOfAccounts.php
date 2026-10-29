<?php

namespace App\Modules\Accounting\Services;

use App\Core\Tenancy\TenantContext;
use App\Modules\Accounting\Models\Account;
use App\Modules\Finance\Models\ExpenseCategory;

/**
 * Each club's chart of accounts: the accounts the system posts to by itself (system_key) are created on first
 * use, so new and existing clubs get them without a separate setup step. Users add their own accounts and banks.
 */
final class ChartOfAccounts
{
    /** [system_key => [code, name, type, normal side, parent key, is group, money kind]] */
    public const DEFAULTS = [
        'assets' => ['1', 'الأصول', 'asset', 'debit', null, true, null],
        'cash_and_banks' => ['11', 'النقدية والبنوك', 'asset', 'debit', 'assets', true, null],
        'cash' => ['1101', 'الصندوق الرئيسي', 'asset', 'debit', 'cash_and_banks', false, 'cash'],
        'bank' => ['1102', 'الحساب البنكي الرئيسي', 'asset', 'debit', 'cash_and_banks', false, 'bank'],
        'card_clearing' => ['1103', 'مدفوعات البطاقات قيد التسوية', 'asset', 'debit', 'cash_and_banks', false, 'card'],
        'paymob_clearing' => ['1104', 'مدفوعات Paymob قيد التسوية', 'asset', 'debit', 'cash_and_banks', false, 'gateway'],
        'receivables' => ['12', 'الذمم المدينة', 'asset', 'debit', 'assets', true, null],
        'receivable' => ['1201', 'ذمم الأعضاء', 'asset', 'debit', 'receivables', false, null],
        'liabilities' => ['2', 'الخصوم', 'liability', 'credit', null, true, null],
        'salaries_payable' => ['2101', 'الرواتب المستحقة', 'liability', 'credit', 'liabilities', false, null],
        'vat_payable' => ['2102', 'ضريبة القيمة المضافة المستحقة', 'liability', 'credit', 'liabilities', false, null],
        'equity' => ['3', 'حقوق الملكية', 'equity', 'credit', null, true, null],
        'owner_equity' => ['3101', 'رأس المال وجاري المالك', 'equity', 'credit', 'equity', false, null],
        'opening_equity' => ['3102', 'الأرصدة الافتتاحية', 'equity', 'credit', 'equity', false, null],
        'revenues' => ['4', 'الإيرادات', 'revenue', 'credit', null, true, null],
        'revenue_membership' => ['4101', 'إيرادات العضويات', 'revenue', 'credit', 'revenues', false, null],
        'revenue_pt' => ['4102', 'إيرادات التدريب الشخصي', 'revenue', 'credit', 'revenues', false, null],
        'revenue_other' => ['4103', 'إيرادات أخرى', 'revenue', 'credit', 'revenues', false, null],
        'sales_returns' => ['4901', 'المردودات والخصومات المسموح بها', 'revenue', 'debit', 'revenues', false, null],
        'expenses' => ['5', 'المصروفات', 'expense', 'debit', null, true, null],
        'staff_costs' => ['51', 'مصروفات الموظفين', 'expense', 'debit', 'expenses', true, null],
        'salaries_expense' => ['5101', 'الرواتب والأجور', 'expense', 'debit', 'staff_costs', false, null],
        'salary_deductions' => ['5102', 'خصومات الرواتب', 'expense', 'credit', 'staff_costs', false, null],
        'operating_expenses' => ['52', 'المصروفات التشغيلية', 'expense', 'debit', 'expenses', true, null],
        'general_expense' => ['5299', 'مصروفات عامة', 'expense', 'debit', 'operating_expenses', false, null],
    ];

    /** Created only when something posts to them (sales from before the point-of-sale module was switched off). */
    private const ON_DEMAND = [
        'revenue_pos' => ['4104', 'إيرادات المبيعات', 'revenue', 'credit', 'revenues', false, null],
    ];

    /** Where money lands or leaves by payment method, unless the club chose otherwise (finance settings). */
    public const RECEIPT_DEFAULTS = ['cash' => 'cash', 'card' => 'card_clearing', 'bank_transfer' => 'bank', 'paymob' => 'paymob_clearing', 'other' => 'cash'];

    public const SPENDING_DEFAULTS = ['cash' => 'cash', 'card' => 'bank', 'bank_transfer' => 'bank', 'other' => 'cash'];

    /** @var array<int, array<string, Account>> per club */
    private array $cache = [];

    public function __construct(private readonly TenantContext $tenants) {}

    /** Make sure every default account exists (safe to call any number of times). */
    public function ensure(): void
    {
        foreach (array_keys(self::DEFAULTS) as $key) {
            $this->account($key);
        }
    }

    public function account(string $key): Account
    {
        $tenantId = $this->tenants->require()->id;
        if (isset($this->cache[$tenantId][$key])) {
            return $this->cache[$tenantId][$key];
        }

        $definition = self::DEFAULTS[$key] ?? self::ON_DEMAND[$key] ?? throw new \InvalidArgumentException("Unknown system account [{$key}].");
        [$code, $name, $type, $side, $parentKey, $isGroup, $kind] = $definition;

        $account = Account::firstWhere('system_key', $key) ?? Account::create([
            'system_key' => $key,
            'parent_id' => $parentKey ? $this->account($parentKey)->id : null,
            'code' => $this->freeCode($code),
            'name' => $name,
            'type' => $type,
            'normal_side' => $side,
            'is_group' => $isGroup,
            'kind' => $kind,
            'is_active' => true,
        ]);

        return $this->cache[$tenantId][$key] = $account;
    }

    /** The expense account a category posts to: its own account under operating expenses, made on first use. */
    public function forCategory(?ExpenseCategory $category): Account
    {
        if (! $category) {
            return $this->account('general_expense');
        }

        if ($account = Account::firstWhere('expense_category_id', $category->id)) {
            return $account;
        }

        $parent = $this->account('operating_expenses');
        $used = Account::where('parent_id', $parent->id)->where('code', '<', '5299')->pluck('code')->map(fn ($c) => (int) $c)->max();

        return Account::create([
            'parent_id' => $parent->id,
            'code' => $this->freeCode((string) max(5201, ($used ?? 5200) + 1)),
            'name' => $category->name,
            'type' => 'expense',
            'normal_side' => 'debit',
            'expense_category_id' => $category->id,
            'is_group' => false,
            'is_active' => true,
        ]);
    }

    /** Revenue account of an invoice type. */
    public function forInvoiceType(string $type): Account
    {
        return $this->account(match ($type) {
            'membership' => 'revenue_membership',
            'pt' => 'revenue_pt',
            'pos' => 'revenue_pos',
            default => 'revenue_other',
        });
    }

    /**
     * The money account a payment method uses: receipts (members paying) or spending (expenses). The club's
     * finance settings may point a method at another active account of a suitable kind.
     */
    public function moneyFor(string $method, string $purpose = 'receipt'): Account
    {
        $defaults = $purpose === 'receipt' ? self::RECEIPT_DEFAULTS : self::SPENDING_DEFAULTS;
        $chosen = $this->tenants->require()->settings['finance'][$purpose][$method] ?? null;

        if ($chosen && ($account = Account::money()->postable()->find($chosen))) {
            if ($purpose === 'receipt' || in_array($account->kind, Account::PAYING_KINDS, true)) {
                return $account;
            }
        }

        return $this->account($defaults[$method] ?? 'cash');
    }

    /** The next free code at or after the one wanted (a club may already have used it for its own account). */
    private function freeCode(string $code): string
    {
        $candidate = $code;
        while (Account::where('code', $candidate)->exists()) {
            $candidate = (string) ((int) $candidate + 1);
        }

        return $candidate;
    }
}
