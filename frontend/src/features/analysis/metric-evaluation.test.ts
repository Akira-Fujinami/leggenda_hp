import { describe, expect, it } from "vitest";
import { classifyMetric } from "@/features/analysis/metric-evaluation";
import type { MetricEvaluation } from "@/types/analysis";

function makeMetric(overrides: Partial<MetricEvaluation> = {}): MetricEvaluation {
  return {
    key: "test_metric",
    name: "テスト指標",
    category_key: "technical_seo",
    unit: null,
    scoring_type: "boolean",
    status: "success",
    value: true,
    raw_value: null,
    min_value: null,
    target_value: null,
    max_value: null,
    higher_is_better: true,
    confidence: 1,
    source_type: "static_html",
    measured_at: null,
    error_code: null,
    error_message: null,
    counts_toward_score: true,
    score: 1,
    max_score: 1,
    ...overrides,
  };
}

describe("classifyMetric", () => {
  it("never treats unavailable as a zero score (0点にしない)", () => {
    const metric = makeMetric({ status: "unavailable", counts_toward_score: false, score: null, max_score: null });

    expect(classifyMetric(metric)).toBe("unavailable");
  });

  it("classifies error status as failed, not a low score", () => {
    const metric = makeMetric({ status: "error", counts_toward_score: false, score: null, max_score: null });

    expect(classifyMetric(metric)).toBe("failed");
  });

  it("classifies not_applicable distinctly from a genuine zero score", () => {
    const metric = makeMetric({ status: "not_applicable", counts_toward_score: false, score: null, max_score: null, value: 37 });

    expect(classifyMetric(metric)).toBe("not_applicable");
  });

  it("classifies a not_scored informational metric as info when detected, not_found when absent", () => {
    const detected = makeMetric({ scoring_type: "not_scored", status: "success", counts_toward_score: false });
    const absent = makeMetric({ scoring_type: "not_scored", status: "not_found", counts_toward_score: false });

    expect(classifyMetric(detected)).toBe("info");
    expect(classifyMetric(absent)).toBe("not_found");
  });

  it("classifies a genuinely scored zero as improve, not unavailable", () => {
    const metric = makeMetric({ status: "not_found", counts_toward_score: true, score: 0, max_score: 3 });

    expect(classifyMetric(metric)).toBe("improve");
  });

  it("classifies high ratio as good, mid ratio as review, low ratio as improve", () => {
    expect(classifyMetric(makeMetric({ score: 9, max_score: 10 }))).toBe("good");
    expect(classifyMetric(makeMetric({ score: 6, max_score: 10 }))).toBe("review");
    expect(classifyMetric(makeMetric({ score: 2, max_score: 10 }))).toBe("improve");
  });

  it("treats a metric with zero max_score as unavailable rather than dividing by zero", () => {
    const metric = makeMetric({ counts_toward_score: true, score: 0, max_score: 0 });

    expect(classifyMetric(metric)).toBe("unavailable");
  });
});
