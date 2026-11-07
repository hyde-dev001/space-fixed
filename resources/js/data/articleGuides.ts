export type ArticleLanguage = "en" | "tl";

export type LocalizedText = Readonly<Record<ArticleLanguage, string>>;

export type ArticleBusinessType = "retail" | "repair" | "both";
export type ArticleRegistrationType = "company" | "individual";

export type ArticleListItem = {
  id: string;
  title: string;
  body: string;
};

export type ArticleStep = ArticleListItem;

export type ArticleWorkflowState = ArticleListItem & {
  status: string;
  owner: string;
};

export type ArticleOutcome = ArticleListItem & {
  owner: string;
  customerView: string;
  tone: "neutral" | "success" | "warning" | "danger";
};

export type ArticleError = ArticleListItem & {
  recovery: string;
};

export type ArticleRelatedLink = {
  slug: string;
  label: string;
};

export type ArticleTranslation = {
  title: string;
  question: string;
  summary: string;
  audience: string;
  keywords: readonly string[];
  readingMinutes: number;
  prerequisites: readonly ArticleListItem[];
  workflow: readonly ArticleWorkflowState[];
  steps: readonly ArticleStep[];
  outcomes: readonly ArticleOutcome[];
  errors: readonly ArticleError[];
  related: readonly ArticleRelatedLink[];
};

export type ArticleAccess = {
  anyOfPermissions: readonly string[];
  allowedRoles?: readonly string[];
  allowedBusinessTypes?: readonly ArticleBusinessType[];
  allowedRegistrationTypes?: readonly ArticleRegistrationType[];
  ownerOnly?: boolean;
};

export type ArticleSourceCoverage = {
  routes: readonly string[];
  pages: readonly string[];
  permissions: readonly string[];
  tests: readonly string[];
};

export type ArticleGuide = {
  slug: string;
  order: number;
  category: string;
  recommended?: boolean;
  access: ArticleAccess;
  translations: Readonly<Record<ArticleLanguage, ArticleTranslation>>;
  sourceCoverage: ArticleSourceCoverage;
};

export type ArticleCategory = {
  key: string;
  label: LocalizedText;
};

export type ArticleCatalog = {
  audience: string;
  label: LocalizedText;
  title: LocalizedText;
  intro: LocalizedText;
  categories: readonly ArticleCategory[];
  articles: readonly ArticleGuide[];
};

export type ArticleViewer = {
  permissions: readonly string[];
  roles?: readonly string[];
  legacyRole?: string | null;
  businessType?: string | null;
  registrationType?: string | null;
  ownerMode?: boolean;
};
