# 10 — Reporting Specification

## Collection Report
Purpose: money collected within a period.

Filters:
- date range
- payment method
- payment type
- staff
- loan
- customer

Columns:
- date
- receipt
- customer
- loan
- payment type
- method
- amount
- staff

Totals:
- gross collected
- totals by payment type
- totals by method

## Due Report
Purpose: upcoming/due/overdue interest.

Filters:
- due date range
- status
- missed threshold
- customer
- loan

Columns:
- customer
- mobile
- loan
- expected interest
- paid interest
- balance due
- due date
- missed count
- status

## Customer Ledger
Chronological financial history for a customer.

Include:
- date
- reference
- loan
- description
- debit
- credit
- running balance

## Loan Outstanding
- loan
- customer
- principal
- outstanding principal
- due interest
- total exposure
- next due
- status

## Collateral Report
- collateral no
- loan
- customer
- type
- weight
- karat
- estimated value
- status
- received/released dates

## Export
PDF:
- shop header
- report title
- filter summary
- generated timestamp
- page numbering
- totals

Excel:
- structured columns
- filters
- totals where appropriate
- correct decimal formats
