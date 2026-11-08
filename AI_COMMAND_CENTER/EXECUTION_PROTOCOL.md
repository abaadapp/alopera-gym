# Execution Protocol

## Claude Code operating rules

When executing a task prepared through the AI Command Center:

1. Read `AI_COMMAND_CENTER/ACTIVE_TASK.md`, or the task issue, completely.
2. Inspect the actual existing implementation before editing.
3. Do not blindly follow stale file paths, class names or assumptions in a brief; reconcile them with the current repository.
4. Stay within scope. If a prerequisite or an architectural conflict turns up, stop and report it rather than silently expanding scope.
5. Preserve club (tenant) isolation, branch isolation, permissions, financial correctness, stock integrity and backward compatibility, unless the task explicitly changes them.
6. Do not delete or bypass tests merely to make CI pass.
7. Add or update tests for changed behavior.
8. Run the most relevant automated checks in the repository: `php artisan test`, `npm test`, `npx tsc --noEmit -p tsconfig.json`, `vendor/bin/pint --test`, `npm run build`.
9. Do not introduce secrets or production credentials.
10. Avoid unrelated refactors in the same task.
11. Never merge: merging into `main` deploys to production, and only the owner decides it.

## Completion report

After implementation, report:

- Summary
- Files changed
- Database/migration impact
- API impact
- UI impact
- Tests added/updated
- Commands/checks run and results
- Known limitations
- Deviations from the task brief
- Follow-up recommendations

## Planning boundary

Claude Code may identify problems and suggest improvements. It does not turn suggestions or backlog items into
implementation work unless the project owner explicitly authorizes it.
