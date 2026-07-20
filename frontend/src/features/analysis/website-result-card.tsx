import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { AnalysisStatusBadge } from "@/features/analysis/analysis-status-badge";
import { isJobRetryable, jobTypeLabel } from "@/features/analysis/job-labels";
import type { WebsiteAnalysisResult } from "@/types/analysis";

const TECHNOLOGY_LABELS: Record<string, string> = {
  cms_detected: "CMS",
  ga_detected: "Google Analytics",
  gtm_detected: "Google Tag Manager",
  clarity_detected: "Microsoft Clarity",
  meta_pixel_detected: "Meta Pixel",
  recaptcha_detected: "reCAPTCHA",
  cdn_detected: "CDN",
};

const DEVICE_LABELS: Record<string, string> = { desktop: "PC", mobile: "モバイル" };

export function WebsiteResultCard({ website }: { website: WebsiteAnalysisResult }) {
  const { score } = website;
  const screenshotByDevice = new Map(website.screenshots.map((s) => [s.device, s]));
  const failedDevices = new Set(
    website.errors
      .filter((e) => e.job_type === "capture_screenshot_desktop" || e.job_type === "capture_screenshot_mobile")
      .map((e) => (e.job_type === "capture_screenshot_desktop" ? "desktop" : "mobile"))
  );

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between space-y-0">
        <div>
          <CardTitle>{website.website_name ?? `サイト #${website.website_id}`}</CardTitle>
          {website.url && <p className="text-xs text-muted-foreground">{website.url}</p>}
        </div>
        <AnalysisStatusBadge status={website.status} />
      </CardHeader>
      <CardContent className="space-y-6">
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="rounded-md border p-4">
            <p className="text-sm text-muted-foreground">総合スコア</p>
            <p className="text-2xl font-semibold">
              {score.display_score} <span className="text-sm font-normal text-muted-foreground">/ {score.configured_max_score}</span>
            </p>
            <p className="mt-1 text-xs text-muted-foreground">
              測定カバー率: {Math.round(score.coverage_rate)}% ・信頼度: {Math.round(score.confidence_rate)}%
              {score.metric_summary.error > 0 && ` ・失敗: ${score.metric_summary.error}件`}
              {score.metric_summary.unavailable > 0 && ` ・測定不可: ${score.metric_summary.unavailable}件`}
            </p>
          </div>
          <div className="rounded-md border p-4">
            <p className="text-sm text-muted-foreground">カテゴリ別スコア</p>
            <ul className="mt-1 space-y-0.5 text-sm">
              {score.category_scores.map((category) => (
                <li key={category.key} className="flex justify-between">
                  <span className="text-muted-foreground">{category.name}</span>
                  <span>
                    {category.score} / {category.configured_max_score}
                  </span>
                </li>
              ))}
            </ul>
          </div>
        </div>

        {website.seo && (
          <div className="space-y-1 text-sm">
            <p className="font-medium">SEO基本情報</p>
            <p className="text-muted-foreground">タイトル: {website.seo.title ?? "(未設定)"}</p>
            <p className="text-muted-foreground">meta description: {website.seo.meta_description ?? "(未設定)"}</p>
            <p className="text-muted-foreground">
              H1: {website.seo.h1_count ?? "-"}件 ・ 本文文字数: {website.seo.word_count ?? "-"}
            </p>
          </div>
        )}

        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <p className="text-sm font-medium">Lighthouse</p>
            <ul className="mt-1 space-y-0.5 text-sm text-muted-foreground">
              <li>Performance: {website.lighthouse.scores.performance ?? "-"}</li>
              <li>Accessibility: {website.lighthouse.scores.accessibility ?? "-"}</li>
              <li>Best Practices: {website.lighthouse.scores.best_practices ?? "-"}</li>
            </ul>
          </div>
          <div>
            <p className="text-sm font-medium">使用技術</p>
            <div className="mt-1 flex flex-wrap gap-1">
              {Object.entries(website.technology).filter(([, detected]) => detected).length === 0 ? (
                <span className="text-sm text-muted-foreground">検出なし</span>
              ) : (
                Object.entries(website.technology)
                  .filter(([, detected]) => detected)
                  .map(([key, value]) => (
                    <Badge key={key} variant="outline">
                      {key === "cms_detected" && typeof value === "string" ? value : TECHNOLOGY_LABELS[key] ?? key}
                    </Badge>
                  ))
              )}
            </div>
          </div>
        </div>

        <div>
          <p className="text-sm font-medium">スクリーンショット</p>
          <div className="mt-2 flex flex-wrap gap-4">
            {(["desktop", "mobile"] as const).map((device) => {
              const screenshot = screenshotByDevice.get(device);
              if (screenshot) {
                return (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img
                    key={device}
                    src={screenshot.url}
                    alt={`${website.website_name ?? ""} (${device})`}
                    className="h-48 w-auto rounded-md border object-cover object-top"
                  />
                );
              }

              return (
                <div
                  key={device}
                  className="flex h-48 w-32 flex-col items-center justify-center gap-1 rounded-md border border-dashed text-center text-xs text-muted-foreground"
                >
                  <span>{DEVICE_LABELS[device]}</span>
                  <span>{failedDevices.has(device) ? "取得できませんでした" : "未取得"}</span>
                </div>
              );
            })}
          </div>
        </div>

        {website.errors.length > 0 && (
          <Alert variant="destructive">
            <AlertDescription>
              <p className="font-medium">一部の処理でエラーが発生しました</p>
              <ul className="mt-1 space-y-1">
                {website.errors.map((error) => (
                  <li key={error.job_type}>
                    <span className="font-medium">{jobTypeLabel(error.job_type)}</span>
                    {error.error_message && `: ${error.error_message}`}
                    {isJobRetryable(error.job_type) && (
                      <span className="ml-1 text-xs text-muted-foreground">(再分析で再取得できる可能性があります)</span>
                    )}
                  </li>
                ))}
              </ul>
            </AlertDescription>
          </Alert>
        )}
      </CardContent>
    </Card>
  );
}
