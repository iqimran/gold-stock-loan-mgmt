# 04 — API Contract

Use the existing API conventions (prefix, authentication, response envelope, pagination format, validation errors).

## Customers
GET /customers
POST /customers
GET /customers/{customer}
PUT/PATCH /customers/{customer}
DELETE/ARCHIVE /customers/{customer}
GET /customers/{customer}/loans
GET /customers/{customer}/ledger
GET /customers/{customer}/payments

Filters:
- search
- status
- date range
- overdue
- missed threshold

## Loans
GET /loans
POST /loans
GET /loans/{loan}
PUT/PATCH /loans/{loan}
POST /loans/{loan}/close
POST /loans/{loan}/cancel
GET /loans/{loan}/collateral
GET /loans/{loan}/payments
GET /loans/{loan}/ledger
GET /loans/{loan}/interest-periods

Filters:
- customer
- status
- date range
- due status
- overdue
- rate

## Collateral
POST /loans/{loan}/collateral
GET /collateral/{item}
PUT/PATCH /collateral/{item}
POST /collateral/{item}/release

## Payments
GET /payments
POST /payments
GET /payments/{payment}
POST /payments/{payment}/reverse
GET /payments/{payment}/receipt

## Dashboard
GET /dashboard/loan-summary
GET /dashboard/collections
GET /dashboard/due-interest
GET /dashboard/overdue-accounts
GET /dashboard/missed-payment-alerts

## Reports
GET /reports/collections
GET /reports/due
GET /reports/customer-ledger
GET /reports/loan-outstanding
GET /reports/collateral
GET /reports/expenses

Export pattern should follow the existing application. Heavy exports should be queued if necessary.

## API rules
- Validate every write request.
- Return stable error structures.
- Use authorization middleware/policies.
- Never trust frontend calculated balances.
- Idempotency should be considered for payment creation.
- Return public reference numbers for receipts/loans where appropriate.
