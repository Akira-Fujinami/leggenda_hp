import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
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
        key: "authority_score", name: "Authority Score", category_key: "authority", value_type: "number", unit: null, scoring_type: "threshold",
        status: "unavailable", value: null, raw_value: null, evidence: null, min_value: null, target_value: null, max_value: null,
        higher_is_better: true, confidence: null, source_type: "semrush", measured_at: null, error_code: "SEMRUSH_NOT_CONFIGURED",
        error_message: null, counts_toward_score: false, score: null, max_score: null,
      },
    ];

    render(<CategoryScoreCard category={category} metrics={metrics} />);

    expect(screen.getByText("未取得の項目: 1件")).toBeInTheDocument();
  });

  it("delegates to onViewDetails instead of expanding locally when provided", async () => {
    const user = userEvent.setup();
    const category: CategoryScore = {
      key: "technical_seo", name: "技術SEO", score: 18, max_available_score: 20, configured_max_score: 20, coverage_rate: 100,
    };
    const onViewDetails = vi.fn();

    render(<CategoryScoreCard category={category} metrics={[]} onViewDetails={onViewDetails} />);
    await user.click(screen.getByRole("button", { name: "詳細を見る" }));

    expect(onViewDetails).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole("button", { name: "詳細を開く" })).not.toBeInTheDocument();
  });

  it("falls back to local expand/collapse when onViewDetails is not provided", async () => {
    const user = userEvent.setup();
    const category: CategoryScore = {
      key: "accessibility", name: "アクセシビリティ", score: 5, max_available_score: 10, configured_max_score: 10, coverage_rate: 100,
    };
    const metric: MetricEvaluation = {
      key: "lang_present", name: "lang属性", category_key: "accessibility", value_type: "boolean", unit: null, scoring_type: "boolean",
      status: "success", value: true, raw_value: null, evidence: null, min_value: null, target_value: null, max_value: null,
      higher_is_better: true, confidence: 1, source_type: "static_html", measured_at: null, error_code: null,
      error_message: null, counts_toward_score: true, score: 1, max_score: 1,
    };

    render(<CategoryScoreCard category={category} metrics={[metric]} />);

    expect(screen.queryByText("lang属性")).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "詳細を開く" }));

    expect(screen.getByText("lang属性")).toBeInTheDocument();
  });
});
