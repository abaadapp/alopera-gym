<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\MemberNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** CRM communication log. */
class MemberNoteController extends Controller
{
    public function store(Request $request, Member $member): RedirectResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'in:'.implode(',', MemberNote::CHANNELS)],
            'direction' => ['required', 'in:inbound,outbound,internal'],
            'body' => ['required', 'string', 'max:3000'],
            'follow_up_on' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $member->notesLog()->create($data + ['user_id' => $request->user()->id]);

        return back()->with('success', 'تم تسجيل التواصل.');
    }

    public function destroy(Request $request, MemberNote $note): RedirectResponse
    {
        abort_unless($note->user_id === $request->user()->id || $request->user()->isOwner(), 403);
        $note->delete();

        return back()->with('success', 'تم حذف الملاحظة.');
    }
}
