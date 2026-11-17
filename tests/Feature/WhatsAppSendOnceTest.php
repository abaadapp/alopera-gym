<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Gym\Models\Member;
use App\Modules\Messaging\Jobs\SendWhatsAppMessage;
use App\Modules\Messaging\Models\Campaign;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\CampaignService;
use App\Modules\Messaging\Services\InboxPresenter;
use App\Modules\Messaging\Services\TemplateCatalog;
use App\Modules\Messaging\Services\WhatsAppService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Promise\Create;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * #203: a WhatsApp message goes out once. The request to WhatsApp is the one thing that cannot be taken back, so the
 * worker claims the message before it (committed, `sending`) and nothing ever sends a claimed message again: when the
 * outcome was not recorded (WhatsApp answered and the save failed, the worker died mid-request, the connection broke
 * after the request left) the message is «نتيجة الإرسال غير مؤكدة» (`uncertain`) for staff to check — never «فشلت»,
 * which would say it did not arrive. A failure known to be before WhatsApp (no connection at all, WhatsApp's own
 * refusal to take it now) is still retried, and then sent once. Each kind is covered: a staff reply in a chat, an
 * automatic reminder, a campaign message. The tests play the queue worker attempt by attempt; no HTTP leaves them.
 */
class WhatsAppSendOnceTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const KINDS = ['reply', 'reminder', 'campaign'];

    /** Requests that reached WhatsApp. */
    private int $calls = 0;

    /** @var \Closure(HttpRequest): mixed WhatsApp's answer to the request being made (the count already includes it). */
    private \Closure $answer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.driver' => 'meta', 'services.whatsapp.app_secret' => 'app-secret']);
        Http::preventStrayRequests();
        $this->answer = fn () => $this->accepted();
        Http::fake(['https://graph.facebook.com/*' => function (HttpRequest $r) {
            $this->calls++;

            return ($this->answer)($r);
        }]);
        Queue::fake([SendWhatsAppMessage::class]); // the test is the worker, attempt by attempt
    }

    /** WhatsApp took it, with an id of its own (never the same twice). */
    private function accepted(?string $id = null): mixed
    {
        return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => $id ?? 'wamid.'.Str::random(12)]]], 200);
    }

    private function connected(): Tenant
    {
        $club = $this->club();
        $this->asTenant($club, function () {
            TenantIntegration::create([
                'provider' => 'whatsapp', 'is_enabled' => true, 'public_identifier' => 'PNID-1',
                'credentials' => ['phone_number_id' => 'PNID-1', 'access_token' => 'EAAG-test'],
            ]);
            TemplateCatalog::seedDefaults();
            MessageTemplate::query()->update(['status' => 'approved']);
        });

        return $club;
    }

    /** A message of the kind, queued as the system queues it (its job waiting for the worker). */
    private function queued(Tenant $club, string $kind): Message
    {
        $member = $kind === 'reply' ? null : $this->member($club, ['phone' => '9689'.random_int(1000000, 9999999), 'marketing_opt_in' => true]);

        return $this->asTenant($club, function () use ($kind, $member) {
            $message = match ($kind) {
                'reply' => app(WhatsAppService::class)->reply($this->chat(), 'أهلًا، موعدك غدًا الساعة 6.'),
                'reminder' => app(WhatsAppService::class)->queueTemplate($member, 'membership_expiring', [
                    'member_name' => 'نور', 'plan_name' => 'شهري', 'ends_on' => '2026-12-01', 'days_left' => 3, 'renew_link' => 'https://example.test/renew',
                ]),
                'campaign' => $this->campaignMessage($member),
            };
            $this->assertSame(Message::QUEUED, $message->fresh()->status, $kind.' is queued');

            return $message->fresh();
        });
    }

    private function chat(): Conversation
    {
        return Conversation::create(['phone' => '9689'.random_int(1000000, 9999999), 'contact_name' => 'Customer', 'last_inbound_at' => now(),
            'first_inbound_at' => now(), 'last_message_at' => now(), 'status' => Conversation::OPEN]);
    }

    private function campaignMessage(Member $member): Message
    {
        $template = MessageTemplate::create(['key' => 'offer_'.$member->id, 'name' => 'offer', 'category' => 'marketing', 'body' => 'عرض {{1}}',
            'variables' => ['member_name'], 'status' => 'approved']);
        $campaign = Campaign::create(['name' => 'Offer', 'message_template_id' => $template->id, 'audience' => ['segment' => 'all']]);
        app(CampaignService::class)->fanOut($campaign->load('template')->forceFill(['status' => Campaign::SENDING]));

        return Message::where('campaign_id', $campaign->id)->where('member_id', $member->id)->sole();
    }

    /** The worker runs the message's job; `$attempt` is the queue's count (2 = a retry, or handed back after a worker died). */
    private function work(Tenant $club, Message $message, int $attempt = 1): ?\Throwable
    {
        $job = (new SendWhatsAppMessage($message->id, $club->id))->withFakeQueueInteractions();
        $job->job->attempts = $attempt;
        try {
            app()->call([$job, 'handle']);
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    private function fresh(Tenant $club, Message $message): Message
    {
        return $this->asTenant($club, fn () => $message->fresh());
    }

    /** Saving the message's outcome fails `$times` times (the database went away, a deadlock), then works again. */
    private function savingTheOutcomeFails(int $times): void
    {
        Message::saving(function (Message $m) use (&$times) {
            if ($times > 0 && $m->isDirty('provider_message_id') && $m->provider_message_id !== null) {
                $times--;
                throw new \RuntimeException('SQLSTATE[08006]: the database connection was lost');
            }
        });
    }

    public function test_a_message_whatsapp_accepted_is_sent_once_when_saving_its_outcome_fails_for_a_moment(): void
    {
        $club = $this->connected();
        foreach (self::KINDS as $kind) {
            $this->calls = 0;
            $message = $this->queued($club, $kind);
            $this->savingTheOutcomeFails(1);

            $attempt = 1;
            while ($this->work($club, $message, $attempt) !== null && $attempt < 4) {
                $attempt++; // the queue retries the job
            }

            $this->assertSame(1, $this->calls, $kind.': WhatsApp got it once');
            $message = $this->fresh($club, $message);
            $this->assertSame(Message::SENT, $message->status, $kind.': its outcome is kept');
            $this->assertStringStartsWith('wamid.', (string) $message->provider_message_id, $kind.': with WhatsApp\'s id');
        }
    }

    public function test_a_message_whose_outcome_could_not_be_saved_is_never_sent_again_and_says_so(): void
    {
        $club = $this->connected();
        foreach (self::KINDS as $kind) {
            $this->calls = 0;
            $message = $this->queued($club, $kind);
            $this->savingTheOutcomeFails(100); // down for the rest of the attempt
            $this->assertNotNull($this->work($club, $message), $kind.': the attempt ends in an error');

            foreach ([2, 3, 4] as $attempt) {
                $this->assertNull($this->work($club, $message, $attempt));
            }

            $this->assertSame(1, $this->calls, $kind.': never sent a second time');
            $message = $this->fresh($club, $message);
            $this->assertSame([Message::UNCERTAIN, 'outcome_unknown'], [$message->status, $message->error_code], $kind.': «نتيجة الإرسال غير مؤكدة», not «فشلت»');
        }
    }

    public function test_a_worker_that_died_with_the_request_in_flight_does_not_send_it_again(): void
    {
        $club = $this->connected();
        $this->answer = function () {
            if ($this->calls === 1) {
                throw new \Error('the worker was killed'); // the request reached WhatsApp; its answer never came back
            }

            return $this->accepted();
        };
        foreach (self::KINDS as $kind) {
            $this->calls = 0;
            $message = $this->queued($club, $kind);
            $this->assertNotNull($this->work($club, $message));
            // The queue hands the job back once its reservation runs out (retry_after).
            $this->assertNull($this->work($club, $message, 2));

            $this->assertSame(1, $this->calls, $kind.': not sent again');
            $this->assertSame(Message::UNCERTAIN, $this->fresh($club, $message)->status, $kind);
        }
    }

    public function test_an_answer_that_leaves_the_outcome_unknown_is_not_retried_automatically(): void
    {
        $club = $this->connected();
        $answers = [
            // The connection broke after the request left: WhatsApp may have it.
            'timeout' => fn (HttpRequest $r) => Create::rejectionFor(new NetworkTimeoutException('cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received', $r->toPsrRequest())),
            // A gateway in front of WhatsApp gave up waiting: no word from WhatsApp itself.
            'gateway' => fn () => Http::response('<html>504 Gateway Time-out</html>', 504),
            // Taken, but without the id its statuses come back by.
            'no_id' => fn () => Http::response(['messaging_product' => 'whatsapp'], 200),
        ];
        foreach ($answers as $case => $answer) {
            $this->calls = 0;
            $this->answer = fn (HttpRequest $r) => $this->calls === 1 ? $answer($r) : $this->accepted('wamid.again');
            $message = $this->queued($club, 'reminder');

            $attempt = 1;
            while ($this->work($club, $message, $attempt) !== null && $attempt < 4) {
                $attempt++;
            }

            $this->assertSame(1, $this->calls, $case.': not sent again on its own');
            $message = $this->fresh($club, $message);
            $this->assertSame(Message::UNCERTAIN, $message->status, $case);
            $this->assertNotSame(Message::FAILED, $message->status, $case.': not said to have failed');
        }
    }

    public function test_a_failure_before_the_request_reached_whatsapp_is_retried_and_the_message_sent_once(): void
    {
        $club = $this->connected();
        foreach (self::KINDS as $kind) {
            $this->calls = 0;
            $this->answer = fn (HttpRequest $r) => match ($this->calls) {
                1 => (Http::failedConnection('cURL error 7: Failed to connect to graph.facebook.com port 443'))($r), // never connected
                2 => Http::response(['error' => ['code' => 130429, 'message' => 'Rate limit hit']], 429),             // WhatsApp: not now
                3 => Http::response(['error' => ['code' => 2, 'message' => 'Service temporarily unavailable']], 503), // WhatsApp: not now
                default => $this->accepted('wamid.'.$kind),
            };
            $message = $this->queued($club, $kind);

            foreach ([1, 2, 3] as $attempt) {
                $this->assertNotNull($this->work($club, $message, $attempt), $kind.': attempt '.$attempt.' is retried');
                $this->assertSame(Message::QUEUED, $this->fresh($club, $message)->status, $kind.': still waiting to be sent');
            }
            $this->assertNull($this->work($club, $message, 4));

            $message = $this->fresh($club, $message);
            $this->assertSame([Message::SENT, 'wamid.'.$kind], [$message->status, $message->provider_message_id], $kind);
        }
    }

    public function test_a_message_that_never_reached_whatsapp_after_every_attempt_failed_says_so(): void
    {
        $club = $this->connected();
        $this->answer = fn (HttpRequest $r) => (Http::failedConnection())($r);
        $message = $this->queued($club, 'reply');

        foreach ([1, 2, 3] as $attempt) {
            $this->assertNotNull($this->work($club, $message, $attempt));
        }
        $this->assertNull($this->work($club, $message, 4));

        $this->assertSame([Message::FAILED, 'connection'], [$this->fresh($club, $message)->status, $this->fresh($club, $message)->error_code], 'it did not reach WhatsApp: failed is the truth');
    }

    public function test_a_message_handed_to_two_workers_at_once_is_sent_once(): void
    {
        $club = $this->connected();
        foreach (self::KINDS as $kind) {
            $this->calls = 0;
            $message = $this->queued($club, $kind);
            // While the first worker's request is with WhatsApp, a second worker picks up the same message (handed over
            // twice: messaging:dispatch-scheduled after a refused hand-over).
            $this->answer = function () use ($club, $message) {
                if ($this->calls === 1) {
                    $this->assertNull($this->work($club, $message));
                }

                return $this->accepted();
            };

            $this->assertNull($this->work($club, $message));

            $this->assertSame(1, $this->calls, $kind.': one request');
            $this->assertSame(Message::SENT, $this->fresh($club, $message)->status, $kind);
        }
    }

    public function test_a_message_left_sending_by_a_lost_job_is_marked_uncertain_and_a_live_one_is_left_alone(): void
    {
        $club = $this->connected();
        $lost = $this->queued($club, 'reminder');
        $live = $this->queued($club, 'reply');
        $this->asTenant($club, function () use ($lost, $live) {
            Message::whereKey($lost->id)->update(['status' => Message::SENDING, 'updated_at' => now()->subMinutes(20)]);
            Message::whereKey($live->id)->update(['status' => Message::SENDING, 'updated_at' => now()->subSeconds(20)]);
        });

        Artisan::call('messaging:dispatch-scheduled');

        $this->assertSame([Message::UNCERTAIN, 'outcome_unknown'], [$this->fresh($club, $lost)->status, $this->fresh($club, $lost)->error_code]);
        $this->assertSame(Message::SENDING, $this->fresh($club, $live)->status);
        $this->assertSame(0, $this->calls, 'neither is sent');
    }

    public function test_staff_see_an_uncertain_outcome_as_such_and_a_campaign_does_not_count_it_as_failed(): void
    {
        $club = $this->connected();
        $this->answer = fn (HttpRequest $r) => Create::rejectionFor(new NetworkTimeoutException('cURL error 28: Operation timed out', $r->toPsrRequest()));
        $reply = $this->queued($club, 'reply');
        $campaignMessage = $this->queued($club, 'campaign');
        $this->work($club, $reply);
        $this->work($club, $campaignMessage);

        $this->asTenant($club, function () use ($reply, $campaignMessage) {
            $shown = app(InboxPresenter::class)->message($reply->fresh());
            $this->assertSame(Message::UNCERTAIN, $shown['status']);
            $this->assertStringContainsString('قد تكون وصلت', (string) $shown['error']);

            $campaign = Campaign::findOrFail($campaignMessage->campaign_id);
            $this->assertSame([0, 0, 1], [$campaign->sent_count, $campaign->failed_count, $campaign->uncertainCount()]);
            $this->assertSame(Campaign::COMPLETED, $campaign->status, 'nothing is left to send');
        });
    }

    // ---- cURL's own errors (#204 review) ----------------------------------------------------------------------------

    /** @var list<Process> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop(0);
        }
        parent::tearDown();
    }

    /** The stand-in for WhatsApp's API on 127.0.0.1 (tests/Support/local-whatsapp-api.php): where it is and its log. */
    private function localWhatsApp(string $mode): string
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'wa-api-');
        $server = new Process([PHP_BINARY, base_path('tests/Support/local-whatsapp-api.php'), $mode, $log]);
        $server->start();
        $server->waitUntil(fn ($type, $output) => str_contains($output, '127.0.0.1:'));
        $this->servers[] = $server;
        $this->pointAt('http://'.trim($server->getOutput()));

        return $log;
    }

    private function pointAt(string $url): void
    {
        config(['services.whatsapp.graph_url' => $url]);
        Http::allowStrayRequests([$url.'/*']);
    }

    /** @return list<array{line: string, body: string}> the requests that reached the stand-in */
    private function arrived(string $log): array
    {
        return array_map(fn ($l) => json_decode($l, true), array_values(array_filter(explode("\n", (string) @file_get_contents($log)))));
    }

    private function runUntilSettled(Tenant $club, Message $message): Message
    {
        $attempt = 1;
        while ($this->work($club, $message, $attempt) !== null && $attempt < 4) {
            $attempt++;
        }

        return $this->fresh($club, $message);
    }

    public function test_real_curl_6_and_7_never_reached_whatsapp_so_they_are_retried_and_the_message_then_sent_once(): void
    {
        $club = $this->connected();
        // cURL 6: the name does not resolve (.invalid never does).
        $this->pointAt('http://whatsapp-send-once.invalid');
        $message = $this->queued($club, 'reminder');
        $this->assertNotNull($this->work($club, $message), 'retried');
        $this->assertSame([Message::QUEUED, 'connection'], [$this->fresh($club, $message)->status, $this->fresh($club, $message)->error_code]);
        $this->assertStringContainsString('cURL error 6', (string) $this->fresh($club, $message)->error_message);

        // cURL 7: nothing listens on the port.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $closed = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->pointAt('http://'.$closed);
        $this->assertNotNull($this->work($club, $message, 2), 'retried');
        $this->assertSame(Message::QUEUED, $this->fresh($club, $message)->status);
        $this->assertStringContainsString('cURL error 7', (string) $this->fresh($club, $message)->error_message);

        // Then WhatsApp answers: sent, once.
        $log = $this->localWhatsApp('ok');
        $this->assertNull($this->work($club, $message, 3));
        $this->assertCount(1, $this->arrived($log));
        $this->assertSame(Message::SENT, $this->fresh($club, $message)->status);
    }

    public function test_real_curl_28_after_the_request_reached_the_provider_is_uncertain_and_never_sent_again(): void
    {
        $club = $this->connected();
        $log = $this->localWhatsApp('silent'); // takes the request, never answers: the 15-second timeout runs out
        $message = $this->queued($club, 'reply');

        $message = $this->runUntilSettled($club, $message);

        $this->assertCount(1, $this->arrived($log), 'the request reached the provider once');
        $this->assertStringContainsString('"to"', $this->arrived($log)[0]['body']);
        $this->assertSame(Message::UNCERTAIN, $message->status, 'cURL 28 after the request left: not retried');
        $this->assertStringContainsString('cURL error 28', (string) $message->error_message);
    }

    public function test_real_curl_52_an_empty_reply_after_the_request_arrived_is_uncertain(): void
    {
        $club = $this->connected();
        $log = $this->localWhatsApp('close');
        $message = $this->runUntilSettled($club, $this->queued($club, 'campaign'));

        $this->assertCount(1, $this->arrived($log));
        $this->assertSame(Message::UNCERTAIN, $message->status);
        $this->assertStringContainsString('cURL error 52', (string) $message->error_message);
    }

    /**
     * The exception's class is not the proof: Guzzle 7 (Laravel accepts ^7.8.2 || ^8.0) reports every cURL timeout and
     * an empty reply as a ConnectException, and a handler may name a broken connection that way too. Only cURL's own
     * error says the request never left: 5, 6, 7, 35, 60, or 28 while resolving or connecting.
     */
    public function test_only_curls_own_before_connection_errors_are_retried_whatever_the_exception_class(): void
    {
        $club = $this->connected();
        $cases = [
            // [the error, as the handler raised it] => retried?
            'cURL 6' => [fn ($psr) => new ConnectException('cURL error 6: Could not resolve host: graph.facebook.com (see https://curl.se/libcurl/c/libcurl-errors.html) for https://graph.facebook.com/v21.0/PNID-1/messages', $psr), true],
            'cURL 7' => [fn ($psr) => new ConnectException('cURL error 7: Failed to connect to graph.facebook.com port 443 after 3 ms: Could not connect to server (see https://curl.se/libcurl/c/libcurl-errors.html)', $psr), true],
            'cURL 28 connecting' => [fn ($psr) => new ConnectTimeoutException('cURL error 28: Connection timed out after 10002 milliseconds (see https://curl.se/libcurl/c/libcurl-errors.html)', $psr), true],
            'cURL 28 resolving' => [fn ($psr) => new ConnectTimeoutException('cURL error 28: Resolving timed out after 10001 milliseconds (see https://curl.se/libcurl/c/libcurl-errors.html)', $psr), true],
            // Guzzle 7's shape for a timeout after the request was sent.
            'cURL 28 after the request (Guzzle 7)' => [fn ($psr) => new ConnectException('cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received (see https://curl.se/libcurl/c/libcurl-errors.html)', $psr), false],
            // Guzzle 7's shape for an empty reply after the request was sent.
            'cURL 52 (Guzzle 7)' => [fn ($psr) => new ConnectException('cURL error 52: Empty reply from server (see https://curl.se/libcurl/c/libcurl-errors.html)', $psr), false],
            // No cURL error at all (another handler): nothing says it did not leave.
            'no cURL error' => [fn ($psr) => new ConnectException('fread(): SSL: Connection reset by peer', $psr), false],
        ];
        foreach ($cases as $case => [$error, $retried]) {
            $this->calls = 0;
            $this->answer = fn (HttpRequest $r) => $this->calls === 1 ? Create::rejectionFor($error($r->toPsrRequest())) : $this->accepted();
            $message = $this->runUntilSettled($club, $this->queued($club, 'reminder'));

            if ($retried) {
                $this->assertSame([2, Message::SENT], [$this->calls, $message->status], $case.': never left, so retried and sent once');
            } else {
                $this->assertSame([1, Message::UNCERTAIN], [$this->calls, $message->status], $case.': may have reached WhatsApp, so not sent again');
            }
        }
    }

    // ---- Settling a claim never overwrites an outcome (#204 review) -----------------------------------------------

    /**
     * Right after the read that decides (the sweep's list of claims, or the job's look at its message), `$meanwhile`
     * runs: what another worker did in between. A locked re-read (`for update`) is not that read.
     */
    private function between(string $read, \Closure $meanwhile): void
    {
        $done = false;
        DB::listen(function (QueryExecuted $q) use (&$done, $read, $meanwhile) {
            $plain = str_starts_with($q->sql, 'select') && str_contains($q->sql, 'from "messages"') && ! str_contains($q->sql, 'for update');
            $isIt = $read === 'sweep' ? in_array(Message::SENDING, $q->bindings, true) : str_contains($q->sql, '"messages"."id" = ?');
            if (! $done && $plain && $isIt) {
                $done = true;
                $meanwhile();
            }
        });
    }

    public function test_the_sweep_never_turns_a_message_a_worker_settled_meanwhile_into_uncertain(): void
    {
        $club = $this->connected();
        $outcomes = [
            'sent' => ['status' => Message::SENT, 'provider_message_id' => 'wamid.LATE-SENT', 'sent_at' => now()],
            'delivered' => ['status' => Message::DELIVERED, 'provider_message_id' => 'wamid.LATE-DELIVERED', 'sent_at' => now(), 'delivered_at' => now()],
            'failed' => ['status' => Message::FAILED, 'error_code' => '131026'],
            'claimed afresh' => ['status' => Message::SENDING, 'updated_at' => now()],
        ];
        foreach ($outcomes as $case => $meanwhile) {
            $message = $this->queued($club, 'campaign');
            $this->asTenant($club, fn () => Message::whereKey($message->id)->update(['status' => Message::SENDING, 'updated_at' => now()->subMinutes(20)]));
            // A slow worker (or one paused for minutes) saves its outcome between the sweep's read and its write.
            $this->between('sweep', fn () => DB::table('messages')->where('id', $message->id)->update($meanwhile));

            Artisan::call('messaging:dispatch-scheduled');

            $this->assertSame($meanwhile['status'], $this->fresh($club, $message)->status, $case.': kept, never turned into uncertain');
            $this->asTenant($club, fn () => Message::whereKey($message->id)->update(['status' => Message::SKIPPED])); // out of the next round's way
        }
    }

    public function test_a_retry_never_turns_the_outcome_its_paused_attempt_saved_meanwhile_into_uncertain(): void
    {
        $club = $this->connected();
        foreach (self::KINDS as $kind) {
            $message = $this->queued($club, $kind);
            $this->asTenant($club, fn () => Message::whereKey($message->id)->update(['status' => Message::SENDING]));
            // The first attempt was paused, not dead: the queue handed the job back, and attempt 1 saves "sent" while
            // attempt 2 is deciding.
            $this->between('job', fn () => DB::table('messages')->where('id', $message->id)->update(['status' => Message::SENT, 'provider_message_id' => 'wamid.PAUSED-'.$kind, 'sent_at' => now()]));

            $this->assertNull($this->work($club, $message, 2));

            $this->assertSame(Message::SENT, $this->fresh($club, $message)->status, $kind.': the saved outcome stands');
        }
    }

    public function test_a_claim_still_standing_is_settled_as_uncertain_once_and_its_campaign_counted(): void
    {
        $club = $this->connected();
        $message = $this->queued($club, 'campaign');
        $this->asTenant($club, fn () => Message::whereKey($message->id)->update(['status' => Message::SENDING, 'updated_at' => now()->subMinutes(20)]));

        Artisan::call('messaging:dispatch-scheduled');
        Artisan::call('messaging:dispatch-scheduled');

        $this->asTenant($club, function () use ($message) {
            $this->assertSame(Message::UNCERTAIN, $message->fresh()->status);
            $campaign = Campaign::findOrFail($message->campaign_id);
            $this->assertSame([0, 1], [$campaign->failed_count, $campaign->uncertainCount()]);
        });
        $this->assertSame(0, $this->calls);
    }
}
