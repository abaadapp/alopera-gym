# Alopera Gym AI Command Center

The shared planning and execution-control layer for Alopera Gym.

## Roles

### ChatGPT: product and architecture lead
- product planning, prioritization, architecture guidance, technical decisions
- implementation briefs (the issue's Prompt), acceptance criteria, review checklists, risk analysis
- maintaining the files in this directory when the project owner asks

A task is not implemented because it was planned: its status is checked in the repository.

### Claude Code: executor
1. Reads `CLAUDE.md` in the repository root and the relevant files here.
2. Implements only the approved, current task scope.
3. Preserves the existing architecture unless a change is asked for.
4. Runs the tests and checks.
5. Reports what changed, the tests run, failures, risks and deviations (`EXECUTION_PROTOCOL.md`).

It never merges. Merging into `main` deploys to production (docs/DEPLOY.md), and only the owner decides it.

### The owner
Approves each task and each merge.

## Files

| File | What it holds |
|---|---|
| `ACTIVE_TASK.md` | The task being executed now |
| `BACKLOG.md` | Tasks waiting, by priority |
| `DECISIONS.md` | Decisions taken, with their date and reason |
| `MASTER_PLAN.md` | The plan and its phases |
| `EXECUTION_PROTOCOL.md` | Claude Code's operating rules and completion report |

Each task is also a GitHub issue (`.github/ISSUE_TEMPLATE/task.yml`). The label `claude:ready` has Claude Code execute
it (`.github/workflows/claude-code-agent.yml`). The working rules are in `docs/PARALLEL_WORKFLOW.md`.
