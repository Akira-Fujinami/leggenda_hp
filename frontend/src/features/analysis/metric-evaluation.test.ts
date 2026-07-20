import { describe, expect, it } from "vitest";
import { classifyMetric, formatMetricValue } from "@/features/analysis/metric-evaluation";
import type { MetricEvaluation } from "@/types/analysis";

function makeMetric(overrides: Partial<MetricEvaluation> = {}): MetricEvaluation {
  return {
    key: "test_metric",
    name: "テスト指標",
    category_key: "technical_seo",
    value_type: "boolean",
    unit: null,
    scoring_type: "boolean",
    status: "success",
    value: true,
    raw_value: null,
    evidence: null,
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

describe("formatMetricValue", () => {
  it("multiplies a percentage-typed ratio by 100 for display, without altering the stored value", () => {
    const metric = makeMetric({ value_type: "percentage", unit: "%", value: 0.9868 });

    expect(formatMetricValue(metric)).toBe("98.68%");
  });

  it("formats a second percentage example correctly (not the raw ratio)", () => {
    const metric = makeMetric({ value_type: "percentage", unit: "%", value: 0.8519 });

    expect(formatMetricValue(metric)).toBe("85.19%");
  });

  it("does not multiply a non-percentage numeric value by 100", () => {
    const metric = makeMetric({ value_type: "number", unit: "count", value: 22 });

    expect(formatMetricValue(metric)).toBe("22件");
  });

  it("labels a characters-unit value in Japanese instead of leaking the internal 'characters' unit", () => {
    const metric = makeMetric({ value_type: "number", unit: "characters", value: 8060 });

    expect(formatMetricValue(metric)).toBe("8,060文字");
  });

  it("labels a words-unit value distinctly from characters", () => {
    const metric = makeMetric({ value_type: "number", unit: "words", value: 120 });

    expect(formatMetricValue(metric)).toBe("120単語");
  });

  it("labels a fields-unit value as 項目", () => {
    const metric = makeMetric({ value_type: "number", unit: "fields", value: 5 });

    expect(formatMetricValue(metric)).toBe("5項目");
  });

  it("formats bytes as KB/MB rather than a raw byte count", () => {
    const metric = makeMetric({ value_type: "number", unit: "bytes", value: 512000 });

    expect(formatMetricValue(metric)).toBe("500.0KB");
  });

  it("formats milliseconds as seconds once the value exceeds 1000ms", () => {
    const metric = makeMetric({ value_type: "number", unit: "ms", value: 2500 });

    expect(formatMetricValue(metric)).toBe("2.5秒");
  });

  it("keeps a sub-second millisecond value in ms", () => {
    const metric = makeMetric({ value_type: "number", unit: "ms", value: 800 });

    expect(formatMetricValue(metric)).toBe("800ms");
  });
});
