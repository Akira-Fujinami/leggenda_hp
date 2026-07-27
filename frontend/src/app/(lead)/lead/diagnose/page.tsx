"use client";

import { Suspense, useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import { LeadAnalysisForm } from "@/features/lead/lead-analysis-form";
import { useLeadProgress, useLeadResults } from "@/features/lead/hooks";
import { LeadProgress } from "@/features/lead/lead-progress";
import { LeadResults } from "@/features/lead/lead-results";

function analysisStorageKey(token: string): string {
  return `lead-analysis-id:${token}`;
}

// useSearchParams()を使うコンポーネントはSuspense境界でラップする必要がある
// (Next.js App Routerの静的生成時の要件。ラップしないとビルドが失敗する)。
export default function LeadDiagnosePage() {
  return (
    <Suspense fallback={<Skeleton className="h-40" />}>
      <LeadDiagnoseContent />
    </Suspense>
  );
}

function LeadDiagnoseContent() {
  const token = useSearchParams().get("token");
  const [analysisId, setAnalysisId] = useState<number | null>(null);

  useEffect(() => {
    if (!token) return;
    const stored = window.localStorage.getItem(analysisStorageKey(token));
    if (stored) setAnalysisId(Number(stored));
  }, [token]);

  const handleStarted = (id: number) => {
    if (token) window.localStorage.setItem(analysisStorageKey(token), String(id));
    setAnalysisId(id);
  };

  if (!token) {
    return (
      <Alert variant="destructive">
        <AlertDescription>診断用のリンクが無効です。もう一度フォームからお申し込みください。</AlertDescription>
      </Alert>
    );
  }

  if (analysisId === null) {
    return (
      <div className="space-y-4">
        <h1 className="text-lg font-semibold">診断するサイトを入力してください</h1>
        <LeadAnalysisForm token={token} onStarted={handleStarted} />
      </div>
    );
  }

  return <DiagnoseResult token={token} analysisId={analysisId} />;
}

function DiagnoseResult({ token, analysisId }: { token: string; analysisId: number }) {
  const progressQuery = useLeadProgress(token, analysisId);
  const isTerminal = progressQuery.data?.data.status !== "processing";
  const resultsQuery = useLeadResults(token, isTerminal ? analysisId : null);

  if (progressQuery.isLoading) {
    return <Skeleton className="h-40" />;
  }

  if (progressQuery.isError || !progressQuery.data) {
    return (
      <Alert variant="destructive">
        <AlertDescription>状況の取得に失敗しました。しばらくしてからページを再読み込みしてください。</AlertDescription>
      </Alert>
    );
  }

  const progress = progressQuery.data.data;

  if (!isTerminal) {
    return <LeadProgress progress={progress} />;
  }

  if (progress.status === "failed") {
    return (
      <Alert variant="destructive">
        <AlertDescription>{progress.message}</AlertDescription>
      </Alert>
    );
  }

  if (resultsQuery.isLoading || !resultsQuery.data) {
    return <Skeleton className="h-40" />;
  }

  return (
    <div className="space-y-6">
      <LeadResults results={resultsQuery.data.data} token={token} analysisId={analysisId} />
      <div className="rounded-md border p-4 text-center">
        <p className="font-medium">もっと他社と比較したい場合はご相談ください</p>
        <p className="mt-1 text-sm text-muted-foreground">
          比較したいサイトを3〜5社お知らせいただければ、後日担当より結果をご説明します。
        </p>
      </div>
    </div>
  );
}
