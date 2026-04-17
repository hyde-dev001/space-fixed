export type PolicySectionKey =
  | 'refund_payment_terms'
  | 'repair_service_terms'
  | 'retail_terms';

export type ShopPolicySections = Record<string, string>;

export interface ShopPolicyVersionDto {
  id: number;
  shop_owner_id: number;
  version_number: number;
  status: 'draft' | 'published';
  policy_sections_json: ShopPolicySections;
  published_at: string | null;
}

export interface ShopPolicyEditorStateData {
  active: ShopPolicyVersionDto | null;
  draft: ShopPolicyVersionDto | null;
  default_sections: ShopPolicySections;
}

export interface ShopPolicyEditorStateResponse {
  success: boolean;
  data: ShopPolicyEditorStateData;
}