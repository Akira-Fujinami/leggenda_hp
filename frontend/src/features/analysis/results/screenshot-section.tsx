import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import type { AnalysisJobError, AnalysisScreenshot } from "@/types/analysis";

const DEVICE_LABELS: Record<string, string> = { desktop: "PC", mobile: "モバイル" };

export function ScreenshotSection({
  screenshots,
  errors,
  websiteName,
}: {
  screenshots: AnalysisScreenshot[];
  errors: AnalysisJobError[];
  websiteName: string | null;
}) {
  const screenshotByDevice = new Map(screenshots.map((s) => [s.device, s]));
  const errorByDevice = new Map(
    errors
      .filter((e) => e.job_type === "capture_screenshot_desktop" || e.job_type === "capture_screenshot_mobile")
      .map((e) => [e.job_type === "capture_screenshot_desktop" ? "desktop" : "mobile", e])
  );

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">スクリーンショット</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="flex flex-wrap gap-4">
          {(["desktop", "mobile"] as const).map((device) => {
            const screenshot = screenshotByDevice.get(device);
            if (screenshot) {
              return (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  key={device}
                  src={screenshot.url}
                  alt={`${websiteName ?? ""} (${device})`}
                  className="h-48 w-auto rounded-md border object-cover object-top"
                />
              );
            }

            const error = errorByDevice.get(device);

            return (
              <div
                key={device}
                className="flex h-48 w-40 flex-col items-center justify-center gap-1 rounded-md border border-dashed p-2 text-center text-xs text-muted-foreground"
              >
                <span className="font-medium">{DEVICE_LABELS[device]}</span>
                <span>{error ? "取得できませんでした" : "未取得"}</span>
                {error && (
                  <>
                    <span>{error.error_message ?? "スクリーンショットの取得に失敗しました。"}</span>
                    <span>{error.error_code}</span>
                    <span>再分析で取得できる可能性があります</span>
                  </>
                )}
              </div>
            );
          })}
        </div>
      </CardContent>
    </Card>
  );
}
