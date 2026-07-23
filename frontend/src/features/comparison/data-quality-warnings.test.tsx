import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { DataQualityWarnings } from "@/features/comparison/data-quality-warnings";
import type { DataQuality, RankingEntry } from "@/types/comparison";

const ranking: RankingEntry[] = [
  {
    rank: 1, website_analysis_id: 1, website_id: 1, website_name: "自社サイト", is_primary: true,
    overall_score: 80, display_score: 80, coverage_rate: 90, confidence_rate: 95, low_data_warning: false,
    score_gap_vs_primary: null,
  },
];

function makeDq(overrides: Partial<DataQuality> = {}): DataQuality {
  return {
    website_analysis_id: 1, coverage_rate: 90, confidence_rate: 95, measured_count: 10, external_count: 1,
    unavailable_count: 0, error_count: 0, mock_count: 0, last_fetched_at: null, warnings: [],
    ...overrides,
  };
}

describe("DataQualityWarnings", () => {
  it("shows a coverage warning normally", () => {
    render(<DataQualityWarnings ranking={ranking} dataQuality={[makeDq({ warnings: ["coverage_below_70"] })]} />);

    expect(screen.getByText(/測定カバー率が70%未満です/)).toBeInTheDocument();
  });

  it("does not show the contains_mock_data warning here (it is consolidated in ExternalSeoMockNotice instead)", () => {
    render(<DataQualityWarnings ranking={ranking} dataQuality={[makeDq({ warnings: ["contains_mock_data"] })]} />);

    expect(screen.queryByText(/デモデータ.*が含まれています/)).not.toBeInTheDocument();
  });

  it("still shows other warnings for a site whose only remaining warning after filtering mock is non-mock", () => {
    render(<DataQualityWarnings ranking={ranking} dataQuality={[makeDq({ warnings: ["contains_mock_data", "lighthouse_failed"] })]} />);

    expect(screen.queryByText(/デモデータ.*が含まれています/)).not.toBeInTheDocument();
    expect(screen.getByText(/Lighthouse計測に失敗しました/)).toBeInTheDocument();
  });

  it("renders nothing when there are no warnings", () => {
    const { container } = render(<DataQualityWarnings ranking={ranking} dataQuality={[makeDq()]} />);

    expect(container).toBeEmptyDOMElement();
  });

  it("renders nothing when the only warning is contains_mock_data", () => {
    const { container } = render(<DataQualityWarnings ranking={ranking} dataQuality={[makeDq({ warnings: ["contains_mock_data"] })]} />);

    expect(container).toBeEmptyDOMElement();
  });
});
