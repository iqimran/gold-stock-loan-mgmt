# 00 — Project Definition

## Product
Gold Stock & Loan Management System for a Jewellery Shop.

## Objective
Manage customers who deposit gold/diamond collateral and receive loans, calculate and collect recurring interest, track missed interest payments, preserve collateral records, manage expenses/users/settings, and produce operational/financial reports.

## Primary users
- Owner / Administrator
- Manager
- Accountant 

## Core modules
1. Authentication
2. Dashboard
3. Customers
4. Loans
5. Collateral
6. Payments / Receipts
7. Customer Ledger
8. Reports / Exports
9. Users / Roles / Permissions
10. Settings
11. Audit Log
12. Notifications / overdue monitoring

## Key business invariant
A loan can have one or more collateral items. Collateral must remain traceable from intake through release/return. Payments must never silently alter historical financial records.

## Interest reminder rule
The shop can configure the number of consecutive missed interest periods that trigger an alert (default example: 2, but must be configurable). The system should track missed periods and alert when the configured threshold is reached.

Do not automatically mark a loan as defaulted or seize collateral merely because an alert threshold is reached. That requires an explicit business rule/action.

## Currency and precision
Use configurable currency. Monetary values use fixed decimal precision, never floating-point arithmetic. Gold weight uses decimal precision suitable for jewellery operations (recommended DECIMAL(12,3) grams; adjust if the existing POS system uses another standard).

## Non-functional requirements
- Responsive web UI
- Dockerized development and deployment
- Secure authentication and authorization
- Server-side validation
- Auditability
- Pagination and filtering
- PDF and Excel exports
- Printable customer receipt/invoice slip
- Reliable transactional financial operations
- Automated tests
- Maintainable modular architecture
