<?php

namespace App\Http\Controllers\Club;

use App\Core\Backup\TenantBackupService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    public function __construct(private readonly TenantBackupService $backups, private readonly TenantContext $tenants) {}

    public function index(): Response
    {
        return Inertia::render('Club/Settings/Backups', ['backups' => $this->backups->list($this->tenants->require())]);
    }

    public function store(): RedirectResponse
    {
        $name = $this->backups->create($this->tenants->require());

        return back()->with('success', 'تم إنشاء النسخة الاحتياطية: '.$name);
    }

    public function download(string $file): StreamedResponse
    {
        $path = $this->backups->path($this->tenants->require(), $file);
        abort_unless($this->backups->disk()->exists($path), 404);

        return $this->backups->disk()->download($path, $file);
    }

    /** Owner-only by permission; requires typing the club slug to confirm. */
    public function restore(Request $request): RedirectResponse
    {
        $tenant = $this->tenants->require();
        $request->validate([
            'archive' => ['required', 'file', 'mimes:zip', 'max:512000'],
            'confirm' => ['required', 'string'],
        ]);

        if ($request->string('confirm')->toString() !== $tenant->slug) {
            throw ValidationException::withMessages(['confirm' => 'اكتب معرّف النادي ('.$tenant->slug.') للتأكيد.']);
        }

        // Safety net: snapshot the current state before replacing it.
        $this->backups->create($tenant);
        $counts = $this->backups->restore($tenant, $request->file('archive')->getRealPath());

        return back()->with('success', 'تمت الاستعادة: '.array_sum($counts).' سجلًا.');
    }
}
