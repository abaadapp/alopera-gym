<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Main Gate (.github/scripts/main-gate-verify.sh): main is deployed only when its tree is the tree a pull request's
 * CI passed on. The real script runs here against a stand-in for the GitHub CLI that answers from files, as GitHub
 * answers: the pull request merged as main's commit, its CI runs, their artifacts, the proof inside.
 *
 * GitHub's run list filtered by status is served from an index that can lag well behind a finished run: after #188 was
 * merged (main 77d7dc7), its CI run 44067580847 was completed and successful when listed without the filter, and
 * missing with status=success for over half an hour, so the gate refused a tested tree. The stand-in answers the same
 * way by default (the filtered list empty), and every check the gate makes is still made: a run is taken only when
 * completed, successful, a pull_request run of this head, with an unexpired proof of this pull request, head and run
 * whose tested tree is main's tree. Without GitHub, without a proof, or with one that does not match: not deployed.
 */
class MainGateVerifyTest extends TestCase
{
    private const PR = 188;

    private const HEAD = '69f3816c76a87fbb12f8c091482affd7cf7931fa';

    private const RUN = 44067580847;

    private const ARTIFACT = 14321832214;

    private string $dir;

    private string $fix;

    private string $main;

    private string $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/alopera-main-gate-'.uniqid();
        $this->fix = $this->dir.'/fix';
        mkdir($this->dir.'/repo', 0777, true);
        mkdir($this->fix);
        mkdir($this->dir.'/bin');

        // main: a repository with one commit, whose tree the proof must name.
        $git = fn (string ...$args) => trim((new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', ...$args], $this->dir.'/repo'))->mustRun()->getOutput());
        $git('init', '-q');
        file_put_contents($this->dir.'/repo/a.txt', 'main');
        $git('add', '.');
        $git('commit', '-q', '-m', 'main');
        $this->main = $git('rev-parse', 'HEAD');
        $this->tree = $git('rev-parse', 'HEAD^{tree}');

        file_put_contents($this->dir.'/bin/gh', self::FAKE_GH);
        file_put_contents($this->dir.'/bin/sleep', "#!/bin/sh\nexit 0\n"); // the gate's waits, without waiting
        chmod($this->dir.'/bin/gh', 0755);
        chmod($this->dir.'/bin/sleep', 0755);

        // As GitHub answered for #188: merged as main's commit; one successful CI run, listed only without the status filter.
        $this->json('pulls.json', [['number' => self::PR, 'merged_at' => '2026-11-17T11:02:37Z', 'base' => ['ref' => 'main'], 'merge_commit_sha' => $this->main, 'head' => ['sha' => self::HEAD]]]);
        $this->runs([self::ciRun()]);
        $this->json('runs-filtered.json', ['workflow_runs' => []]);
        $this->artifact(self::RUN, self::ARTIFACT);
        $this->proof(self::ARTIFACT, self::proofOf($this->tree));
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();
        parent::tearDown();
    }

    /** A stand-in for `gh api`: the answer is the file for the path asked, through --jq as gh applies it; each call is logged. */
    private const FAKE_GH = <<<'SH'
#!/usr/bin/env bash
set -u
[ "${1:-}" = api ] || exit 2
shift
path=""; filter=""; status=""
while [ $# -gt 0 ]; do
    case "$1" in
        -X) shift 2 ;;
        -f) case "$2" in status=*) status=${2#status=} ;; esac; shift 2 ;;
        --jq) filter=$2; shift 2 ;;
        *) path=$1; shift ;;
    esac
done
echo "${path} status=${status}" >> "$FIX/calls.log"
case "$path" in
    */commits/*/pulls) file=$FIX/pulls.json ;;
    */actions/workflows/ci.yml/runs)
        n=$(grep -c 'ci.yml/runs' "$FIX/calls.log")
        if [ -n "$status" ]; then file=$FIX/runs-filtered.json
        elif [ -f "$FIX/runs-$n.json" ]; then file=$FIX/runs-$n.json
        else file=$FIX/runs.json; fi ;;
    */actions/runs/*/artifacts) id=${path#*/actions/runs/}; file=$FIX/artifacts-${id%/artifacts}.json ;;
    */actions/artifacts/*/zip) id=${path#*/actions/artifacts/}; file=$FIX/artifact-${id%/zip}.zip
        [ -f "$file" ] || { echo "gh: Not Found (HTTP 404)" >&2; exit 1; }
        cat "$file"; exit 0 ;;
    *) echo "gh: unexpected ${path}" >&2; exit 1 ;;
esac
[ -f "$file" ] || { echo "gh: HTTP 502" >&2; exit 1; }
if [ -n "$filter" ]; then jq -r -c "$filter" < "$file"; else cat "$file"; fi
SH;

    private function json(string $name, array $data): void
    {
        file_put_contents($this->fix.'/'.$name, json_encode($data));
    }

    /** @param list<array<string, mixed>> $runs */
    private function runs(array $runs, ?int $call = null): void
    {
        $this->json($call === null ? 'runs.json' : "runs-{$call}.json", ['total_count' => count($runs), 'workflow_runs' => $runs]);
    }

    private static function ciRun(array $with = []): array
    {
        return $with + ['id' => self::RUN, 'status' => 'completed', 'conclusion' => 'success', 'event' => 'pull_request', 'head_sha' => self::HEAD, 'created_at' => '2026-11-17T10:44:18Z'];
    }

    private function artifact(int $run, int $id, array $with = []): void
    {
        $this->json("artifacts-{$run}.json", ['artifacts' => [$with + ['id' => $id, 'name' => 'pr-proof-'.self::PR."-{$run}-1", 'expired' => false]]]);
    }

    private static function proofOf(string $tree, array $with = []): array
    {
        return $with + ['pr_number' => self::PR, 'head_sha' => self::HEAD, 'base_sha' => str_repeat('b', 40), 'tested_commit_sha' => str_repeat('c', 40), 'tested_tree_sha' => $tree, 'workflow_run_id' => self::RUN, 'workflow_run_attempt' => 1];
    }

    private function proof(int $artifact, array $proof): void
    {
        $zip = new ZipArchive;
        $zip->open($this->fix."/artifact-{$artifact}.zip", ZipArchive::CREATE);
        $zip->addFromString('pr-proof.json', json_encode($proof));
        $zip->close();
    }

    /** @return array{0: int, 1: string} the gate's exit code and what it said */
    private function gate(): array
    {
        $process = new Process(['bash', dirname(__DIR__, 2).'/.github/scripts/main-gate-verify.sh'], $this->dir.'/repo', [
            'PATH' => $this->dir.'/bin:'.getenv('PATH'), 'FIX' => $this->fix, 'GH_REPO' => 'abaadapp/alopera-gym',
            'MAIN_SHA' => $this->main, 'GITHUB_STEP_SUMMARY' => $this->dir.'/summary.md',
        ]);
        $process->setTimeout(60)->run();

        return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
    }

    private function assertDeployed(array $gate): void
    {
        [$code, $said] = $gate;
        $this->assertSame(0, $code, $said);
        $this->assertStringContainsString('✓ main '.$this->main.' is the tree #'.self::PR.' passed CI on (run '.self::RUN.')', $said);
    }

    private function assertRefused(array $gate, string $reason): void
    {
        [$code, $said] = $gate;
        $this->assertSame(1, $code, $said);
        $this->assertStringContainsString('::error title=Main Gate::not deployed: '.$reason, $said);
        $this->assertStringNotContainsString('✓ main', $said);
    }

    private function calls(string $path): int
    {
        return substr_count((string) @file_get_contents($this->fix.'/calls.log'), $path);
    }

    public function test_a_successful_run_github_lists_only_without_the_status_filter_is_found(): void
    {
        // #188: completed and successful, but not in the status-filtered list yet.
        $this->assertDeployed($this->gate());
    }

    public function test_a_run_github_has_not_listed_yet_is_asked_for_again_then_found(): void
    {
        $this->runs([], 1);
        $this->runs([], 2);

        $this->assertDeployed($this->gate());
        $this->assertSame(3, $this->calls('ci.yml/runs'), 'asked again until it was listed');
    }

    public function test_a_run_never_listed_is_refused_after_asking_again(): void
    {
        $this->runs([]);

        $this->assertRefused($this->gate(), 'no successful CI run for the head of #188 ('.self::HEAD.')');
        $this->assertGreaterThan(1, $this->calls('ci.yml/runs'));
        $this->assertSame(0, $this->calls('/artifacts'), 'no proof read for a run that is not there');
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function runsThatAreNotEnough(): array
    {
        return [
            'still running' => [['status' => 'in_progress', 'conclusion' => null]],
            'queued' => [['status' => 'queued', 'conclusion' => null]],
            'failed' => [['conclusion' => 'failure']],
            'cancelled' => [['conclusion' => 'cancelled']],
            'skipped' => [['conclusion' => 'skipped']],
            'not a pull request run' => [['event' => 'push']],
            'another head' => [['head_sha' => str_repeat('d', 40)]],
        ];
    }

    #[DataProvider('runsThatAreNotEnough')]
    public function test_a_run_that_is_not_a_finished_successful_run_of_this_head_is_not_taken(array $run): void
    {
        // Its proof would even name main's tree: the run itself is not enough.
        $this->runs([self::ciRun($run)]);

        $this->assertRefused($this->gate(), 'no successful CI run for the head of #188');
        $this->assertSame(0, $this->calls('/artifacts'));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function proofsThatDoNotMatch(): array
    {
        return [
            'another pull request' => [['pr_number' => 187]],
            'another head' => [['head_sha' => str_repeat('e', 40)]],
            'another run' => [['workflow_run_id' => 44000000001]],
        ];
    }

    #[DataProvider('proofsThatDoNotMatch')]
    public function test_a_proof_of_another_pull_request_head_or_run_is_refused(array $proof): void
    {
        $this->proof(self::ARTIFACT, self::proofOf($this->tree, $proof));

        [$code, $said] = $this->gate();
        $this->assertRefused([$code, $said], "main's tree {$this->tree} is not a tree any passing CI run of #188 tested");
        $this->assertStringContainsString('the proof is for another pull request, head or run', $said);
    }

    public function test_a_proof_of_another_tree_is_refused_main_moved_before_the_merge(): void
    {
        $this->proof(self::ARTIFACT, self::proofOf(str_repeat('f', 40)));

        $this->assertRefused($this->gate(), "main's tree {$this->tree} is not a tree any passing CI run of #188 tested (main moved before the merge?)");
    }

    public function test_an_older_successful_run_of_another_tree_is_not_taken_for_main(): void
    {
        // Two successful runs of this head: the newer tested another mix (main moved), the older a tree older still.
        $this->runs([self::ciRun(), self::ciRun(['id' => 44000000002, 'created_at' => '2026-11-17T09:00:00Z'])]);
        $this->proof(self::ARTIFACT, self::proofOf(str_repeat('f', 40)));
        $this->json('artifacts-44000000002.json', ['artifacts' => [['id' => 9002, 'name' => 'pr-proof-188-44000000002-1', 'expired' => false]]]);
        $this->proof(9002, self::proofOf(str_repeat('a', 40), ['workflow_run_id' => 44000000002]));

        $this->assertRefused($this->gate(), "main's tree {$this->tree} is not a tree any passing CI run of #188 tested");

        // The older one did test main's tree: it is taken, the newest first notwithstanding.
        $this->proof(9002, self::proofOf($this->tree, ['workflow_run_id' => 44000000002]));
        [$code, $said] = $this->gate();
        $this->assertSame(0, $code, $said);
        $this->assertStringContainsString('(run 44000000002)', $said);
    }

    public function test_without_a_proof_an_expired_one_or_an_unreadable_one_it_is_refused(): void
    {
        $refused = "main's tree {$this->tree} is not a tree any passing CI run of #188 tested";

        $this->artifact(self::RUN, self::ARTIFACT, ['expired' => true]);
        $this->assertRefused($this->gate(), $refused);

        $this->json('artifacts-'.self::RUN.'.json', ['artifacts' => []]);
        $this->assertRefused($this->gate(), $refused);

        $this->artifact(self::RUN, self::ARTIFACT);
        file_put_contents($this->fix.'/artifact-'.self::ARTIFACT.'.zip', 'not a zip');
        [$code, $said] = $this->gate();
        $this->assertRefused([$code, $said], $refused);
        $this->assertStringContainsString('the proof could not be downloaded or read', $said);
    }

    public function test_without_github_nothing_is_deployed(): void
    {
        unlink($this->fix.'/runs.json'); // the runs cannot be read
        $this->assertRefused($this->gate(), 'cannot ask GitHub for the CI runs of #188');

        unlink($this->fix.'/pulls.json'); // nor the pull request
        $this->assertRefused($this->gate(), 'no pull request was merged into main as '.$this->main);
    }

    public function test_a_direct_push_with_no_merged_pull_request_is_refused(): void
    {
        $this->json('pulls.json', []);

        $this->assertRefused($this->gate(), 'no pull request was merged into main as '.$this->main.' (a direct push?)');
        $this->assertSame(0, $this->calls('ci.yml/runs'));
    }
}
