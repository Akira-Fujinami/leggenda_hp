import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { PerformanceDetails } from "@/features/analysis/results/performance-details";
import type { MetricEvaluation } from "@/types/analysis";

function makeMetric(overrides: Partial<MetricEvaluation> = {}): MetricEvaluation {
  return {
    key: "lighthouse_performance", name: "Lighthouse Performance", category_key: "performance", value_type: "score", unit: "pt",
    scoring_type: "lighthouse", status: "unavailable", value: null, raw_value: null, evidence: null, min_value: null,
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

  it("shows the Lighthouse SEO score alongside the other category scores", () => {
    const metrics = [
      makeMetric({ status: "success", value: 82, counts_toward_score: true, score: 8.2, max_score: 10, error_message: null }),
      { ...makeMetric({ status: "success" }), key: "lighthouse_seo_score", name: "Lighthouse SEOスコア", value: 95, counts_toward_score: true, score: 3, max_score: 3 },
    ];

    render(<PerformanceDetails metrics={metrics} />);

    expect(screen.getByText("SEO")).toBeInTheDocument();
    expect(screen.getByText("95pt")).toBeInTheDocument();
  });

  it("shows a single-run measurement warning when run_count is 1", () => {
    const metrics = [
      makeMetric({
        status: "success", value: 82, counts_toward_score: true, score: 8.2, max_score: 10, error_message: null,
        evidence: { metadata: { run_count: 1 } },
      }),
    ];

    render(<PerformanceDetails metrics={metrics} />);

    expect(screen.getByText(/単発計測/)).toBeInTheDocument();
    expect(screen.getByText(/再計測を推奨/)).toBeInTheDocument();
  });

  it("does not show the single-run warning when evidence has no run_count metadata", () => {
    const metrics = [
      makeMetric({ status: "success", value: 82, counts_toward_score: true, score: 8.2, max_score: 10, error_message: null }),
    ];

    render(<PerformanceDetails metrics={metrics} />);

    expect(screen.queryByText(/単発計測/)).not.toBeInTheDocument();
  });

  it("shows request count and transfer size as reference info, converting bytes to KB, behind the detail expand", async () => {
    const user = userEvent.setup();
    const metrics = [
      makeMetric({ status: "success", value: 82, counts_toward_score: true, score: 8.2, max_score: 10, error_message: null }),
      { ...makeMetric({ status: "success" }), key: "lighthouse_request_count", name: "リクエスト数", scoring_type: "not_scored", unit: "requests", value: 42, counts_toward_score: false },
      { ...makeMetric({ status: "success" }), key: "lighthouse_transfer_size", name: "転送量", scoring_type: "not_scored", unit: "bytes", value: 512000, counts_toward_score: false },
    ];

    render(<PerformanceDetails metrics={metrics} />);

    expect(screen.queryByText(/リクエスト数: 42requests/)).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /詳細指標を表示/ }));

    expect(screen.getByText(/リクエスト数: 42requests/)).toBeInTheDocument();
    expect(screen.getByText(/転送量: 500 KB/)).toBeInTheDocument();
  });
});
