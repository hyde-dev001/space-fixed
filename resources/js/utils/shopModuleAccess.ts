import type { ShopModuleStates } from '../types/shopModules';

/**
 * Navigation is only a convenience layer. The route middleware remains the
 * security boundary; this predicate keeps the menu consistent with it.
 */
export function canRenderShopModule(
  states: ShopModuleStates | Record<string, unknown> | undefined,
  moduleKey: string | undefined,
  enforcementEnabled = true,
): boolean {
  if (!moduleKey || !enforcementEnabled) {
    return true;
  }

  const state = states?.[moduleKey];

  if (!state || typeof state !== 'object') {
    return false;
  }

  return (state as { accessible?: unknown }).accessible === true;
}
