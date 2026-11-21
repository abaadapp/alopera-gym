<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Messaging\Services\InvoiceMessage;
use App\Modules\Messaging\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * «إرسال الفاتورة عبر واتساب» by hand (owner's decision, 2026-11-21): the member, one of her open invoices and the
 * language chosen, the message read before it leaves — its template, the fallback said, its text with that invoice's
 * values — then sent after the same checks. For whoever may send WhatsApp messages and see invoices; an invoice of
 * another member, or out of the user's branch, is not reached.
 */
class InvoiceMessageController extends Controller
{
    public function __construct(private readonly InvoiceMessage $messages) {}

    /** The member's open invoices to choose from, and the language she prefers. */
    public function invoices(Request $request): JsonResponse
    {
        $data = $request->validate(['member_id' => ['required', 'integer', TenantRule::exists('gym_members')]]);
        $member = Member::findOrFail($data['member_id']);
        $invoices = BranchContext::scope(Invoice::query())->where('billable_type', 'member')->where('billable_id', $member->id)
            ->whereIn('status', Invoice::OPEN_STATUSES)->whereNotNull('number')->latest('issue_date')->latest('id')->limit(30)->get();
        $waiting = Membership::whereIn('invoice_id', $invoices->pluck('id'))->where('status', Membership::PENDING)->pluck('invoice_id')->all();

        return response()->json([
            'member' => ['id' => $member->id, 'label' => $member->full_name.' · '.$member->member_no, 'language' => $member->message_language ?: 'ar', 'opt_in' => (bool) $member->whatsapp_opt_in],
            'invoices' => $invoices->filter(fn (Invoice $i) => (float) $i->balance() > 0)->values()->map(fn (Invoice $i) => [
                'id' => $i->id, 'number' => $i->number, 'issue_date' => $i->issue_date->toDateString(), 'balance' => $i->balance(),
                'currency' => $i->currency, 'membership' => in_array($i->id, $waiting, true),
            ]),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        [$member, $invoice, $language] = $this->chosen($request);

        return response()->json($this->messages->preview($member, $invoice, $language));
    }

    public function send(Request $request, WhatsAppService $whatsapp): JsonResponse
    {
        [$member, $invoice, $language] = $this->chosen($request);
        if (! $whatsapp->enabled()) {
            throw ValidationException::withMessages(['invoice_id' => 'واتساب غير مفعّل. فعّله من الإعدادات ← الربط.']);
        }
        $sent = $this->messages->sendByHand($member, $invoice, $language);

        return response()->json([
            'ok' => $sent['error'] === null,
            'error' => $sent['error'],
            'fallback' => $sent['fallback'],
            'template_key' => $sent['message']?->template_key,
        ], $sent['error'] === null ? 200 : 422);
    }

    /** @return array{0: Member, 1: Invoice, 2: string} */
    private function chosen(Request $request): array
    {
        $data = $request->validate([
            'member_id' => ['required', 'integer', TenantRule::exists('gym_members')],
            'invoice_id' => ['required', 'integer', TenantRule::exists('invoices')],
            'language' => ['required', 'in:'.implode(',', InvoiceMessage::LANGUAGES)],
        ], [], ['member_id' => 'العضو', 'invoice_id' => 'الفاتورة', 'language' => 'اللغة']);
        $member = Member::findOrFail($data['member_id']);
        $invoice = BranchContext::scope(Invoice::query())->find($data['invoice_id']);
        if (! $invoice) {
            throw ValidationException::withMessages(['invoice_id' => 'الفاتورة خارج فرعك.']);
        }

        return [$member, $invoice, $data['language']];
    }
}
