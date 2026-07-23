"use client";

import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { AnalysisStatusBadge } from "@/features/analysis/analysis-status-badge";
import type { WebsiteAnalysisResult } from "@/types/analysis";

function hostnameOf(url: string | null): string | null {
  if (!url) return null;
  try {
    return new URL(url).hostname;
  } catch {
    return null;
  }
}

export function WebsiteTabs({
  websites,
  value,
  onValueChange,
}: {
  websites: WebsiteAnalysisResult[];
  value: number;
  onValueChange: (websiteAnalysisId: number) => void;
}) {
  if (websites.length <= 1) return null;

  return (
    <Tabs value={String(value)} onValueChange={(v) => onValueChange(Number(v))}>
      <TabsList className="h-auto w-full flex-wrap justify-start gap-1 overflow-x-auto">
        {websites.map((website) => {
          const hostname = hostnameOf(website.url);
          return (
            <TabsTrigger
              key={website.website_analysis_id}
              value={String(website.website_analysis_id)}
              className="flex-col items-start gap-0.5 whitespace-normal px-3 py-1.5"
            >
              <span className="flex items-center gap-1.5">
                <span className="max-w-[9rem] truncate font-medium">{website.website_name ?? `サイト #${website.website_id}`}</span>
                <AnalysisStatusBadge status={website.status} />
              </span>
              <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                {hostname && <span className="max-w-[9rem] truncate">{hostname}</span>}
                <span>{website.score.display_score}点</span>
              </span>
            </TabsTrigger>
          );
        })}
      </TabsList>
    </Tabs>
  );
}
