import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { ContentDetails } from "@/features/analysis/results/content-details";
import type { MetricEvaluation } from "@/types/analysis";

function makeMetric(overrides: Partial<MetricEvaluation> = {}): MetricEvaluation {
  return {
    key: "pricing_info_link_present", name: "料金情報リンク", category_key: "content", value_type: "boolean", unit: null,
    scoring_type: "boolean", status: "not_found", value: false, raw_value: null, evidence: null, min_value: null,
    target_value: null, max_value: null, higher_is_better: true, confidence: 1, source_type: "static_html",
    measured_at: null, error_code: null, error_message: null, counts_toward_score: false, score: null, max_score: null,
    ...overrides,
  };
}

describe("ContentDetails", () => {
  it("shows the detected link URL and text as a safe clickable link", () => {
    const metric = makeMetric({
      status: "success",
      value: true,
      counts_toward_score: true,
      score: 1,
      max_score: 1,
      raw_value: { url: "https://example.com/pricing", text: "料金プラン", confidence: 0.95, link_type: "internal" },
    });

    render(<ContentDetails metrics={[metric]} />);

    const link = screen.getByRole("link", { name: "料金プラン" });
    expect(link).toHaveAttribute("href", "https://example.com/pricing");
    expect(link).toHaveAttribute("target", "_blank");
    expect(link).toHaveAttribute("rel", "noopener noreferrer");
  });

  it("does not render a link when no business link was detected", () => {
    const metric = makeMetric({ status: "not_found", value: false, raw_value: { url: null, text: null } });

    render(<ContentDetails metrics={[metric]} />);

    expect(screen.getByText("検出されませんでした")).toBeInTheDocument();
    expect(screen.queryByRole("link")).not.toBeInTheDocument();
  });

  it("refuses to render a javascript: scheme href even if present in raw_value", () => {
    const metric = makeMetric({
      status: "success",
      value: true,
      raw_value: { url: "javascript:alert(1)", text: "clickme" },
    });

    render(<ContentDetails metrics={[metric]} />);

    expect(screen.queryByRole("link")).not.toBeInTheDocument();
  });

  it("shows a priced product/plan card as distinct from a fixed pricing page link", () => {
    const priceCardMetric = makeMetric({
      key: "pricing_card_or_product_price_present", name: "価格付き商品・プラン", scoring_type: "not_scored",
      status: "success", value: true,
      raw_value: { present: true, count: 3, confidence: 0.85, sample_text: "宿泊プランA 10,000円〜" },
    });

    render(<ContentDetails metrics={[priceCardMetric]} />);

    expect(screen.getByText("価格付き商品・プラン")).toBeInTheDocument();
    expect(screen.getByText(/宿泊プランA 10,000円〜/)).toBeInTheDocument();
  });

  it("shows a help center row separately from FAQ", () => {
    const helpCenterMetric = makeMetric({
      key: "help_center_link_present", name: "ヘルプ・サポート導線", scoring_type: "not_scored",
      status: "success", value: true,
      raw_value: { url: "https://example.com/help", text: "ヘルプ", confidence: 0.75, link_type: "internal" },
    });

    render(<ContentDetails metrics={[helpCenterMetric]} />);

    expect(screen.getByText("ヘルプ・サポート導線")).toBeInTheDocument();
  });
});
