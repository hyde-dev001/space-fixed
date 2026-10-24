# Connected flagship showroom implementation plan

Outcome: one continuous walkable sneaker boutique with 150 physical display positions, premium architectural fixtures, lounge corners, and day/evening lighting.

Contract: retain the server-provided capacity, product order, uploaded image/360-frame workflow, product inspection, touch controls, showroom route, and profile return link. No backend, subscription, dependency, or unrelated working-tree changes. Main risks are slot reachability, GPU/texture cost, and movement regressions.

1. Replace `showroomRooms.ts` and its obsolete split-room tests with `showroomLayout.ts` and behavioral tests: 150 unique slots, plan capacity clamping, fixture collision, and connected access to every display.
2. Add `showroomScene.ts` to build the continuous architecture from that layout: 120 framed wall positions, 24 island positions, six feature plinths; stone, walnut, metal, upholstered seating, rugs, plants, ceiling tracks, signage, and bounded lighting. Reuse installed Three.js geometry/environment helpers and instance repeated fixture parts.
3. Update `VirtualShowroom.tsx`: remove room slicing/switching and mobile product truncation; reuse inspection and touch flow; integrate scene, collisions, eye-level entrance camera, minimal HUD, static shelf previews with on-demand full inspection frames, and resource cleanup.
4. Run `pnpm run test:frontend -- resources/js/Pages/UserSide/Products/showroomLayout.test.ts resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts`; build with `pnpm run build -- --outDir .tmp/showroom-build`; inspect desktop/mobile day/night runtime with Playwright where runnable. Review final diff, reuse, dead code, and `git diff --check`.

Implementation completed sequentially. No commits or parallel agents.

## Review and verification record

- Standards, specification, correctness, TypeScript inspection, reuse, simplification, dead-code scan: pass. Retained existing React interaction and uploaded-image paths; reused installed Three.js helpers. Deleted obsolete room splitting and frame-preview animation infrastructure.
- Security/backend/migrations: N/A; no server, authorization, tenant, subscription, route, or persistence changes.
- Performance review: instanced repeated architecture and contact shadows; shared product geometry; one static shadow map; bounded pixel ratio and shelf texture dimensions; initial shelf loading uses one image per product. Frame-rate improvement: not measured.
- `node node_modules/vitest/vitest.mjs run resources/js/Pages/UserSide/Products/showroomLayout.test.ts resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts resources/js/Pages/UserSide/Shared/__tests__/navigationCoverage.contract.test.ts`: 19 tests passed. Reachability test caught a column obstructing a rear display; repositioned the column and verified all slots again.
- `node node_modules/vite/bin/vite.js build --outDir .tmp/showroom-build`: production build passed. Generated output stays in the temporary verification directory.
- pnpm was attempted through `pnpm.cmd`; its requested version could not be verified because registry access failed. Used the already-installed Vitest and Vite binaries directly; no package-manager or dependency configuration changed.
- Playwright, Chromium software WebGL, isolated component preview using existing local product images: 150 rendered cards on desktop and phone; WASD movement; touch-drag joystick movement; day/night position preservation; pickup, image inspection and return; portrait/landscape; empty products; reduced-motion setting; no JavaScript errors. Final compiled CSS verifies the portrait movement hint clears the joystick.
- Browser artifacts: `.tmp/showroom-review/` contains the first-pass day/night, portrait/landscape, inspection, and empty-state screenshots. `.tmp/showroom-visual-review/` contains same-viewport original/revised comparisons plus the verified mobile and empty-state captures. Tests use representative local images, not an authenticated live shop. Back-link and standalone navigation preservation are covered by source contract tests.
- No repository TypeScript configuration or frontend lint command exists; standalone compiler/lint checks were not run. Durable learning log: N/A.
- Revision after visual review: the first pass was functionally correct but visually too flat and repetitive. Added locally hosted CC0 1K PBR maps from Poly Haven (`public/images/SHOWROOM/materials/`), varied walnut/stone/black fixture finishes, a lower coffered ceiling with cove and track lighting, lounge placement visible from the entrance, static contact shadows, and one bounded planar floor reflection with a five-tap blur. The first-pass behavior and capacity tests remained green after these changes.
- Revision verification: the standalone Playwright comparison rendered the original at 75 cards and the revised scene at 150 cards in the same 1440×900 viewport; revised day/night and 390×844 mobile/empty captures completed with no page errors. The corrected uppercase asset URL was rebuilt with Vite successfully.
- Limitation: product presentation remains image-based, including the original uploaded image backgrounds/padding. The floor reflection is a bounded low-resolution approximation rather than a full screen-space reflection. Physical mobile GPU performance needs device benchmarking.
