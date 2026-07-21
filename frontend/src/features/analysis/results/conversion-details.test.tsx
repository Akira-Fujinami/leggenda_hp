import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { ConversionDetails } from "@/features/analysis/results/conversion-details";
import type { MetricEvaluation } from "@/types/analysis";

function makeMetric(overrides: Partial<MetricEvaluation> = {}): MetricEvaluation {
  return {
    key: "fixed_cta_present", name: "固定表示CTA", category_key: "conversion", value_type: "boolean", unit: null,
    scoring_type: "boolean", status: "not_found", value: false, raw_value: null, evidence: null, min_value: null,
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

  it("shows the form input burden tier as a reference description, not a bare number", () => {
    const metric = makeMetric({
      key: "form_input_burden", name: "フォーム入力負担(必須項目数)", value_type: "number", unit: "fields",
      scoring_type: "inverse_linear", status: "success", value: 8,
      raw_value: { tier: "medium", representative_form_reason: "field_names" },
      target_value: 3, max_value: 10, higher_is_better: false, source_type: "static_html",
      counts_toward_score: true, score: 0.6, max_score: 2,
    });

    render(<ConversionDetails metrics={[metric]} />);

    expect(screen.getByText(/入力負担: 普通/)).toBeInTheDocument();
  });

  it("never classifies a large representative form (35 fields, 0 required) as a small burden", () => {
    const metric = makeMetric({
      key: "form_input_burden", name: "フォーム入力負担(必須項目数)", value_type: "number", unit: "fields",
      scoring_type: "inverse_linear", status: "success", value: 0,
      raw_value: { tier: "large", representative_form_reason: "largest_search_form_fallback" },
      target_value: 3, max_value: 10, higher_is_better: false, source_type: "static_html",
      counts_toward_score: true, score: 0, max_score: 2,
    });

    render(<ConversionDetails metrics={[metric]} />);

    expect(screen.getByText(/入力負担: 多い/)).toBeInTheDocument();
    expect(screen.queryByText(/入力負担: 少ない/)).not.toBeInTheDocument();
  });

  it("shows page-wide form/input counts separately from the representative form's own field count", () => {
    const metrics = [
      makeMetric({ key: "page_form_count", name: "ページ内フォーム数", value_type: "number", unit: "count", scoring_type: "not_scored", value: 12 }),
      makeMetric({ key: "page_input_count", name: "ページ内入力項目総数", value_type: "number", unit: "fields", scoring_type: "not_scored", value: 134 }),
      makeMetric({ key: "representative_form_field_count", name: "代表フォームの入力項目数", value_type: "number", unit: "fields", scoring_type: "not_scored", status: "success", value: 35 }),
    ];

    render(<ConversionDetails metrics={metrics} />);

    expect(screen.getByText("ページ内フォーム数")).toBeInTheDocument();
    expect(screen.getByText(/12件/)).toBeInTheDocument();
    expect(screen.getByText(/134項目/)).toBeInTheDocument();
    expect(screen.getByText(/35項目/)).toBeInTheDocument();
  });

  it("shows the detected SNS platform names and a collapsible URL list", () => {
    const metric = makeMetric({
      key: "sns_link_present", name: "SNSリンク", value_type: "boolean", scoring_type: "boolean",
      status: "success", value: true, counts_toward_score: true, score: 1, max_score: 1,
      raw_value: {
        detected: true,
        count: 5,
        platforms: [
          { platform: "facebook", url: "https://www.facebook.com/example", label: "Facebook", source: "href_host", confidence: 0.95 },
          { platform: "x", url: "https://x.com/example", label: "X", source: "href_host", confidence: 0.95 },
          { platform: "instagram", url: "https://www.instagram.com/example", label: "Instagram", source: "href_host", confidence: 0.95 },
          { platform: "line", url: "https://line.me/example", label: "LINE", source: "href_host", confidence: 0.95 },
          { platform: "youtube", url: "https://www.youtube.com/example", label: "YouTube", source: "href_host", confidence: 0.95 },
        ],
      },
    });

    render(<ConversionDetails metrics={[metric]} />);

    expect(screen.getByText("Facebook、X、Instagram、LINE、YouTube")).toBeInTheDocument();
    expect(screen.getByText(/SNSリンクのURLを表示/)).toBeInTheDocument();
  });

  it("shows a chatbot support row when detected", () => {
    const metric = makeMetric({
      key: "chatbot_detected", name: "チャットサポート", value_type: "boolean", scoring_type: "not_scored",
      status: "success", value: true, raw_value: { detected: true, matched: "tawk.to" },
    });

    render(<ConversionDetails metrics={[metric]} />);

    expect(screen.getByText("チャットサポート")).toBeInTheDocument();
  });

  it("distinguishes a genuinely-absent third-party reservation service from unmeasured", () => {
    const metric = makeMetric({
      key: "external_reservation_service_detected", name: "外部予約サービス利用", value_type: "boolean",
      scoring_type: "not_scored", status: "not_found", value: false, raw_value: { detected: false },
      source_type: "static_html",
    });

    render(<ConversionDetails metrics={[metric]} />);

    expect(screen.getByText("検出されませんでした")).toBeInTheDocument();
  });
});
