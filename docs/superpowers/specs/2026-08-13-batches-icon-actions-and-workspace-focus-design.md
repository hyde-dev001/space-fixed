# Batches Icon Actions and Workspace Focus Design

## Goal

Make batch actions consistent with the ERP icon-button pattern and focus the editing workflow by collapsing Available deliveries while a batch is open.

## Approved interaction

- Replace the visible text action for editing/opening a batch with a 44px icon button using the existing Pencil icon convention.
- Replace the visible View details action with a 44px icon button using the existing Eye icon convention.
- Apply the same icon actions in the active table and the history modal.
- Keep accessible names and native `title` tooltips on every icon-only button.
- Opening an existing batch collapses Available deliveries and scrolls to the existing workspace as it does today.
- Starting a new batch expands Available deliveries so deliveries can be selected.
- Existing Review, Cancel, Restore, API calls, and workspace behavior remain unchanged.

## Boundaries and risks

- No backend, route, payload, or persistence changes.
- Collapse state is local React state owned by `Batches.tsx`.
- The panel remains mounted while collapsed to avoid resetting filters or selected delivery state.
- Icon buttons retain keyboard focus styles and minimum touch target sizing.

## Verification

- Update Batches frontend tests for icon labels and collapse/expand behavior.
- Run the focused Batches test file, the full frontend suite, the production build, and `git diff --check`.
