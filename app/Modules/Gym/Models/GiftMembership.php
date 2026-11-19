<?php

namespace App\Modules\Gym\Models;

use App\Core\Audit\Auditable;
use App\Core\Support\Money;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Core\Tenancy\OwnedByBranch;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Messaging\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A plan bought for someone else (#192). Lifecycle:
 *
 *   pending ──invoice paid (InvoicePaid)──▶ paid ──redeemed once──▶ redeemed
 *      │                                     │ │
 *      │                                     │ └── window passed (gym:expire-gifts) ──▶ expired
 *      └── invoice voided ──▶ cancelled ◀────┤
 *                                            └── credit note on its invoice ──▶ refunded
 *
 * `refunded` says the gift was withdrawn by a credit note, not that the money is back with the buyer: issuing the note
 * and paying the money back are separate steps (CreditNoteService, PaymentService::refund), and a refund by bank
 * transfer or Paymob waits for confirmation. refundState() says where the money is.
 *
 * A paid gift past expires_on is treated as expired at once (isExpired), before the nightly job stores it.
 * Only a fully paid invoice moves a gift out of `pending`; a bank transfer awaiting confirmation does not.
 */
class GiftMembership extends Model implements BranchOwned
{
    use Auditable, BelongsToTenant, OwnedByBranch;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const REDEEMED = 'redeemed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    public const REFUNDED = 'refunded';

    public const STATUSES = [self::PENDING, self::PAID, self::REDEEMED, self::EXPIRED, self::CANCELLED, self::REFUNDED];

    public const STATUS_LABELS = [
        self::PENDING => 'بانتظار الدفع', self::PAID => 'مدفوعة — لم تُستخدم', self::REDEEMED => 'مستخدمة',
        self::EXPIRED => 'منتهية الصلاحية', self::CANCELLED => 'ملغاة', self::REFUNDED => 'أُلغيت بإشعار دائن',
    ];

    public const STATUS_LABELS_EN = [
        self::PENDING => 'Awaiting payment', self::PAID => 'Paid — not used', self::REDEEMED => 'Used',
        self::EXPIRED => 'Expired', self::CANCELLED => 'Cancelled', self::REFUNDED => 'Withdrawn by credit note',
    ];

    public const DEFAULT_REDEEM_DAYS = 90;

    /** The languages the gift's WhatsApp message goes in, as the giver picks it (Meta's codes: ar, en). */
    public const LANGUAGES = ['ar' => 'العربية', 'en' => 'English'];

    /** What the recipient reads in place of a giver who asked not to be named (is_anonymous). */
    public const ANONYMOUS_GIVER = ['ar' => 'شخص يفضّل عدم ذكر اسمه', 'en' => 'Someone who prefers to remain anonymous'];

    protected $table = 'gym_gift_memberships';

    protected $guarded = ['id', 'tenant_id'];

    /**
     * Where the gift's WhatsApp message is (GiftWhatsApp::delivery), as the panel shows it. `sent` is WhatsApp having
     * accepted it, not the recipient having it: only a `delivered` (or `read`) status from the webhook says that.
     */
    public const DELIVERY_LABELS = [
        'not_due' => 'تُرسل بعد اكتمال الدفع',
        'waiting' => 'بانتظار الإرسال',
        'sending' => 'قيد الإرسال',
        'sent' => 'أُرسلت إلى المزود',
        'delivered' => 'تم التسليم',
        'read' => 'تمت القراءة',
        'failed' => 'فشل الإرسال', 'uncertain' => 'نتيجة الإرسال غير مؤكدة',
        'closed' => 'لا إرسال',
    ];

    /** Never in an array or JSON: the link is shown only through linkToken(), to staff allowed to share it; the
     *  number's confirmation code only to whoever may share it (GiftWhatsApp::verification). */
    protected $hidden = ['token', 'token_hash', 'phone_verify_code', 'phone_verify_hash'];

    protected function casts(): array
    {
        return [
            'plan_snapshot' => 'array',
            'price' => 'decimal:3',
            'token' => 'encrypted',
            'paid_at' => 'datetime',
            'expires_on' => 'immutable_date',
            'redeemed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expired_at' => 'datetime',
            'refunded_at' => 'datetime',
            'phone_verify_code' => 'encrypted',
            'phone_verify_expires_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'is_anonymous' => 'boolean',
        ];
    }

    /** The language the gift's WhatsApp message goes in: the giver's choice, Arabic for gifts from before it. */
    public function messageLanguage(): string
    {
        return array_key_exists((string) $this->message_language, self::LANGUAGES) ? (string) $this->message_language : 'ar';
    }

    /**
     * The giver's name as the recipient sees it (the WhatsApp message, the gift's card, its share text): the real name,
     * or the anonymous wording in that language. The real name itself stays in giver_name for staff and the invoice.
     */
    public function publicGiverName(string $language): string
    {
        return $this->is_anonymous ? self::ANONYMOUS_GIVER[$language === 'en' ? 'en' : 'ar'] : (string) $this->giver_name;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The gift a link opens, or null; compared by hash, so the token itself is never queried. */
    public static function findByToken(string $token): ?self
    {
        if (strlen($token) < 32 || strlen($token) > 128) {
            return null;
        }

        return self::where('token_hash', self::hashToken($token))->first();
    }

    public function linkToken(): string
    {
        return $this->token;
    }

    public function isExpired(?CarbonImmutable $today = null): bool
    {
        $today ??= CarbonImmutable::today();

        return $this->status === self::EXPIRED
            || ($this->status === self::PAID && $this->expires_on !== null && $this->expires_on->lt($today));
    }

    public function isRedeemable(?CarbonImmutable $today = null): bool
    {
        return $this->status === self::PAID && ! $this->isExpired($today);
    }

    public function displayStatus(?CarbonImmutable $today = null): string
    {
        return $this->isExpired($today) ? self::EXPIRED : $this->status;
    }

    /** The money-back states of a gift withdrawn by a credit note (refundState). */
    public const REFUND_STATE_LABELS = [
        'due' => 'المبلغ لم يُرد بعد', 'pending' => 'الرد بانتظار التأكيد', 'done' => 'رُدّ المبلغ', 'none' => 'لا مبلغ مدفوع ليُرد',
    ];

    /**
     * Where the money of a gift withdrawn by a credit note is: `due` (nothing recorded as paid back yet: finance still
     * owes the buyer), `pending` (a refund by bank transfer or Paymob awaits confirmation), `done` (paid back), or
     * `none` (nothing was paid). Null for a gift that is not `refunded`. Read from the note's refund payments, so it
     * never says the money went back before finance recorded (and, where it waits, confirmed) it.
     *
     * @return array{state: string, label: string, due: string, pending: string, refunded: string, credit_note: string|null}|null
     */
    public function refundState(): ?array
    {
        if ($this->status !== self::REFUNDED) {
            return null;
        }
        $note = $this->refund_note_id ? CreditNote::find($this->refund_note_id) : null;
        $invoice = $this->invoice_id ? Invoice::find($this->invoice_id) : null;
        $pending = $note ? Money::normalize((string) Payment::where('credit_note_id', $note->id)->where('kind', Payment::KIND_REFUND)->where('status', Payment::PENDING)->sum('amount')) : '0.000';
        $left = $note && $invoice ? Money::min($note->refundable(), $invoice->refundDue()) : '0.000';
        $due = Money::max('0', Money::sub($left, $pending));
        $refunded = $note ? Money::normalize($note->refunded_total) : '0.000';
        $state = match (true) {
            Money::cmp($due, 0) > 0 => 'due',
            Money::cmp($pending, 0) > 0 => 'pending',
            Money::cmp($refunded, 0) > 0 => 'done',
            default => 'none',
        };

        return ['state' => $state, 'label' => self::REFUND_STATE_LABELS[$state], 'due' => $due, 'pending' => $pending, 'refunded' => $refunded, 'credit_note' => $note?->number];
    }

    /** The plan's length as it was bought, for working out the redeemed period's end date. */
    public function durationPlan(): MembershipPlan
    {
        return (new MembershipPlan)->forceFill([
            'duration_value' => $this->plan_snapshot['duration_value'],
            'duration_unit' => $this->plan_snapshot['duration_unit'],
        ]);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /** The recipient's number was confirmed by a message she sent from it (not by a send WhatsApp accepted). */
    public function phoneVerified(): bool
    {
        return $this->phone_verified_at !== null;
    }

    public function whatsappMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'whatsapp_message_id');
    }
}
