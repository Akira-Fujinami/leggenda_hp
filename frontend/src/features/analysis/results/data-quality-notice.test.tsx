import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { DataQualityNotice } from "@/features/analysis/results/data-quality-notice";
import type { AnalysisScore } from "@/types/analysis";

function makeScore(overrides: Partial<AnalysisScore> = {}): AnalysisScore {
  return {
    overall_score: 37, display_score: 37, available_score: 40, configured_max_score: 100,
    coverage_rate: 55, confidence_rate: 90, category_scores: [],
    metric_summary: { success: 30, not_found: 5, unavailable: 8, error: 0, not_applicable: 10 },
    ...overrides,
  };
}

describe("DataQualityNotice", () => {
  it("shows 参考スコア and a warning when coverage is below 70%", () => {
    render(<DataQualityNotice score={makeScore({ coverage_rate: 55 })} />);

    expect(screen.getByText("参考スコア")).toBeInTheDocument();
    expect(screen.getByText(/測定カバー率が55%のため、このスコアは参考値です/)).toBeInTheDocument();
  });

  it("shows 総合スコア without a warning when coverage is 70% or higher", () => {
    render(<DataQualityNotice score={makeScore({ coverage_rate: 85 })} />);

    expect(screen.getByText("総合スコア")).toBeInTheDocument();
    expect(screen.queryByText(/参考値です/)).not.toBeInTheDocument();
  });
});
