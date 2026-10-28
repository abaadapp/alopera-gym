<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Export\TableExporter;
use App\Core\Support\ImageStore;
use App\Core\Support\Money;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Http\GymPresenter;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\CheckIn;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Gym\Services\MemberService;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MemberController extends Controller
{
    public function __construct(private readonly MemberService $members)
    {
    }

    public function index(Request $request): Response
    {
        $status = $request->string('membership')->toString();

        $members = Member::with('currentMembership')
            ->search($request->string('q')->toString())
            ->when($status === 'active', fn ($q) => $q->whereHas('memberships', fn ($m) => $m->where('status', Membership::ACTIVE)))
            ->when($status === 'frozen', fn ($q) => $q->whereHas('memberships', fn ($m) => $m->where('status', Membership::FROZEN)))
            ->when($status === 'expiring', fn ($q) => $q->whereHas('memberships', fn ($m) => $m->expiringWithin(7)))
            ->when($status === 'expired', fn ($q) => $q->whereDoesntHave('memberships', fn ($m) => $m->whereIn('status', [Membership::ACTIVE, Membership::FROZEN, Membership::SCHEDULED, Membership::PENDING])))
            ->when($status === 'pending', fn ($q) => $q->whereHas('memberships', fn ($m) => $m->where('status', Membership::PENDING)))
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->latest('id')
            ->paginate(25)->withQueryString()
            ->through(fn (Member $m) => GymPresenter::memberRow($m));

        return Inertia::render('Gym/Members/Index', ['members' => $members, 'filters' => $request->only('q', 'membership', 'status')]);
    }

    /** Quick lookup for reception, POS and pickers. */
    public function search(Request $request): JsonResponse
    {
        $results = Member::with('currentMembership')->search($request->string('q')->toString())->limit(10)->get()
            ->map(fn (Member $m) => GymPresenter::memberRow($m));

        return response()->json($results);
    }

    public function create(): Response
    {
        return Inertia::render('Gym/Members/Form', ['member' => null, 'branches' => Branch::where('is_active', true)->get(['id', 'name'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $photo = $request->file('photo');
        unset($data['photo']);

        $member = $this->members->create($data);
        if ($photo) {
            $member->forceFill(['photo_path' => ImageStore::store($photo, 'members', 600)])->save();
        }

        return redirect()->route('club.members.show', $member)->with('success', 'تم تسجيل المشتركة '.$member->member_no.'.');
    }

    public function show(Request $request, Member $member, OnlinePaymentService $online): Response
    {
        $user = $request->user();
        $member->load(['branch:id,name']);

        $memberships = Membership::where('member_id', $member->id)->latest('id')->get();
        $invoices = $user->can('invoices.view')
            ? Invoice::where('billable_type', 'member')->where('billable_id', $member->id)->latest('id')->limit(50)->get()
                ->map(fn (Invoice $i) => $i->only('id', 'number', 'type', 'status', 'total', 'paid_total', 'issue_date') + ['balance' => $i->balance(), 'issue_date' => $i->issue_date->toDateString()])
            : [];
        $payments = $user->can('invoices.view')
            ? Payment::where('payer_type', 'member')->where('payer_id', $member->id)->latest('id')->limit(50)->get()
                ->map(fn (Payment $p) => $p->only('id', 'receipt_number', 'kind', 'method', 'status', 'amount', 'reference') + ['paid_at' => $p->paid_at?->format('Y-m-d H:i'), 'invoice_id' => $p->invoice_id])
            : [];

        return Inertia::render('Gym/Members/Show', [
            'member' => GymPresenter::memberRow($member) + $member->only(
                'gender', 'national_id', 'nationality', 'address', 'emergency_contact_name', 'emergency_contact_phone',
                'goals', 'source', 'notes', 'whatsapp_opt_in', 'marketing_opt_in', 'phone_alt', 'branch_id'
            ) + [
                'date_of_birth' => $member->date_of_birth?->toDateString(),
                'health_notes' => $member->health_notes,
                'branch' => $member->branch?->name,
                'has_portal_access' => $member->password !== null,
                'qr_token' => $user->can('members.manage') ? $member->qr_token : null,
                'balance' => Money::normalize(Invoice::where('billable_type', 'member')->where('billable_id', $member->id)->open()->get()->sum(fn ($i) => (float) $i->balance())),
            ],
            'memberships' => $memberships->map(fn ($m) => GymPresenter::membership($m)),
            'events' => \App\Modules\Gym\Models\MembershipEvent::whereIn('membership_id', $memberships->pluck('id'))->with('user:id,name')->latest('id')->limit(30)->get()
                ->map(fn ($e) => ['type' => $e->type, 'data' => $e->data, 'user' => $e->user?->name, 'at' => $e->created_at->format('Y-m-d H:i'), 'membership_id' => $e->membership_id]),
            'invoices' => $invoices,
            'payments' => $payments,
            'bookings' => Booking::where('member_id', $member->id)->with('session.program:id,name')->latest('id')->limit(30)->get()
                ->map(fn (Booking $b) => ['id' => $b->id, 'status' => $b->status, 'source' => $b->source, 'program' => $b->session?->program?->name, 'starts_at' => $b->session?->starts_at->format('Y-m-d H:i'), 'session_id' => $b->class_session_id]),
            'checkins' => CheckIn::where('member_id', $member->id)->latest('id')->limit(30)->get()
                ->map(fn (CheckIn $c) => $c->only('id', 'method', 'result', 'reason') + ['at' => $c->checked_in_at->format('Y-m-d H:i')]),
            'documents' => $member->documents()->with('uploader:id,name')->latest()->get()
                ->map(fn ($d) => $d->only('id', 'type', 'title', 'original_name', 'size') + ['uploaded_by' => $d->uploader?->name, 'created_at' => $d->created_at->toDateString(), 'expires_on' => $d->expires_on?->toDateString()]),
            'notes' => $member->notesLog()->with('user:id,name')->latest()->limit(50)->get()
                ->map(fn ($n) => $n->only('id', 'channel', 'direction', 'body') + ['user' => $n->user?->name, 'at' => $n->created_at->format('Y-m-d H:i'), 'follow_up_on' => $n->follow_up_on?->toDateString()]),
            'messages' => $user->can('whatsapp.inbox') ? Message::where('member_id', $member->id)->latest('id')->limit(50)->get()
                ->map(fn ($m) => $m->only('id', 'direction', 'type', 'template_key', 'body', 'status', 'error_code') + ['at' => $m->created_at->format('Y-m-d H:i')]) : [],
            'credentials' => \App\Modules\Gym\Models\AccessCredential::where('holder_type', 'member')->where('holder_id', $member->id)->get(['id', 'type', 'identifier', 'label', 'is_active']),
            'pt' => $member->ptSubscriptions()->with(['package:id,name', 'trainer:id,name'])->latest()->get()
                ->map(fn ($s) => $s->only('id', 'status', 'sessions_total', 'sessions_used', 'price') + ['package' => $s->package?->name, 'trainer' => $s->trainer?->name, 'expires_on' => $s->expires_on?->toDateString()]),
            'plans' => MembershipPlan::active()->with('programs:id')->orderBy('sort')->get()->map(fn ($p) => GymPresenter::plan($p)),
            'ptPackages' => $user->can('pt.manage') ? PtPackage::where('is_active', true)->get(['id', 'name', 'sessions_count', 'price']) : [],
            'trainers' => Trainer::where('is_active', true)->get(['id', 'name']),
            'templates' => $user->can('whatsapp.send') ? MessageTemplate::where('status', 'approved')->where('is_active', true)->get(['key', 'name', 'body']) : [],
            'onlinePayments' => $online->available(),
        ]);
    }

    public function edit(Member $member): Response
    {
        return Inertia::render('Gym/Members/Form', [
            'member' => $member->only('id', 'full_name', 'phone', 'phone_alt', 'email', 'gender', 'national_id', 'nationality', 'address',
                'emergency_contact_name', 'emergency_contact_phone', 'goals', 'source', 'status', 'notes', 'whatsapp_opt_in', 'marketing_opt_in', 'branch_id') + [
                    'date_of_birth' => $member->date_of_birth?->toDateString(),
                    'health_notes' => $member->health_notes,
                    'photo_url' => $member->photoUrl(),
                ],
            'branches' => Branch::where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Member $member): RedirectResponse
    {
        $data = $this->validated($request, $member);
        if ($request->hasFile('photo')) {
            ImageStore::delete($member->photo_path);
            $member->photo_path = ImageStore::store($request->file('photo'), 'members', 600);
        }
        unset($data['photo']);
        $this->members->update($member, $data);

        return redirect()->route('club.members.show', $member)->with('success', 'تم حفظ بيانات المشتركة.');
    }

    public function destroy(Member $member): RedirectResponse
    {
        if ($member->memberships()->whereIn('status', [Membership::ACTIVE, Membership::FROZEN, Membership::SCHEDULED])->exists()) {
            return back()->with('error', 'لا يمكن حذف مشتركة لديها عضوية سارية. ألغي العضوية أولًا.');
        }
        $member->delete();

        return redirect()->route('club.members.index')->with('success', 'تم حذف المشتركة.');
    }

    public function portalPassword(Request $request, Member $member, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', Password::min(8)]]);
        $member->forceFill(['password' => $data['password']])->save();
        $audit->log('member.portal_password_set', $member);

        return back()->with('success', 'تم تعيين كلمة مرور بوابة المشتركة.');
    }

    public function rotateQr(Member $member, AuditLogger $audit): RedirectResponse
    {
        $member->rotateQrToken();
        $audit->log('member.qr_rotated', $member);

        return back()->with('success', 'تم إصدار رمز QR جديد؛ الرمز القديم لم يعد صالحًا.');
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = function () {
            foreach (Member::with('currentMembership')->orderBy('id')->lazy(500) as $m) {
                $ms = $m->currentMembership;
                yield [$m->member_no, $m->full_name, $m->phone, $m->email, $m->status, $m->joined_on?->toDateString(),
                    $ms?->plan_snapshot['name'] ?? '', $ms?->status ?? '', $ms?->ends_on?->toDateString() ?? '',
                    $m->marketing_opt_in ? 'نعم' : 'لا', $m->last_check_in_at?->format('Y-m-d H:i')];
            }
        };

        return TableExporter::download('members', ['رقم العضوية', 'الاسم', 'الهاتف', 'البريد', 'الحالة', 'تاريخ الانضمام', 'الباقة الحالية', 'حالة العضوية', 'تنتهي في', 'موافقة تسويق', 'آخر دخول'], $rows(), $request->string('format')->toString() === 'xlsx' ? 'xlsx' : 'csv');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Member $member = null): array
    {
        return $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^[+0-9٠-٩\s-]{8,20}$/u'],
            'phone_alt' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255', TenantRule::unique('gym_members', 'email')->ignore($member?->id)],
            'gender' => ['nullable', 'in:female,male'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'national_id' => ['nullable', 'string', 'max:40'],
            'nationality' => ['nullable', 'string', 'size:2'],
            'address' => ['nullable', 'string', 'max:255'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'],
            'health_notes' => ['nullable', 'string', 'max:2000'],
            'goals' => ['nullable', 'string', 'max:1000'],
            'source' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', 'in:active,inactive,blocked'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'whatsapp_opt_in' => ['boolean'],
            'marketing_opt_in' => ['boolean'],
            'branch_id' => ['nullable', TenantRule::exists('branches')],
            'photo' => ['nullable', 'image', 'max:'.config('gym.max_image_kb')],
        ]);
    }
}
