import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { RankingSummary } from "@/features/comparison/ranking-summary";
import type { RankingEntry } from "@/types/comparison";

function makeEntry(overrides: Partial<RankingEntry> = {}): RankingEntry {
  return {
    rank: 1,
    website_analysis_id: 1,
    website_id: 1,
    website_name: "自社サイト",
    is_primary: true,
    overall_score: 80,
    display_score: 80,
    coverage_rate: 90,
    confidence_rate: 95,
    low_data_warning: false,
    score_gap_vs_primary: null,
    ...overrides,
  };
}

describe("RankingSummary", () => {
  it("renders each site in rank order with its score gap", () => {
    render(
      <RankingSummary
        ranking={[
          makeEntry(),
          makeEntry({
            rank: 2,
            website_analysis_id: 2,
            website_id: 2,
            website_name: "競合サイト",
            is_primary: false,
            display_score: 65,
            score_gap_vs_primary: -15,
          }),
        ]}
      />
    );

    expect(screen.getByText("自社サイト")).toBeInTheDocument();
    expect(screen.getByText("競合サイト")).toBeInTheDocument();
    expect(screen.getByText("-15")).toBeInTheDocument();
  });

  it("shows a no-primary notice instead of an error when no site is marked primary", () => {
    render(<RankingSummary ranking={[makeEntry({ is_primary: false, score_gap_vs_primary: null })]} />);

    expect(screen.getByText(/自社サイトが設定されていないため/)).toBeInTheDocument();
  });

  it("surfaces a low-data warning banner when any site has low_data_warning", () => {
    render(<RankingSummary ranking={[makeEntry({ low_data_warning: true })]} />);

    expect(screen.getByText(/測定データが少ないサイトがあります/)).toBeInTheDocument();
  });
});
