<?php

namespace App\Http\Controllers\Club;

use App\Core\Auth\PermissionRegistry;
use App\Core\Support\Money;
use App\Core\Tenancy\BranchContext;
use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\BusinessDocument;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\Member;
use App\Modules\Inventory\Http\Controllers\SalesController;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Payroll\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The top bar's quick search: members, products, sales and website orders, invoices, payments, employees and
 * business documents — each group only for whoever may open it, narrowed to the current branch, at most five per
 * group. Arabic text matches whatever form of alef, yaa or taa marbuta was typed, and Arabic-Indic digits are read
 * as digits. Employees show their job title, never their pay; confidential documents only to who may see them. The
 * panel's pages are searched in the browser.
 */
class SearchController extends Controller
{
    private const LIMIT = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $term = self::normalize((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json(['groups' => []]);
        }

        $user = $request->user();
        $like = '%'.addcslashes(self::fold($term), '%_\\').'%';
        $digits = preg_replace('/\D/', '', $term);
        $phone = strlen($digits) >= 3 ? '%'.$digits.'%' : null;
        $groups = [];

        if ($user->can('members.view')) {
            $groups[] = ['key' => 'members', 'label' => 'الأعضاء', 'items' => BranchContext::scope(Member::query())
                ->where(fn ($q) => $q->whereRaw(self::folded('full_name').' ilike ?', [$like])->orWhere('member_no', 'ilike', $like)->orWhere('email', 'ilike', $like)
                    ->when($phone, fn ($w) => $w->orWhere('phone', 'like', $phone)))
                ->orderBy('full_name')->limit(self::LIMIT)->get(['id', 'full_name', 'member_no', 'phone'])
                ->map(fn (Member $m) => ['label' => $m->full_name, 'hint' => trim($m->member_no.' · '.$m->phone, ' ·'), 'url' => route('club.members.show', $m)])];
        }

        if (PermissionRegistry::moduleEnabled('sales') && $user->can('products.manage')) {
            $groups[] = ['key' => 'products', 'label' => 'المنتجات', 'items' => Product::query()
                ->where(fn ($q) => $q->whereRaw(self::folded('name').' ilike ?', [$like])->orWhere('sku', 'ilike', $like)->orWhere('barcode', 'ilike', $like))
                ->orderBy('name')->limit(self::LIMIT)->get(['id', 'name', 'sku', 'price', 'stock_quantity', 'track_stock'])
                ->map(fn (Product $p) => [
                    'label' => $p->name,
                    'hint' => trim(Money::normalize($p->price).($p->track_stock ? ' · المخزون '.(float) $p->stock_quantity : '').($p->sku ? ' · '.$p->sku : ''), ' ·'),
                    'url' => route('club.products.index', ['q' => $p->name]),
                ])];
        }

        $seesSales = PermissionRegistry::moduleEnabled('sales') && $user->can('sales.view');
        $seesOrders = PermissionRegistry::moduleEnabled('sales') && $user->can('orders.manage');
        if ($seesSales || $seesOrders) {
            $groups[] = ['key' => 'sales', 'label' => 'المبيعات وطلبات الموقع', 'items' => SalesController::scopedFor($user)
                ->when(! $seesSales, fn ($q) => $q->where('source', Sale::WEBSITE))
                ->where(fn ($q) => $q->where('number', 'ilike', $like)->orWhereRaw(self::folded('customer_name').' ilike ?', [$like])
                    ->when($phone, fn ($w) => $w->orWhere('customer_phone', 'like', $phone)))
                ->latest('id')->limit(self::LIMIT)->get(['id', 'number', 'source', 'customer_name', 'total'])
                ->map(fn (Sale $s) => [
                    'label' => $s->number.($s->customer_name ? ' — '.$s->customer_name : ''),
                    'hint' => Money::normalize($s->total),
                    'url' => $s->source === Sale::WEBSITE && $seesOrders ? route('club.sales.orders.index', ['tab' => 'all', 'q' => $s->number]) : route('club.sales.receipt', $s),
                ])];
        }

        if ($user->can('invoices.view')) {
            $groups[] = ['key' => 'invoices', 'label' => 'الفواتير', 'items' => BranchContext::scope(Invoice::query())
                ->where(fn ($q) => $q->where('number', 'ilike', $like)->orWhereRaw(self::folded('customer_name').' ilike ?', [$like])
                    ->when($phone, fn ($w) => $w->orWhere('customer_phone', 'like', $phone)))
                ->latest('id')->limit(self::LIMIT)->get(['id', 'number', 'customer_name', 'total', 'currency'])
                ->map(fn (Invoice $i) => ['label' => $i->number.' — '.$i->customer_name, 'hint' => Money::normalize($i->total).' '.$i->currency, 'url' => route('club.invoices.show', $i)])];
        }

        if ($user->can('invoices.view')) {
            $groups[] = ['key' => 'payments', 'label' => 'المدفوعات', 'items' => BranchContext::scope(Payment::query())
                ->where(fn ($q) => $q->where('receipt_number', 'ilike', $like)->orWhere('reference', 'ilike', $like)
                    ->orWhereHas('invoice', fn ($i) => $i->where('number', 'ilike', $like)->orWhereRaw(self::folded('customer_name').' ilike ?', [$like])))
                ->with('invoice:id,number,customer_name')->latest('id')->limit(self::LIMIT)->get(['id', 'invoice_id', 'receipt_number', 'kind', 'amount', 'currency', 'status'])
                ->map(fn (Payment $p) => [
                    'label' => trim(($p->receipt_number ?? ($p->kind === Payment::KIND_REFUND ? 'رد مبلغ' : 'دفعة')).' — '.($p->invoice?->customer_name ?? ''), ' —'),
                    'hint' => ($p->kind === Payment::KIND_REFUND ? '− ' : '').Money::normalize($p->amount).' '.$p->currency.($p->invoice ? ' · '.$p->invoice->number : ''),
                    'url' => $p->invoice_id ? route('club.invoices.show', $p->invoice_id) : route('club.payments.index'),
                ])];
        }

        if ($user->can('employees.view')) {
            $groups[] = ['key' => 'employees', 'label' => 'الموظفون', 'items' => BranchContext::scope(Employee::query())
                ->where(fn ($q) => $q->whereRaw(self::folded('name').' ilike ?', [$like])->orWhere('email', 'ilike', $like)
                    ->when($phone, fn ($w) => $w->orWhere('phone', 'like', $phone)))
                ->with('jobTitle:id,name')->orderBy('name')->limit(self::LIMIT)->get(['id', 'name', 'phone', 'job_title_id'])
                // Name, job title and phone only: pay and bank details stay on the employee's own screen.
                ->map(fn (Employee $e) => ['label' => $e->name, 'hint' => trim(($e->jobTitle?->name ?? '').' · '.($e->phone ?? ''), ' ·'), 'url' => route('club.employees.index', ['q' => $e->name])])];
        }

        if ($user->can('documents.view')) {
            $groups[] = ['key' => 'documents', 'label' => 'المستندات', 'items' => BranchContext::scope(BusinessDocument::visibleTo($user))
                ->where(fn ($q) => $q->whereRaw(self::folded('name').' ilike ?', [$like])->orWhere('reference_number', 'ilike', $like)->orWhereRaw(self::folded('coalesce(issuer, \'\')').' ilike ?', [$like]))
                ->orderBy('name')->limit(self::LIMIT)->get(['id', 'name', 'reference_number', 'expiry_date'])
                ->map(fn (BusinessDocument $d) => ['label' => $d->name, 'hint' => trim(($d->reference_number ?? '').' · '.($d->expiry_date?->toDateString() ?? ''), ' ·'), 'url' => route('club.business-documents.index', ['view' => 'all', 'q' => $d->name])])];
        }

        return response()->json(['groups' => array_values(array_filter($groups, fn ($g) => $g['items']->isNotEmpty()))]);
    }

    /** Arabic-Indic and Persian digits as ASCII, spaces collapsed. */
    public static function normalize(string $term): string
    {
        $term = strtr($term, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);

        return trim(preg_replace('/\s+/u', ' ', $term));
    }

    /** One spelling for the letters people type interchangeably: أ إ آ → ا, ى → ي, ة → ه. */
    public static function fold(string $text): string
    {
        return strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
    }

    /** The same folding in SQL, for a column. */
    private static function folded(string $column): string
    {
        return "translate({$column}, 'أإآىة', 'ااايه')";
    }
}
