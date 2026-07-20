"use client";

import { use, useState } from "react";
import Link from "next/link";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { useAnalyses, useAnalysis } from "@/features/analysis/hooks";
import { useHistoryComparison } from "@/features/comparison/hooks";
import type { HistorySiteComparison, MetricDiffClassification } from "@/types/comparison";

const CLASSIFICATION_LABELS: Record<MetricDiffClassification, string> = {
  improved: "改善", degraded: "悪化", changed: "変化", unchanged: "変化なし",
};
const CLASSIFICATION_VARIANTS: Record<MetricDiffClassification, "default" | "destructive" | "secondary" | "outline"> = {
  improved: "default", degraded: "destructive", changed: "secondary", unchanged: "outline",
};

function formatValue(value: boolean | number | string | null): string {
  if (value === null || value === undefined) return "-";
  if (typeof value === "boolean") return value ? "○" : "×";
  return String(value);
}

export default function AnalysisHistoryPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const analysisId = Number(id);
  const [previousAnalysisId, setPreviousAnalysisId] = useState<number | undefined>(undefined);

  const { data: analysisData } = useAnalysis(analysisId);
  const projectId = analysisData?.data.project_id;
  const { data: analysesList } = useAnalyses(projectId ?? Number.NaN);
  const { data, isLoading, isError } = useHistoryComparison(analysisId, previousAnalysisId);

  const candidateAnalyses = (analysesList?.data ?? []).filter(
    (a) => a.id !== analysisId && (a.status === "completed" || a.status === "partial")
  );

  if (isLoading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-10 w-1/2" />
        <Skeleton className="h-64" />
      </div>
    );
  }

  if (isError || !data) {
    return (
      <Alert variant="destructive">
        <AlertDescription>履歴比較の取得に失敗しました。しばらくしてからページを再読み込みしてください。</AlertDescription>
      </Alert>
    );
  }

  const history = data.data;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold tracking-tight">過去の分析との比較</h1>
        <Link href={`/analyses/${analysisId}/comparison`} className="text-sm text-muted-foreground hover:underline">
          サイト比較に戻る
        </Link>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">比較対象の選択</CardTitle>
        </CardHeader>
        <CardContent>
          <select
            className="rounded-md border bg-background px-2 py-1 text-sm"
            value={previousAnalysisId ?? ""}
            onChange={(e) => setPreviousAnalysisId(e.target.value ? Number(e.target.value) : undefined)}
            aria-label="比較する過去の分析を選択"
          >
            <option value="">自動選択(直近の完了した分析)</option>
            {candidateAnalyses.map((a) => (
              <option key={a.id} value={a.id}>
                分析 #{a.id} ({a.completed_at ?? a.created_at})
              </option>
            ))}
          </select>
        </CardContent>
      </Card>

      {history.previous === null ? (
        <Alert>
          <AlertDescription>比較できる過去の分析がありません。同じプロジェクトで別の分析を完了させると比較できます。</AlertDescription>
        </Alert>
      ) : (
        <>
          {history.coverage_rate_diff_warning && (
            <Alert variant="destructive">
              <AlertDescription>
                測定カバー率が大きく変動しているサイトがあります。差分がデータ量の変化による見かけ上のものである可能性があります。
              </AlertDescription>
            </Alert>
          )}

          <div className="space-y-4">
            {history.sites.map((site) => (
              <SiteHistoryCard key={site.website_id} site={site} />
            ))}
          </div>
        </>
      )}
    </div>
  );
}

function SiteHistoryCard({ site }: { site: HistorySiteComparison }) {
  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between space-y-0">
        <CardTitle className="text-base">{site.website_name ?? `サイト #${site.website_id}`}</CardTitle>
        {!site.present_in_previous && <Badge variant="secondary">今回追加されたサイト</Badge>}
        {!site.present_in_current && <Badge variant="outline">前回のみ分析</Badge>}
      </CardHeader>
      <CardContent className="space-y-4">
        {site.present_in_current && site.present_in_previous && (
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="rounded-md border p-4">
              <p className="text-sm text-muted-foreground">総合スコアの変化</p>
              <p className="text-2xl font-semibold">
                {site.overall_score_delta === null
                  ? "-"
                  : site.overall_score_delta > 0
                    ? `+${site.overall_score_delta}`
                    : site.overall_score_delta}
              </p>
            </div>
            <div className="rounded-md border p-4">
              <p className="text-sm text-muted-foreground">カバー率の変化</p>
              <p className="text-2xl font-semibold">
                {site.coverage_rate_delta === null
                  ? "-"
                  : site.coverage_rate_delta > 0
                    ? `+${site.coverage_rate_delta}pt`
                    : `${site.coverage_rate_delta}pt`}
              </p>
            </div>
          </div>
        )}

        {site.metric_deltas.length > 0 && (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>項目</TableHead>
                <TableHead>前回</TableHead>
                <TableHead>今回</TableHead>
                <TableHead>分類</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {site.metric_deltas.map((delta) => (
                <TableRow key={delta.key}>
                  <TableCell>{delta.name}</TableCell>
                  <TableCell>{formatValue(delta.previous_value)}</TableCell>
                  <TableCell>{formatValue(delta.current_value)}</TableCell>
                  <TableCell>
                    <Badge variant={CLASSIFICATION_VARIANTS[delta.classification]}>
                      {CLASSIFICATION_LABELS[delta.classification]}
                    </Badge>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}

        {(site.recommendation_added.length > 0 || site.recommendation_resolved.length > 0) && (
          <div className="grid gap-4 sm:grid-cols-2">
            {site.recommendation_added.length > 0 && (
              <div>
                <p className="text-sm font-medium">新たに追加された改善提案</p>
                <ul className="mt-1 list-inside list-disc text-sm text-muted-foreground">
                  {site.recommendation_added.map((rec, index) => (
                    <li key={index}>{rec.title}</li>
                  ))}
                </ul>
              </div>
            )}
            {site.recommendation_resolved.length > 0 && (
              <div>
                <p className="text-sm font-medium">解消された改善提案</p>
                <ul className="mt-1 list-inside list-disc text-sm text-muted-foreground">
                  {site.recommendation_resolved.map((rec, index) => (
                    <li key={index}>{rec.title}</li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
