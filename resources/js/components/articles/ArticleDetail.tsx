import {
  ArrowLeft,
  ArrowRight,
  CheckCircle2,
  CircleAlert,
  Info,
  ListChecks,
  ShieldCheck,
} from "lucide-react";
import { Link } from "@inertiajs/react";

import type {
  ArticleCatalog,
  ArticleGuide,
  ArticleLanguage,
  ArticleOutcome,
} from "../../data/articleGuides";
import { resolveRelatedArticles } from "../../utils/articleGuides";
import { ArticleLanguageToggle } from "./ArticleHub";

type ArticleDetailProps = {
  catalog: ArticleCatalog;
  article: ArticleGuide | undefined;
  accessibleArticles: readonly ArticleGuide[];
  basePath: string;
  language: ArticleLanguage;
  onLanguageChange: (language: ArticleLanguage) => void;
};

const detailCopy = {
  en: {
    back: "Back to all articles",
    notFound: "Article not found",
    notFoundMessage: "That article link does not match a guide in the current catalog.",
    unavailable: "Article unavailable",
    unavailableMessage: "This guide is not available for the access or shop settings of this account.",
    returnHub: "Return to articles",
    audience: "Who can use this",
    prerequisites: "Before you start",
    workflow: "What happens to the work",
    steps: "Steps",
    outcomes: "What happens next",
    errors: "Common problems and what to do",
    related: "Related articles",
    owner: "Next owner",
    customerView: "What the customer sees",
    recovery: "What to do",
    step: "Step",
    status: "Status",
    readMinutes: "min read",
  },
  tl: {
    back: "Bumalik sa lahat ng artikulo",
    notFound: "Hindi nahanap ang artikulo",
    notFoundMessage: "Ang article link ay hindi tumutugma sa kasalukuyang catalog.",
    unavailable: "Hindi available ang artikulo",
    unavailableMessage: "Hindi available ang guide para sa access o shop settings ng account na ito.",
    returnHub: "Bumalik sa mga artikulo",
    audience: "Sino ang puwedeng gumamit",
    prerequisites: "Bago ka magsimula",
    workflow: "Ano ang mangyayari sa work",
    steps: "Mga hakbang",
    outcomes: "Ano ang susunod na mangyayari",
    errors: "Karaniwang problema at gagawin",
    related: "Kaugnay na mga artikulo",
    owner: "Susunod na owner",
    customerView: "Makikita ng customer",
    recovery: "Gagawin",
    step: "Hakbang",
    status: "Status",
    readMinutes: "min na basa",
  },
} as const;

const outcomeToneClasses: Record<ArticleOutcome["tone"], string> = {
  neutral: "border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950",
  success: "border-emerald-200 bg-emerald-50/60 dark:border-emerald-900 dark:bg-emerald-950/20",
  warning: "border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950",
  danger: "border-red-200 bg-red-50/60 dark:border-red-900 dark:bg-red-950/20",
};

const outcomeIconClass = (tone: ArticleOutcome["tone"]): string => {
  if (tone === "success") return "text-emerald-700 dark:text-emerald-300";
  if (tone === "danger") return "text-red-700 dark:text-red-300";

  return "text-gray-700 dark:text-gray-200";
};

function DetailState({
  title,
  message,
  basePath,
  language,
  onLanguageChange,
}: {
  title: string;
  message: string;
  basePath: string;
  language: ArticleLanguage;
  onLanguageChange: (language: ArticleLanguage) => void;
}) {
  const labels = detailCopy[language];

  return (
    <main className="min-w-0 space-y-6" data-testid="staff-article-detail-state">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <Link
          href={basePath}
          aria-label={labels.back}
          className="inline-flex min-h-11 items-center gap-2 rounded-full px-2 text-sm font-semibold text-gray-700 underline decoration-gray-300 underline-offset-4 hover:text-gray-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:text-gray-200 dark:hover:text-white dark:focus-visible:ring-white"
        >
          <ArrowLeft aria-hidden="true" className="h-4 w-4" />
          {labels.back}
        </Link>
        <ArticleLanguageToggle language={language} onLanguageChange={onLanguageChange} />
      </div>
      <section className="rounded-2xl border border-dashed border-gray-300 bg-white p-8 dark:border-gray-700 dark:bg-gray-950 sm:p-12">
        <Info aria-hidden="true" className="h-6 w-6 text-gray-600 dark:text-gray-300" />
        <h1 className="mt-5 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">{title}</h1>
        <p className="mt-3 max-w-2xl text-base leading-7 text-gray-600 dark:text-gray-300">{message}</p>
        <Link
          href={basePath}
          className="mt-6 inline-flex min-h-11 items-center gap-2 rounded-full border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 transition-colors hover:border-gray-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:hover:border-white dark:focus-visible:ring-white"
        >
          {labels.returnHub}
          <ArrowRight aria-hidden="true" className="h-4 w-4" />
        </Link>
      </section>
    </main>
  );
}

export default function ArticleDetail({
  catalog,
  article,
  accessibleArticles,
  basePath,
  language,
  onLanguageChange,
}: ArticleDetailProps) {
  const labels = detailCopy[language];

  if (!article) {
    return (
      <DetailState
        title={labels.notFound}
        message={labels.notFoundMessage}
        basePath={basePath}
        language={language}
        onLanguageChange={onLanguageChange}
      />
    );
  }

  const isAccessible = accessibleArticles.some((item) => item.slug === article.slug);
  if (!isAccessible) {
    return (
      <DetailState
        title={labels.unavailable}
        message={labels.unavailableMessage}
        basePath={basePath}
        language={language}
        onLanguageChange={onLanguageChange}
      />
    );
  }

  const translation = article.translations[language];
  const categoryLabel = catalog.categories.find((category) => category.key === article.category)?.label[language]
    ?? article.category;
  const relatedArticles = resolveRelatedArticles(article, accessibleArticles, language);

  return (
    <main className="min-w-0" data-testid="staff-article-detail">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <Link
          href={basePath}
          aria-label={labels.back}
          className="inline-flex min-h-11 items-center gap-2 rounded-full px-2 text-sm font-semibold text-gray-700 underline decoration-gray-300 underline-offset-4 hover:text-gray-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:text-gray-200 dark:hover:text-white dark:focus-visible:ring-white"
        >
          <ArrowLeft aria-hidden="true" className="h-4 w-4" />
          {labels.back}
        </Link>
        <ArticleLanguageToggle language={language} onLanguageChange={onLanguageChange} />
      </div>

      <article className="mx-auto mt-6 max-w-5xl">
        <header className="border-b border-gray-200 pb-8 dark:border-gray-800">
          <div className="flex flex-wrap items-center gap-2 text-xs font-semibold text-gray-500 dark:text-gray-400">
            <span className="rounded-full border border-gray-200 bg-white px-2.5 py-1 dark:border-gray-800 dark:bg-gray-950">{categoryLabel}</span>
            <span>{translation.readingMinutes} {labels.readMinutes}</span>
          </div>
          <h1 className="mt-5 max-w-4xl text-3xl font-bold tracking-tight text-gray-950 dark:text-white sm:text-5xl">{translation.title}</h1>
          <p className="mt-4 max-w-3xl text-lg font-medium leading-8 text-gray-700 dark:text-gray-200">{translation.question}</p>
          <p className="mt-5 max-w-3xl text-base leading-7 text-gray-600 dark:text-gray-300">{translation.summary}</p>

          <div className="mt-6 flex items-start gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-950">
            <ShieldCheck aria-hidden="true" className="mt-0.5 h-5 w-5 shrink-0 text-gray-700 dark:text-gray-200" />
            <div>
              <h2 className="text-sm font-semibold text-gray-950 dark:text-white">{labels.audience}</h2>
              <p className="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">{translation.audience}</p>
            </div>
          </div>
        </header>

        <div className="space-y-12 py-8">
          <section aria-labelledby="article-prerequisites">
            <div className="flex items-center gap-3">
              <ShieldCheck aria-hidden="true" className="h-5 w-5 text-gray-700 dark:text-gray-200" />
              <h2 id="article-prerequisites" className="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{labels.prerequisites}</h2>
            </div>
            <div className="mt-5 grid gap-3 md:grid-cols-2">
              {translation.prerequisites.map((item) => (
                <div key={item.id} className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-950">
                  <h3 className="font-semibold text-gray-950 dark:text-white">{item.title}</h3>
                  <p className="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">{item.body}</p>
                </div>
              ))}
            </div>
          </section>

          <section aria-labelledby="article-workflow">
            <div className="flex items-center gap-3">
              <ListChecks aria-hidden="true" className="h-5 w-5 text-gray-700 dark:text-gray-200" />
              <h2 id="article-workflow" className="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{labels.workflow}</h2>
            </div>
            <div className="mt-5 grid gap-3 lg:grid-cols-2">
              {translation.workflow.map((state) => (
                <div key={state.id} className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-950">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="font-semibold text-gray-950 dark:text-white">{state.title}</h3>
                    <span className="rounded-full border border-gray-300 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">
                      {labels.status}: {state.status}
                    </span>
                  </div>
                  <p className="mt-3 text-sm leading-6 text-gray-600 dark:text-gray-300">{state.body}</p>
                  <p className="mt-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{labels.owner}: <span className="normal-case tracking-normal">{state.owner}</span></p>
                </div>
              ))}
            </div>
          </section>

          <section aria-labelledby="article-steps">
            <div className="flex items-center gap-3">
              <ListChecks aria-hidden="true" className="h-5 w-5 text-gray-700 dark:text-gray-200" />
              <h2 id="article-steps" className="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{labels.steps}</h2>
            </div>
            <ol className="mt-6 space-y-6">
              {translation.steps.map((step, index) => (
                <li key={step.id} className="border-l-2 border-gray-200 pl-5 dark:border-gray-800 lg:pl-6">
                  <p className="text-xs font-bold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">{labels.step} {index + 1}</p>
                  <h3 className="mt-2 text-xl font-semibold tracking-tight text-gray-950 dark:text-white">{step.title}</h3>
                  <p className="mt-3 max-w-3xl text-base leading-7 text-gray-600 dark:text-gray-300">{step.body}</p>
                </li>
              ))}
            </ol>
          </section>

          <section aria-labelledby="article-outcomes">
            <div className="flex items-center gap-3">
              <ArrowRight aria-hidden="true" className="h-5 w-5 text-gray-700 dark:text-gray-200" />
              <h2 id="article-outcomes" className="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{labels.outcomes}</h2>
            </div>
            <div className="mt-5 grid gap-4 md:grid-cols-2">
              {translation.outcomes.map((outcome) => (
                <div key={outcome.id} className={`rounded-xl border p-5 ${outcomeToneClasses[outcome.tone]}`}>
                  <div className="flex items-start gap-3">
                    {outcome.tone === "danger" ? (
                      <CircleAlert aria-hidden="true" className={`mt-0.5 h-5 w-5 shrink-0 ${outcomeIconClass(outcome.tone)}`} />
                    ) : (
                      <CheckCircle2 aria-hidden="true" className={`mt-0.5 h-5 w-5 shrink-0 ${outcomeIconClass(outcome.tone)}`} />
                    )}
                    <div>
                      <h3 className="font-semibold text-gray-950 dark:text-white">{outcome.title}</h3>
                      <p className="mt-2 text-sm leading-6 text-gray-700 dark:text-gray-200">{outcome.body}</p>
                    </div>
                  </div>
                  <dl className="mt-5 space-y-3 border-t border-black/10 pt-4 text-sm dark:border-white/10">
                    <div><dt className="font-semibold text-gray-950 dark:text-white">{labels.owner}</dt><dd className="mt-1 text-gray-600 dark:text-gray-300">{outcome.owner}</dd></div>
                    <div><dt className="font-semibold text-gray-950 dark:text-white">{labels.customerView}</dt><dd className="mt-1 text-gray-600 dark:text-gray-300">{outcome.customerView}</dd></div>
                  </dl>
                </div>
              ))}
            </div>
          </section>

          <section aria-labelledby="article-errors">
            <div className="flex items-center gap-3">
              <CircleAlert aria-hidden="true" className="h-5 w-5 text-red-700 dark:text-red-300" />
              <h2 id="article-errors" className="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{labels.errors}</h2>
            </div>
            <div className="mt-5 grid gap-4 md:grid-cols-2">
              {translation.errors.map((error) => (
                <div key={error.id} className="rounded-xl border border-red-200 bg-red-50/60 p-5 dark:border-red-900 dark:bg-red-950/20">
                  <h3 className="font-semibold text-gray-950 dark:text-white">{error.title}</h3>
                  <p className="mt-2 text-sm leading-6 text-gray-700 dark:text-gray-200">{error.body}</p>
                  <p className="mt-4 border-l-2 border-red-400 pl-3 text-sm leading-6 text-gray-700 dark:border-red-500 dark:text-gray-200"><span className="font-semibold">{labels.recovery}:</span> {error.recovery}</p>
                </div>
              ))}
            </div>
          </section>

          {relatedArticles.length > 0 && (
            <section aria-labelledby="article-related" className="border-t border-gray-200 pt-8 dark:border-gray-800">
              <h2 id="article-related" className="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{labels.related}</h2>
              <div className="mt-4 grid gap-3 md:grid-cols-2">
                {relatedArticles.map(({ article: relatedArticle, label }) => (
                  <Link
                    key={relatedArticle.slug}
                    href={`${basePath}/${relatedArticle.slug}`}
                    className="group inline-flex min-h-11 items-center justify-between gap-4 rounded-xl border border-gray-200 bg-white p-4 text-sm font-semibold text-gray-800 transition-colors hover:border-gray-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-100 dark:hover:border-white dark:focus-visible:ring-white"
                  >
                    <span>{label}</span>
                    <ArrowRight aria-hidden="true" className="h-4 w-4 shrink-0 transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none" />
                  </Link>
                ))}
              </div>
            </section>
          )}
        </div>
      </article>
    </main>
  );
}
