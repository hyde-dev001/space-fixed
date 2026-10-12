import { describe, expect, it } from 'vitest';
import { canRenderShopModule } from '../shopModuleAccess';

const states = {
  retail_operations: { eligible: true, enabled: true, accessible: true, code: null, reason: null },
  repair_operations: { eligible: true, enabled: false, accessible: false, code: 'MODULE_DISABLED', reason: 'Disabled by owner' },
  hr_employees: { eligible: false, enabled: false, accessible: false, code: 'MODULE_INELIGIBLE', reason: 'Not available' },
  finance: { eligible: true, enabled: true, accessible: true, code: null, reason: null },
  crm: { eligible: true, enabled: true, accessible: true, code: null, reason: null },
  inventory: { eligible: true, enabled: true, accessible: true, code: null, reason: null },
  procurement: { eligible: true, enabled: true, accessible: true, code: null, reason: null },
  logistics: { eligible: true, enabled: true, accessible: true, code: null, reason: null },
};

describe('canRenderShopModule', () => {
  it('renders an accessible module and hides disabled or ineligible modules', () => {
    expect(canRenderShopModule(states, 'retail_operations', true)).toBe(true);
    expect(canRenderShopModule(states, 'repair_operations', true)).toBe(false);
    expect(canRenderShopModule(states, 'hr_employees', true)).toBe(false);
  });

  it('fails closed for missing or unknown state while enforcement is active', () => {
    expect(canRenderShopModule(undefined, 'finance', true)).toBe(false);
    expect(canRenderShopModule(states, 'unknown_runtime_key', true)).toBe(false);
  });

  it('does not hide navigation while enforcement is disabled', () => {
    expect(canRenderShopModule(undefined, 'finance', false)).toBe(true);
    expect(canRenderShopModule(states, 'repair_operations', false)).toBe(true);
  });

  it('keeps core items visible when no module key is supplied', () => {
    expect(canRenderShopModule(undefined, undefined, true)).toBe(true);
  });
});
