import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { ComparisonTable } from "@/features/comparison/comparison-table";
import type { CategoryComparison, MetricComparison, RankingEntry } from "@/types/comparison";

const ranking: RankingEntry[] = [
  {
    rank: 1, website_analysis_id: 1, website_id: 1, website_name: "自社サイト", is_primary: true,
    overall_score: 80, display_score: 80, coverage_rate: 90, confidence_rate: 95, low_data_warning: false,
    score_gap_vs_primary: null,
  },
  {
    rank: 2, website_analysis_id: 2, website_id: 2, website_name: "競合サイト", is_primary: false,
    overall_score: 65, display_score: 65, coverage_rate: 80, confidence_rate: 90, low_data_warning: false,
    score_gap_vs_primary: -15,
  },
];

const categories: CategoryComparison[] = [
  {
    key: "technical_seo", name: "技術的SEO", configured_max_score: 20,
    sites: [
      { website_analysis_id: 1, score: 18, max_available_score: 20, coverage_rate: 100, gap_vs_primary: null },
      { website_analysis_id: 2, score: 12, max_available_score: 20, coverage_rate: 100, gap_vs_primary: -6 },
    ],
  },
];

function metric(overrides: Partial<MetricComparison> = {}): MetricComparison {
  return {
    key: "title_present", name: "titleタグ", category_key: "technical_seo", value_type: "boolean",
    unit: null, source_type: "static_html", higher_is_better: true,
    sites: [
      { website_analysis_id: 1, status: "success", value: true, confidence: 1, evidence: null, measured_at: null, error_code: null, error_message: null, is_mock: false, gap_vs_primary: null },
      { website_analysis_id: 2, status: "success", value: false, confidence: 1, evidence: null, measured_at: null, error_code: null, error_message: null, is_mock: false, gap_vs_primary: null },
    ],
    ...overrides,
  };
}

describe("ComparisonTable", () => {
  it("renders a sticky first column and sticky header row", () => {
    render(<ComparisonTable ranking={ranking} categories={categories} metrics={[metric()]} />);

    const headerCell = screen.getByText("項目");
    expect(headerCell.className).toContain("sticky");
    expect(headerCell.className).toContain("left-0");
  });

  it("shows the category score budget per site", () => {
    render(<ComparisonTable ranking={ranking} categories={categories} metrics={[metric()]} />);

    expect(screen.getByText("18 / 20")).toBeInTheDocument();
    expect(screen.getByText("12 / 20")).toBeInTheDocument();
  });

  it("greys out unmeasured metrics instead of showing a fabricated value", () => {
    const unmeasured = metric({
      sites: [
        { website_analysis_id: 1, status: "unavailable", value: null, confidence: null, evidence: null, measured_at: null, error_code: null, error_message: null, is_mock: false, gap_vs_primary: null },
        { website_analysis_id: 2, status: "success", value: true, confidence: 1, evidence: null, measured_at: null, error_code: null, error_message: null, is_mock: false, gap_vs_primary: null },
      ],
    });

    render(<ComparisonTable ranking={ranking} categories={categories} metrics={[unmeasured]} />);

    expect(screen.getByText("未取得")).toBeInTheDocument();
  });

  it("flags mock data with a demo-data badge", () => {
    const mocked = metric({
      sites: [
        { website_analysis_id: 1, status: "not_applicable", value: 42, confidence: 0, evidence: null, measured_at: null, error_code: null, error_message: null, is_mock: true, gap_vs_primary: null },
        { website_analysis_id: 2, status: "success", value: true, confidence: 1, evidence: null, measured_at: null, error_code: null, error_message: null, is_mock: false, gap_vs_primary: null },
      ],
    });

    render(<ComparisonTable ranking={ranking} categories={categories} metrics={[mocked]} />);

    expect(screen.getByText("デモデータ")).toBeInTheDocument();
  });

  it("shows an error indicator for metrics that failed", () => {
    const errored = metric({
      sites: [
        { website_analysis_id: 1, status: "error", value: null, confidence: null, evidence: null, measured_at: null, error_code: "TIMEOUT", error_message: "計測タイムアウト", is_mock: false, gap_vs_primary: null },
        { website_analysis_id: 2, status: "success", value: true, confidence: 1, evidence: null, measured_at: null, error_code: null, error_message: null, is_mock: false, gap_vs_primary: null },
      ],
    });

    render(<ComparisonTable ranking={ranking} categories={categories} metrics={[errored]} />);

    expect(screen.getByText(/エラー/)).toBeInTheDocument();
  });
});
