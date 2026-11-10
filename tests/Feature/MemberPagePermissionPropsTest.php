<?php

namespace Tests\Feature;

use App\Modules\Gym\Models\AccessCredential;
use App\Modules\Gym\Models\MemberDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The member page sends a member's door cards and document list only to those who may see them, as its tabs show them:
 * «وسائل الدخول» with members.manage, «المستندات» with members.documents. A door card number copied from the page's data
 * would open the door; a document's title and file name tell what is in a member's file.
 */
class MemberPagePermissionPropsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function memberWithCardAndDocument($club): array
    {
        $member = $this->member($club);
        $this->asTenant($club, function () use ($member) {
            AccessCredential::create(['holder_type' => 'member', 'holder_id' => $member->id, 'type' => 'card', 'identifier' => '04A1B2C3D4', 'label' => 'بطاقة']);
            MemberDocument::create([
                'member_id' => $member->id, 'type' => 'id', 'title' => 'البطاقة المدنية', 'path' => 'tenants/x/members/doc.jpg',
                'original_name' => 'civil-id-front.jpg', 'mime' => 'image/jpeg', 'size' => 1234,
            ]);
        });

        return [$member];
    }

    public function test_staff_without_the_permissions_receive_neither_the_card_number_nor_the_document_list(): void
    {
        $club = $this->club();
        [$member] = $this->memberWithCardAndDocument($club);

        foreach (['cashier', 'finance'] as $role) {
            $staff = $this->staff($club, $role);
            $this->assertFalse($staff->can('members.manage'));
            $this->assertFalse($staff->can('members.documents'));

            $this->actingAs($staff)->get(route('club.members.show', $member))->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('member.qr_token', null)
                    ->where('credentials', [])
                    ->where('documents', []));
        }
    }

    public function test_those_who_may_see_them_still_do(): void
    {
        $club = $this->club();
        [$member] = $this->memberWithCardAndDocument($club);

        $this->actingAs($this->owner($club))->get(route('club.members.show', $member))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('credentials.0.identifier', '04A1B2C3D4')
                ->where('documents.0.original_name', 'civil-id-front.jpg'));
    }
}
