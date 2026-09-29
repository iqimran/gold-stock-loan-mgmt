# 08 — Business Rules & Edge Cases

## Interest
1. Interest rate and calculation method are configurable.
2. Each due period is explicit.
3. A missed period is determined by due date + payment allocation, not merely by absence of any payment row.
4. Partial interest payment creates a partially_paid state.
5. Consecutive missed count is calculated over due periods, resetting when the business-defined payment condition is satisfied.
6. Alert threshold is configurable.
7. Alert threshold triggers an alert, not an automatic seizure/default unless separately configured.

## Payments
1. Payment amount must be positive unless an explicit adjustment workflow exists.
2. A payment cannot exceed allowed outstanding balances unless overpayment is explicitly supported.
3. Reversal creates an auditable reversal and recalculates affected balances.
4. Never edit a posted payment's financial amount in place.
5. Receipt numbers are unique.

## Principal
Principal payments reduce outstanding principal.
Interest payments do not reduce principal.
Combined payments must allocate amounts deterministically.

## Collateral
1. Every collateral item belongs to a loan.
2. Released collateral must record release time and actor.
3. Released collateral cannot be silently reused for another loan.
4. Weight and valuation are historical facts; changes require audit.
5. Diamond collateral may not have a meaningful karat field; make it nullable.

## Dates
Define application timezone and date policy in settings.
Use date-only types where a time is not meaningful.

## Customer deletion
Prefer archive/deactivate over hard delete when financial history exists.

## Loan closure
Closing a loan must be an explicit action and must satisfy configured settlement rules.

## Concurrency
Two staff members must not be able to create conflicting payment allocations against the same loan balance. Use DB transactions and appropriate locking where supported.

## Reports
Report totals must use the same domain/query rules as dashboard metrics.

## Alerts
Avoid duplicate alerts for the same loan + interest period + threshold condition.
