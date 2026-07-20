import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { PriorityRecommendations } from "@/features/analysis/results/priority-recommendations";
import type { ResultRecommendation } from "@/types/analysis";

function makeRecommendation(id: number, sortScore: number): ResultRecommendation {
  return {
    id, category_key: "technical_seo", title: `提案${id}`, description: "問題の説明",
    evidence: { count: 1 }, current_value: { value: false }, recommended_value: null,
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
});
