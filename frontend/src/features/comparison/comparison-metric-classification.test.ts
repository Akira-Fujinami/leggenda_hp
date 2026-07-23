import { describe, expect, it } from "vitest";
import { classifyComparisonMetric } from "@/features/comparison/comparison-metric-classification";
import type { MetricComparison, MetricSiteValue } from "@/types/comparison";

function site(overrides: Partial<MetricSiteValue> = {}): MetricSiteValue {
  return {
    website_analysis_id: 1, status: "success", value: 10, confidence: 1, evidence: null,
    measured_at: null, error_code: null, error_message: null, is_mock: false, gap_vs_primary: null,
    ...overrides,
  };
}

function metric(overrides: Partial<MetricComparison> = {}): MetricComparison {
  return {
    key: "m", name: "Metric", category_key: "performance", value_type: "number", unit: null,
    source_type: "static_html", higher_is_better: true,
    sites: [site({ website_analysis_id: 1, value: 10 }), site({ website_analysis_id: 2, value: 10 })],
    ...overrides,
  };
}

describe("classifyComparisonMetric", () => {
  it("classifies as unavailable when any site is not cleanly measured", () => {
    const m = metric({ sites: [site({ website_analysis_id: 1, status: "success" }), site({ website_analysis_id: 2, status: "unavailable", value: null })] });

    expect(classifyComparisonMetric(m)).toBe("unavailable");
  });

  it("classifies as good when numeric values are within 10% of each other", () => {
    const m = metric({ sites: [site({ website_analysis_id: 1, value: 100 }), site({ website_analysis_id: 2, value: 105 })] });

    expect(classifyComparisonMetric(m)).toBe("good");
  });

  it("classifies as diff when numeric values differ by 10% or more", () => {
    const m = metric({ sites: [site({ website_analysis_id: 1, value: 100 }), site({ website_analysis_id: 2, value: 130 })] });

    expect(classifyComparisonMetric(m)).toBe("diff");
  });

  it("classifies a boolean metric as improve when a site lacks a higher_is_better feature", () => {
    const m = metric({
      higher_is_better: true,
      sites: [site({ website_analysis_id: 1, value: true }), site({ website_analysis_id: 2, value: false })],
    });

    expect(classifyComparisonMetric(m)).toBe("improve");
  });

  it("classifies a boolean metric as good when all sites share the same value", () => {
    const m = metric({
      higher_is_better: true,
      sites: [site({ website_analysis_id: 1, value: true }), site({ website_analysis_id: 2, value: true })],
    });

    expect(classifyComparisonMetric(m)).toBe("good");
  });

  it("respects higher_is_better=false when classifying a boolean metric as improve", () => {
    const m = metric({
      higher_is_better: false,
      sites: [site({ website_analysis_id: 1, value: false }), site({ website_analysis_id: 2, value: true })],
    });

    expect(classifyComparisonMetric(m)).toBe("improve");
  });
});
