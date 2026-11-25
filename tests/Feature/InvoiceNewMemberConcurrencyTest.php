<?php

namespace Tests\Feature;

use App\Core\Support\Phone;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * Two real PostgreSQL processes issuing an invoice for the same new customer at once (two tabs, a retry with a new
 * form): one member file, one invoice paid on issue, one active membership; the other is told the number is a member's now — never a
 * second member, never an error page.
 */
class InvoiceNewMemberConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    /** @return array{status: int, errors: array<string, mixed>, exception: ?string} */
    private function send(User $user, array $data): array
    {
        $response = $this->actingAs($user)->post(route('club.invoices.store'), $data);
        $errors = session('errors');

        return [
            'status' => $response->getStatusCode(),
            'errors' => $response->exception instanceof ValidationException ? $response->exception->errors() : ($errors instanceof ViewErrorBag ? $errors->getBag('default')->toArray() : []),
            'exception' => $response->exception ? $response->exception::class.': '.$response->exception->getMessage() : null,
        ];
    }

    public function test_the_same_new_customer_issued_twice_at_once_is_one_member_one_invoice_one_membership(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $plan = $this->asTenant($club, fn () => MembershipPlan::create(['name' => 'Monthly', 'type' => 'combo', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 25]));
        $form = fn () => [
            'customer_mode' => 'new', 'customer_name' => 'سارة', 'customer_phone' => '91234567', 'issue' => 1, 'payment_method' => 'cash',
            'issue_date' => today()->toDateString(), 'due_date' => today()->addDays(7)->toDateString(), 'client_key' => (string) Str::uuid(),
            'lines' => [['item_type' => 'plan', 'item_id' => $plan->id, 'description' => 'Monthly', 'quantity' => 1, 'unit_price' => 25, 'discount' => 0]],
        ];

        $results = $this->concurrently([
            function (string $dir) use ($owner, $form) {
                self::pauseAfter('insert into "gym_members"', $dir, 0.8); // registered, not yet committed

                return $this->send($owner, $form());
            },
            function (string $dir) use ($owner, $form) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? $this->send($owner, $form()) : 'not at the same time';
            },
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        [$first, $second] = array_column($results, 'value');
        $this->assertSame([302, [], null], [$first['status'], $first['errors'], $first['exception']], json_encode($first));
        $this->assertSame(302, $second['status'], json_encode($second));
        // Back to the form with its errors (a validation refusal), not an error page.
        $this->assertStringStartsWith('Illuminate\Validation\ValidationException', (string) $second['exception'], json_encode($second));
        $this->assertArrayHasKey('customer_phone', $second['errors'], json_encode($second));

        $this->asTenant($club, function () {
            $this->assertSame(1, Member::where('phone', Phone::normalize('91234567'))->count());
            $this->assertSame([1, 1], [Invoice::count(), Membership::count()]);
            // Sold paid (owner's decision, 2026-11-25): the one invoice paid in full on issue, its membership active.
            $this->assertSame([Invoice::PAID, Membership::ACTIVE], [Invoice::sole()->status, Membership::sole()->status]);
        });
    }
}
