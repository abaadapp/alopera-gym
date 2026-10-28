<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Models\PtSession;
use App\Modules\Gym\Models\PtSubscription;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Gym\Services\PtService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Personal training: subscriptions and their sessions. */
class PtController extends Controller
{
    public function __construct(private readonly PtService $pt) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Gym/Pt/Index', [
            'subscriptions' => PtSubscription::with(['member:id,full_name,member_no', 'package:id,name,session_minutes', 'trainer:id,name'])
                ->when($request->string('status')->toString() ?: 'active', fn ($q, $s) => $s === 'all' ? $q : $q->where('status', $s))
                ->latest()->paginate(25)->withQueryString()
                ->through(fn (PtSubscription $s) => $s->only('id', 'status', 'sessions_total', 'sessions_used', 'price', 'trainer_id', 'invoice_id') + [
                    'member' => $s->member?->only('id', 'full_name', 'member_no'), 'package' => $s->package?->name, 'trainer' => $s->trainer?->name,
                    'expires_on' => $s->expires_on?->toDateString(), 'remaining' => $s->sessionsRemaining(),
                ]),
            'upcoming' => PtSession::with(['member:id,full_name', 'trainer:id,name'])->where('status', PtSession::SCHEDULED)
                ->where('starts_at', '>=', now()->startOfDay())->orderBy('starts_at')->limit(50)->get()
                ->map(fn (PtSession $s) => ['id' => $s->id, 'member' => $s->member?->full_name, 'trainer' => $s->trainer?->name, 'starts_at' => $s->starts_at->format('Y-m-d H:i'), 'ends_at' => $s->ends_at->format('H:i'), 'status' => $s->status, 'started' => $s->starts_at->lte(now())]),
            'trainers' => Trainer::where('is_active', true)->get(['id', 'name']),
            'filters' => $request->only('status'),
        ]);
    }

    public function store(Request $request, Member $member): RedirectResponse
    {
        $data = $request->validate([
            'pt_package_id' => ['required', TenantRule::exists('gym_pt_packages')],
            'trainer_id' => ['nullable', TenantRule::exists('gym_trainers')],
        ]);
        $sub = $this->pt->sell($member, PtPackage::findOrFail($data['pt_package_id']), isset($data['trainer_id']) ? Trainer::find($data['trainer_id']) : null);

        return redirect()->route('club.invoices.show', $sub->invoice_id)->with('success', 'تم إصدار فاتورة التدريب الشخصي. تُفعّل الباقة بعد الدفع.');
    }

    public function schedule(Request $request, PtSubscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'trainer_id' => ['nullable', TenantRule::exists('gym_trainers')],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $this->pt->schedule($subscription, CarbonImmutable::parse($data['starts_at']), $data['trainer_id'] ?? null, $data['notes'] ?? null);

        return back()->with('success', 'تمت جدولة الجلسة.');
    }

    public function close(Request $request, PtSession $ptSession): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:completed,no_show,cancelled'],
            'notes' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:255'], // why it is closed before its start time
        ]);

        $user = $request->user();
        if (! $user->can('pt.manage')) {
            abort_unless($user->trainer && $ptSession->trainer_id === $user->trainer->id, 403);
        }

        $this->pt->close($ptSession, $data['status'], $data['notes'] ?? null, $data['reason'] ?? null);

        return back()->with('success', 'تم تحديث الجلسة.');
    }
}
