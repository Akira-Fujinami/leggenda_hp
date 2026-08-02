import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { DataQualityNotice } from "@/features/analysis/results/data-quality-notice";
import type { AnalysisScore, HtmlAnalysisSource } from "@/types/analysis";

function makeScore(overrides: Partial<AnalysisScore> = {}): AnalysisScore {
  return {
    overall_score: 37, display_score: 37, available_score: 40, configured_max_score: 100,
    coverage_rate: 55, confidence_rate: 90, category_scores: [],
    metric_summary: {
      success: 30, not_found: 5, unavailable: 8, error: 0, not_applicable: 10,
      scored_unavailable: 6, informational_unavailable: 2,
    },
    ...overrides,
  };
}

describe("DataQualityNotice", () => {
  it("shows 参考スコア and a warning when coverage is below 70%", () => {
    render(<DataQualityNotice score={makeScore({ coverage_rate: 55 })} />);

    expect(screen.getByText("参考スコア")).toBeInTheDocument();
    expect(screen.getByText(/測定カバー率が55%のため、このスコアは参考値です/)).toBeInTheDocument();
  });

  it("shows 総合スコア without a warning when coverage is 70% or higher", () => {
    render(<DataQualityNotice score={makeScore({ coverage_rate: 85 })} />);

    expect(screen.getByText("総合スコア")).toBeInTheDocument();
    expect(screen.queryByText(/参考値です/)).not.toBeInTheDocument();
  });

  it("shows the scored vs informational unavailable breakdown separately", () => {
    render(<DataQualityNotice score={makeScore({ coverage_rate: 85 })} />);

    expect(screen.getByText(/採点対象の未取得: 6件/)).toBeInTheDocument();
    expect(screen.getByText(/参考情報の未取得: 2件/)).toBeInTheDocument();
  });

  it("shows effective confidence as coverage × measured confidence", () => {
    // coverage 81% × confidence 97% / 100 = 78.57%
    render(<DataQualityNotice score={makeScore({ coverage_rate: 81, confidence_rate: 97 })} />);

    expect(screen.getByText(/総合評価の参考信頼度: 78.57%/)).toBeInTheDocument();
  });

  it("bands effective confidence below 70% as 参考値", () => {
    render(<DataQualityNotice score={makeScore({ coverage_rate: 55, confidence_rate: 90 })} />);

    // 55 * 90 / 100 = 49.5
    expect(screen.getByText(/総合評価の参考信頼度: 49.5%\(参考値\)/)).toBeInTheDocument();
  });

  it("shows a rendered HTML source line without a warning", () => {
    const source: HtmlAnalysisSource = { source: "rendered", fallback_used: false, render_job_status: "completed", reanalysis_job_status: "completed" };
    render(<DataQualityNotice score={makeScore({ coverage_rate: 85 })} htmlAnalysisSource={source} />);

    expect(screen.getByText("HTML解析元: レンダリング済みページ")).toBeInTheDocument();
    expect(screen.queryByText(/JavaScriptレンダリングに失敗/)).not.toBeInTheDocument();
  });

  it("shows a static fallback warning when rendering failed", () => {
    const source: HtmlAnalysisSource = { source: "static", fallback_used: true, render_job_status: "failed", reanalysis_job_status: "completed" };
    render(<DataQualityNotice score={makeScore({ coverage_rate: 85 })} htmlAnalysisSource={source} />);

    expect(screen.getByText("HTML解析元: 静的HTML")).toBeInTheDocument();
    expect(screen.getByText(/JavaScriptレンダリングに失敗したため、一部の動的要素/)).toBeInTheDocument();
  });

  it("shows a neutral static message (no failure warning) while rendering is still pending", () => {
    const source: HtmlAnalysisSource = { source: "static", fallback_used: false, render_job_status: "running", reanalysis_job_status: "pending" };
    render(<DataQualityNotice score={makeScore({ coverage_rate: 85 })} htmlAnalysisSource={source} />);

    expect(screen.getByText(/HTML解析元: 静的HTML/)).toBeInTheDocument();
    expect(screen.queryByText(/JavaScriptレンダリングに失敗/)).not.toBeInTheDocument();
  });

  it("shows nothing for html analysis source when absent (backward compat for pre-existing analyses)", () => {
    render(<DataQualityNotice score={makeScore({ coverage_rate: 85 })} />);

    expect(screen.queryByText(/HTML解析元/)).not.toBeInTheDocument();
  });

  it("shows nothing for html analysis source when source is null (analyses recorded before the source column was introduced)", () => {
    const source: HtmlAnalysisSource = { source: null, fallback_used: false, render_job_status: null, reanalysis_job_status: null };
    render(<DataQualityNotice score={makeScore({ coverage_rate: 85 })} htmlAnalysisSource={source} />);

    expect(screen.queryByText(/HTML解析元/)).not.toBeInTheDocument();
  });

  describe("showPercentage (lead-facing only, 2026-08-03)", () => {
    it("keeps the internal points display unchanged when showPercentage is not passed", () => {
      render(<DataQualityNotice score={makeScore({ overall_score: 32, display_score: 32, available_score: 45, configured_max_score: 45.45 })} />);

      expect(screen.getByText("32")).toBeInTheDocument();
      expect(screen.getByText("/ 45.45")).toBeInTheDocument();
      expect(screen.queryByText("63%")).not.toBeInTheDocument();
      expect(screen.queryByText("評価できませんでした")).not.toBeInTheDocument();
    });

    /**
     * 4観点比較チャート(lead-perspective-comparison.tsx)の各バーは
     * score/max_available_score*100を採用しているため、overall_score/
     * available_scoreの合計はその加重平均と代数的に一致する。実データに近い
     * フィクスチャ(analysis 275, website_analysis_id=350の実測値)で確認する。
     */
    it("matches the max_available_score-weighted average of the 4 perspective bars (real-data fixture)", () => {
      // 実測値(2026-08-01のA-1調査時点、analysis 275 website_analysis_id=350):
      // completeness score=0/max_available=0(除外), clarity 8.15/11.45,
      // findability 5.81/10.55, usability 11.46/18.3。
      // overall_score=25.42, available_score=40.3 → 25.42/40.3*100 = 63.07...% → 63%
      const score = makeScore({
        overall_score: 25.42,
        display_score: 25,
        available_score: 40.3,
        configured_max_score: 45.45,
        coverage_rate: 88.67,
      });

      render(<DataQualityNotice score={score} showPercentage />);

      // バー側の加重平均(手計算): (71.2*11.45 + 55.1*10.55 + 62.6*18.3) / 40.3 = 63.08...
      expect(screen.getByText("63%")).toBeInTheDocument();
    });

    it("shows 評価できませんでした instead of 0% when nothing was measurable (available_score <= 0)", () => {
      render(<DataQualityNotice score={makeScore({ overall_score: 0, display_score: 0, available_score: 0 })} showPercentage />);

      expect(screen.getByText("評価できませんでした")).toBeInTheDocument();
      expect(screen.queryByText("0%")).not.toBeInTheDocument();
    });

    it("still switches to 参考評価 below the coverage threshold when showPercentage is on", () => {
      render(
        <DataQualityNotice
          score={makeScore({ coverage_rate: 55, available_score: 40 })}
          label="採用サイトとして重要な4観点での評価"
          referenceLabel="採用サイトとして重要な4観点での参考評価"
          showPercentage
        />,
      );

      expect(screen.getByText("採用サイトとして重要な4観点での参考評価")).toBeInTheDocument();
      expect(screen.getByText(/測定カバー率が55%のため、このスコアは参考値です/)).toBeInTheDocument();
    });
  });
});
