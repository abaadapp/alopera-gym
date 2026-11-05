<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Core\Support\Period;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Gym\Access\AccessControlService;
use App\Modules\Gym\Models\StaffAttendance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Unified staff attendance log: fingerprint devices and manual punches. */
class StaffAttendanceController extends Controller
{
    public function index(Request $request, TenantContext $tenants): Response
    {
        $period = Period::fromRequest($request, 'month');

        $rows = BranchContext::scope(StaffAttendance::with(['user:id,name', 'user.employee:id,user_id,name', 'device:id,name']))
            ->tap(fn ($q) => $period->apply($q, 'clock_in_at'))
            ->when($request->integer('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->latest('clock_in_at')->paginate(50)->withQueryString()
            ->through(fn (StaffAttendance $a) => [
                'id' => $a->id, 'user' => $a->user?->staffName(), 'method' => $a->method, 'device' => $a->device?->name,
                'date' => $a->clock_in_at->toDateString(), 'in' => $a->clock_in_at->format('H:i'), 'out' => $a->clock_out_at?->format('H:i'),
                'hours' => $a->clock_out_at ? round($a->clock_in_at->diffInMinutes($a->clock_out_at) / 60, 2) : null,
            ]);

        // Over the whole period (not just this page): hours worked and punches left open. Each person's days and hours
        // are the staff attendance report (the reports page).
        $punches = BranchContext::scope(StaffAttendance::query())
            ->tap(fn ($q) => $period->apply($q, 'clock_in_at'))
            ->when($request->integer('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->get(['clock_in_at', 'clock_out_at']);

        return Inertia::render('Gym/StaffAttendance/Index', [
            'rows' => $rows,
            'stats' => [
                'hours' => round($punches->sum(fn ($a) => $a->clock_out_at ? $a->clock_in_at->diffInMinutes($a->clock_out_at) : 0) / 60, 2),
                'open' => $punches->whereNull('clock_out_at')->count(),
            ],
            'staff' => User::ofTenant($tenants->require()->id)->with('employee:id,user_id,name')->get(['id', 'name'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->staffName()])->sortBy('name')->values(),
            'period' => $period->toArray(),
            'filters' => ['user_id' => $request->integer('user_id') ?: null],
        ]);
    }

    /** Any staff member can punch herself in/out from the panel. */
    public function punch(Request $request, AccessControlService $access): RedirectResponse
    {
        $record = $access->staffPunch($request->user(), null, 'manual');

        return back()->with('success', $record->clock_out_at ? 'تم تسجيل الانصراف.' : 'تم تسجيل الحضور.');
    }
}
