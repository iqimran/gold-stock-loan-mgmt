# 02 — Architecture

## Principle
Use the same frontend/backend conventions as the existing Inventory POS application. Do not introduce a second architecture merely for this product.

## Recommended layers

### Backend
- HTTP/API layer: controllers, request validation, resources
- Application layer: use cases/services
- Domain layer: loan, interest, collateral and payment rules
- Persistence layer: models/repositories/query objects
- Infrastructure: queues, exports, PDF, storage, notifications

### Frontend
- App shell/layout
- route-level pages
- reusable data tables
- filters
- forms
- drawers/modals
- confirmation dialogs
- printable receipt/report views
- API/query layer
- permissions-aware UI

## Financial domain rule
Controllers should not contain financial calculations. Use dedicated services such as:
- LoanService
- InterestScheduleService
- InterestCalculationService
- PaymentService
- LoanSettlementService
- CollateralService
- CustomerLedgerService
- DashboardMetricsService
- ReportService

## Transactions
Creating a payment should be atomic:
1. validate loan/payment
2. create payment
3. allocate payment
4. update interest period(s)
5. update loan balances
6. update ledger entries
7. update derived/customer metrics
8. write audit event
9. commit

If any step fails, the financial transaction rolls back.

## Money
Never use binary floating-point for money. Use database DECIMAL and application-level decimal arithmetic.

## Audit
Financial mutations must capture:
- actor
- timestamp
- action
- entity
- entity id
- before/after or structured change payload
- reason for reversal/void where applicable

## Background jobs
Use jobs/scheduler for:
- generating/updating interest periods
- detecting overdue periods
- generating missed-payment alerts
- optional notification delivery
- heavy report exports

Jobs must be idempotent.

## Security
- CSRF/authentication according to stack
- authorization on server
- rate limiting for sensitive auth endpoints
- validation and sanitization
- secure file upload handling
- no financial authority in frontend
- no exposing internal IDs unnecessarily when public references are available
