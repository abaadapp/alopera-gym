# Decisions

Each decision, with its date and reason. Newest first.

| Date | Decision | Reason |
|---|---|---|
| 2026-11-08 | Deploy: tests on the pull request, a gate on main, GitHub Actions deploys over SSH, a tag after the deploy, rollback by hand, no database dump before the deploy (docs/DEPLOY.md) | The owner asked for the same deploy steps as the owner's other product |
| 2026-11-08 | Tasks: one GitHub issue per task (`task.yml`), `claude:ready` for Claude Code, one branch and one pull request, merge only by the owner (docs/PARALLEL_WORKFLOW.md) | The same task and merge workflow as the owner's other product |
