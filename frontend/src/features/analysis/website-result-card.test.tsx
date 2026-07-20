import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { WebsiteResultCard } from "@/features/analysis/website-result-card";
import type { WebsiteAnalysisResult } from "@/types/analysis";

function makeWebsite(overrides: Partial<WebsiteAnalysisResult> = {}): WebsiteAnalysisResult {
  return {
    website_analysis_id: 71,
    website_id: 17,
    website_name: "旅行会社HP",
    url: "https://example.com",
    status: "partial",
    http_status: 200,
    final_url: "https://example.com",
    score: {
      overall_score: 47.09,
      display_score: 47,
      available_score: 56,
      configured_max_score: 100,
      coverage_rate: 56,
      confidence_rate: 100,
      category_scores: [
        { key: "technical_seo", name: "技術SEO", score: 20, max_available_score: 20, configured_max_score: 20, coverage_rate: 100 },
      ],
      metric_summary: { success: 34, not_found: 7, unavailable: 8, error: 0, not_applicable: 10 },
    },
    seo: null,
    lighthouse: { scores: { performance: null, accessibility: null, best_practices: null }, metrics: null },
    technology: {},
    screenshots: [],
    errors: [],
    ...overrides,
  };
}

describe("WebsiteResultCard", () => {
  it("renders without crashing when there are zero screenshots, showing placeholders instead of hiding the section", () => {
    render(<WebsiteResultCard website={makeWebsite()} />);

    expect(screen.getByText("スクリーンショット")).toBeInTheDocument();
    expect(screen.getAllByText("未取得")).toHaveLength(2);
  });

  it("shows a distinct message for a screenshot that failed vs one that was simply never attempted", () => {
    const website = makeWebsite({
      errors: [
        { job_type: "capture_screenshot_desktop", error_code: "SCREENSHOT_FAILED", error_message: "スクリーンショットの取得に失敗しました。" },
      ],
    });

    render(<WebsiteResultCard website={website} />);

    expect(screen.getByText("取得できませんでした")).toBeInTheDocument();
    expect(screen.getByText("未取得")).toBeInTheDocument();
  });

  it("translates internal job type names to Japanese user-facing labels in the error list", () => {
    const website = makeWebsite({
      errors: [
        { job_type: "fetch_external_seo_data", error_code: "SEMRUSH_NOT_CONFIGURED", error_message: null },
        { job_type: "render_page", error_code: "RENDER_FAILED", error_message: "レンダリングに失敗しました。" },
      ],
    });

    render(<WebsiteResultCard website={website} />);

    expect(screen.getByText("外部SEOデータ取得")).toBeInTheDocument();
    expect(screen.getByText("JavaScriptレンダリング")).toBeInTheDocument();
    expect(screen.queryByText(/fetch_external_seo_data/)).not.toBeInTheDocument();
    expect(screen.queryByText(/render_page/)).not.toBeInTheDocument();
  });

  it("indicates that a failed job may be recoverable via re-analysis", () => {
    const website = makeWebsite({
      errors: [{ job_type: "render_page", error_code: "RENDER_FAILED", error_message: "失敗しました。" }],
    });

    render(<WebsiteResultCard website={website} />);

    expect(screen.getByText(/再分析で再取得できる可能性があります/)).toBeInTheDocument();
  });

  it("renders normally when a screenshot is present", () => {
    const website = makeWebsite({
      screenshots: [{ device: "desktop", url: "https://example.com/shot.png", width: 1280, height: 800 }],
    });

    render(<WebsiteResultCard website={website} />);

    expect(screen.getByRole("img")).toBeInTheDocument();
    expect(screen.getByText("未取得")).toBeInTheDocument();
  });
});
