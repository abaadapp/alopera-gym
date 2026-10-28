<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Gym\Access\AccessControlService;
use App\Modules\Gym\Models\StaffAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Unified staff attendance log: fingerprint devices and manual punches. */
class StaffAttendanceController extends Controller
{
    public function index(Request $request, TenantContext $tenants): Response
    {
        $from = CarbonImmutable::parse($request->string('from')->toString() ?: now()->startOfMonth()->toDateString());
        $to = CarbonImmutable::parse($request->string('to')->toString() ?: today()->toDateString());

        $rows = StaffAttendance::with(['user:id,name', 'device:id,name'])
            ->whereBetween('clock_in_at', [$from->startOfDay(), $to->endOfDay()])
            ->when($request->integer('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->latest('clock_in_at')->paginate(50)->withQueryString()
            ->through(fn (StaffAttendance $a) => [
                'id' => $a->id, 'user' => $a->user?->name, 'method' => $a->method, 'device' => $a->device?->name,
                'date' => $a->clock_in_at->toDateString(), 'in' => $a->clock_in_at->format('H:i'), 'out' => $a->clock_out_at?->format('H:i'),
                'hours' => $a->clock_out_at ? round($a->clock_in_at->diffInMinutes($a->clock_out_at) / 60, 2) : null,
            ]);

        // Per person over the whole period (not just this page): days present, hours worked, punches left open.
        $summary = StaffAttendance::with('user:id,name')
            ->whereBetween('clock_in_at', [$from->startOfDay(), $to->endOfDay()])
            ->when($request->integer('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->get()->groupBy('user_id')
            ->map(fn ($records) => [
                'user' => $records->first()->user?->name,
                'days' => $records->map(fn ($a) => $a->clock_in_at->toDateString())->unique()->count(),
                'hours' => round($records->sum(fn ($a) => $a->clock_out_at ? $a->clock_in_at->diffInMinutes($a->clock_out_at) : 0) / 60, 2),
                'open' => $records->whereNull('clock_out_at')->count(),
                'records' => $records->count(),
            ])->sortByDesc('hours')->values();

        return Inertia::render('Gym/StaffAttendance/Index', [
            'rows' => $rows,
            'summary' => $summary,
            'staff' => User::ofTenant($tenants->require()->id)->orderBy('name')->get(['id', 'name']),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'user_id' => $request->integer('user_id') ?: null],
        ]);
    }

    /** Any staff member can punch herself in/out from the panel. */
    public function punch(Request $request, AccessControlService $access): RedirectResponse
    {
        $record = $access->staffPunch($request->user(), null, 'manual');

        return back()->with('success', $record->clock_out_at ? 'تم تسجيل الانصراف.' : 'تم تسجيل الحضور.');
    }
}
