<?php

namespace App\Core\Papers;

use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\BranchOwned;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Finance\Papers\CreditNotePaper;
use App\Modules\Finance\Papers\InvoicePaper;
use App\Modules\Finance\Papers\ReceiptPaper;
use App\Modules\Inventory\Papers\GoodsReceiptPaper;
use App\Modules\Inventory\Papers\PurchaseOrderPaper;
use App\Modules\Inventory\Papers\SalePaper;
use App\Modules\Inventory\Papers\SupplierBillPaper;
use App\Modules\Payroll\Papers\PayrollPayoutPaper;
use App\Modules\Payroll\Papers\PayrollSheetPaper;
use App\Modules\Payroll\Papers\PayslipPaper;
use Illuminate\Database\Eloquent\Model;

/** Builds papers: for a record (screens, print, PDF, public link) and for the template editor's live preview. */
final class Papers
{
    /** @var array<string, class-string<PaperBuilder>> */
    public const BUILDERS = [
        'sale' => SalePaper::class,
        'invoice' => InvoicePaper::class,
        'receipt' => ReceiptPaper::class,
        'credit_note' => CreditNotePaper::class,
        'purchase_order' => PurchaseOrderPaper::class,
        'goods_receipt' => GoodsReceiptPaper::class,
        'supplier_bill' => SupplierBillPaper::class,
        'payslip' => PayslipPaper::class,
        'payroll_sheet' => PayrollSheetPaper::class,
        'payroll_payout' => PayrollPayoutPaper::class,
    ];

    /** @return class-string<PaperBuilder> */
    public static function builder(string $type): string
    {
        abort_unless(isset(self::BUILDERS[$type]) && PaperTypes::exists($type), 404);

        return self::BUILDERS[$type];
    }

    /**
     * The paper of a record. $face picks the sheet or the thermal strip (for papers that have both); $size forces a
     * size (a point-of-sale device whose printer is 58 mm).
     *
     * @param  array<string, mixed>|null  $override
     */
    public static function for(string $type, Model $record, ?array $override = null, string $face = 'sheet', ?string $size = null): Paper
    {
        $tenant = app(TenantContext::class)->require();
        $builder = self::builder($type);
        $tpl = PaperTypes::settings($tenant, $type, $override);

        return new Paper($tenant, $type, $builder::document($record, $tpl, $tenant), $tpl, $builder::issuer($record, $tenant), self::size($tpl, $face, $size), $builder::filename($record));
    }

    /**
     * The editor's preview: the club's latest document of this type when the editor may see it, else a sample — drawn
     * with the settings on screen, saved or not, through the same builder and view as the real thing. Payroll papers
     * have no latest(): they always preview with the sample, so no one's salary shows on a settings screen.
     *
     * @param  array<string, mixed>|null  $override
     */
    public static function preview(string $type, User $user, ?array $override, string $face = 'sheet'): Paper
    {
        $tenant = app(TenantContext::class)->require();
        $builder = self::builder($type);
        $record = $builder::latest($user);
        if ($record && self::reachable($user, $record) && $builder::allows($user, $record)) {
            return self::for($type, $record, $override, $face);
        }
        $tpl = PaperTypes::settings($tenant, $type, $override);

        return new Paper($tenant, $type, $builder::sample($tenant, $tpl), $tpl, PaperIssuer::resolve($tenant), self::size($tpl, $face), 'sample');
    }

    /** May this user open this record at all (its branch)? */
    public static function reachable(User $user, Model $record): bool
    {
        return ! $record instanceof BranchOwned || app(BranchContext::class)->allows($user, $record->owningBranchId());
    }

    private static function size(array $tpl, string $face, ?string $size = null): string
    {
        if ($size !== null && isset(PaperSize::PRESETS[$size])) {
            return $size;
        }
        if ($face === 'strip' && isset($tpl['strip'])) {
            return $tpl['strip'];
        }

        return $tpl['paper'] ?? PaperSize::A4;
    }
}
