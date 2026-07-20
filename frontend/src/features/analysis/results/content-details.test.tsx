import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { ContentDetails } from "@/features/analysis/results/content-details";
import type { MetricEvaluation } from "@/types/analysis";

function makeMetric(overrides: Partial<MetricEvaluation> = {}): MetricEvaluation {
  return {
    key: "pricing_info_link_present", name: "料金情報リンク", category_key: "content", unit: null,
    scoring_type: "boolean", status: "not_found", value: false, raw_value: null, min_value: null,
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
});
