<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\DocumentType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** أنواع المستندات: the kinds of documents the business keeps, with their usual renewal period. */
class DocumentTypeController extends Controller
{
    public function index(): Response
    {
        DocumentType::ensureDefaults();

        return Inertia::render('Documents/Types', [
            'types' => DocumentType::withCount('documents')->orderByDesc('is_active')->orderBy('name')->get()
                ->map(fn (DocumentType $t) => $t->only('id', 'name', 'renewal_months', 'is_active', 'documents_count')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        DocumentType::create($this->validated($request));

        return back()->with('success', 'أُضيف نوع المستند.');
    }

    public function update(Request $request, DocumentType $documentType): RedirectResponse
    {
        $documentType->update($this->validated($request, $documentType));

        return back()->with('success', 'حُفظ نوع المستند.');
    }

    /** A type in use is retired instead of deleted, so its documents keep their type. */
    public function destroy(DocumentType $documentType): RedirectResponse
    {
        if ($documentType->documents()->withTrashed()->exists()) {
            $documentType->update(['is_active' => false]);

            return back()->with('success', 'النوع مستخدم في مستندات، فأُوقف بدل حذفه.');
        }
        $documentType->delete();

        return back()->with('success', 'حُذف نوع المستند.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?DocumentType $type = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', TenantRule::unique('gym_document_types', 'name')->ignore($type?->id)],
            'renewal_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'is_active' => ['boolean'],
        ]);
    }
}
