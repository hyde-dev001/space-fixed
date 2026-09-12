import type { ErpCapabilities } from '../types/erp';

export function erpCapabilityKey(method: string, routeName: string): string {
  return `${method.toUpperCase()}:${routeName}`;
}

function resolveCapabilityKey(methodOrKey: string, routeName?: string): string {
  if (routeName !== undefined) {
    return erpCapabilityKey(methodOrKey, routeName);
  }

  const separator = methodOrKey.indexOf(':');

  return separator === -1
    ? methodOrKey
    : erpCapabilityKey(
        methodOrKey.slice(0, separator),
        methodOrKey.slice(separator + 1),
      );
}

export function canUseErpCapability(
  capabilities: ErpCapabilities | undefined,
  methodOrKey: string,
  routeName?: string,
): boolean {
  const capability = capabilities?.[resolveCapabilityKey(methodOrKey, routeName)];

  return capability?.allowed === true
    && typeof capability.url === 'string'
    && capability.url.length > 0;
}

export function erpUrl(
  capabilities: ErpCapabilities | undefined,
  methodOrKey: string,
  routeName?: string,
): string | null {
  const key = resolveCapabilityKey(methodOrKey, routeName);

  return canUseErpCapability(capabilities, key)
    ? capabilities?.[key]?.url ?? null
    : null;
}

export function erpUrlWithParams(
  capabilities: ErpCapabilities | undefined,
  methodOrKey: string,
  params: Record<string, string | number>,
): string | null {
  const template = erpUrl(capabilities, methodOrKey);

  if (!template) {
    return null;
  }

  const resolved = Object.entries(params).reduce(
    (url, [name, value]) => url.split(`__ERP_PARAM_${name}__`).join(encodeURIComponent(String(value))),
    template,
  );

  return /__ERP_PARAM_[A-Za-z0-9_.-]+__/.test(resolved) ? null : resolved;
}
