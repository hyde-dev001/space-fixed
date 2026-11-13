# Product Quick View Gallery Layout Design

## Context

The product quick-view modal currently renders its gallery in a stretched two-column grid. The gallery uses slate-colored surfaces, the main image is forced into a square frame, and the thumbnail rail uses horizontal scrolling. On landscape product images this creates a visible bottom gap and a horizontal scrollbar.

## Goals

- Remove horizontal/landscape scrolling from the thumbnail gallery.
- Keep every thumbnail accessible by wrapping them within the available width.
- Let the main image preserve its natural aspect ratio and scale responsively.
- Prevent the left gallery column from stretching below its content.
- Use white backgrounds for the gallery surface, image frame, and thumbnails.
- Preserve existing image navigation, keyboard closing, cart behavior, and responsive modal behavior.

## Non-goals

- Do not change product image URLs, image ordering, or fallback behavior.
- Do not crop product images with `object-cover`.
- Do not change the dimmed modal backdrop; it remains intentional modal context.
- Do not change the product details or cart workflow.

## Approaches considered

1. **Responsive natural-size gallery (recommended):** remove the forced square frame, use a responsive `img` constrained by available width/height, wrap thumbnails, and align the gallery to its content. This removes the root causes while preserving the full image.
2. **Fixed frame with hidden overflow:** keep the square frame and hide the thumbnail scrollbar. This is the smallest CSS change, but it can hide thumbnails and keeps the blank space for landscape images.
3. **Fixed frame with cropped image:** use `object-cover` to fill the frame. This removes gaps visually but can crop product details and is unsuitable for product photography.

## Design

The dialog will explicitly prevent horizontal overflow while retaining vertical scrolling when the details column is taller than the viewport. The image section will use a white surface and `self-start`/content-sized behavior on large screens so it does not paint a blank area below the gallery. The main image will be rendered as a block with `max-width` and viewport-aware `max-height`, using `object-contain` without a forced aspect ratio. The thumbnail container will use wrapping flex layout with fixed, shrink-resistant buttons and no `overflow-x-auto`.

The overlay remains darkened to separate the modal from the page behind it. Only the gallery/image surfaces change to white.

## Verification

- Extend `ProductQuickView` tests to assert the gallery uses white surfaces, the dialog blocks horizontal overflow, and thumbnails wrap instead of scrolling.
- Run the focused quick-view test file.
- Run the full frontend test suite and production build.
- Run `git diff --check` and inspect the final committed diff for unrelated files.
