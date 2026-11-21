<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * scripts/rollback-code.sh going back past payment links v2 (PR #218). To R1 (v0.140) or later — its link cancel ends
 * the link only — naming the migration (ALLOW_MIGRATIONS) is all it asks. To code from before R1, whose cancel voids the
 * link's invoice, what that code does differently is said, and nothing changes until it is agreed to
 * (ACCEPT_PAYMENT_LINKS_V1=1). Run on a throwaway git tree, never the app's.
 */
class RollbackCodePaymentLinksTest extends TestCase
{
    private const MIGRATION = '2026_11_21_000100_add_payment_link_history.php';

    private const LINKS = 'app/Modules/Payments/Services/PaymentLinks.php';

    /** PaymentLinks::cancel as v0.139 had it (voids the invoice), or as R1 (v0.140) has it (the link only). */
    private function linksCode(bool $voids): void
    {
        @mkdir(dirname($this->dir.'/'.self::LINKS), 0777, true);
        file_put_contents($this->dir.'/'.self::LINKS, $voids
            ? "<?php\n// v0.139\n\$this->engine->void(\$invoice, 'إلغاء رابط الدفع');\n"
            : "<?php\n// R1: the link only\n\$link->forceFill(['cancelled_at' => now()])->save();\n");
    }

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/rollback-links-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/database/migrations', 0777, true);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();
        parent::tearDown();
    }

    private function git(string ...$args): string
    {
        $p = new Process(['git', '-C', $this->dir, '-c', 'user.email=t@example.invalid', '-c', 'user.name=t', ...$args]);
        $p->mustRun();

        return trim($p->getOutput());
    }

    private function rollBack(string $to, array $env): Process
    {
        $p = new Process(['bash', dirname(__DIR__, 2).'/scripts/rollback-code.sh', $to], null, [
            'APP_DIR' => $this->dir, 'STATE_DIR' => $this->dir.'/state', 'PHP_BIN' => 'false', 'RELOAD_FPM' => 'true',
            'HEALTH_URL' => 'http://127.0.0.1:9/up', 'ALLOW_MIGRATIONS' => '', 'ACCEPT_PAYMENT_LINKS_V1' => '', ...$env,
        ]);
        $p->setTimeout(60)->run();

        return $p;
    }

    public function test_going_back_past_payment_links_v2_to_code_before_r1_says_what_it_does_and_waits_for_agreement(): void
    {
        file_put_contents($this->dir.'/database/migrations/.keep', '');
        $this->linksCode(voids: true);
        $this->git('init', '-q');
        $this->git('add', '-A');
        $this->git('commit', '-qm', 'v0.139');
        $before = $this->git('rev-parse', '--short', 'HEAD');
        mkdir($this->dir.'/app/Modules/Payments/Database/migrations', 0777, true);
        file_put_contents($this->dir.'/app/Modules/Payments/Database/migrations/'.self::MIGRATION, "<?php\n");
        $this->linksCode(voids: false);
        $this->git('add', '-A');
        $this->git('commit', '-qm', 'payment links v2');
        $v2 = $this->git('rev-parse', '--short', 'HEAD');

        // The migration named, nothing agreed: stopped, with what changes — and the code is where it was.
        $named = $this->rollBack($before, ['ALLOW_MIGRATIONS' => self::MIGRATION]);
        $this->assertNotSame(0, $named->getExitCode());
        $said = $named->getErrorOutput();
        foreach (['ACCEPT_PAYMENT_LINKS_V1=1', 'أقدم من R1 (v0.140)', 'الأسلم الرجوع إلى v0.140', 'يلغي معه الفاتورة نفسها', 'فيُلغى الاشتراك المنتظر لها', '/pay/link/', 'يُرفض كاملًا ولا يحذفها'] as $words) {
            $this->assertStringContainsString($words, $said);
        }
        $this->assertSame($v2, $this->git('rev-parse', '--short', 'HEAD'));

        // Agreed, the migration not named: the migrations' own stop still holds.
        $agreed = $this->rollBack($before, ['ACCEPT_PAYMENT_LINKS_V1' => '1']);
        $this->assertStringContainsString('migrations came in between', $agreed->getErrorOutput());
        $this->assertSame($v2, $this->git('rev-parse', '--short', 'HEAD'));

        // Both: it goes on to the code (what follows needs the droplet; here it stops at composer).
        $both = $this->rollBack($before, ['ALLOW_MIGRATIONS' => self::MIGRATION, 'ACCEPT_PAYMENT_LINKS_V1' => '1']);
        $this->assertStringContainsString("الكود: $v2 → $before", $both->getOutput());
        $this->assertSame($before, $this->git('rev-parse', '--short', 'HEAD'));
    }

    public function test_going_back_past_payment_links_v2_to_r1_or_later_asks_only_for_the_migration(): void
    {
        file_put_contents($this->dir.'/database/migrations/.keep', '');
        $this->linksCode(voids: false);
        $this->git('init', '-q');
        $this->git('add', '-A');
        $this->git('commit', '-qm', 'v0.140 (R1)');
        $r1 = $this->git('rev-parse', '--short', 'HEAD');
        mkdir($this->dir.'/app/Modules/Payments/Database/migrations', 0777, true);
        file_put_contents($this->dir.'/app/Modules/Payments/Database/migrations/'.self::MIGRATION, "<?php\n");
        $this->git('add', '-A');
        $this->git('commit', '-qm', 'payment links v2');
        $v2 = $this->git('rev-parse', '--short', 'HEAD');

        // The migrations' own stop still holds: the migration is named first.
        $unnamed = $this->rollBack($r1, []);
        $this->assertStringContainsString('migrations came in between', $unnamed->getErrorOutput());
        $this->assertSame($v2, $this->git('rev-parse', '--short', 'HEAD'));

        // Named: it goes on, nothing more asked (what follows needs the droplet; here it stops at composer).
        $named = $this->rollBack($r1, ['ALLOW_MIGRATIONS' => self::MIGRATION]);
        $this->assertStringNotContainsString('ACCEPT_PAYMENT_LINKS_V1', $named->getErrorOutput());
        $this->assertStringContainsString("الكود: $v2 → $r1", $named->getOutput());
        $this->assertSame($r1, $this->git('rev-parse', '--short', 'HEAD'));
    }

    public function test_going_back_between_commits_after_payment_links_v2_asks_nothing_more(): void
    {
        mkdir($this->dir.'/app/Modules/Payments/Database/migrations', 0777, true);
        file_put_contents($this->dir.'/app/Modules/Payments/Database/migrations/'.self::MIGRATION, "<?php\n");
        $this->git('init', '-q');
        $this->git('add', '-A');
        $this->git('commit', '-qm', 'payment links v2');
        $v2 = $this->git('rev-parse', '--short', 'HEAD');
        file_put_contents($this->dir.'/README', "later\n");
        $this->git('add', '-A');
        $this->git('commit', '-qm', 'later');
        $later = $this->git('rev-parse', '--short', 'HEAD');

        $back = $this->rollBack($v2, []);
        $this->assertStringNotContainsString('ACCEPT_PAYMENT_LINKS_V1', $back->getErrorOutput());
        $this->assertStringContainsString("الكود: $later → $v2", $back->getOutput());
    }
}
