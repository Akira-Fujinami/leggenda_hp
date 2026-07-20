import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { CategoryScoreCard } from "@/features/analysis/results/category-score-card";
import type { CategoryScore, MetricEvaluation } from "@/types/analysis";

describe("CategoryScoreCard", () => {
  it("shows 評価不可 instead of a fabricated 0/X score when nothing was measurable", () => {
    const category: CategoryScore = {
      key: "performance", name: "表示速度", score: 0, max_available_score: 0, configured_max_score: 15, coverage_rate: 0,
    };

    render(<CategoryScoreCard category={category} metrics={[]} />);

    expect(screen.getByText("評価不可")).toBeInTheDocument();
    expect(screen.queryByText("0 / 15")).not.toBeInTheDocument();
    expect(screen.getByText(/採点対象となる指標を取得できなかった/)).toBeInTheDocument();
  });

  it("shows a genuine measured score when max_available_score is positive", () => {
    const category: CategoryScore = {
      key: "technical_seo", name: "技術SEO", score: 18, max_available_score: 20, configured_max_score: 20, coverage_rate: 100,
    };

    render(<CategoryScoreCard category={category} metrics={[]} />);

    expect(screen.getByText(/18 \/ 20点/)).toBeInTheDocument();
  });

  it("counts unavailable metrics separately from problem metrics", () => {
    const category: CategoryScore = {
      key: "authority", name: "外部SEO", score: 0, max_available_score: 4, configured_max_score: 15, coverage_rate: 26.67,
    };
    const metrics: MetricEvaluation[] = [
      {
        key: "authority_score", name: "Authority Score", category_key: "authority", unit: null, scoring_type: "threshold",
        status: "unavailable", value: null, raw_value: null, min_value: null, target_value: null, max_value: null,
        higher_is_better: true, confidence: null, source_type: "semrush", measured_at: null, error_code: "SEMRUSH_NOT_CONFIGURED",
        error_message: null, counts_toward_score: false, score: null, max_score: null,
      },
    ];

    render(<CategoryScoreCard category={category} metrics={metrics} />);

    expect(screen.getByText("未取得の項目: 1件")).toBeInTheDocument();
  });
});
