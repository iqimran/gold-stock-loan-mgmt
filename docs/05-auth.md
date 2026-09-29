# 05 — Authentication & Authorization

Reuse the Inventory POS authentication system.

Required:
- Login
- Logout
- Forgot password
- Reset password
- Session/token handling
- Password hashing
- Responsive auth pages
- Permission-aware navigation

Authorization:
- Backend policies/permissions are authoritative.
- Frontend hides unavailable actions for usability but is not a security boundary.

Sensitive actions:
- payment reversal
- loan cancellation
- loan closure
- collateral release
- settings changes
- user/role changes
- exports containing sensitive customer data

Require explicit permissions. For high-risk actions, require confirmation and capture reason.

Do not implement a second auth system if the POS already provides one.
