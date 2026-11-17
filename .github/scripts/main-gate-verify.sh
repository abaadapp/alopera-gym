#!/usr/bin/env bash
# Main Gate: main is deployed only when its tree is the tree a pull request's CI passed on. The tests are not run again.
#
# The tree, not the commit: a merge makes a new commit on main that was never tested itself, but CI tested
# refs/pull/N/merge — main at that moment with the pull request merged in. If main's tree now is that very tree, what
# will be deployed is what passed, file for file. If main moved in between (another pull request merged first), the
# mix is new and untested, and it is not deployed by itself.
#
# Read-only on GitHub (gh api GETs), so it can be tried by hand on the real repository:
#   GH_REPO=abaadapp/alopera-gym MAIN_SHA=$(git rev-parse origin/main) bash .github/scripts/main-gate-verify.sh
# Exit 0 when proven; otherwise a plain reason, and no deploy.
set -euo pipefail
: "${GH_REPO:?set GH_REPO=owner/repo}"
: "${MAIN_SHA:?set MAIN_SHA}"
SUMMARY=${GITHUB_STEP_SUMMARY:-/dev/null}

refuse() {
    echo "::error title=Main Gate::not deployed: $1"
    {
        echo "### ⛔ Main Gate"
        echo
        echo "**لا يُنشر تلقائيًا:** $1"
        echo
        echo "للنشر: حدّث فرع الطلب من main (فيُعاد CI على المزيج الجديد) ثم ادمجه، أو شغّل «Deploy Main» يدويًا عن قصد."
    } >> "$SUMMARY"
    exit 1
}

main_tree=$(git rev-parse --verify "${MAIN_SHA}^{tree}") || refuse "cannot read the tree of ${MAIN_SHA}"
echo "main ${MAIN_SHA} → tree ${main_tree}"

# 1) The pull request whose merge commit is this very commit (GitHub may link it a few seconds after the push).
pr=""
for attempt in 1 2 3 4 5 6; do
    pr=$(gh api "repos/${GH_REPO}/commits/${MAIN_SHA}/pulls" \
        --jq "[.[] | select(.merged_at != null and .base.ref == \"main\" and .merge_commit_sha == \"${MAIN_SHA}\")] | first // empty" \
        2>/dev/null) || pr=""
    [ -n "$pr" ] && break
    echo "attempt ${attempt}: no merged pull request linked yet"
    sleep 10
done
[ -n "$pr" ] || refuse "no pull request was merged into main as ${MAIN_SHA} (a direct push?)"
number=$(jq -r '.number' <<<"$pr")
head=$(jq -r '.head.sha' <<<"$pr")
echo "pull request #${number}, head ${head}"

# 2) Its successful CI runs (pull_request event), newest first. Listed without GitHub's status filter: that filter is
#    served from an index that can lag well behind a finished run (#188's run 44067580847, completed and successful,
#    was missing from status=success for over half an hour, and the gate refused a tested tree). Each run listed is
#    checked here instead: completed, successful, a pull_request run of this very head. None yet: GitHub is asked again
#    a few times (a run not listed yet), and then refused; never deployed on less, nor when GitHub cannot be asked.
runs=""
for attempt in 1 2 3 4 5 6; do
    runs=$(gh api -X GET "repos/${GH_REPO}/actions/workflows/ci.yml/runs" \
        -f event=pull_request -f head_sha="$head" -f per_page=100 \
        --jq ".workflow_runs | sort_by(.created_at) | reverse | .[]
            | select(.status == \"completed\" and .conclusion == \"success\" and .event == \"pull_request\" and .head_sha == \"${head}\")
            | .id") \
        || refuse "cannot ask GitHub for the CI runs of #${number}"
    [ -n "$runs" ] && break
    echo "attempt ${attempt}: no completed successful CI run of ${head} listed yet"
    sleep 10
done
[ -n "$runs" ] || refuse "no successful CI run for the head of #${number} (${head})"

# 3) A run whose proof names this pull request, this head, this run, and main's tree.
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
for run in $runs; do
    artifact=$(gh api "repos/${GH_REPO}/actions/runs/${run}/artifacts" \
        --jq "[.artifacts[] | select(.expired == false and (.name | startswith(\"pr-proof-${number}-${run}-\")))] | first | .id // empty") \
        || artifact=""
    if [ -z "$artifact" ]; then
        echo "run ${run}: no proof uploaded (older than the pr-proof job, or expired)"
        continue
    fi
    mkdir -p "${work}/${run}"
    if ! gh api "repos/${GH_REPO}/actions/artifacts/${artifact}/zip" > "${work}/${run}/proof.zip" \
        || ! unzip -q -o "${work}/${run}/proof.zip" -d "${work}/${run}" \
        || [ ! -f "${work}/${run}/pr-proof.json" ]; then
        echo "run ${run}: the proof could not be downloaded or read"
        continue
    fi
    proof="${work}/${run}/pr-proof.json"
    if [ "$(jq -r '.pr_number' "$proof")" != "$number" ] || [ "$(jq -r '.head_sha' "$proof")" != "$head" ] \
        || [ "$(jq -r '.workflow_run_id' "$proof")" != "$run" ]; then
        echo "run ${run}: the proof is for another pull request, head or run"
        continue
    fi
    tested=$(jq -r '.tested_tree_sha' "$proof")
    if [ "$tested" = "$main_tree" ]; then
        echo "✓ main ${MAIN_SHA} is the tree #${number} passed CI on (run ${run})"
        {
            echo "### ✓ Main Gate"
            echo
            echo "شجرة main هي الشجرة التي اجتازت CI الطلب #${number} (التشغيل ${run}): \`${main_tree}\`"
        } >> "$SUMMARY"
        exit 0
    fi
    echo "run ${run}: tested tree ${tested} is not main's tree ${main_tree}"
done

refuse "main's tree ${main_tree} is not a tree any passing CI run of #${number} tested (main moved before the merge?)"
