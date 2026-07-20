import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { PriorityRecommendations } from "@/features/analysis/results/priority-recommendations";
import type { ResultRecommendation } from "@/types/analysis";

function makeRecommendation(id: number, sortScore: number): ResultRecommendation {
  return {
    id, category_key: "technical_seo", title: `提案${id}`, description: "問題の説明",
    evidence: { count: 1 }, current_value: { value: false }, recommended_value: null,
    metric_key: "test_metric", metric_value_type: "boolean", metric_unit: null,
    priority: "high", impact: "high", effort: "small", confidence: 1, status: "open", source: "rule",
    sort_score: sortScore,
  };
}

describe("PriorityRecommendations", () => {
  it("shows only the top 5 recommendations even when more exist", () => {
    const recommendations = Array.from({ length: 8 }, (_, i) => makeRecommendation(i + 1, 100 - i));

    render(<PriorityRecommendations recommendations={recommendations} url="https://example.com" />);

    expect(screen.getByText("提案1")).toBeInTheDocument();
    expect(screen.getByText("提案5")).toBeInTheDocument();
    expect(screen.queryByText("提案6")).not.toBeInTheDocument();
  });

  it("shows an empty-state message when there are no recommendations", () => {
    render(<PriorityRecommendations recommendations={[]} url={null} />);

    expect(screen.getByText("現時点で改善提案はありません。")).toBeInTheDocument();
  });

  it("shows evidence, current value, and target URL", () => {
    render(<PriorityRecommendations recommendations={[makeRecommendation(1, 90)]} url="https://example.com" />);

    expect(screen.getByText(/根拠:/)).toBeInTheDocument();
    expect(screen.getByText(/現在値:/)).toBeInTheDocument();
    expect(screen.getByText(/対象URL: https:\/\/example.com/)).toBeInTheDocument();
  });

  it("never dumps raw JSON keys like 'value:' or 'count:' to the screen", () => {
    render(<PriorityRecommendations recommendations={[makeRecommendation(1, 90)]} url="https://example.com" />);

    expect(screen.queryByText(/value:\s*false/)).not.toBeInTheDocument();
    expect(screen.queryByText(/count:\s*1/)).not.toBeInTheDocument();
    expect(screen.getByText(/検出されませんでした/)).toBeInTheDocument();
    expect(screen.getByText(/関連スクリプト検出数：1件/)).toBeInTheDocument();
  });

  it("formats a percentage current_value using metric_value_type context, not the raw ratio", () => {
    const rec: ResultRecommendation = {
      ...makeRecommendation(2, 80),
      current_value: { value: 0.9868 },
      metric_value_type: "percentage",
      metric_unit: "%",
    };

    render(<PriorityRecommendations recommendations={[rec]} url={null} />);

    expect(screen.getByText(/98\.68%/)).toBeInTheDocument();
    expect(screen.queryByText(/0\.9868/)).not.toBeInTheDocument();
  });
});
