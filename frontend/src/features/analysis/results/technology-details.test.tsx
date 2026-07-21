import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { TechnologyDetails } from "@/features/analysis/results/technology-details";
import type { MetricEvaluation } from "@/types/analysis";

function makeMetric(overrides: Partial<MetricEvaluation> = {}): MetricEvaluation {
  return {
    key: "ga_detected", name: "Google Analytics", category_key: "technology", value_type: "boolean", unit: null, scoring_type: "not_scored",
    status: "not_found", value: false, raw_value: null, evidence: null, min_value: null, target_value: null, max_value: null,
    higher_is_better: true, confidence: 1, source_type: "analyzer", measured_at: null, error_code: null,
    error_message: null, counts_toward_score: false, score: null, max_score: null,
    ...overrides,
  };
}

describe("TechnologyDetails", () => {
  it("distinguishes investigated-but-not-found from a genuine fetch failure", () => {
    const metrics = [makeMetric({ key: "ga_detected", status: "not_found" })];

    render(<TechnologyDetails metrics={metrics} />);

    expect(screen.getByText("検出されませんでした")).toBeInTheDocument();
  });

  it("shows a detected badge distinct from a good/bad judgment (no CMS bias)", () => {
    const metrics = [makeMetric({ key: "cms_detected", name: "CMS検出", value: "WordPress", status: "success" })];

    render(<TechnologyDetails metrics={metrics} />);

    expect(screen.getByText("WordPress")).toBeInTheDocument();
    expect(screen.getByText("検出されました")).toBeInTheDocument();
  });

  it("shows a clear failure state instead of a blank section when technology detection fails entirely", () => {
    const metrics = [
      makeMetric({
        key: "cms_detected", name: "CMS検出", status: "error", value: null,
        error_message: "analyzerへの接続に失敗しました。",
      }),
      makeMetric({ key: "analytics_configured", name: "アクセス解析タグ", status: "error", value: null }),
    ];

    render(<TechnologyDetails metrics={metrics} />);

    expect(screen.getByText(/技術・計測環境の検出に失敗しました/)).toBeInTheDocument();
    expect(screen.getByText("analyzerへの接続に失敗しました。")).toBeInTheDocument();
    expect(screen.getByText(/再分析することで再取得できる可能性があります/)).toBeInTheDocument();
    expect(screen.queryByText("検出されませんでした")).not.toBeInTheDocument();
  });
});
