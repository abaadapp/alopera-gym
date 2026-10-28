<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Http\GymPresenter;
use App\Modules\Gym\Models\CheckIn;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Simplified front-desk screen (spec 1.10): search, register/renew, invoice,
 * booking, status check and WhatsApp — on one page.
 */
class ReceptionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Gym/Reception', [
            'todaySessions' => ClassSession::with(['program:id,name,color', 'trainer:id,name'])
                ->whereDate('starts_at', today())->where('status', '!=', ClassSession::CANCELLED)
                ->orderBy('starts_at')->get()->map(fn ($s) => GymPresenter::session($s)),
            'recentCheckins' => CheckIn::with('member:id,full_name,member_no')->latest('id')->limit(12)->get()
                ->map(fn (CheckIn $c) => ['id' => $c->id, 'member' => $c->member?->full_name, 'member_id' => $c->member_id, 'result' => $c->result, 'reason' => $c->reason, 'at' => $c->checked_in_at->format('H:i')]),
            'expiringToday' => Membership::with('member:id,full_name,phone')->where('status', Membership::ACTIVE)->whereDate('ends_on', '<=', today()->addDays(3))
                ->orderBy('ends_on')->limit(10)->get()->map(fn ($m) => ['member_id' => $m->member_id, 'member' => $m->member?->full_name, 'ends_on' => $m->ends_on->toDateString(), 'plan' => $m->plan_snapshot['name'] ?? '']),
            'pendingPayments' => Membership::with(['member:id,full_name', 'invoice:id,number,total,paid_total'])->where('status', Membership::PENDING)->latest('id')->limit(10)->get()
                ->map(fn ($m) => ['member_id' => $m->member_id, 'member' => $m->member?->full_name, 'invoice_id' => $m->invoice_id, 'number' => $m->invoice?->number, 'balance' => $m->invoice?->balance()]),
            'plans' => MembershipPlan::active()->orderBy('sort')->get()->map(fn ($p) => GymPresenter::plan($p)),
            'stats' => [
                'checkins' => CheckIn::where('result', 'allowed')->where('checked_in_at', '>=', today())->count(),
                'denied' => CheckIn::where('result', 'denied')->where('checked_in_at', '>=', today())->count(),
                'collected' => (float) Payment::completed()->where('kind', 'payment')->where('paid_at', '>=', today())->where('received_by', auth()->id())->sum('amount'),
            ],
        ]);
    }
}
