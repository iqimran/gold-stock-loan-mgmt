# 00 — Technology Stack

## 1. Stack Decision

Before implementing any feature, inspect the existing project and reuse its:

* programming language
* backend framework
* frontend framework
* database
* authentication
* authorization/RBAC
* UI component library
* CSS framework
* API conventions
* state management
* validation
* file storage
* PDF generation
* Excel export
* testing framework
* Docker configuration
* CI/CD conventions

Do NOT introduce a second framework or replace an existing technology without an explicit requirement.

---

# 2. Target Technology Stack

## Recommended architecture
Laravel 12 modular monolith with React + Inertia + TypeScript.

## Recommended frontend architecture

Use:

* component-based architecture
* reusable forms
* reusable DataTable
* reusable modal/dialog components
* reusable filters
* reusable pagination
* reusable API/query layer
* responsive layouts

---

# 4. Styling / UI

Reuse:

* buttons
* inputs
* selects
* modals
* dropdowns
* tables
* cards
* alerts
* badges
* pagination
* navigation
* form components
---

# 5. Database

## Primary Database

PostgreSQL.

Recommended:
* PostgreSQL 16+

Use database transactions for all financial operations.

Important financial fields must use fixed decimal database types.

Examples:

```text
money:
DECIMAL(18,2)

gold weight:
DECIMAL(12,3)

interest rate:
DECIMAL(8,4)
```

Actual precision must follow the existing application's conventions and business requirements.

Never use floating-point database fields for financial amounts.

---

# 6. ORM

Use Laravel Eloquent if the existing backend is Laravel.

Use:

* Models
* Relationships
* Query scopes
* Form Requests
* Policies
* Services
* Resources
* Jobs
* Events/listeners where appropriate

Do not place complex financial calculations inside controllers.

---

# 7. Authentication

Reuse the existing Inventory POS authentication system.

Required functionality:

* Login
* Logout
* Forgot Password
* Reset Password
* Session/token handling
* Password hashing
* Authentication middleware

Do not create a separate authentication system.

---

# 8. Authorization

Use the existing RBAC implementation.

The system must support:

* Users
* Roles
* Permissions
* Policies/middleware

Financial operations must be permission-protected.

Sensitive actions include:

* Payment creation
* Payment reversal
* Loan cancellation
* Loan closure
* Collateral release
* Report export
* Settings changes
* User/role management

Backend authorization is always authoritative.

---

# 10. State Management

Use the state/query management approach already used by the Inventory POS.

Prefer server-state/query management for:

* Customers
* Loans
* Collateral
* Payments
* Interest periods
* Dashboard
* Reports

Avoid duplicating server state unnecessarily in global frontend state.

---

# 11. Validation

## Backend

Backend validation is mandatory.

Use the existing Laravel validation/Form Request conventions.

Validate:

* customer information
* NID
* mobile
* loan amounts
* interest rates
* dates
* collateral weight
* karat
* estimated value
* payment amounts
* payment methods
* permissions

## Frontend

Frontend validation improves UX but is not authoritative.

Never trust frontend financial calculations or validation alone.

---

# 12. Financial Calculation

All financial calculations must happen on the backend.

Examples:

* Interest calculation
* Outstanding principal
* Interest due
* Payment allocation
* Customer ledger balance
* Loan settlement
* Missed-period calculation

Frontend may display calculations returned by the backend but must not become the source of truth.

---

# 13. Money Handling

Never use JavaScript/PHP floating-point arithmetic for authoritative financial calculations.

Use:

* database DECIMAL
* decimal-safe application calculations
* centralized rounding policy

The rounding policy must be defined centrally.

Do not independently round values in:

* controllers
* frontend
* reports
* PDF templates

---

# 14. Background Jobs

Use Laravel queues/jobs if supported by the existing POS architecture.

Potential jobs:

```text
GenerateInterestPeriodsJob
MarkInterestPeriodsDueJob
DetectOverdueLoansJob
GenerateMissedPaymentAlertsJob
GenerateReportExportJob
```

Jobs must be idempotent where applicable.

Do not create duplicate interest periods or alerts when a scheduled job runs multiple times.

---

# 15. Scheduler

Use the existing Laravel scheduler.

Potential scheduled operations:

```text
Generate interest periods
Update due statuses
Detect overdue accounts
Generate missed-payment alerts
Process queued reports
```

The actual frequency must be configurable according to business requirements.

---

# 16. Cache

Reuse the existing cache infrastructure.

Potential cache candidates:

* dashboard aggregates
* settings
* permission lookups
* frequently used configuration

Do not cache financial data without a clear invalidation strategy.

Financial reports must always be capable of obtaining authoritative data.

---

# 17. File Storage

Reuse the existing storage architecture.

Potential uploaded files:

* Customer image
* Collateral photos/documents
* Report files
* Other business documents

Requirements:

* validate MIME type
* validate file size
* generate safe filenames
* do not expose private files publicly without authorization
* use existing storage abstraction

If the POS already uses AWS S3, continue using the same S3 configuration.

---

# 18. PDF

Reuse the existing PDF generation library used by the Inventory POS.

PDFs will be required for:

* Collection Reports
* Due Reports
* Customer Ledger
* Loan Reports
* Collateral Reports
* Customer statements
* Payment receipts

Do not introduce a second PDF library unless the existing one cannot satisfy a documented requirement.

---

# 19. Excel

Reuse the existing Excel export library.

If the Inventory POS uses Laravel Excel / Maatwebsite Excel, continue using it.

Exports must support:

* filtered data
* proper columns
* decimal formatting
* dates
* totals where applicable

Large exports should use queues if supported.

---

# 20. Testing

Use the existing testing stack.

For Laravel applications this will typically include:

* PHPUnit
* Laravel Feature Tests
* Laravel Unit Tests

Frontend tests should use whatever testing framework the Inventory POS already uses.

Critical financial functionality must have automated tests.

Minimum backend test areas:

```text
InterestCalculation
InterestPeriods
LoanBalance
PaymentAllocation
PaymentReversal
CustomerLedger
MissedPaymentDetection
CollateralRelease
LoanClosure
Reports
Permissions
```

---

# 21. Docker

Use Docker.

Reuse the existing Inventory POS Docker architecture wherever possible.

Typical services may include:

```text
app
web
database
redis
queue
```

Do not introduce services that are not required.

Environment configuration should be handled through:

```text
.env
.env.example
```

Never commit secrets.

---

# 22. Code Quality

Follow the existing project's coding standards.

Recommended:

Backend:

* PSR standards
* Laravel conventions
* strict validation
* service-oriented business logic

Frontend:

* consistent component conventions
* reusable components
* typed interfaces where the existing project uses TypeScript
* no unnecessary duplication

---

# 23. Git

Use the existing Git workflow.

Each task should ideally produce a focused commit.

Suggested commit format:

```text
feat(customers): implement customer backend
feat(loans): implement loan management
feat(interest): implement interest engine
feat(collateral): implement collateral lifecycle
feat(payments): implement payment engine
feat(reports): implement loan reports
```

Do not combine unrelated features into one commit.

---

# 24. Architecture Rule

The most important technology rule is:

> Existing Inventory POS architecture takes precedence over this document.

If this document says Laravel + React + PostgreSQL but the existing POS uses a different version or supporting library, inspect the POS and follow the actual existing implementation.

Do not migrate or rewrite the existing application.

---

# 25. Claude Implementation Rule

Before implementing any task:

1. Inspect the existing code.
2. Identify the technology actually used.
3. Identify existing reusable components.
4. Identify existing conventions.
5. Implement the requested task using those conventions.
6. Do not introduce duplicate infrastructure.
7. Do not upgrade dependencies unless required.
8. Do not replace existing libraries without explicit approval.

If the existing application and this document conflict, prefer the existing application architecture and report the conflict.

---

# 26. Technology Decision Summary

| Layer             | Technology                                      |
| ----------------- | ----------------------------------------------- |
| Backend           | PHP 8.2+                                        |
| Backend Framework | Laravel 11+                                     |
| API               | REST                                            |
| Database          | PostgreSQL                                      |
| ORM               | Eloquent                                        |
| Frontend          | Existing Inventory POS frontend framework       |
| UI                | Existing POS design system                      |
| CSS               | Existing POS CSS framework, preferably Tailwind |
| Auth              | Existing POS authentication                     |
| RBAC              | Existing POS roles/permissions                  |
| Queue             | Existing Laravel queue infrastructure           |
| Scheduler         | Laravel Scheduler                               |
| Cache             | Existing cache infrastructure                   |
| Storage           | Existing storage/S3 infrastructure              |
| PDF               | Existing POS PDF library                        |
| Excel             | Existing POS Excel library                      |
| Testing           | Existing backend/frontend test stack            |
| Containers        | Docker                                          |
| Version Control   | Git                                             |
| CI/CD             | Existing POS CI/CD                              |
| """               |                                                 |

I would also **change the reading order of every Claude task** so that `00-tech-stack.md` is read before implementation. For example:

```text
Read:
- docs/00-project.md
- docs/00-tech-stack.md
- docs/02-architecture.md
- docs/03-database.md
- docs/04-api.md
- docs/07-backend.md
- docs/tasks/002-database-foundation.md

Implement ONLY Task 002.
```

### One important point

I intentionally wrote **"Existing Inventory POS frontend framework"** rather than blindly forcing React/Vue/etc. Your previous requirements say the new application should be **similar to the Inventory POS**. If your existing POS is already **Laravel + React + Tailwind + PostgreSQL**, then we should lock those exact versions into the document after inspecting the POS.

That will make Claude much less likely to suddenly decide to use a different framework, ORM, UI library, database package, PDF library, or authentication approach.
