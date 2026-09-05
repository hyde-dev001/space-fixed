import React from "react";
import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import ArticleLightbox from "../ArticleLightbox";
import type { ArticleScreenshot as ArticleScreenshotData } from "../../../data/staffArticles";

const screenshot: ArticleScreenshotData = {
  id: "step-01",
  fileName: "step-01.webp",
  path: "/images/articles/staff/orders/example/step-01.webp",
  alt: {
    en: "Order status example with identifiers redacted.",
    tl: "Order status example na redacted ang identifiers.",
  },
  aspectRatio: 16 / 9,
};

describe("ArticleLightbox", () => {
  it("opens, closes with Escape, and returns focus to the trigger", () => {
    const onClose = vi.fn();
    const trigger = document.createElement("button");
    trigger.setAttribute("aria-label", "Open screenshot");
    document.body.append(trigger);
    trigger.focus();

    const { rerender } = render(
      <ArticleLightbox open={true} screenshot={screenshot} language="en" onClose={onClose} />,
    );

    expect(screen.getByRole("dialog", { name: /screenshot preview/i })).toBeInTheDocument();
    fireEvent.keyDown(document, { key: "Escape" });
    expect(onClose).toHaveBeenCalledTimes(1);

    rerender(
      <ArticleLightbox open={false} screenshot={screenshot} language="en" onClose={onClose} />,
    );
    expect(document.activeElement).toBe(trigger);

    trigger.remove();
  });

  it("closes through its labelled close button", () => {
    const onClose = vi.fn();

    render(
      <ArticleLightbox open={true} screenshot={screenshot} language="tl" onClose={onClose} />,
    );

    fireEvent.click(screen.getByRole("button", { name: /isara ang preview ng screenshot/i }));

    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
