# Customer Repair Return Recovery Implementation Plan

1. Add failing feature tests for customer ownership, both recovery choices, schedule validation, and schedule propagation after payment.
2. Add failing My Repairs interaction tests for the two customer choices.
3. Add the customer route/controller action and extend the existing recovery entry with date/window.
4. Apply the stored schedule when the paid replacement outbound leg is created.
5. Add the mobile-first customer controls to My Repairs.
6. Run focused backend/frontend tests, related regressions, and a production build.
