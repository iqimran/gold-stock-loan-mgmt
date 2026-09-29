# Task 007 — Interest Engine
## Goal
Implement configurable interest calculation and explicit interest periods.

## Scope
- calculation service
- period generation
- rounding
- due/overdue state
- idempotent scheduler/job

## Acceptance
- unit tests cover date/rate/rounding boundaries
- repeated generation does not duplicate periods
- overdue state is deterministic
