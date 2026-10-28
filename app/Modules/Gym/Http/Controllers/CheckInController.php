<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Gym\Access\AccessControlService;
use App\Modules\Gym\Access\AccessDecision;
use App\Modules\Gym\Models\CheckIn;
use App\Modules\Gym\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CheckInController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Gym/CheckIns/Index', [
            'checkins' => CheckIn::with(['member:id,full_name,member_no', 'device:id,name', 'performer:id,name'])
                ->when($request->string('result')->toString(), fn ($q, $r) => $q->where('result', $r))
                ->when($request->date('date'), fn ($q, $d) => $q->whereDate('checked_in_at', $d))
                ->latest('id')->paginate(50)->withQueryString()
                ->through(fn (CheckIn $c) => $c->only('id', 'method', 'direction', 'result', 'reason', 'credential_hint') + [
                    'reason_label' => AccessDecision::REASONS[$c->reason] ?? $c->reason,
                    'member' => $c->member?->only('id', 'full_name', 'member_no'),
                    'device' => $c->device?->name, 'by' => $c->performer?->name,
                    'at' => $c->checked_in_at->format('Y-m-d H:i:s'),
                ]),
            'filters' => $request->only('result', 'date'),
        ]);
    }

    /** Reception check-in by member id (search), or by scanning a member's QR code. */
    public function store(Request $request, AccessControlService $access): JsonResponse
    {
        $data = $request->validate([
            'member_id' => ['nullable', 'required_without:qr', TenantRule::exists('gym_members')],
            'qr' => ['nullable', 'required_without:member_id', 'string', 'max:128'],
        ]);

        if (! empty($data['member_id'])) {
            $decision = $access->checkIn(Member::find($data['member_id']), 'manual');
        } else {
            $member = $access->resolve('qr', $data['qr']);
            $decision = $access->checkIn($member instanceof Member ? $member : null, 'qr', null, AccessControlService::mask($data['qr']));
        }

        return response()->json($decision->toArray());
    }
}
