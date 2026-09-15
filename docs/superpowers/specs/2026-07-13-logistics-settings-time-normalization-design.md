# Logistics Settings Time Normalization Design

## Problem

Laravel serializes persisted `TIME` values as `HH:mm:ss`, while the settings API validates `HH:mm`. The form visually slices values for `<input type="time">` but submits the untouched `HH:mm:ss` state, causing a 422 response.

## Design

- Normalize `cutoff_time`, `morning_start`, `morning_end`, `afternoon_start`, and `afternoon_end` to `HH:mm` when initializing form state.
- Keep API validation strict and unchanged.
- Add a frontend regression test proving an untouched form submits normalized time values.

## Verification

- Run the focused frontend regression test.
- Run logistics feature tests and the production frontend build.
