import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { TechnologyDetails } from "@/features/analysis/results/technology-details";
import type { MetricEvaluation } from "@/types/analysis";

function makeMetric(overrides: Partial<MetricEvaluation> = {}): MetricEvaluation {
  return {
    key: "ga_detected", name: "Google Analytics", category_key: "technology", unit: null, scoring_type: "not_scored",
    status: "not_found", value: false, raw_value: null, min_value: null, target_value: null, max_value: null,
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
});
