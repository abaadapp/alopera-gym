<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Models\AutoReply;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AutoReplyController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        AutoReply::create($this->validated($request));

        return back()->with('success', 'تمت إضافة الرد الآلي.');
    }

    public function update(Request $request, AutoReply $autoReply): RedirectResponse
    {
        $autoReply->update($this->validated($request));

        return back()->with('success', 'تم الحفظ.');
    }

    public function destroy(AutoReply $autoReply): RedirectResponse
    {
        $autoReply->delete();

        return back()->with('success', 'تم الحذف.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'keyword' => ['required', 'string', 'max:120'],
            'match_type' => ['required', 'in:exact,contains'],
            'reply' => ['required', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);
    }
}
