import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { PerformanceDetails } from "@/features/analysis/results/performance-details";
import type { MetricEvaluation } from "@/types/analysis";

function makeMetric(overrides: Partial<MetricEvaluation> = {}): MetricEvaluation {
  return {
    key: "lighthouse_performance", name: "Lighthouse Performance", category_key: "performance", unit: "pt",
    scoring_type: "lighthouse", status: "unavailable", value: null, raw_value: null, min_value: null,
    target_value: null, max_value: null, higher_is_better: true, confidence: null, source_type: "lighthouse",
    measured_at: null, error_code: "ANALYZER_LIGHTHOUSE_FAILED", error_message: "Lighthouse計測に失敗しました。",
    counts_toward_score: false, score: null, max_score: null,
    ...overrides,
  };
}

describe("PerformanceDetails", () => {
  it("shows the failure reason instead of a bare dash when Lighthouse is unavailable", () => {
    render(<PerformanceDetails metrics={[makeMetric()]} />);

    expect(screen.getByText(/Lighthouse計測に失敗したため/)).toBeInTheDocument();
    expect(screen.getByText("Lighthouse計測に失敗しました。")).toBeInTheDocument();
    expect(screen.getByText(/表示速度カテゴリの採点のみに影響/)).toBeInTheDocument();
  });

  it("shows the score grid when Lighthouse succeeded", () => {
    const metrics = [
      makeMetric({ status: "success", value: 82, counts_toward_score: true, score: 8.2, max_score: 10, error_message: null }),
      { ...makeMetric({ status: "success" }), key: "lcp", name: "LCP", unit: "ms", value: 2200 },
    ];

    render(<PerformanceDetails metrics={metrics} />);

    expect(screen.getByText("82pt")).toBeInTheDocument();
  });
});
