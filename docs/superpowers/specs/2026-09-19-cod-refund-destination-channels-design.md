# COD Refund Destination Channels

**Goal:** Let customers choose an actual Xendit-supported Philippine bank or e-wallet when submitting a COD refund destination.

**Design:** Reuse `XenditPayoutService::getPayoutChannels()` so the customer-facing list is the same live list used by Supplier Profile. Add a customer-authorized, refund-scoped endpoint that returns only public channel metadata; it never returns the shop's Xendit credentials. The destination form selects Bank Account or E-wallet, then the exact Xendit channel, and submits the channel code with the account details.

The server rechecks the selected channel against the shop's connected Xendit account before saving the encrypted destination. The payout payload uses the saved channel code as its routing value. Existing legacy `gcash`/free-text bank destinations remain readable and executable for backward compatibility.

**Failure behavior:** Unauthenticated users, other customers, refunds without Finance approval, non-COD refunds, refunds whose payout has started or completed, missing Xendit setup, unavailable Xendit channels, and unsupported channel codes are rejected without changing the refund. A saved destination remains replaceable while the payout is still not started, and the customer sees only its masked value.

**Verification:** Add Laravel feature coverage for channel loading, ownership/state authorization, selected-channel persistence, and payout routing. Update the My Orders component test to cover the live channel selection and request payload. Run the focused Laravel and frontend suites, PHP syntax checks, frontend build, and `git diff --check`.
