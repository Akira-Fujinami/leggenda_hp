import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { SeoDetails } from "@/features/analysis/results/seo-details";
import type { MetricEvaluation } from "@/types/analysis";

function makeMetric(overrides: Partial<MetricEvaluation> = {}): MetricEvaluation {
  return {
    key: "title_present", name: "titleタグ", category_key: "technical_seo", unit: null, scoring_type: "boolean",
    status: "success", value: true, raw_value: null, min_value: null, target_value: null, max_value: null,
    higher_is_better: true, confidence: 1, source_type: "static_html", measured_at: null, error_code: null,
    error_message: null, counts_toward_score: true, score: 1, max_score: 1,
    ...overrides,
  };
}

describe("SeoDetails", () => {
  it("evaluates the title with its recommended character-length range", () => {
    const metrics: MetricEvaluation[] = [
      makeMetric({ key: "title_present", value: true }),
      makeMetric({
        key: "title_length_optimal", name: "title文字数", unit: "chars", scoring_type: "range", value: 50,
        min_value: 10, max_value: 65, score: 0.87, max_score: 0.87,
      }),
    ];

    render(<SeoDetails metrics={metrics} seo={{ title: "サンプルタイトル", meta_description: null, h1_count: 1, word_count: 100 }} />);

    expect(screen.getByText("タイトル(title)")).toBeInTheDocument();
    expect(screen.getByText(/50文字/)).toBeInTheDocument();
    expect(screen.getByText(/10〜65文字/)).toBeInTheDocument();
    expect(screen.getByText(/サンプルタイトル/)).toBeInTheDocument();
  });

  it("shows H1 as needing improvement when zero H1 tags are present", () => {
    const metrics: MetricEvaluation[] = [
      makeMetric({ key: "h1_single", name: "H1タグ(1件)", value: false, status: "not_found", score: 0, max_score: 3 }),
    ];

    render(<SeoDetails metrics={metrics} seo={null} />);

    expect(screen.getByText("要改善")).toBeInTheDocument();
  });
});
