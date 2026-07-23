"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowLeft } from "lucide-react";
import { Button } from "@/components/ui/button";
import { AnalysisStatusBadge } from "@/features/analysis/analysis-status-badge";
import type { AnalysisStatus, WebsiteAnalysisStatus } from "@/types/analysis";

export function ResultsStickyHeader({
  analysisId,
  websiteName,
  score,
  status,
}: {
  analysisId: number;
  websiteName: string | null;
  score: number;
  status: AnalysisStatus | WebsiteAnalysisStatus;
}) {
  const router = useRouter();

  return (
    <div className="sticky top-0 z-30 -mx-4 border-b bg-background/95 px-4 py-2 backdrop-blur supports-backdrop-filter:bg-background/80">
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5">
        <div className="flex min-w-0 items-center gap-2">
          <Button variant="ghost" size="icon-sm" aria-label="戻る" onClick={() => router.back()}>
            <ArrowLeft />
          </Button>
          <p className="max-w-[10rem] truncate text-sm font-semibold sm:max-w-xs">{websiteName ?? "分析結果"}</p>
          <AnalysisStatusBadge status={status} />
          <span className="text-sm text-muted-foreground">{score}点</span>
        </div>
        <div className="flex flex-wrap gap-x-3 gap-y-1 text-sm">
          <Link href={`/analyses/${analysisId}/comparison`} className="text-muted-foreground hover:underline">
            比較
          </Link>
          <Link href={`/analyses/${analysisId}/comparison`} className="text-muted-foreground hover:underline">
            改善提案
          </Link>
          <Link href={`/analyses/${analysisId}/history`} className="text-muted-foreground hover:underline">
            履歴
          </Link>
        </div>
      </div>
    </div>
  );
}
