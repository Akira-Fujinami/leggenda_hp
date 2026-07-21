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
});
