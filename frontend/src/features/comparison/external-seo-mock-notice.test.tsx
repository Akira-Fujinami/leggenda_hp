import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { ExternalSeoMockNotice } from "@/features/comparison/external-seo-mock-notice";
import type { ExternalSeoInfo, RankingEntry } from "@/types/comparison";

const ranking: RankingEntry[] = [
  {
    rank: 1, website_analysis_id: 1, website_id: 1, website_name: "日本旅行", is_primary: true,
    overall_score: 80, display_score: 80, coverage_rate: 90, confidence_rate: 95, low_data_warning: false,
    score_gap_vs_primary: null,
  },
  {
    rank: 2, website_analysis_id: 2, website_id: 2, website_name: "楽天トラベル", is_primary: false,
    overall_score: 65, display_score: 65, coverage_rate: 80, confidence_rate: 90, low_data_warning: false,
    score_gap_vs_primary: -15,
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

describe("ExternalSeoMockNotice", () => {
  it("shows one consolidated notice, not one per site, when every site is mock", () => {
    const externalSeo = [
      makeInfo({ website_analysis_id: 1 }),
      makeInfo({ website_analysis_id: 2 }),
    ];

    render(<ExternalSeoMockNotice ranking={ranking} externalSeo={externalSeo} />);

    expect(screen.getByText("外部SEOデータについて")).toBeInTheDocument();
    expect(screen.getByText(/日本旅行・楽天トラベルともSemrush実データを取得できていません/)).toBeInTheDocument();
    expect(screen.getAllByText(/外部SEOデータについて/)).toHaveLength(1);
  });

  it("shows an individual notice only for the mock site when data is mixed", () => {
    const externalSeo = [
      makeInfo({ website_analysis_id: 1, is_mock: true }),
      makeInfo({ website_analysis_id: 2, is_mock: false, provider: "semrush" }),
    ];

    render(<ExternalSeoMockNotice ranking={ranking} externalSeo={externalSeo} />);

    expect(screen.getByText("日本旅行の外部SEOデータについて")).toBeInTheDocument();
    expect(screen.queryByText("楽天トラベルの外部SEOデータについて")).not.toBeInTheDocument();
    expect(screen.queryByText("外部SEOデータについて", { exact: true })).not.toBeInTheDocument();
  });

  it("renders nothing when no site has mock data", () => {
    const externalSeo = [
      makeInfo({ website_analysis_id: 1, is_mock: false, provider: "semrush" }),
      makeInfo({ website_analysis_id: 2, is_mock: false, provider: "semrush" }),
    ];

    const { container } = render(<ExternalSeoMockNotice ranking={ranking} externalSeo={externalSeo} />);

    expect(container).toBeEmptyDOMElement();
  });

  it("renders nothing when no site has external SEO data at all", () => {
    const { container } = render(<ExternalSeoMockNotice ranking={ranking} externalSeo={[]} />);

    expect(container).toBeEmptyDOMElement();
  });
});
