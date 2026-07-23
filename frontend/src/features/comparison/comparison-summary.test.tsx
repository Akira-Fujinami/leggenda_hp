import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { ComparisonSummary } from "@/features/comparison/comparison-summary";
import type { CategoryComparison, DataQuality, ExternalSeoInfo, RankingEntry } from "@/types/comparison";

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

const categories: CategoryComparison[] = [
  {
    key: "performance", name: "表示速度", configured_max_score: 15,
    sites: [
      { website_analysis_id: 1, score: 0.6, max_available_score: 15, coverage_rate: 100, gap_vs_primary: null },
      { website_analysis_id: 2, score: 3.56, max_available_score: 15, coverage_rate: 100, gap_vs_primary: 2.96 },
    ],
  },
  {
    key: "authority", name: "外部SEO", configured_max_score: 15,
    sites: [
      { website_analysis_id: 1, score: 0, max_available_score: 0, coverage_rate: 0, gap_vs_primary: null },
      { website_analysis_id: 2, score: 0, max_available_score: 0, coverage_rate: 0, gap_vs_primary: null },
    ],
  },
];

const dataQuality: DataQuality[] = [];
const externalSeo: ExternalSeoInfo[] = [];

describe("ComparisonSummary", () => {
  it("shows the ranking table", () => {
    render(<ComparisonSummary ranking={ranking} categories={categories} dataQuality={dataQuality} externalSeo={externalSeo} />);

    expect(screen.getByText("サイト別ランキング")).toBeInTheDocument();
  });

  it("shows the top category differences, excluding evaluation-unavailable categories", () => {
    render(<ComparisonSummary ranking={ranking} categories={categories} dataQuality={dataQuality} externalSeo={externalSeo} />);

    expect(screen.getByText("重要な差")).toBeInTheDocument();
    expect(screen.getByText("表示速度")).toBeInTheDocument();
    expect(screen.getByText("差 2.96")).toBeInTheDocument();
    // authority(全サイト評価不可)は差の対象に含めない。
    expect(screen.queryByText("外部SEO")).not.toBeInTheDocument();
  });

  it("does not show a 重要な差 card when no category has a positive diff", () => {
    const equalCategories: CategoryComparison[] = [
      {
        key: "content", name: "コンテンツ", configured_max_score: 15,
        sites: [
          { website_analysis_id: 1, score: 10, max_available_score: 15, coverage_rate: 100, gap_vs_primary: null },
          { website_analysis_id: 2, score: 10, max_available_score: 15, coverage_rate: 100, gap_vs_primary: 0 },
        ],
      },
    ];

    render(<ComparisonSummary ranking={ranking} categories={equalCategories} dataQuality={dataQuality} externalSeo={externalSeo} />);

    expect(screen.queryByText("重要な差")).not.toBeInTheDocument();
  });
});
