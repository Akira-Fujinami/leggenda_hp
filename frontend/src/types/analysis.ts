export type AnalysisStatus = "pending" | "queued" | "running" | "completed" | "partial" | "failed" | "cancelled";

export type WebsiteAnalysisStatus = "pending" | "running" | "completed" | "partial" | "failed";

export type AnalysisJobStatus = "pending" | "running" | "completed" | "failed";

export interface Analysis {
  id: number;
  project_id: number;
  status: AnalysisStatus;
  progress: number;
  website_count?: number;
  started_at: string | null;
  completed_at: string | null;
  failed_at: string | null;
  error_summary: string | null;
  created_at: string;
}

export interface AnalysisJobProgress {
  job_type: string;
  status: AnalysisJobStatus;
  error_message: string | null;
}

export interface JobStatusSummary {
  total: number;
  completed: number;
  failed: number;
  running: number;
  pending: number;
  skipped: number;
  finished: number;
}

export interface WebsiteAnalysisProgress {
  website_analysis_id: number;
  website_id: number;
  website_name: string | null;
  status: WebsiteAnalysisStatus;
  progress: number;
  job_summary: JobStatusSummary;
  jobs: AnalysisJobProgress[];
}

export interface AnalysisProgress {
  id: number;
  status: AnalysisStatus;
  progress: number;
  started_at: string | null;
  completed_at: string | null;
  jobs: JobStatusSummary;
  websites: WebsiteAnalysisProgress[];
}

export interface CategoryScore {
  key: string;
  name: string;
  score: number;
  max_available_score: number;
  configured_max_score: number;
  coverage_rate: number;
}

export interface MetricSummary {
  success: number;
  not_found: number;
  unavailable: number;
  error: number;
  not_applicable: number;
}

export interface AnalysisScore {
  overall_score: number;
  display_score: number;
  available_score: number;
  configured_max_score: number;
  coverage_rate: number;
  confidence_rate: number;
  category_scores: CategoryScore[];
  metric_summary: MetricSummary;
}

export interface AnalysisSeoSummary {
  title: string | null;
  meta_description: string | null;
  h1_count: number | null;
  word_count: number | null;
}

export interface LighthouseSummary {
  scores: {
    performance: number | null;
    accessibility: number | null;
    best_practices: number | null;
  };
  metrics: Record<string, number | null> | null;
}

export type TechnologySummary = Record<string, boolean | string | null>;

export interface AnalysisScreenshot {
  device: "desktop" | "mobile";
  url: string;
  width: number;
  height: number;
}

export interface AnalysisJobError {
  job_type: string;
  error_code: string | null;
  error_message: string | null;
}

export type MetricEvaluationStatus = "success" | "not_found" | "unavailable" | "not_applicable" | "error";

export interface MetricEvaluation {
  key: string;
  name: string;
  category_key: string;
  unit: string | null;
  scoring_type: string;
  status: MetricEvaluationStatus;
  value: boolean | number | string | null;
  raw_value: Record<string, unknown> | null;
  min_value: number | null;
  target_value: number | null;
  max_value: number | null;
  higher_is_better: boolean;
  confidence: number | null;
  source_type: string;
  measured_at: string | null;
  error_code: string | null;
  error_message: string | null;
  counts_toward_score: boolean;
  score: number | null;
  max_score: number | null;
}

export interface ResultRecommendation {
  id: number;
  category_key: string;
  title: string;
  description: string | null;
  evidence: Record<string, unknown> | null;
  current_value: unknown;
  recommended_value: unknown;
  priority: "critical" | "high" | "medium" | "low";
  impact: "high" | "medium" | "low";
  effort: "small" | "medium" | "large";
  confidence: number;
  status: "open" | "resolved" | "dismissed";
  source: "rule" | "external_api" | "ai" | "manual";
  sort_score: number;
}

export interface WebsiteAnalysisResult {
  website_analysis_id: number;
  website_id: number;
  website_name: string | null;
  url: string | null;
  status: WebsiteAnalysisStatus;
  http_status: number | null;
  final_url: string | null;
  score: AnalysisScore;
  seo: AnalysisSeoSummary | null;
  lighthouse: LighthouseSummary;
  technology: TechnologySummary;
  screenshots: AnalysisScreenshot[];
  errors: AnalysisJobError[];
  metrics: MetricEvaluation[];
  recommendations: ResultRecommendation[];
}

export interface AnalysisResults {
  id: number;
  status: AnalysisStatus;
  progress: number;
  started_at: string | null;
  completed_at: string | null;
  websites: WebsiteAnalysisResult[];
}
