export interface LeadOnboardingInput {
  company_name: string;
  contact_name: string;
  email: string;
  phone?: string;
  industry?: string;
  employee_range?: string;
  privacy_policy_agreed: boolean;
}

export interface LeadOnboardingResult {
  token: string;
  expires_at: string;
}

export interface LeadAnalysisStartInput {
  self_url: string;
  competitor_url?: string;
}

export interface LeadAnalysisStartResult {
  analysis_id: number;
}

import type { AnalysisScore } from "@/types/analysis";

export type LeadAnalysisPhase = "processing" | "completed" | "partial" | "failed";

export interface LeadProgress {
  percent: number;
  status: LeadAnalysisPhase;
  message: string;
}

// 内部向けAnalysisScoreと完全に同じ形状(OverallScoreCalculatorをそのまま
// 再利用しているため)。カバー率70%未満で「参考スコア」にする既存の
// DataQualityNotice(誠実性の維持)をそのまま流用する。
export type LeadWebsiteScore = AnalysisScore;

export interface LeadRecommendation {
  title: string;
  description: string;
  priority: string;
  impact: string;
  effort: string;
}

// リード分析ではCaptureScreenshotJob自体を省略するため(採点への影響は
// ゼロ)、スクリーンショットは持たない。社内向けフル機能とは異なる。
export interface LeadWebsiteResult {
  website_name: string | null;
  is_primary: boolean;
  score: LeadWebsiteScore;
  top_recommendations: LeadRecommendation[];
}

export type LeadReportStatus = "processing" | "ready" | "unavailable";

export interface LeadResults {
  status: LeadAnalysisPhase;
  reports: { docx: LeadReportStatus; pdf: LeadReportStatus };
  websites: LeadWebsiteResult[];
}
