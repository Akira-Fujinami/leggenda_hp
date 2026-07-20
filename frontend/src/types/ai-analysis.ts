export type AiAnalysisStatus = "pending" | "running" | "success" | "error";

export interface AiEvidenceItem {
  title: string;
  description: string;
  evidence_metric_keys: string[];
}

export interface AiPriorityAction {
  title: string;
  description: string;
  priority: "critical" | "high" | "medium" | "low";
  impact: "high" | "medium" | "low";
  effort: "small" | "medium" | "large";
  evidence_metric_keys: string[];
}

export interface AiCompetitorInsight {
  title: string;
  description: string;
  competitor_website_analysis_ids: number[];
}

export interface AiAnalysisResult {
  id: number;
  analysis_id: number;
  website_analysis_id: number;
  provider: string | null;
  model: string | null;
  status: AiAnalysisStatus;
  summary: string | null;
  strengths: AiEvidenceItem[];
  weaknesses: AiEvidenceItem[];
  priority_actions: AiPriorityAction[];
  competitor_insights: AiCompetitorInsight[];
  cautions: string[];
  confidence: number | null;
  is_mock: boolean;
  error_code: string | null;
  error_message: string | null;
  generated_at: string | null;
  created_at: string | null;
}

export interface AiAnalysisTriggerMeta {
  needs_confirmation?: boolean;
  cooldown_remaining_seconds?: number;
}
