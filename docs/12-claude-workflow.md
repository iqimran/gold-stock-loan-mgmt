# 12 — Claude Code Workflow

## Golden rule
One task = one implementation unit.

### Prompt template
Read:
- docs/00-project.md
- docs/02-architecture.md
- docs/03-database.md
- docs/04-api.md
- docs/05-auth.md
- docs/06-frontend.md
- docs/07-backend.md
- docs/08-business-rules.md
- docs/tasks/<TASK>.md

Implement only <TASK>.

Constraints:
1. Inspect existing code before creating new abstractions.
2. Reuse existing POS components/services/patterns.
3. Do not modify unrelated code.
4. Do not change public APIs unless the task requires it.
5. Add tests required by the task.
6. Keep financial operations transactional.
7. Keep authorization server-side.
8. Do not use floating-point arithmetic for money.
9. Run relevant tests/lint/type checks.
10. Summarize changed files and verification.

## If blocked
Stop and report:
- what was inspected
- exact blocker
- smallest missing decision/input
Do not invent a business rule.

## Completion response format
- Task
- Implemented
- Files changed
- Tests/checks
- Notes / follow-up
