<?php

namespace App\Http\Controllers\Club;

use App\Core\Audit\AuditRedaction;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Sensitive operations log: who did what, when (spec 7.2). */
class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $logs = AuditLog::query()
            ->when($request->string('event')->toString(), fn ($q, $e) => $q->where('event', 'like', $e.'%'))
            ->when($request->string('actor')->toString(), fn ($q, $a) => $q->where('actor_name', 'ilike', '%'.$a.'%'))
            ->when($request->date('from'), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($request->date('to'), fn ($q, $d) => $q->where('created_at', '<=', $d->endOfDay()))
            ->latest('id')->paginate(40)->withQueryString()
            ->through(fn (AuditLog $l) => $l->only('id', 'actor_type', 'actor_name', 'event', 'subject_type', 'subject_id', 'ip_address') + [
                // Salary and bank values on employee entries reach only someone who may read salaries.
                'old_values' => AuditRedaction::values($l->old_values, $l->subject_type, $request->user()),
                'new_values' => AuditRedaction::values($l->new_values, $l->subject_type, $request->user()),
                'properties' => AuditRedaction::values($l->properties, $l->subject_type, $request->user()),
                'created_at' => $l->created_at->format('Y-m-d H:i:s'),
            ]);

        return Inertia::render('Club/Settings/Audit', ['logs' => $logs, 'filters' => $request->only('event', 'actor', 'from', 'to')]);
    }
}
