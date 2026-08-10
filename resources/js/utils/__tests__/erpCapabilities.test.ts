import { describe, expect, it } from 'vitest';

import type { ErpCapabilities } from '../../types/erp';
import {
  canUseErpCapability,
  erpCapabilityKey,
  erpUrl,
  erpUrlWithParams,
} from '../erpCapabilities';

const capabilities: ErpCapabilities = {
  'GET:erp.hr': {
    allowed: true,
    method: 'GET',
    routeName: 'erp.hr',
    url: '/erp/hr',
    reason: null,
  },
  'GET:erp.finance': {
    allowed: false,
    method: 'GET',
    routeName: 'erp.finance',
    url: null,
    reason: 'MODULE_DISABLED',
  },
};

describe('ERP capability helpers', () => {
  it('normalizes capability keys by HTTP method', () => {
    expect(erpCapabilityKey('get', 'erp.hr')).toBe('GET:erp.hr');
  });

  it('allows only concrete, server-provided URLs', () => {
    expect(canUseErpCapability(capabilities, 'GET:erp.hr')).toBe(true);
    expect(erpUrl(capabilities, 'GET:erp.hr')).toBe('/erp/hr');
    expect(erpUrl(capabilities, 'get', 'erp.hr')).toBe('/erp/hr');
  });

  it('fails closed for missing, denied, or URL-less capabilities', () => {
    expect(canUseErpCapability(capabilities, 'GET:erp.finance')).toBe(false);
    expect(canUseErpCapability(capabilities, 'GET:erp.missing')).toBe(false);
    expect(erpUrl(capabilities, 'GET:erp.finance')).toBeNull();
    expect(erpUrl(capabilities, 'GET:erp.missing')).toBeNull();
  });

  it('fills server-provided route placeholders without changing the capability contract', () => {
    const parameterizedCapabilities: ErpCapabilities = {
      'GET:crm.customer.show': {
        allowed: true,
        method: 'GET',
        routeName: 'crm.customer.show',
        url: '/api/shop-owner/erp/crm/customers/__ERP_PARAM_id__',
        reason: null,
      },
    };

    expect(erpUrlWithParams(parameterizedCapabilities, 'GET:crm.customer.show', { id: 42 }))
      .toBe('/api/shop-owner/erp/crm/customers/42');
    expect(erpUrlWithParams(parameterizedCapabilities, 'GET:crm.customer.missing', { id: 42 }))
      .toBeNull();
  });
});
