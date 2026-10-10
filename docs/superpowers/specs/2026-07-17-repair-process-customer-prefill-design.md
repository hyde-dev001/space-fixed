# Repair Process Customer Prefill Design

## Goal

Prefill the repair request form with the authenticated customer's registration information and default shipping address while preserving any repair-specific information the customer previously chose to save.

## Data Sources and Priority

Use the existing sources in this order:

1. `repair_process_checkout_info` from local storage for previously saved repair checkout values.
2. Authenticated `auth.user` values for empty customer name, email, and phone fields.
3. The default address returned by the existing `/api/user/addresses` endpoint for empty pickup and return address fields.

Saved repair checkout values are never overwritten. Account/default-address values only fill empty fields.

## Field Mapping

Map `auth.user.name`, `auth.user.email`, and `auth.user.phone` to Contact fields. Map the default `UserAddress` to both pickup and return delivery fields:

- `address_line` to address line;
- `barangay` to barangay;
- `city` to city/municipality;
- `province` or `region` to the normalized province field; and
- `postal_code` to postal code.

Existing delivery-method visibility and validation decide when pickup or return fields are shown and required. Prefilling them does not select a delivery method for the customer.

## Failure and Guest Behavior

Guests keep the current blank/manual form behavior. If the saved checkout data is invalid, retain the current cleanup behavior. If the address request fails or returns no addresses, keep any existing form values and allow manual entry without blocking the page.

## Scope

Modify only `RepairProcess.tsx` plus one focused frontend test. Reuse the existing authenticated address endpoint and location-normalization helpers. Do not add a backend endpoint, dependency, address selector, or unrelated form changes.

## Verification

Add a focused integration test for priority and field mapping. Run that test, the existing repair process location integration test, and the production frontend build. Keep the compiled `public/build` bundle updated because local Laravel serves it when `public/hot` is absent.
