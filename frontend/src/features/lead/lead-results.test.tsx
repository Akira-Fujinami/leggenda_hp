import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { LeadResults } from "@/features/lead/lead-results";
import type { LeadResults as LeadResultsType } from "@/types/lead";

function baseWebsite(overrides: Partial<LeadResultsType["websites"][number]> = {}): LeadResultsType["websites"][number] {
  return {
    website_name: "サンプル株式会社",
    is_primary: true,
    score: {
      overall_score: 72.5,
      display_score: 73,
      available_score: 72.5,
      configured_max_score: 100,
      coverage_rate: 90,
      confidence_rate: 88,
      category_scores: [
        { key: "technical_seo", name: "技術SEO", score: 15, max_available_score: 20, configured_max_score: 20, coverage_rate: 100 },
        { key: "performance", name: "表示速度", score: 10, max_available_score: 15, configured_max_score: 15, coverage_rate: 100 },
      ],
      metric_summary: {
        success: 40,
        not_found: 5,
        unavailable: 2,
        error: 0,
        not_applicable: 0,
        scored_unavailable: 2,
        informational_unavailable: 0,
      },
    },
    top_recommendations: [
      { title: "画像を圧縮してください", description: "表示速度の改善につながります。", priority: "high", impact: "high", effort: "low" },
    ],
    screenshots: [{ device: "desktop", url: "https://example.com/desktop.jpg" }],
    ...overrides,
  };
}

describe("LeadResults", () => {
  it("shows 総合スコア when coverage is at or above the honesty threshold", () => {
    render(<LeadResults results={{ status: "completed", websites: [baseWebsite()] }} />);

    expect(screen.getByText("総合スコア")).toBeInTheDocument();
    expect(screen.getByText("サンプル株式会社")).toBeInTheDocument();
    expect(screen.getByText("自社サイト")).toBeInTheDocument();
  });

  it("shows 参考スコア and a warning when coverage is below 70%, never silently upgrading it", () => {
    const lowCoverage = baseWebsite({
      score: { ...baseWebsite().score, coverage_rate: 40 },
    });
    render(<LeadResults results={{ status: "completed", websites: [lowCoverage] }} />);

    expect(screen.getByText("参考スコア")).toBeInTheDocument();
    expect(screen.getByText(/測定カバー率が40%のため、このスコアは参考値です/)).toBeInTheDocument();
  });

  it("shows the top recommendation and screenshot", () => {
    render(<LeadResults results={{ status: "completed", websites: [baseWebsite()] }} />);

    expect(screen.getByText("画像を圧縮してください")).toBeInTheDocument();
    expect(screen.getByRole("img", { name: /サンプル株式会社.*PC/ })).toBeInTheDocument();
  });

  it("shows a partial-data notice when the analysis status is partial", () => {
    render(<LeadResults results={{ status: "partial", websites: [baseWebsite()] }} />);

    expect(screen.getByText(/一部のデータは取得できませんでした/)).toBeInTheDocument();
  });
});
