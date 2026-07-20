import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { ConversionDetails } from "@/features/analysis/results/conversion-details";
import type { MetricEvaluation } from "@/types/analysis";

function makeMetric(overrides: Partial<MetricEvaluation> = {}): MetricEvaluation {
  return {
    key: "fixed_cta_present", name: "固定表示CTA", category_key: "conversion", unit: null,
    scoring_type: "boolean", status: "not_found", value: false, raw_value: null, min_value: null,
    target_value: null, max_value: null, higher_is_better: true, confidence: 1, source_type: "analyzer",
    measured_at: null, error_code: null, error_message: null, counts_toward_score: false, score: null, max_score: null,
    ...overrides,
  };
}

describe("ConversionDetails", () => {
  it("shows the detected fixed CTA's text, position, and link", () => {
    const metric = makeMetric({
      status: "success",
      value: true,
      counts_toward_score: true,
      score: 2,
      max_score: 2,
      raw_value: { text: "お問い合わせ", href: "/contact", position: "fixed" },
    });

    render(<ConversionDetails metrics={[metric]} />);

    expect(screen.getByText("position: fixed")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "お問い合わせ" })).toHaveAttribute("href", "/contact");
  });

  it("shows the form input burden tier as reference description, not a bare number", () => {
    const metric: MetricEvaluation = {
      key: "form_input_burden", name: "フォーム入力負担(必須項目数)", category_key: "conversion", unit: "fields",
      scoring_type: "inverse_linear", status: "success", value: 8, raw_value: { total_field_count: 10, tier: "medium" },
      min_value: null, target_value: 3, max_value: 10, higher_is_better: false, confidence: 1, source_type: "static_html",
      measured_at: null, error_code: null, error_message: null, counts_toward_score: true, score: 0.6, max_score: 2,
    };

    render(<ConversionDetails metrics={[metric]} />);

    expect(screen.getByText(/入力項目合計10個・負担: 普通/)).toBeInTheDocument();
  });

  it("distinguishes a genuinely-absent third-party reservation service from unmeasured", () => {
    const metric: MetricEvaluation = {
      key: "external_reservation_service_detected", name: "外部予約サービス利用", category_key: "conversion", unit: null,
      scoring_type: "not_scored", status: "not_found", value: false, raw_value: { detected: false },
      min_value: null, target_value: null, max_value: null, higher_is_better: true, confidence: 1, source_type: "static_html",
      measured_at: null, error_code: null, error_message: null, counts_toward_score: false, score: null, max_score: null,
    };

    render(<ConversionDetails metrics={[metric]} />);

    expect(screen.getByText("検出されませんでした")).toBeInTheDocument();
  });
});
