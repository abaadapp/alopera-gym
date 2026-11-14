<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Core\Support\Files\SafeFiles;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\MemberDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Contracts, IDs and medical forms live on the private disk under the club's
 * folder and are only served through this controller (permission + tenant scope). A file is kept as it came, and a
 * record points only at a file that landed whole (SafeFiles): written in the transaction that records it, it goes
 * again if the record fails; removed, the record goes first and the file once that is saved.
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
        DB::transaction(fn () => $member->documents()->create([
            'type' => $data['type'],
            'title' => $data['title'],
            'expires_on' => $data['expires_on'] ?? null,
            'path' => SafeFiles::putUpload($file, 'tenants/'.$tenants->require()->id.'/members/'.$member->id),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]));

        return back()->with('success', 'تم رفع المستند.');
    }

    public function download(MemberDocument $document): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->original_name ?: basename($document->path));
    }

    public function destroy(MemberDocument $document): RedirectResponse
    {
        DB::transaction(function () use ($document) {
            $document->delete();
            SafeFiles::deleteAfterCommit($document->path);
        });

        return back()->with('success', 'تم حذف المستند.');
    }
}
