<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Documents\Models\BusinessDocument;
use App\Modules\Documents\Models\DocumentAlert;
use App\Modules\Documents\Models\DocumentRenewal;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Documents\Services\DocumentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** المستندات: the business's documents, their expiry, renewals and alerts. Members' documents live elsewhere. */
class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents, private readonly TenantContext $tenants) {}

    public function index(Request $request): Response
    {
        DocumentType::ensureDefaults();
        $user = $request->user();
        $tenant = $this->tenants->require();
        $soon = DocumentService::soonDays($tenant);
        $today = CarbonImmutable::today();
        $view = in_array($request->query('view'), ['expiring', 'expired'], true) ? $request->query('view') : 'all';
        $visible = fn () => BusinessDocument::visibleTo($user);
        $active = fn () => $visible()->where('status', 'active');

        $documents = $visible()->with(['type:id,name', 'branch:id,name', 'responsible:id,name'])
            ->when($view === 'expiring', fn ($q) => $q->where('status', 'active')->whereBetween('expiry_date', [$today, $today->addDays($soon)]))
            ->when($view === 'expired', fn ($q) => $q->where('status', 'active')->where('expiry_date', '<', $today))
            ->when($request->string('q')->trim()->toString(), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$t}%")->orWhere('reference_number', 'ilike', "%{$t}%")->orWhere('issuer', 'ilike', "%{$t}%")))
            ->when($request->integer('type'), fn ($q, $type) => $q->where('document_type_id', $type))
            ->orderByRaw('expiry_date is null')->orderBy('expiry_date')->orderBy('name')
            ->paginate(25)->withQueryString()
            ->through(fn (BusinessDocument $d) => $this->row($d, $soon, $today));

        $alerts = DocumentAlert::with('document:id,name,expiry_date')
            ->whereNull('acknowledged_at')->whereIn('document_id', $active()->select('id'))
            ->latest('id')->limit(10)->get()
            ->map(fn (DocumentAlert $a) => ['id' => $a->id, 'document_id' => $a->document_id, 'name' => $a->document?->name, 'expiry_date' => $a->expiry_date->toDateString(), 'days_left' => $a->document?->daysLeft($today), 'threshold' => $a->threshold_days]);

        return Inertia::render('Documents/Index', [
            'view' => $view,
            'documents' => $documents,
            'alerts' => $alerts,
            'stats' => [
                'total' => $active()->count(),
                'expiring' => $active()->whereBetween('expiry_date', [$today, $today->addDays($soon)])->count(),
                'expired' => $active()->where('expiry_date', '<', $today)->count(),
                'alerts' => DocumentAlert::whereNull('acknowledged_at')->whereIn('document_id', $active()->select('id'))->count(),
            ],
            'soon' => $soon,
            'filters' => $request->only('q', 'type'),
            'types' => DocumentType::where('is_active', true)->orderBy('name')->get(['id', 'name', 'renewal_months']),
            'branches' => Branch::orderByDesc('is_main')->orderBy('name')->get(['id', 'name']),
            'users' => User::where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'can' => [
                'create' => $user->can('documents.create'),
                'renew' => $user->can('documents.renew'),
                'manage' => $user->can('documents.manage'),
                'confidential' => $user->can('documents.confidential'),
            ],
            'maxKb' => (int) config('gym.max_document_kb'),
        ]);
    }

    public function renewals(Request $request): Response
    {
        $renewals = DocumentRenewal::with(['document:id,name,deleted_at', 'renewedBy:id,name'])
            ->whereIn('document_id', BusinessDocument::visibleTo($request->user())->withTrashed()->select('id'))
            ->latest('id')->paginate(30)->withQueryString()
            ->through(fn (DocumentRenewal $r) => [
                'id' => $r->id,
                'document' => $r->document?->name,
                'deleted' => $r->document?->trashed() ?? false,
                'previous_expiry' => $r->previous_expiry_date?->toDateString(),
                'new_expiry' => $r->new_expiry_date->toDateString(),
                'previous_reference' => $r->previous_reference,
                'new_reference' => $r->new_reference,
                'has_file' => (bool) $r->previous_file_path,
                'previous_file_name' => $r->previous_file_name,
                'notes' => $r->notes,
                'by' => $r->renewedBy?->name,
                'at' => $r->created_at->format('Y-m-d H:i'),
            ]);

        return Inertia::render('Documents/Renewals', ['renewals' => $renewals]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->documents->create($data, $request->file('file'));

        return back()->with('success', 'أُضيف المستند.');
    }

    public function update(Request $request, BusinessDocument $document): RedirectResponse
    {
        $this->ensureVisible($request, $document);
        $data = $this->validated($request, $document);
        $this->documents->update($document, $data, $request->file('file'));

        return back()->with('success', 'حُفظ المستند.');
    }

    public function renew(Request $request, BusinessDocument $document): RedirectResponse
    {
        $this->ensureVisible($request, $document);
        $data = $request->validate([
            'expiry_date' => ['required', 'date'],
            'issue_date' => ['nullable', 'date'],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'file' => $this->fileRule(),
        ]);
        $this->documents->renew($document, $data, $request->file('file'));

        return back()->with('success', 'جُدّد المستند «'.$document->name.'»، وحُفظت النسخة السابقة في سجل التجديدات.');
    }

    public function destroy(Request $request, BusinessDocument $document): RedirectResponse
    {
        $this->ensureVisible($request, $document);
        $this->documents->delete($document);

        return back()->with('success', 'حُذف المستند.');
    }

    public function download(Request $request, BusinessDocument $document): StreamedResponse
    {
        $this->ensureVisible($request, $document);
        abort_unless($document->file_path && Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->file_name ?: basename($document->file_path));
    }

    /** A version kept in the archive by a renewal. */
    public function downloadArchived(Request $request, DocumentRenewal $renewal): StreamedResponse
    {
        $document = BusinessDocument::withTrashed()->findOrFail($renewal->document_id);
        abort_unless(BusinessDocument::withTrashed()->whereKey($document->id)->visibleTo($request->user())->exists(), 404);
        abort_unless($renewal->previous_file_path && Storage::disk('local')->exists($renewal->previous_file_path), 404);

        return Storage::disk('local')->download($renewal->previous_file_path, $renewal->previous_file_name ?: basename($renewal->previous_file_path));
    }

    public function acknowledge(Request $request, DocumentAlert $alert): RedirectResponse
    {
        $this->ensureVisible($request, BusinessDocument::findOrFail($alert->document_id));
        $this->documents->acknowledge($alert);

        return back();
    }

    /** @return array<string, mixed> */
    private function row(BusinessDocument $d, int $soon, CarbonImmutable $today): array
    {
        $state = $d->state($soon, $today);

        return $d->only('id', 'name', 'reference_number', 'issuer', 'status', 'is_confidential', 'notes', 'renewal_months', 'document_type_id', 'branch_id', 'responsible_user_id') + [
            'issue_date' => $d->issue_date?->toDateString(),
            'expiry_date' => $d->expiry_date?->toDateString(),
            'days_left' => $d->daysLeft($today),
            'state' => $state,
            'state_label' => BusinessDocument::STATE_LABELS[$state],
            'type' => $d->type?->name,
            'branch' => $d->branch?->name,
            'responsible' => $d->responsible?->name,
            'file_name' => $d->file_name,
            'has_file' => (bool) $d->file_path,
        ];
    }

    /** Someone who may not see a document (confidential, another branch) gets "not found", not "forbidden". */
    private function ensureVisible(Request $request, BusinessDocument $document): void
    {
        abort_unless($document->isVisibleTo($request->user()), 404);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?BusinessDocument $document = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'document_type_id' => ['nullable', 'integer', TenantRule::exists('gym_document_types')],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'issuer' => ['nullable', 'string', 'max:160'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'renewal_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'branch_id' => ['nullable', 'integer', TenantRule::exists('branches')],
            'responsible_user_id' => ['nullable', 'integer', TenantRule::exists('users')],
            'status' => ['nullable', 'in:active,cancelled'],
            'is_confidential' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'file' => $this->fileRule(),
        ], ['expiry_date.after_or_equal' => 'تاريخ الانتهاء يجب أن يكون بعد تاريخ الإصدار.']);

        unset($data['file']);
        $data['status'] ??= $document?->status ?? 'active';
        // Only someone who sees every confidential document decides which documents are confidential.
        if (! $request->user()->can('documents.confidential')) {
            $data['is_confidential'] = $document?->is_confidential ?? false;
        }

        return $data;
    }

    /** @return list<string> */
    private function fileRule(): array
    {
        return ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:'.config('gym.max_document_kb')];
    }
}
