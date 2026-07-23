import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ExternalSeoComparison } from "@/features/comparison/external-seo-comparison";
import type { ExternalSeoInfo, RankingEntry } from "@/types/comparison";

const ranking: RankingEntry[] = [
  {
    rank: 1, website_analysis_id: 1, website_id: 1, website_name: "日本旅行", is_primary: true,
    overall_score: 80, display_score: 80, coverage_rate: 90, confidence_rate: 95, low_data_warning: false,
    score_gap_vs_primary: null,
  },
];

function makeInfo(overrides: Partial<ExternalSeoInfo> = {}): ExternalSeoInfo {
  return {
    website_analysis_id: 1, provider: "mock", is_mock: true, status: "success", database: "us",
    requested_domain: "example.com", normalized_domain: "example.com", scope: "root_domain",
    fetched_at: "2026-07-20T00:00:00+00:00", cache_hit: false, error_code: null, error_message: null,
    ...overrides,
  };
}

describe("ExternalSeoComparison", () => {
  it("collapses the detail table behind 評価不可/デモデータあり when every site is mock", async () => {
    const user = userEvent.setup();
    render(<ExternalSeoComparison ranking={ranking} externalSeo={[makeInfo()]} />);

    expect(screen.getByText("評価不可")).toBeInTheDocument();
    expect(screen.getByText("デモデータあり")).toBeInTheDocument();
    expect(screen.queryByText("Provider")).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /詳細を見る/ }));

    expect(screen.getByText("Provider")).toBeInTheDocument();
  });

  it("shows the detail table directly (not collapsed) when real data is present", () => {
    render(<ExternalSeoComparison ranking={ranking} externalSeo={[makeInfo({ is_mock: false, provider: "semrush" })]} />);

    expect(screen.queryByText("評価不可")).not.toBeInTheDocument();
    expect(screen.getByText("Provider")).toBeInTheDocument();
  });
});
