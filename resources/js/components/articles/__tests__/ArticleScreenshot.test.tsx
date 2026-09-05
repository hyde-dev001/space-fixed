import React from "react";
import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import ArticleScreenshot from "../ArticleScreenshot";
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

describe("ArticleScreenshot", () => {
  it("renders a successful image with an accessible open action", () => {
    const onOpen = vi.fn();

    render(<ArticleScreenshot screenshot={screenshot} language="en" onOpen={onOpen} />);

    expect(screen.getByRole("img", { name: screenshot.alt.en })).toHaveAttribute(
      "src",
      screenshot.path,
    );
    fireEvent.click(screen.getByRole("button", { name: /open screenshot/i }));

    expect(onOpen).toHaveBeenCalledWith(screenshot);
  });

  it("shows the exact configured path when the image is missing", () => {
    render(<ArticleScreenshot screenshot={screenshot} language="tl" onOpen={vi.fn()} />);

    fireEvent.error(screen.getByRole("img", { name: screenshot.alt.tl }));

    expect(screen.getByTestId("article-screenshot-placeholder")).toHaveTextContent(
      screenshot.path,
    );
    expect(screen.getByText(/hindi available ang screenshot/i)).toBeInTheDocument();
  });
});
