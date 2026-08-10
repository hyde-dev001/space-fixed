export type ErpActorType = 'employee' | 'shop_owner';

export interface ErpActor {
  type: ErpActorType;
  id: number;
  name: string;
  guard: 'user' | 'shop_owner';
  ownerMode: boolean;
  tenantOwnerId: number;
}

export interface ErpCapability {
  allowed: boolean;
  method: string;
  routeName: string;
  url: string | null;
  reason: string | null;
}

export type ErpCapabilities = Record<string, ErpCapability>;

export interface ErpUrls {
  portal: string | null;
  settings: string | null;
  workspace: string | null;
  notifications: string | null;
  profile: string | null;
  logout: string | null;
  manageModules: string | null;
}
