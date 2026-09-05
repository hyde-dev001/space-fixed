import {
  ArrowLeft,
  ArrowRight,
  CheckCircle2,
  CircleAlert,
  Info,
  ListChecks,
  ShieldCheck,
} from "lucide-react";
import { useEffect, useState } from "react";
import { Link } from "@inertiajs/react";

import type {
  ArticleLanguage,
  ArticleOutcome,
  StaffArticle,
} from "../../data/staffArticles";
import { STAFF_ARTICLE_CATEGORIES } from "../../data/staffArticles";
import {
  resolveRelatedStaffArticles,
} from "../../utils/staffArticles";
import ArticleLightbox from "./ArticleLightbox";
import ArticleScreenshot from "./ArticleScreenshot";
import { ArticleLanguageToggle } from "./ArticleHub";

type ArticleDetailProps = {
  article: StaffArticle | undefined;
  accessibleArticles: readonly StaffArticle[];
  language: ArticleLanguage;
  onLanguageChange: (language: ArticleLanguage) => void;
};

const detailCopy = {
  en: {
    back: "Back to all Staff Articles",
    notFound: "Article not found",
    notFoundMessage: "That article link does not match a guide in the current Staff catalog.",
    unavailable: "Article unavailable",
    unavailableMessage: "This guide is not available for the permissions or business context of this account.",
    returnHub: "Return to Staff Articles",
    audience: "Audience & access",
    prerequisites: "Before you start",
    workflow: "Workflow map",
    steps: "Steps",
    outcomes: "What happens next",
    errors: "Common errors and recovery",
    related: "Related Staff Articles",
    owner: "Next owner",
    customerView: "Customer-visible result",
    recovery: "Recovery",
    step: "Step",
    status: "Status",
    readMinutes: "min read",
  },
  tl: {
    back: "Bumalik sa lahat ng Staff Articles",
    notFound: "Hindi nahanap ang artikulo",
    notFoundMessage: "Ang article link ay hindi tumutugma sa kasalukuyang Staff catalog.",
    unavailable: "Hindi available ang artikulo",
    unavailableMessage: "Hindi available ang guide para sa permissions o business context ng account na ito.",
    returnHub: "Bumalik sa Staff Articles",
    audience: "Audience at access",
    prerequisites: "Bago ka magsimula",
    workflow: "Workflow map",
    steps: "Mga hakbang",
    outcomes: "Ano ang susunod na mangyayari",
    errors: "Karaniwang errors at recovery",
    related: "Kaugnay na Staff Articles",
    owner: "Susunod na owner",
    customerView: "Resultang nakikita ng customer",
    recovery: "Recovery",
    step: "Hakbang",
    status: "Status",
    readMinutes: "min na basa",
  },
} as const;

const outcomeToneClasses: Record<ArticleOutcome["tone"], string> = {
  neutral: "border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900/40",
  success: "border-emerald-200 bg-emerald-50/60 dark:border-emerald-900 dark:bg-emerald-950/20",
  warning: "border-amber-200 bg-amber-50/60 dark:border-amber-900 dark:bg-amber-950/20",
  danger: "border-red-200 bg-red-50/60 dark:border-red-900 dark:bg-red-950/20",
};

function DetailState({
  title,
  message,
  language,
  onLanguageChange,
}: {
  title: string;
  message: string;
  language: ArticleLanguage;
  onLanguageChange: (language: ArticleLanguage) => void;
}) {
  const labels = detailCopy[language];

  return (
    <main className="min-w-0 space-y-6" data-testid="staff-article-detail-state">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <Link
          href="/erp/articles"
          aria-label={labels.back}
          className="inline-flex min-h-11 items-center gap-2 rounded-full px-2 text-sm font-semibold text-gray-700 underline decoration-gray-300 underline-offset-4 hover:text-gray-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:text-gray-200 dark:hover:text-white dark:focus-visible:ring-white"
        >
          <ArrowLeft aria-hidden="true" className="h-4 w-4" />
          {labels.back}
        </Link>
        <ArticleLanguageToggle language={language} onLanguageChange={onLanguageChange} />
      </div>
      <section className="rounded-2xl border border-dashed border-gray-300 bg-gray-50 p-8 dark:border-gray-700 dark:bg-gray-900/40 sm:p-12">
        <Info aria-hidden="true" className="h-6 w-6 text-gray-600 dark:text-gray-300" />
        <h1 className="mt-5 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">{title}</h1>
        <p className="mt-3 max-w-2xl text-base leading-7 text-gray-600 dark:text-gray-300">{message}</p>
        <Link
          href="/erp/articles"
          className="mt-6 inline-flex min-h-11 items-center gap-2 rounded-full border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-800 transition-colors hover:border-gray-950 hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 dark:border-gray-700 dark:text-gray-100 dark:hover:border-white dark:hover:bg-gray-900 dark:focus-visible:ring-white"
        >
          {labels.returnHub}
          <ArrowRight aria-hidden="true" className="h-4 w-4" />
        </Link>
      </section>
    </main>
  );
}

export default function ArticleDetail({
  article,
  accessibleArticles,
  language,
  onLanguageChange,
}: ArticleDetailProps) {
  const labels = detailCopy[language];
  const [selectedScreenshot, setSelectedScreenshot] = useState<StaffArticle["screenshots"][number] | null>(null);

  useEffect(() => {
    setSelectedScreenshot(null);
  }, [article?.slug]);

  if (!article) {
    return (
      <DetailState
        title={labels.notFound}
        message={labels.notFoundMessage}
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
        language={language}
        onLanguageChange={onLanguageChange}
      />
    );
  }

  const translation = article.translations[language];
  const categoryLabel = STAFF_ARTICLE_CATEGORIES.find((category) => category.key === article.category)?.label[language]
    ?? article.category;
  const relatedArticles = resolveRelatedStaffArticles(article, accessibleArticles, language);

  return (
    <main className="min-w-0" data-testid="staff-article-detail">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <Link
          href="/erp/articles"
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
            <span className="rounded-full bg-gray-100 px-2.5 py-1 dark:bg-gray-900">{categoryLabel}</span>
            <span>{translation.readingMinutes} {labels.readMinutes}</span>
          </div>
          <h1 className="mt-5 max-w-4xl text-3xl font-bold tracking-tight text-gray-950 dark:text-white sm:text-5xl">{translation.title}</h1>
          <p className="mt-4 max-w-3xl text-lg font-medium leading-8 text-gray-700 dark:text-gray-200">{translation.question}</p>
          <p className="mt-5 max-w-3xl text-base leading-7 text-gray-600 dark:text-gray-300">{translation.summary}</p>

          <div className="mt-6 flex items-start gap-3 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-900/40">
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
                <div key={item.id} className="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
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
                <div key={state.id} className="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="font-semibold text-gray-950 dark:text-white">{state.title}</h3>
                    <span className="rounded-full border border-gray-300 px-2.5 py-1 text-xs font-semibold text-gray-700 dark:border-gray-700 dark:text-gray-200">
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
            <ol className="mt-6 space-y-8">
              {translation.steps.map((step, index) => {
                const screenshot = step.screenshotId
                  ? article.screenshots.find((item) => item.id === step.screenshotId)
                  : undefined;

                return (
                  <li key={step.id} className="grid gap-5 border-l-2 border-gray-200 pl-5 dark:border-gray-800 lg:grid-cols-[minmax(0,1fr)_minmax(16rem,28rem)] lg:pl-6">
                    <div>
                      <p className="text-xs font-bold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">{labels.step} {index + 1}</p>
                      <h3 className="mt-2 text-xl font-semibold tracking-tight text-gray-950 dark:text-white">{step.title}</h3>
                      <p className="mt-3 max-w-2xl text-base leading-7 text-gray-600 dark:text-gray-300">{step.body}</p>
                    </div>
                    {screenshot && (
                      <ArticleScreenshot
                        screenshot={screenshot}
                        language={language}
                        onOpen={setSelectedScreenshot}
                      />
                    )}
                  </li>
                );
              })}
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
                    {outcome.tone === "danger" ? <CircleAlert aria-hidden="true" className="mt-0.5 h-5 w-5 shrink-0 text-red-700 dark:text-red-300" /> : <CheckCircle2 aria-hidden="true" className="mt-0.5 h-5 w-5 shrink-0 text-gray-700 dark:text-gray-200" />}
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
              <CircleAlert aria-hidden="true" className="h-5 w-5 text-gray-700 dark:text-gray-200" />
              <h2 id="article-errors" className="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{labels.errors}</h2>
            </div>
            <div className="mt-5 grid gap-4 md:grid-cols-2">
              {translation.errors.map((error) => (
                <div key={error.id} className="rounded-xl border border-gray-200 p-5 dark:border-gray-800">
                  <h3 className="font-semibold text-gray-950 dark:text-white">{error.title}</h3>
                  <p className="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">{error.body}</p>
                  <p className="mt-4 border-l-2 border-gray-400 pl-3 text-sm leading-6 text-gray-700 dark:border-gray-500 dark:text-gray-200"><span className="font-semibold">{labels.recovery}:</span> {error.recovery}</p>
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
                    href={`/erp/articles/${relatedArticle.slug}`}
                    className="group inline-flex min-h-11 items-center justify-between gap-4 rounded-xl border border-gray-200 p-4 text-sm font-semibold text-gray-800 transition-colors hover:border-gray-950 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 dark:border-gray-800 dark:text-gray-100 dark:hover:border-white dark:hover:bg-white/10 dark:focus-visible:ring-white"
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

      <ArticleLightbox
        open={selectedScreenshot !== null}
        screenshot={selectedScreenshot}
        language={language}
        onClose={() => setSelectedScreenshot(null)}
      />
    </main>
  );
}
