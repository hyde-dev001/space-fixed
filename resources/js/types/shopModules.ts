/**
 * Module identifiers shared by the backend catalog and internal navigation.
 * Keep this literal list aligned with config/shop_modules.php.
 */
export type ShopModuleKey =
  | 'retail_operations'
  | 'repair_operations'
  | 'hr_employees'
  | 'finance'
  | 'crm'
  | 'inventory'
  | 'procurement'
  | 'logistics';

export interface ShopModuleState {
  eligible: boolean;
  enabled: boolean;
  accessible: boolean;
  code: string | null;
  reason: string | null;
}

export type ShopModuleStates = Record<ShopModuleKey, ShopModuleState>;
