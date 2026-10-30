# Rider Proof and Issue Mutual Exclusion

On an in-transit rider delivery, choosing an issue reason disables **Submit proof**. Choosing a proof file disables **Report issue**. Selecting the blank issue option or using a small **Clear proof** button restores the opposite action.

This is a frontend safety guard using the existing per-leg `issueForms` and `proofFiles` state. The API behavior remains unchanged.

Verification covers disabling and restoring both directions in the existing Shipments component test.
