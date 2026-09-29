# 06 — Frontend Plan

Follow the existing Inventory POS design system.

## Layout
- responsive navbar/sidebar
- breadcrumbs
- page title/action area
- filter toolbar
- table
- pagination
- empty/loading/error states
- toast notifications
- modal/drawer forms

## Main routes
/dashboard
/customers
/customers/:id
/loans
/loans/create
/loans/:id
/loans/:id/edit
/payments
/payments/:id
/reports/collections
/reports/due
/reports/customer-ledger
/reports/loan-outstanding
/reports/collateral
/expenses
/users
/roles
/settings

## Customer list
Columns:
- customer
- mobile
- active loans
- interest due
- consecutive missed
- last payment
- next due
- actions

## Loan list
Columns:
- loan no
- customer
- principal
- outstanding
- rate
- status
- next due
- actions

## Loan detail
Header:
- loan number
- customer
- status
- principal/outstanding
- next due
- actions

Tabs:
- Overview
- Collateral
- Payments
- Summary

## Payment UX
- choose customer/loan
- display current due/outstanding
- payment type
- amount
- method
- date
- optional reference
- confirmation
- receipt preview/print

Never present a frontend-calculated amount as authoritative. Display server response after save.

## Tables
Reusable DataTable component:
- server pagination
- search
- sort
- filters
- column visibility if existing system supports it
- export action
- responsive behavior

## Accessibility
- keyboard navigable
- labels for controls
- visible validation
- semantic buttons/links
- adequate focus states
