# Showroom Interaction and XOX Polish Design

**Goal:** Make the virtual showroom feel interactive without changing its existing access and placement rules: desktop camera look follows the cursor, couch and shoe actions have physical showroom cues, owner/staff shelf editing remains drag-and-drop, and the lounge XOX game becomes a continuous scored session.

## Interaction

On desktop, pointer position inside the showroom becomes the camera look target. The viewport center is neutral; moving toward the edges changes yaw and pitch, with the existing smoothing and pitch limits retained. Pointer leave eases the target back to the neutral heading. Touch devices keep the existing joystick. When owner/staff edit mode is active, pointer drag is reserved for moving a shoe between shelf slots so camera look cannot steal the drag.

World-space `CanvasTexture` sprites reuse the showroom's charcoal, brass, and linen palette. A small `E / PLAY` sprite appears above the nearest lounge when the visitor is within interaction range and gently bobs. Each displayed shoe gets a compact `CLICK` sprite tied to its display position, with the selected/picked shoe hidden while its existing pickup animation runs. The existing keyboard and raycast actions remain the source of truth; prompts are affordances only.

## XOX session

The XOX panel uses the showroom palette and inset display styling. The bot uses an optimal minimax move selector with randomized equally-good choices, making it difficult without making repeated games visually identical. Wins, losses, and draws are tracked for the current seated session in a side score panel. Every completed round shows an animated result banner and automatically starts the next round after a short pause. The New game action is removed. Closing the panel leaves the seated session intact; standing up ends and resets the session.

## Error and accessibility behavior

Existing click, keyboard, focus, and reduced-motion behavior remains available. Result text is announced through the existing live status region. Prompt sprites are supplementary, so the keyboard instructions and button labels remain available to assistive technology. Reduced-motion users receive immediate camera/round transitions and no decorative bobbing.

## Verification

Add rule tests for minimax blocking/winning and winning-line reporting, contract tests for the new interaction hooks and removed New game action, run the existing showroom and sidebar Vitest suites, run the focused Laravel showroom feature test, run the production Vite build, and inspect the final diff.
