import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { ExternalSeoInfoPanel } from "@/features/comparison/external-seo-info";
import type { ExternalSeoInfo, RankingEntry } from "@/types/comparison";

const ranking: RankingEntry[] = [
  {
    rank: 1, website_analysis_id: 1, website_id: 1, website_name: "自社サイト", is_primary: true,
    overall_score: 80, display_score: 80, coverage_rate: 90, confidence_rate: 95, low_data_warning: false,
    score_gap_vs_primary: null,
  },
];

function makeInfo(overrides: Partial<ExternalSeoInfo> = {}): ExternalSeoInfo {
  return {
    website_analysis_id: 1,
    provider: "semrush",
    is_mock: false,
    status: "success",
    database: "jp",
    requested_domain: "www.example.com",
    normalized_domain: "example.com",
    scope: "root_domain",
    fetched_at: "2026-07-20T00:00:00+00:00",
    cache_hit: false,
    error_code: null,
    error_message: null,
    ...overrides,
  };
}

describe("ExternalSeoInfoPanel", () => {
  it("shows real-data badge for a genuine (non-mock) successful fetch", () => {
    render(<ExternalSeoInfoPanel ranking={ranking} externalSeo={[makeInfo()]} />);

    expect(screen.getByText("実データ")).toBeInTheDocument();
    expect(screen.getByText("semrush")).toBeInTheDocument();
  });

  it("shows a demo-data badge when the snapshot is mock", () => {
    render(<ExternalSeoInfoPanel ranking={ranking} externalSeo={[makeInfo({ is_mock: true, provider: "mock" })]} />);

    expect(screen.getByText("デモデータ")).toBeInTheDocument();
  });

  it("shows the unavailable reason instead of fabricating data", () => {
    render(
      <ExternalSeoInfoPanel
        ranking={ranking}
        externalSeo={[makeInfo({ status: "unavailable", provider: null, error_code: "SEMRUSH_NOT_CONFIGURED" })]}
      />
    );

    expect(screen.getByText(/未取得/)).toBeInTheDocument();
    expect(screen.getByText(/Semrush APIキーが設定されていません/)).toBeInTheDocument();
  });
});
