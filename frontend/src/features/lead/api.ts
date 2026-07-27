import { api, API_URL, ApiEnvelope } from "@/lib/api-client";
import type {
  LeadAnalysisStartInput,
  LeadAnalysisStartResult,
  LeadOnboardingInput,
  LeadOnboardingResult,
  LeadProgress,
  LeadResults,
} from "@/types/lead";

/**
 * リード向け公開エンドポイント。auth:sanctumのCookieセッションではなく、
 * ワンタイムトークン(?token=、以後はlead_token Cookieへ引き継がれる)で
 * 認可される。既存のapiクライアント(CSRF Cookie取得・XSRF-TOKEN付与)を
 * そのまま再利用する ―― BFFプロキシ経由という原則は変えない。
 */
export const leadApi = {
  submitOnboarding: (input: LeadOnboardingInput) =>
    api.post<ApiEnvelope<LeadOnboardingResult>>("/api/lead/onboarding", input),

  startAnalysis: (token: string, input: LeadAnalysisStartInput) =>
    api.post<ApiEnvelope<LeadAnalysisStartResult>>(`/api/lead/analyses?token=${encodeURIComponent(token)}`, input),

  progress: (token: string, analysisId: number) =>
    api.get<ApiEnvelope<LeadProgress>>(`/api/lead/analyses/${analysisId}/progress?token=${encodeURIComponent(token)}`),

  results: (token: string, analysisId: number) =>
    api.get<ApiEnvelope<LeadResults>>(`/api/lead/analyses/${analysisId}/results?token=${encodeURIComponent(token)}`),

  // ダウンロードはファイル取得(GET)のため、CSRF不要でJSクライアントを
  // 介さずブラウザから直接開ける(<a href>やwindow.open)。既存のBFFプロキシ
  // (/backend/[...path])を経由する点はJSON APIと同じにする。
  reportDownloadUrl: (token: string, analysisId: number, format: "docx" | "pdf") =>
    `${API_URL}/api/lead/analyses/${analysisId}/reports/${format}?token=${encodeURIComponent(token)}`,
};
