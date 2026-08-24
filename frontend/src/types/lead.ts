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
  brand_wheel?: BrandWheelResult | null;
}

export interface LeadConsultationResult {
  already_requested: boolean;
}

// "skipped": 自社サイトのブランド・ホイール判定に実質的な中身が無かった
// ための意図的な見送り(診断回数は消費されていない、別のURLで再挑戦可能)。
// "unavailable"(本当の生成失敗、診断回数は消費済み)とは区別する
// (バックエンドのLeadAnalysisController::singleReportStatus()参照、2026-08-24)。
export type LeadReportStatus = "processing" | "ready" | "unavailable" | "skipped";

export interface LeadResults {
  status: LeadAnalysisPhase;
  reports: { docx: LeadReportStatus; pdf: LeadReportStatus };
  websites: LeadWebsiteResult[];
  brand_wheel_comparison?: BrandWheelComparison | null;
}

// --- ブランド・ホイール（採用ブランドの6軸） ---

/**
 * 6軸の判定結果。件数(matched_count)は「サイトの記述から、その軸に該当する
 * 下位要素がいくつ読み取れたか」であり、点数ではない。max_countは
 * config/brand_wheel.phpのsub_elements件数から算出されるため、軸ごとに
 * 異なり得るし、将来4から5に変わり得る ―― フロント側で固定値を持たない。
 */
export interface BrandWheelAxis {
  key: string;
  group: BrandWheelGroup;
  name: string;
  matched_count: number;
  max_count: number;
  matched_sub_elements: { key: string; name: string }[];
}

export type BrandWheelGroup = "company_appeal" | "company_distance" | "job_appeal";

/**
 * statusは分岐用の閉じた集合、status_messageは画面とレポートに出す文言
 * (唯一の定義元はバックエンドのconfig)。success以外ではaxesが空になり、
 * 図を描いてはいけない ―― 6軸すべて0件の図は「魅力のない会社」に見えるが、
 * 実際は「読み取れなかった」であるため。
 */
export type BrandWheelStatus =
  | "success"
  | "pending"
  | "insufficient_input"
  | "recruit_page_unreadable"
  | "no_matched_content"
  | "error";

export interface BrandWheelResult {
  status: BrandWheelStatus;
  status_message: string | null;
  analyzed_url: string | null;
  axes: BrandWheelAxis[];
  key_message: string | null;
  impression: string | null;
  source_pages: { recruit_page: string; home_page: string } | null;
}

/** 件数から機械的に導出する比較まとめ(AIには書かせない)。 */
export interface BrandWheelComparison {
  self_points: string[];
  competitor_points: string[];
  one_point: { key: string; text: string } | null;
}
