import type { ArticleViewer } from "../data/articleGuides";

type RecordValue = Record<string, unknown>;

const isRecord = (value: unknown): value is RecordValue => (
  typeof value === "object" && value !== null && !Array.isArray(value)
);

const readStringArray = (value: unknown): string[] => (
  Array.isArray(value)
    ? value.filter((item): item is string => typeof item === "string")
    : []
);

export const readArticleViewer = (props: unknown, audience: string): ArticleViewer => {
  const root = isRecord(props) ? props : {};
  const auth = isRecord(root.auth) ? root.auth : {};
  const user = isRecord(auth.user) ? auth.user : {};
  const shopOwner = isRecord(auth.shop_owner)
    ? auth.shop_owner
    : isRecord(user.shop_owner)
      ? user.shop_owner
      : isRecord(root.shop_owner)
        ? root.shop_owner
        : {};
  const erpActor = isRecord(auth.erpActor) ? auth.erpActor : {};

  return {
    permissions: readStringArray(auth.permissions),
    roles: readStringArray(user.roles),
    legacyRole: typeof user.role === "string" ? user.role : null,
    businessType: typeof shopOwner.business_type === "string" ? shopOwner.business_type : null,
    registrationType: typeof shopOwner.registration_type === "string" ? shopOwner.registration_type : null,
    ownerMode: audience === "shop-owner"
      && erpActor.type === "shop_owner"
      && erpActor.ownerMode === true,
  };
};
