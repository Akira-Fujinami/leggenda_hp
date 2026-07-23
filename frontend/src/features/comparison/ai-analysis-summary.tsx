"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useAiAnalysis, useGenerateAiAnalysis } from "@/features/ai-analysis/hooks";
import { AiAnalysisPanel } from "@/features/ai-analysis/ai-analysis-panel";
import type { RankingEntry } from "@/types/comparison";

const STATUS_LABELS: Record<string, string> = {
  pending: "生成中…",
  running: "生成中…",
  error: "生成に失敗しました",
};

function AiAnalysisSiteRow({ site }: { site: RankingEntry }) {
  const { data, isLoading } = useAiAnalysis(site.website_analysis_id);
  const generate = useGenerateAiAnalysis(site.website_analysis_id);
  const [expanded, setExpanded] = useState(false);
  const siteName = site.website_name ?? `サイト #${site.website_id}`;

  if (expanded) {
    return (
      <div className="space-y-2">
        <p className="text-sm font-medium text-muted-foreground">{siteName}</p>
        <AiAnalysisPanel websiteAnalysisId={site.website_analysis_id} />
      </div>
    );
  }

  if (isLoading) {
    return <Skeleton className="h-14" />;
  }

  const result = data?.data ?? null;
  const hasResult = result?.status === "success";

  return (
    <div className="flex items-center justify-between rounded-md border p-3">
      <div>
        <p className="text-sm font-medium">{siteName}</p>
        <p className="text-xs text-muted-foreground">{!result ? "未生成" : (STATUS_LABELS[result.status] ?? "生成済み")}</p>
      </div>
      {hasResult ? (
        <Button size="sm" variant="ghost" onClick={() => setExpanded(true)}>
          詳細を見る
        </Button>
      ) : (
        <Button size="sm" variant="outline" disabled={generate.isPending} onClick={() => generate.mutate(false)}>
          生成する
        </Button>
      )}
    </div>
  );
}

/**
 * AI分析未生成のサイトは「未生成 / [生成する]」の1行に留め、
 * 大きなAiAnalysisPanelカードをサイトごとに縦連続で表示しない。
 * 生成済みの内容は「詳細を見る」で個別に展開する。
 */
export function AiAnalysisSummary({ ranking }: { ranking: RankingEntry[] }) {
  const orderedSites = [...ranking].sort((a, b) => a.rank - b.rank);

  return (
    <div className="space-y-2">
      <h2 className="text-base font-semibold tracking-tight">サイト別 AI参考分析</h2>
      <div className="space-y-2">
        {orderedSites.map((site) => (
          <AiAnalysisSiteRow key={site.website_analysis_id} site={site} />
        ))}
      </div>
    </div>
  );
}
