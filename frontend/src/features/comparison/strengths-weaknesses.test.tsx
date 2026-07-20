import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { StrengthsWeaknesses } from "@/features/comparison/strengths-weaknesses";
import type { RankingEntry, StrengthWeaknessGroup } from "@/types/comparison";

const ranking: RankingEntry[] = [
  {
    rank: 1, website_analysis_id: 1, website_id: 1, website_name: "自社サイト", is_primary: true,
    overall_score: 80, display_score: 80, coverage_rate: 90, confidence_rate: 95, low_data_warning: false,
    score_gap_vs_primary: null,
  },
];

describe("StrengthsWeaknesses", () => {
  it("renders the label of each strength/weakness item (which the API returns as an object, not a bare string)", () => {
    const strengths: StrengthWeaknessGroup[] = [
      { website_analysis_id: 1, items: [{ type: "category", category_key: "technical_seo", label: "技術的SEOのスコアが高水準です" }] },
    ];
    const weaknesses: StrengthWeaknessGroup[] = [
      { website_analysis_id: 1, items: [{ type: "recommendation", metric_key: null, label: "titleタグを設定してください。", priority: "high" }] },
    ];

    render(<StrengthsWeaknesses ranking={ranking} strengths={strengths} weaknesses={weaknesses} />);

    expect(screen.getByText("技術的SEOのスコアが高水準です")).toBeInTheDocument();
    expect(screen.getByText("titleタグを設定してください。")).toBeInTheDocument();
  });

  it("shows an empty-state message when there are no strengths or weaknesses", () => {
    const empty: StrengthWeaknessGroup[] = [{ website_analysis_id: 1, items: [] }];

    render(<StrengthsWeaknesses ranking={ranking} strengths={empty} weaknesses={empty} />);

    expect(screen.getByText("目立った強みは見つかりませんでした。")).toBeInTheDocument();
    expect(screen.getByText("目立った弱みは見つかりませんでした。")).toBeInTheDocument();
  });
});
