"use client";

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { ScreenshotLightbox } from "@/features/analysis/results/screenshot-lightbox";
import type { AnalysisJobError, AnalysisScreenshot } from "@/types/analysis";

const DEVICE_LABELS: Record<string, string> = { desktop: "PC", mobile: "モバイル" };
const DEVICES = ["desktop", "mobile"] as const;

function DevicePane({
  device,
  screenshot,
  error,
  websiteName,
}: {
  device: (typeof DEVICES)[number];
  screenshot: AnalysisScreenshot | undefined;
  error: AnalysisJobError | undefined;
  websiteName: string | null;
}) {
  if (screenshot) {
    const alt = `${websiteName ?? ""} (${DEVICE_LABELS[device]})`;
    return (
      <ScreenshotLightbox
        src={screenshot.url}
        alt={alt}
        trigger={
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={screenshot.url}
            alt={alt}
            loading="lazy"
            style={{ maxHeight: 360 }}
            className="w-full rounded-md border object-contain"
          />
        }
      />
    );
  }

  return (
    <div className="flex h-48 flex-col items-center justify-center gap-1 rounded-md border border-dashed p-2 text-center text-xs text-muted-foreground">
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
}

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
      .map((e) => [e.job_type === "capture_screenshot_desktop" ? "desktop" : "mobile", e]),
  );

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">スクリーンショット</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="hidden gap-4 sm:grid sm:grid-cols-2">
          {DEVICES.map((device) => (
            <DevicePane
              key={device}
              device={device}
              screenshot={screenshotByDevice.get(device)}
              error={errorByDevice.get(device)}
              websiteName={websiteName}
            />
          ))}
        </div>
        <div className="sm:hidden">
          <Tabs defaultValue="desktop">
            <TabsList>
              <TabsTrigger value="desktop">PC</TabsTrigger>
              <TabsTrigger value="mobile">モバイル</TabsTrigger>
            </TabsList>
            {DEVICES.map((device) => (
              <TabsContent key={device} value={device}>
                <DevicePane device={device} screenshot={screenshotByDevice.get(device)} error={errorByDevice.get(device)} websiteName={websiteName} />
              </TabsContent>
            ))}
          </Tabs>
        </div>
      </CardContent>
    </Card>
  );
}
