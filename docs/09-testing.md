# 09 — Testing Strategy

## Unit tests
- interest calculations
- rate/period conversions
- rounding
- consecutive missed-period calculation
- payment allocation
- loan balance calculation
- status transitions

## Integration/feature tests
- create customer
- create loan
- add collateral
- generate interest period
- post interest payment
- post principal payment
- combined payment
- partial payment
- reverse payment
- close loan
- release collateral
- authorization failures
- report filters
- export authorization

## Security tests
- unauthorized API access
- permission bypass attempts
- invalid IDs
- mass assignment
- file upload validation
- rate limiting where applicable

## UI tests
At minimum for critical flows:
- login
- customer create/search
- loan create
- collateral add
- payment create
- receipt print
- report filter/export

## Acceptance
A task is complete only when:
- code follows project conventions
- tests pass
- no unrelated files are changed
- migration/API changes are documented
- authorization is enforced
