import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ExternalSeoDetails } from "@/features/analysis/results/external-seo-details";
import type { MetricEvaluation } from "@/types/analysis";

function makeMetric(overrides: Partial<MetricEvaluation> = {}): MetricEvaluation {
  return {
    key: "authority_score", name: "Authority Score", category_key: "authority", value_type: "number", unit: null, scoring_type: "threshold",
    status: "unavailable", value: null, raw_value: null, evidence: null, min_value: null, target_value: null, max_value: null,
    higher_is_better: true, confidence: null, source_type: "semrush", measured_at: null,
    error_code: "SEMRUSH_NOT_CONFIGURED", error_message: null, counts_toward_score: false, score: null, max_score: null,
    ...overrides,
  };
}

describe("ExternalSeoDetails", () => {
  it("shows an unavailable reason instead of a fabricated 0 point when Semrush is unconfigured", () => {
    render(<ExternalSeoDetails metrics={[makeMetric()]} />);

    expect(screen.getByText("未取得")).toBeInTheDocument();
    expect(screen.getByText(/Semrush APIキーが設定されていない/)).toBeInTheDocument();
  });

  it("labels mock data distinctly from real data", () => {
    const metric = makeMetric({ status: "not_applicable", value: 37, confidence: 0 });

    render(<ExternalSeoDetails metrics={[metric]} />);

    expect(screen.getByText("デモデータ")).toBeInTheDocument();
  });

  it("shows real values with a 実データ badge when the fetch succeeded", () => {
    const metric = makeMetric({ status: "success", value: 42, confidence: 0.9, evidence: { provider: "semrush", is_mock: false } });

    render(<ExternalSeoDetails metrics={[metric]} />);

    expect(screen.getByText("実データ")).toBeInTheDocument();
    expect(screen.getByText("42")).toBeInTheDocument();
    expect(screen.getByText(/Provider: semrush/)).toBeInTheDocument();
  });

  it("never shows the raw semrush provider name alongside the デモデータ badge for mock data", () => {
    // MetricDefinition.source_type is always "semrush" regardless of which
    // provider actually ran at measurement time, so the panel must read the
    // per-result evidence.provider/is_mock instead of source_type.
    const metric = makeMetric({
      status: "not_applicable",
      value: 37,
      confidence: 0,
      evidence: { provider: "mock", is_mock: true },
    });

    render(<ExternalSeoDetails metrics={[metric]} />);

    expect(screen.getByText("デモデータ")).toBeInTheDocument();
    expect(screen.getByText(/Provider: Mock/)).toBeInTheDocument();
    expect(screen.queryByText(/Provider: semrush/)).not.toBeInTheDocument();
  });

  it("collapses the score grid by default for mock data, revealing it only on expand", async () => {
    const user = userEvent.setup();
    const metric = makeMetric({ status: "not_applicable", value: 37, confidence: 0 });

    render(<ExternalSeoDetails metrics={[metric]} />);

    expect(screen.queryByText("37")).not.toBeInTheDocument();
    expect(screen.getByText(/総合スコアには未反映/)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /総合スコアには未反映/ }));

    expect(screen.getByText("37")).toBeInTheDocument();
  });
});
