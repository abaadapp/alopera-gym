<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\MemberDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Contracts, IDs and medical forms live on the private disk under the club's
 * folder and are only served through this controller (permission + tenant scope).
 */
class MemberDocumentController extends Controller
{
    public function store(Request $request, Member $member, TenantContext $tenants): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', MemberDocument::TYPES)],
            'title' => ['required', 'string', 'max:160'],
            'expires_on' => ['nullable', 'date'],
            'file' => ['required', 'file', 'max:'.config('gym.max_document_kb'), 'mimes:pdf,jpg,jpeg,png,webp,heic,doc,docx'],
        ]);

        $file = $request->file('file');
        $path = $file->store('tenants/'.$tenants->require()->id.'/members/'.$member->id, 'local');

        $member->documents()->create([
            'type' => $data['type'],
            'title' => $data['title'],
            'expires_on' => $data['expires_on'] ?? null,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return back()->with('success', 'تم رفع المستند.');
    }

    public function download(MemberDocument $document): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->original_name ?: basename($document->path));
    }

    public function destroy(MemberDocument $document): RedirectResponse
    {
        Storage::disk('local')->delete($document->path);
        $document->delete();

        return back()->with('success', 'تم حذف المستند.');
    }
}
