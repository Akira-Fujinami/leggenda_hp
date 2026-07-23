import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { WebsiteTabs } from "@/features/analysis/results/website-tabs";
import type { WebsiteAnalysisResult } from "@/types/analysis";

function makeWebsite(overrides: Partial<WebsiteAnalysisResult> = {}): WebsiteAnalysisResult {
  return {
    website_analysis_id: 1, website_id: 1, website_name: "サイトA", url: "https://a.example.com", status: "completed",
    http_status: 200, final_url: null,
    score: {
      overall_score: 59, display_score: 59, available_score: 80, configured_max_score: 100, coverage_rate: 81, confidence_rate: 97,
      category_scores: [],
      metric_summary: { success: 1, not_found: 0, unavailable: 0, error: 0, not_applicable: 0, scored_unavailable: 0, informational_unavailable: 0 },
    },
    seo: null,
    lighthouse: { scores: { performance: null, accessibility: null, best_practices: null }, metrics: null },
    technology: {},
    html_analysis_source: { source: null, fallback_used: false, render_job_status: null, reanalysis_job_status: null },
    screenshots: [],
    errors: [],
    metrics: [],
    recommendations: [],
    ...overrides,
  };
}

describe("WebsiteTabs", () => {
  it("renders nothing when there is only a single site", () => {
    const { container } = render(<WebsiteTabs websites={[makeWebsite()]} value={1} onValueChange={vi.fn()} />);

    expect(container).toBeEmptyDOMElement();
  });

  it("shows each site's name, hostname, status, and score, and switches on click", async () => {
    const user = userEvent.setup();
    const onValueChange = vi.fn();
    const websites = [
      makeWebsite({ website_analysis_id: 1, website_name: "日本旅行", url: "https://www.nta.co.jp/" }),
      makeWebsite({ website_analysis_id: 2, website_name: "楽天トラベル", url: "https://travel.rakuten.co.jp/", score: { ...makeWebsite().score, display_score: 53 } }),
    ];

    render(<WebsiteTabs websites={websites} value={1} onValueChange={onValueChange} />);

    expect(screen.getByText("日本旅行")).toBeInTheDocument();
    expect(screen.getByText("楽天トラベル")).toBeInTheDocument();
    expect(screen.getByText("www.nta.co.jp")).toBeInTheDocument();
    expect(screen.getByText("travel.rakuten.co.jp")).toBeInTheDocument();
    expect(screen.getByText("53点")).toBeInTheDocument();

    await user.click(screen.getByRole("tab", { name: /楽天トラベル/ }));

    expect(onValueChange).toHaveBeenCalledWith(2);
  });
});
