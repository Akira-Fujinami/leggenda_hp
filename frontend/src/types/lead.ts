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

// 内部向けAnalysisScoreと同じ形状(WebsiteScoreResultを共有しているため)だが、
// 値そのものは社内版のOverallScoreCalculator(7カテゴリ100点)とは別建て ――
// バックエンドのLeadScoreCalculatorが、4観点(LeadMetricCatalog)に表示している
// 指標だけを対象に算出する(2026-07-28: 4観点と点数の対象がずれる問題への対応)。
// configured_max_scoreは常に100とは限らない。カバー率70%未満で「参考スコア」に
// する既存のDataQualityNotice(誠実性の維持)は、この別建ての値に対しても
// そのまま流用する。
export type LeadWebsiteScore = AnalysisScore;

export interface LeadRecommendation {
  title: string;
  description: string;
  priority: string;
  impact: string;
  effort: string;
}

export type LeadPerspectiveKey = "completeness" | "clarity" | "findability" | "usability";

// バックエンドのLeadPerspectiveComposer::STATUS_*と一致させる。
export type LeadPerspectiveStatus =
  | "good"
  | "needs_review"
  | "needs_improvement"
  | "not_measured"
  | "not_applicable"
  | "not_detected"
  | "unavailable";

export interface LeadPerspectiveItem {
  label: string;
  status: LeadPerspectiveStatus;
  detail: string | null;
}

// 採用担当向けの4観点(①書くべきこと・②メッセージ・③導線・④見やすさ)。
// summaryは①(completeness)のみが持つ(採用ページの検出有無を文章で示すため)。
// noteは①と④のみ非null(法定記載事項の注記/デザイン印象は自動判定外の注記)。
export interface LeadPerspective {
  key: LeadPerspectiveKey;
  label: string;
  // 採用担当が実際に持つ問いの形の見出し(画面の見出しはこれをそのまま使う)。
  // バックエンドのLeadMetricCatalog::PERSPECTIVE_HEADINGSが唯一の定義元 ――
  // 画面とWord/PDFレポートが同じ値を参照するため、フロント側では上書き・
  // 複製しない(2026-07-28: 見出しの食い違いを防ぐための一本化)。
  heading: string;
  note: string | null;
  summary?: string;
  status: LeadPerspectiveStatus;
  items: LeadPerspectiveItem[];
}

// リード分析ではCaptureScreenshotJob自体を省略するため(採点への影響は
// ゼロ)、スクリーンショットは持たない。社内向けフル機能とは異なる。
export interface LeadWebsiteResult {
  website_name: string | null;
  is_primary: boolean;
  score: LeadWebsiteScore;
  perspectives: LeadPerspective[];
  top_recommendations: LeadRecommendation[];
}

export interface LeadConsultationResult {
  already_requested: boolean;
}

export type LeadReportStatus = "processing" | "ready" | "unavailable";

export interface LeadResults {
  status: LeadAnalysisPhase;
  reports: { docx: LeadReportStatus; pdf: LeadReportStatus };
  websites: LeadWebsiteResult[];
}
