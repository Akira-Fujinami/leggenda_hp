"use client";

import { useState } from "react";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useAiAnalysis, useGenerateAiAnalysis } from "@/features/ai-analysis/hooks";
import { ApiError } from "@/lib/api-client";

const PRIORITY_LABELS: Record<string, string> = { critical: "緊急", high: "高", medium: "中", low: "低" };

export function AiAnalysisPanel({ websiteAnalysisId }: { websiteAnalysisId: number }) {
  const { data, isLoading } = useAiAnalysis(websiteAnalysisId);
  const generate = useGenerateAiAnalysis(websiteAnalysisId);
  const [needsConfirmation, setNeedsConfirmation] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const result = data?.data ?? null;
  const isBusy = result?.status === "pending" || result?.status === "running";

  const handleGenerateClick = (confirm: boolean) => {
    setErrorMessage(null);
    generate.mutate(confirm, {
      onSuccess: () => setNeedsConfirmation(false),
      onError: (error) => {
        if (error instanceof ApiError && error.status === 409) {
          setNeedsConfirmation(true);
          setErrorMessage(error.message);
          return;
        }
        setNeedsConfirmation(false);
        setErrorMessage(error instanceof ApiError ? error.message : "AI分析の開始に失敗しました。");
      },
    });
  };

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between space-y-0">
        <div className="flex items-center gap-2">
          <CardTitle className="text-base">AIによる参考分析</CardTitle>
          <Badge variant="outline">参考情報(AI生成)</Badge>
        </div>
        <Button
          size="sm"
          variant="outline"
          disabled={isBusy || generate.isPending}
          onClick={() => handleGenerateClick(needsConfirmation)}
        >
          {isBusy ? "生成中…" : result ? "再生成する" : "AI分析を生成する"}
        </Button>
      </CardHeader>
      <CardContent className="space-y-4">
        <p className="text-xs text-muted-foreground">
          この内容はAIによる参考情報であり、確定した事実や公式な評価ではありません。総合スコアには影響しません。
        </p>

        {needsConfirmation && (
          <Alert>
            <AlertDescription>
              既にAI分析結果があります。再生成にはAPIコストが発生します。よろしければもう一度「再生成する」を押してください。
            </AlertDescription>
          </Alert>
        )}

        {errorMessage && !needsConfirmation && (
          <Alert variant="destructive">
            <AlertDescription>{errorMessage}</AlertDescription>
          </Alert>
        )}

        {isLoading && <Skeleton className="h-24" />}

        {!isLoading && !result && !isBusy && (
          <p className="text-sm text-muted-foreground">まだAI分析は生成されていません。</p>
        )}

        {isBusy && <Skeleton className="h-24" />}

        {result && result.status === "error" && (
          <Alert variant="destructive">
            <AlertDescription>AI分析の生成に失敗しました。{result.error_message ?? ""}</AlertDescription>
          </Alert>
        )}

        {result && result.status === "success" && (
          <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
              <Badge variant={result.is_mock ? "outline" : "secondary"}>{result.is_mock ? "デモデータ" : "AI生成"}</Badge>
              {result.provider && <span>provider: {result.provider}</span>}
              {result.model && <span>model: {result.model}</span>}
              {result.generated_at && <span>生成日時: {new Date(result.generated_at).toLocaleString("ja-JP")}</span>}
              {result.confidence !== null && <span>確信度: {Math.round(result.confidence * 100)}%</span>}
            </div>

            <p className="text-sm">{result.summary}</p>

            {result.strengths.length > 0 && (
              <div>
                <p className="text-sm font-medium">強み(AI所見)</p>
                <ul className="mt-1 space-y-1 text-sm text-muted-foreground">
                  {result.strengths.map((item, index) => (
                    <li key={index}>
                      <span className="font-medium text-foreground">{item.title}</span>: {item.description}
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {result.weaknesses.length > 0 && (
              <div>
                <p className="text-sm font-medium">弱み(AI所見)</p>
                <ul className="mt-1 space-y-1 text-sm text-muted-foreground">
                  {result.weaknesses.map((item, index) => (
                    <li key={index}>
                      <span className="font-medium text-foreground">{item.title}</span>: {item.description}
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {result.priority_actions.length > 0 && (
              <div>
                <p className="text-sm font-medium">優先アクション(AI提案)</p>
                <ul className="mt-1 space-y-1 text-sm text-muted-foreground">
                  {result.priority_actions.map((item, index) => (
                    <li key={index} className="flex flex-wrap items-center gap-1.5">
                      <Badge variant="outline">{PRIORITY_LABELS[item.priority] ?? item.priority}</Badge>
                      <span className="font-medium text-foreground">{item.title}</span>: {item.description}
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {result.competitor_insights.length > 0 && (
              <div>
                <p className="text-sm font-medium">競合との比較(AI所見)</p>
                <ul className="mt-1 space-y-1 text-sm text-muted-foreground">
                  {result.competitor_insights.map((item, index) => (
                    <li key={index}>
                      <span className="font-medium text-foreground">{item.title}</span>: {item.description}
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {result.cautions.length > 0 && (
              <ul className="list-inside list-disc text-xs text-muted-foreground">
                {result.cautions.map((caution, index) => (
                  <li key={index}>{caution}</li>
                ))}
              </ul>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
