export type MetricResultStatus = "success" | "not_found" | "unavailable" | "not_applicable" | "error";

export interface AnalysisSummary {
  id: number;
  status: string;
  started_at: string | null;
  completed_at: string | null;
}

export interface RankingEntry {
  rank: number;
  website_analysis_id: number;
  website_id: number;
  website_name: string | null;
  is_primary: boolean;
  overall_score: number;
  display_score: number;
  coverage_rate: number;
  confidence_rate: number;
  low_data_warning: boolean;
  score_gap_vs_primary: number | null;
}

export interface CategorySiteScore {
  website_analysis_id: number;
  score: number;
  max_available_score: number;
  coverage_rate: number;
  gap_vs_primary: number | null;
}

export interface CategoryComparison {
  key: string;
  name: string;
  configured_max_score: number;
  sites: CategorySiteScore[];
}

export interface MetricSiteValue {
  website_analysis_id: number;
  status: MetricResultStatus | null;
  value: boolean | number | string | null;
  confidence: number | null;
  evidence: Record<string, unknown> | null;
  measured_at: string | null;
  error_code: string | null;
  error_message: string | null;
  is_mock: boolean;
  gap_vs_primary: number | null;
}

export interface MetricComparison {
  key: string;
  name: string;
  category_key: string;
  value_type: string;
  unit: string | null;
  source_type: string;
  higher_is_better: boolean;
  sites: MetricSiteValue[];
}

export interface StrengthWeaknessItem {
  type: "category" | "metric" | "recommendation";
  category_key?: string;
  metric_key?: string | null;
  label: string;
  priority?: string;
}

export interface StrengthWeaknessGroup {
  website_analysis_id: number;
  items: StrengthWeaknessItem[];
}

export interface DataQuality {
  website_analysis_id: number;
  coverage_rate: number;
  confidence_rate: number;
  measured_count: number;
  external_count: number;
  unavailable_count: number;
  error_count: number;
  mock_count: number;
  last_fetched_at: string | null;
  warnings: string[];
}

export interface ComparisonResult {
  analysis: AnalysisSummary;
  primary_website_analysis_id: number | null;
  ranking: RankingEntry[];
  categories: CategoryComparison[];
  metrics: MetricComparison[];
  strengths: StrengthWeaknessGroup[];
  weaknesses: StrengthWeaknessGroup[];
  data_quality: DataQuality[];
}

export interface RecommendationItem {
  id: number;
  website_analysis_id: number;
  website_name: string | null;
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
  created_at: string | null;
}

export interface RecommendationFilters {
  category_key?: string;
  priority?: RecommendationItem["priority"];
  effort?: RecommendationItem["effort"];
  source?: RecommendationItem["source"];
  website_analysis_id?: number;
  sort?: "default" | "impact" | "effort" | "site";
}

export type MetricDiffClassification = "unchanged" | "changed" | "improved" | "degraded";

export interface CategoryScoreDelta {
  key: string;
  name: string;
  current_score: number;
  previous_score: number | null;
  delta: number | null;
}

export interface MetricDelta {
  key: string;
  name: string;
  category_key: string;
  previous_value: boolean | number | string | null;
  current_value: boolean | number | string | null;
  classification: MetricDiffClassification;
}

export interface RecommendationDiffItem {
  title: string;
  category_key: string;
  priority: string;
}

export interface HistorySiteComparison {
  website_id: number;
  website_name: string | null;
  present_in_current: boolean;
  present_in_previous: boolean;
  status_changed: boolean | null;
  current_status: string | null;
  previous_status: string | null;
  overall_score_delta: number | null;
  coverage_rate_delta: number | null;
  coverage_rate_diff_warning: boolean;
  category_score_deltas: CategoryScoreDelta[];
  metric_deltas: MetricDelta[];
  recommendation_added: RecommendationDiffItem[];
  recommendation_resolved: RecommendationDiffItem[];
  recommendation_continued: RecommendationDiffItem[];
}

export interface HistoryComparisonResult {
  current: AnalysisSummary;
  previous: AnalysisSummary | null;
  coverage_rate_diff_warning: boolean;
  sites: HistorySiteComparison[];
}
