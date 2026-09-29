# 03 — Database Design

Use the existing POS users/roles/permissions/settings/expenses conventions where available. Add only missing tables/columns.

## Core tables

### customers
- id
- customer_no unique
- name
- mobile
- nid nullable
- image_path nullable
- address nullable
- status
- created_by
- timestamps
- soft_deleted_at if the existing system uses soft deletion

Indexes:
- customer_no
- mobile
- nid
- name

### loans
- id
- loan_no unique
- customer_id FK
- principal DECIMAL
- outstanding_principal DECIMAL
- interest_rate DECIMAL
- interest_rate_type
- interest_period_unit
- status
- start_date
- next_due_date nullable
- closed_at nullable
- notes nullable
- created_by
- timestamps

Indexes:
- customer_id
- status
- next_due_date
- start_date

### collateral_items
- id
- collateral_no unique
- loan_id FK
- type
- weight_grams DECIMAL(12,3)
- karat DECIMAL(5,2) nullable
- estimated_value DECIMAL
- description nullable
- status
- received_at
- released_at nullable
- created_by
- timestamps

Indexes:
- loan_id
- type
- status
- collateral_no

### interest_periods
- id
- loan_id FK
- period_start
- period_end
- due_date
- expected_interest DECIMAL
- paid_interest DECIMAL default 0
- status
- paid_at nullable
- waived_at nullable
- waiver_reason nullable
- timestamps

Unique constraint:
- loan_id + period_start + period_end

Indexes:
- loan_id + due_date
- status + due_date

### payments
- id
- receipt_no unique
- customer_id FK
- loan_id FK
- type
- amount DECIMAL
- method
- payment_date
- reference nullable
- notes nullable
- status
- created_by
- reversed_by nullable
- reversed_at nullable
- reversal_reason nullable
- timestamps

Indexes:
- customer_id + payment_date
- loan_id + payment_date
- type
- status

### payment_allocations
Use this if one payment can cover multiple components.
- id
- payment_id FK
- interest_period_id nullable
- principal_amount DECIMAL
- interest_amount DECIMAL
- fee_amount DECIMAL
- total_amount DECIMAL

### ledger_entries
- id
- customer_id FK
- loan_id nullable
- payment_id nullable
- entry_type
- debit DECIMAL
- credit DECIMAL
- balance_after DECIMAL
- entry_date
- description
- reference
- created_by
- timestamps

### loan_events
Optional but recommended:
- id
- loan_id
- event_type
- event_date
- payload JSON
- actor_id
- timestamps

### alerts
- id
- customer_id nullable
- loan_id nullable
- type
- threshold
- message
- status
- triggered_at
- resolved_at nullable

## Relationships
Customer 1—N Loans
Loan 1—N CollateralItems
Loan 1—N InterestPeriods
Loan 1—N Payments
Payment 1—N PaymentAllocations
Customer 1—N LedgerEntries
Loan 1—N LedgerEntries
Loan 1—N LoanEvents

## Derived values
Do not persist customer totals unless there is a clear performance requirement. Prefer queries/materialized summaries:
- total interest due
- consecutive missed periods
- last payment
- next due date

If cached, define invalidation/rebuild rules.

## Indexing
Every foreign key and common filter field should be indexed. Use composite indexes for dashboard/report queries based on real query patterns.

## Migration safety
- backward-compatible migrations
- no destructive changes without explicit migration task
- seed only safe/demo data
