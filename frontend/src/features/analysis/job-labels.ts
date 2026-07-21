export const JOB_TYPE_LABELS: Record<string, string> = {
  fetch_static_page: "静的HTML取得",
  fetch_robots: "robots.txt取得",
  fetch_sitemap: "sitemap.xml取得",
  render_page: "JavaScriptレンダリング",
  capture_screenshot_desktop: "スクリーンショット(PC)",
  capture_screenshot_mobile: "スクリーンショット(モバイル)",
  run_lighthouse: "Lighthouse計測",
  analyze_html_seo: "SEO解析",
  reanalyze_rendered_html: "レンダリング後の再解析",
  detect_technology: "使用技術検出",
  fetch_external_seo_data: "外部SEOデータ取得",
  finalize_website_analysis: "サイト分析の確定",
};

export function jobTypeLabel(jobType: string): string {
  return JOB_TYPE_LABELS[jobType] ?? jobType;
}

// 再分析すれば取り直せる可能性が高いJob(一時的な取得失敗が主な原因になりやすいもの)。
// 現時点ではJob単位の再実行APIは無いため、UI上は案内文言の出し分けにのみ使う。
const RETRYABLE_JOB_TYPES = new Set([
  "fetch_static_page",
  "fetch_robots",
  "fetch_sitemap",
  "render_page",
  "capture_screenshot_desktop",
  "capture_screenshot_mobile",
  "run_lighthouse",
  "fetch_external_seo_data",
]);

export function isJobRetryable(jobType: string): boolean {
  return RETRYABLE_JOB_TYPES.has(jobType);
}
