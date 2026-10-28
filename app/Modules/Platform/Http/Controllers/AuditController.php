<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenants): Response
    {
        $logs = $tenants->bypass(fn () => AuditLog::query()
            ->when($request->integer('tenant_id'), fn ($q, $id) => $q->where('tenant_id', $id))
            ->when($request->string('event')->toString(), fn ($q, $e) => $q->where('event', 'like', $e.'%'))
            ->latest('id')->paginate(40)->withQueryString()
            ->through(fn (AuditLog $l) => $l->only('id', 'tenant_id', 'actor_type', 'actor_name', 'event', 'subject_type', 'subject_id', 'ip_address', 'old_values', 'new_values', 'properties') + [
                'created_at' => $l->created_at->format('Y-m-d H:i:s'),
            ]));

        return Inertia::render('Platform/Audit', ['logs' => $logs, 'filters' => $request->only('tenant_id', 'event')]);
    }
}
