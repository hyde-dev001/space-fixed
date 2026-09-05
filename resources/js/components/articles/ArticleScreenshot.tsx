import { ImageOff } from "lucide-react";
import { useEffect, useState } from "react";

import type {
  ArticleLanguage,
  ArticleScreenshot as ArticleScreenshotData,
} from "../../data/staffArticles";

type ArticleScreenshotProps = {
  screenshot: ArticleScreenshotData;
  language: ArticleLanguage;
  onOpen: (screenshot: ArticleScreenshotData) => void;
};

const copy = {
  en: { unavailable: "Screenshot unavailable", open: "Open screenshot" },
  tl: { unavailable: "Hindi available ang screenshot", open: "Buksan ang screenshot" },
} as const;

export default function ArticleScreenshot({
  screenshot,
  language,
  onOpen,
}: ArticleScreenshotProps) {
  const [hasError, setHasError] = useState(false);
  const alt = screenshot.alt[language];
  const languageCopy = copy[language];

  useEffect(() => {
    setHasError(false);
  }, [screenshot.path]);

  return (
    <figure className="space-y-2">
      <button
        type="button"
        className="group relative block w-full overflow-hidden rounded-xl border border-gray-300 bg-gray-100 text-left transition-colors hover:border-gray-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-white dark:focus-visible:ring-white"
        style={{ aspectRatio: screenshot.aspectRatio }}
        aria-label={hasError ? `${languageCopy.unavailable}: ${screenshot.path}` : `${languageCopy.open}: ${alt}`}
        onClick={() => onOpen(screenshot)}
      >
        {hasError ? (
          <span
            className="flex h-full flex-col items-center justify-center gap-2 p-4 text-center text-gray-600 dark:text-gray-300"
            data-testid="article-screenshot-placeholder"
          >
            <ImageOff aria-hidden="true" className="h-6 w-6" />
            <span className="text-sm font-semibold">{languageCopy.unavailable}</span>
            <code className="max-w-full break-all text-xs text-gray-500 dark:text-gray-400">{screenshot.path}</code>
          </span>
        ) : (
          <img
            src={screenshot.path}
            alt={alt}
            loading="lazy"
            className="h-full w-full object-cover transition-transform duration-200 group-hover:scale-[1.01] motion-reduce:transition-none motion-reduce:group-hover:scale-100"
            onError={() => setHasError(true)}
          />
        )}
      </button>
      <figcaption className="text-xs leading-5 text-gray-500 dark:text-gray-400">
        {alt}
      </figcaption>
    </figure>
  );
}
