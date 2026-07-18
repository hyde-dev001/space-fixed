# Return-to-Shop Rider Handoff Implementation Plan

1. Add frontend tests proving return legs hide outbound outcomes, submit a receive proof followed by rider handoff, and expose staff receipt confirmation.
2. Add a backend regression test proving failed-attempt recording cannot mutate a return leg.
3. Add the service guard and the smallest return-specific controls to the existing logistics page.
4. Run focused frontend and backend tests, the logistics test suite, and the production frontend build.
