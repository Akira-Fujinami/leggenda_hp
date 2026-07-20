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
}

export interface WebsiteAnalysisProgress {
  website_analysis_id: number;
  website_id: number;
  website_name: string | null;
  status: WebsiteAnalysisStatus;
  progress: number;
  jobs: AnalysisJobProgress[];
}

export interface AnalysisProgress {
  id: number;
  status: AnalysisStatus;
  progress: number;
  started_at: string | null;
  completed_at: string | null;
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
}

export interface AnalysisResults {
  id: number;
  status: AnalysisStatus;
  progress: number;
  started_at: string | null;
  completed_at: string | null;
  websites: WebsiteAnalysisResult[];
}
