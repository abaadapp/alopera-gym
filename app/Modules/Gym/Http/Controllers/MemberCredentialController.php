<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Gym\Models\AccessCredential;
use App\Modules\Gym\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Card numbers / fingerprint user ids enrolled on the door devices for a member. */
class MemberCredentialController extends Controller
{
    public function store(Request $request, Member $member): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', AccessCredential::TYPES)],
            'identifier' => ['required', 'string', 'max:128'],
            'label' => ['nullable', 'string', 'max:80'],
        ]);
        $request->validate(['identifier' => [TenantRule::unique('gym_access_credentials', 'identifier')->where('type', $data['type'])]],
            ['identifier.unique' => 'هذا المعرّف مسجّل لشخص آخر.']);

        AccessCredential::create($data + ['holder_type' => 'member', 'holder_id' => $member->id]);

        return back()->with('success', 'تم ربط وسيلة الدخول.');
    }

    public function destroy(AccessCredential $credential): RedirectResponse
    {
        $credential->delete();

        return back()->with('success', 'تم حذف وسيلة الدخول.');
    }
}
