import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { AnalysisSummary } from "@/features/analysis/results/analysis-summary";
import type { AnalysisScore, CategoryScore, MetricEvaluation, ResultRecommendation } from "@/types/analysis";

function makeCategory(overrides: Partial<CategoryScore> = {}): CategoryScore {
  return {
    key: "technical_seo", name: "技術SEO", score: 18, max_available_score: 20, configured_max_score: 20,
    coverage_rate: 100,
    ...overrides,
  };
}

function makeScore(overrides: Partial<AnalysisScore> = {}): AnalysisScore {
  return {
    overall_score: 59, display_score: 59, available_score: 80, configured_max_score: 100,
    coverage_rate: 81, confidence_rate: 97, category_scores: [],
    metric_summary: {
      success: 40, not_found: 5, unavailable: 3, error: 0, not_applicable: 2,
      scored_unavailable: 3, informational_unavailable: 0,
    },
    ...overrides,
  };
}

function makeMetric(overrides: Partial<MetricEvaluation> = {}): MetricEvaluation {
  return {
    key: "lighthouse_performance", name: "Lighthouse Performance", category_key: "performance", value_type: "score",
    unit: "pt", scoring_type: "lighthouse", status: "success", value: 8, raw_value: null, evidence: null,
    min_value: null, target_value: null, max_value: null, higher_is_better: true, confidence: 0.75,
    source_type: "lighthouse", measured_at: null, error_code: null, error_message: null,
    counts_toward_score: true, score: 0.8, max_score: 10,
    ...overrides,
  };
}

describe("AnalysisSummary", () => {
  it("does not flatly declare 'many items need improvement' from the total score alone when categories are measured", () => {
    const score = makeScore({
      category_scores: [
        makeCategory({ key: "technical_seo", name: "技術SEO", score: 18, configured_max_score: 20 }),
        makeCategory({ key: "content", name: "コンテンツ", score: 16, configured_max_score: 20 }),
        makeCategory({ key: "performance", name: "表示速度", score: 2, configured_max_score: 20 }),
      ],
    });

    render(<AnalysisSummary websiteName="日本旅行" score={score} recommendations={[]} metrics={[]} generatedAt={null} />);

    expect(screen.queryByText(/改善が必要な項目が多い状態です/)).not.toBeInTheDocument();
    expect(screen.getByText(/技術SEO・コンテンツは良好です/)).toBeInTheDocument();
    expect(screen.getByText(/表示速度には改善余地があります/)).toBeInTheDocument();
  });

  it("softens the summary to a reference-only statement when coverage is below 70%", () => {
    const score = makeScore({ coverage_rate: 55 });

    render(<AnalysisSummary websiteName="楽天トラベル" score={score} recommendations={[]} metrics={[]} generatedAt={null} />);

    expect(screen.getByText(/測定できた範囲では判断が難しい状況です/)).toBeInTheDocument();
  });

  it("adds a Lighthouse single-run caveat when a lighthouse metric was measured with run_count=1", () => {
    const score = makeScore({
      category_scores: [makeCategory({ key: "performance", name: "表示速度", score: 2, configured_max_score: 20 })],
    });
    const metrics = [makeMetric({ evidence: { metadata: { run_count: 1 } } })];

    render(<AnalysisSummary websiteName="日本旅行" score={score} recommendations={[]} metrics={metrics} generatedAt={null} />);

    expect(screen.getByText(/Lighthouseのローカル環境での単発計測結果の影響を受けています/)).toBeInTheDocument();
  });

  it("shows the scored-unavailable count, not the raw unavailable+error total", () => {
    const score = makeScore({ metric_summary: { success: 1, not_found: 0, unavailable: 5, error: 2, not_applicable: 0, scored_unavailable: 4, informational_unavailable: 3 } });

    render(<AnalysisSummary websiteName="サイト" score={score} recommendations={[]} metrics={[]} generatedAt={null} />);

    expect(screen.getByText(/未取得件数\(採点対象\): 4件/)).toBeInTheDocument();
    expect(screen.getByText(/採点対象のうち4件の項目は今回取得できませんでした/)).toBeInTheDocument();
  });

  it("shows the top-priority recommendation title", () => {
    const recommendation: ResultRecommendation = {
      id: 1, category_key: "technical_seo", title: "H1を設定してください", description: null, evidence: null,
      current_value: null, recommended_value: null, metric_key: "h1_single", metric_value_type: null,
      metric_unit: null, priority: "high", impact: "high", effort: "small", confidence: 1,
      status: "open", source: "rule", sort_score: 10,
    };

    render(<AnalysisSummary websiteName="サイト" score={makeScore()} recommendations={[recommendation]} metrics={[]} generatedAt={null} />);

    expect(screen.getByText("H1を設定してください")).toBeInTheDocument();
  });
});
