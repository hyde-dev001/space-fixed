# Return-to-Shop Rider Handoff Implementation Plan

1. Add frontend tests proving return legs hide outbound outcomes, submit a receive proof followed by rider handoff, and expose staff receipt confirmation.
2. Add a backend regression test proving failed-attempt recording cannot mutate a return leg.
3. Add the service guard and the smallest return-specific controls to the existing logistics page.
4. Run focused frontend and backend tests, the logistics test suite, and the production frontend build.

## Mixed-batch sequencing update (2026-10-08)

When a batch contains customer deliveries and a return-to-shop stop, deliveries run first and return stops run last, regardless of saved stop order. A rider-confirmed return handoff still waits for dispatcher receipt, but that review must not block remaining deliveries. The return remains incomplete until the dispatcher confirms receipt.
