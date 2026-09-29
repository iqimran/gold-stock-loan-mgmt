# 11 — Critical UX Flows

## New customer + loan
Customer → Create customer → Create loan → Add collateral → Review summary → Save → Loan detail.

## Receive monthly interest
Loan detail → Payments → Add payment → Select interest → Enter amount/method/date → Server validates → Save → Receipt → Print.

## Combined payment
Payment → Select principal + interest → Server allocates amount according to configured/business rule → Save → Receipt.

## Missed interest
Scheduler generates due period → due date passes unpaid → period becomes overdue → consecutive missed count updates → alert generated when threshold is reached → dashboard/report shows alert.

## Loan closure
Loan detail → Close → server verifies settlement rules → confirm → status closed → collateral becomes eligible for release workflow.

## Collateral release
Loan detail → Collateral → Release → confirmation/reason → record actor/time → item released.

## Payment reversal
Payment detail → Reverse → permission check → reason required → create reversal/audit → recompute affected balances → receipt/history remains visible.
