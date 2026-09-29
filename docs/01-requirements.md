# 01 — Functional Requirements

## Customer
Fields:
- Name
- Mobile
- NID
- Image (optional)
- Address
- Total Interest Due (derived)
- Consecutive Missed (derived)
- Last Payment (derived)
- Next Due Date (derived)

Features:
- Create/update/view/archive
- Search by name/mobile/NID
- Customer detail page
- Active loans
- Collateral summary
- Payment history
- Ledger
- Print customer statement
- Export filtered customer data

## Loan
Fields:
- Loan number/reference
- Customer
- Principal
- Remaining Principal
- Interest Rate
- Interest Rate Type / Period
- Status
- Start Date
- Next Due Date
- Notes

Recommended statuses:
- draft
- active
- overdue
- closed
- cancelled

Do not hard-code status transitions; centralize them in a domain service.

## Loan details
Tabs/sections:
1. Overview
2. Collateral
3. Payments
4. Summary / Ledger

### Collateral item
- Type: gold, diamond, mixed/other (configurable)
- Weight in grams
- Karat/purity where applicable
- Estimated value
- Description
- Optional item/reference number
- Status
- Received date
- Returned/released date
- Photo/document attachments if supported by the base application

A collateral item must not be deleted after a financial transaction. Use archival/reversal patterns.

## Payments
- Receipt number
- Customer
- Loan
- Payment type
- Amount
- Payment method
- Date
- Notes/reference
- Created by

Recommended payment types:
- interest
- principal
- principal_and_interest
- other_fee
- adjustment

Payment method should be configurable:
- cash
- bank
- mobile_banking
- card
- other

Features:
- Create payment
- Receipt preview
- Print receipt
- View receipt
- Search/filter
- Reverse/void with permission and audit trail
- Customer ledger update

## Interest periods
Create a dedicated interest-period concept rather than deriving missed payments only from payment rows.

Each period should track:
- loan
- period start
- period end
- due date
- expected interest
- paid interest
- status
- paid_at
- grace/waiver if the business later requires it

Recommended statuses:
- upcoming
- due
- partially_paid
- paid
- overdue
- waived

This makes missed-period counting deterministic.

## Dashboard
Cards/metrics:
- Active Loans
- Monthly Collection / Revenue
- Due Interest
- Overdue Accounts
- Outstanding Loan Amount
- Missed Payment Alerts

Dashboard values must have a defined date range and timezone.

## Reports
- Collection Report
- Due Report
- Customer Ledger
- Loan Outstanding Report
- Overdue/Missed Interest Report
- Collateral Inventory Report
- Expense Report
- Optional daily closing report

All reports:
- filters
- pagination where applicable
- PDF export
- Excel export
- print-friendly view

## Users/Roles/Permissions
Reuse existing RBAC conventions. Suggested permissions:
- customers.view/create/update/archive
- loans.view/create/update/close/cancel
- collateral.view/create/update/release
- payments.view/create/reverse
- reports.view/export
- users.manage
- roles.manage
- settings.manage
- audit.view

## Settings
At minimum:
- currency
- shop/business information
- receipt numbering
- loan numbering
- default interest rate
- interest calculation mode
- due-day policy
- missed-period alert threshold
- grace period
- payment methods
- collateral types
- karat/purity options
- report/receipt template settings
