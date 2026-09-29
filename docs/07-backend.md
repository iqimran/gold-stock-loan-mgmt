# 07 — Backend Implementation Plan

## Domain services

### InterestCalculationService
Responsible for calculating expected interest using configured rules.

Inputs:
- principal/outstanding principal
- rate
- rate type
- period
- applicable dates/settings

Output:
- expected interest

Must have unit tests for rounding and boundary dates.

### InterestScheduleService
Responsible for creating and updating interest periods.

Must be idempotent.

### PaymentService
Responsible for:
- validation
- payment creation
- allocation
- loan balance update
- interest-period update
- ledger entries
- audit
- receipt number

Use DB transaction.

### LoanService
Responsible for:
- loan creation
- status changes
- closing/cancellation rules
- outstanding calculations

### CollateralService
Responsible for:
- add/update
- release
- status transitions
- audit

### CustomerLedgerService
Responsible for immutable-ish ledger events and balance reconstruction.

### DashboardMetricsService
Centralize dashboard queries to avoid duplicating financial logic.

### ReportService
Centralize filters and export data contracts.

## Scheduled processing
Recommended scheduler jobs:
- GenerateInterestPeriodsJob
- MarkInterestPeriodsDueJob
- DetectOverdueLoansJob
- GenerateMissedPaymentAlertsJob

The exact framework command/scheduler syntax must follow the existing application.

## Rounding
Define one rounding policy in settings/domain logic. Never independently round in controllers, frontend and reports.

## Closing a loan
A loan should only be closed when business-defined settlement conditions are met. Typical condition:
- outstanding principal = 0
- no unpaid interest/fees

But this must remain configurable/documented rather than assumed.
