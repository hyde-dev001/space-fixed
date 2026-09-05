import type {
  ArticleCatalog,
  ArticleError,
  ArticleGuide,
  ArticleLanguage,
  ArticleListItem,
  ArticleOutcome,
  ArticleStep,
  ArticleTranslation,
  ArticleWorkflowState,
  LocalizedText,
} from "../articleGuides";

export type Bilingual<T> = Readonly<Record<ArticleLanguage, T>>;

export type ArticleCatalogDefinition = {
  audience: string;
  label: LocalizedText;
  title: LocalizedText;
  intro: LocalizedText;
  categories: ArticleCatalog["categories"];
  articles: readonly ArticleGuide[];
};

export type ArticleStepSeed = {
  id: string;
  title: LocalizedText;
  body: LocalizedText;
};

export type ArticleRelatedSeed = {
  slug: string;
  label: LocalizedText;
};

export type RoleArticleSeed = {
  slug: string;
  order: number;
  category: string;
  recommended?: boolean;
  access: ArticleGuide["access"];
  title: LocalizedText;
  question: LocalizedText;
  summary: LocalizedText;
  audience: LocalizedText;
  keywords: Bilingual<readonly string[]>;
  page: LocalizedText;
  checks: LocalizedText;
  finish: LocalizedText;
  nextOwner: LocalizedText;
  customerView: LocalizedText;
  pending: LocalizedText;
  error: LocalizedText;
  recovery: LocalizedText;
  steps: readonly ArticleStepSeed[];
  related: readonly ArticleRelatedSeed[];
  sourceCoverage: ArticleGuide["sourceCoverage"];
  failureTone?: ArticleOutcome["tone"];
};

export const defineArticle = (article: ArticleGuide): ArticleGuide => article;

export const defineCatalog = (catalog: ArticleCatalogDefinition): ArticleCatalog => catalog;

export const localized = <T>(en: T, tl: T): Bilingual<T> => ({ en, tl });

export const text = (en: string, tl: string): LocalizedText => ({ en, tl });

export const keywords = (...values: string[]): Bilingual<readonly string[]> => ({
  en: values,
  tl: values,
});

export const step = (
  id: string,
  enTitle: string,
  enBody: string,
  tlTitle: string,
  tlBody: string,
): ArticleStepSeed => ({
  id,
  title: text(enTitle, tlTitle),
  body: text(enBody, tlBody),
});

export const related = (
  slug: string,
  enLabel: string,
  tlLabel: string,
): ArticleRelatedSeed => ({
  slug,
  label: text(enLabel, tlLabel),
});

const listItem = (
  id: string,
  title: LocalizedText,
  body: LocalizedText,
): Bilingual<ArticleListItem> => ({
  en: { id, title: title.en, body: body.en },
  tl: { id, title: title.tl, body: body.tl },
});

const workflowItem = (
  id: string,
  title: LocalizedText,
  body: LocalizedText,
  status: LocalizedText,
  owner: LocalizedText,
): Bilingual<ArticleWorkflowState> => ({
  en: { id, title: title.en, body: body.en, status: status.en, owner: owner.en },
  tl: { id, title: title.tl, body: body.tl, status: status.tl, owner: owner.tl },
});

const outcomeItem = (
  id: string,
  title: LocalizedText,
  body: LocalizedText,
  owner: LocalizedText,
  customerView: LocalizedText,
  tone: ArticleOutcome["tone"],
): Bilingual<ArticleOutcome> => ({
  en: {
    id,
    title: title.en,
    body: body.en,
    owner: owner.en,
    customerView: customerView.en,
    tone,
  },
  tl: {
    id,
    title: title.tl,
    body: body.tl,
    owner: owner.tl,
    customerView: customerView.tl,
    tone,
  },
});

const errorItem = (
  id: string,
  title: LocalizedText,
  body: LocalizedText,
  recovery: LocalizedText,
): Bilingual<ArticleError> => ({
  en: { id, title: title.en, body: body.en, recovery: recovery.en },
  tl: { id, title: title.tl, body: body.tl, recovery: recovery.tl },
});

const translationFor = (
  seed: RoleArticleSeed,
  language: ArticleLanguage,
): ArticleTranslation => {
  const page = seed.page[language];
  const isEnglish = language === "en";
  const beforeTitle = text("Use the correct account", "Gamitin ang tamang account");
  const pageTitle = text("Open this page", "Buksan ang page na ito");
  const readyStatus = isEnglish ? "Ready" : "Handa";
  const reviewStatus = isEnglish ? "Check" : "Suriin";
  const doneStatus = isEnglish ? "Done or waiting" : "Tapos o naghihintay";
  const you = isEnglish ? "You" : "Ikaw";
  const nextOwner = seed.nextOwner[language];
  const successTitle = isEnglish ? "The task is recorded" : "Naitala ang task";
  const pendingTitle = isEnglish ? "The result needs another step" : "May kailangan pang susunod na hakbang";
  const errorTitle = isEnglish ? "The page cannot finish the task" : "Hindi matapos ng page ang task";
  const staleTitle = isEnglish ? "The page shows old or missing data" : "Luma o kulang ang data sa page";
  const staleBody = isEnglish
    ? "The record may have changed after the page opened, or another user may have finished it."
    : "Maaaring nagbago ang record matapos buksan ang page, o natapos na ito ng ibang user.";

  const prerequisites = [
    listItem("signed-in", beforeTitle, text(
      "Sign in with the account for this work. The page still checks your access and shop.",
      "Mag-sign in gamit ang account para sa work na ito. Tinitingnan pa rin ng page ang access at shop mo.",
    )),
    listItem("page-access", pageTitle, text(page, page)),
  ].map((item) => item[language]);
  const workflow = [
    workflowItem("open-page", pageTitle, text(page, page), text(readyStatus, readyStatus), text(you, you)),
    workflowItem("check-details", text("Check the details", "Suriin ang details"), seed.checks, text(reviewStatus, reviewStatus), text(you, you)),
    workflowItem("finish-task", text("Finish or send the task", "Tapusin o ipadala ang task"), seed.finish, text(doneStatus, doneStatus), seed.nextOwner),
  ].map((item) => item[language]);
  const outcomes = [
    outcomeItem("success", text(successTitle, successTitle), seed.finish, seed.nextOwner, seed.customerView, "success"),
    outcomeItem("pending", text(pendingTitle, pendingTitle), seed.pending, seed.nextOwner, seed.customerView, seed.failureTone ?? "neutral"),
  ].map((item) => item[language]);
  const errors = [
    errorItem("task-error", text(errorTitle, errorTitle), seed.error, seed.recovery),
    errorItem("stale-data", text(staleTitle, staleTitle), text(staleBody, staleBody), seed.recovery),
  ].map((item) => item[language]);

  return {
    title: seed.title[language],
    question: seed.question[language],
    summary: seed.summary[language],
    audience: seed.audience[language],
    keywords: seed.keywords[language],
    readingMinutes: Math.max(3, Math.ceil(seed.steps.length * 1.5)),
    prerequisites,
    workflow,
    steps: seed.steps.map((item) => ({
      id: item.id,
      title: item.title[language],
      body: item.body[language],
    })),
    outcomes,
    errors,
    related: seed.related.map((item) => ({
      slug: item.slug,
      label: item.label[language],
    })),
  };
};

export const buildRoleArticle = (seed: RoleArticleSeed): ArticleGuide => defineArticle({
  slug: seed.slug,
  order: seed.order,
  category: seed.category,
  recommended: seed.recommended,
  access: seed.access,
  translations: {
    en: translationFor(seed, "en"),
    tl: translationFor(seed, "tl"),
  },
  sourceCoverage: seed.sourceCoverage,
});

export const emptyCatalog = (audience: string): ArticleCatalog => ({
  audience,
  label: { en: audience, tl: audience },
  title: { en: `${audience} Articles`, tl: `${audience} Articles` },
  intro: { en: "Guides for this SoleSpace workspace.", tl: "Mga guide para sa SoleSpace workspace na ito." },
  categories: [],
  articles: [],
});
