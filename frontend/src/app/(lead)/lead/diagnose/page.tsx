"use client";

import { Suspense, useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import { LeadAnalysisForm } from "@/features/lead/lead-analysis-form";
import { useLeadProgress, useLeadResults } from "@/features/lead/hooks";
import { isLeadTokenError, LeadTokenError } from "@/features/lead/lead-token-error";
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

  // 2026-08-24追加: 自社サイトの分析が「白紙」だった場合、診断回数を
  // 消費していないため別のURLで再挑戦できる(バックエンド側の設計)。
  // ここでは保存済みのanalysisIdを破棄し、STEP2のURL入力フォームへ
  // 戻すだけ ―― 新しい診断はLeadAnalysisFormが通常通りPOSTする。
  const handleRetry = () => {
    if (token) window.localStorage.removeItem(analysisStorageKey(token));
    setAnalysisId(null);
  };

  if (!token) {
    return <LeadTokenError />;
  }

  if (analysisId === null) {
    return (
      <div className="space-y-6" data-lead-medium>
        <div className="space-y-3 text-center">
          <p className="lead-eyebrow">STEP 2 / 2</p>
          <h1 className="lead-heading">診断するサイトをお選びください。</h1>
        </div>
        <div className="space-y-4">
          <h2 className="lead-card-heading">URLのご入力</h2>
          <LeadAnalysisForm token={token} onStarted={handleStarted} />
        </div>
      </div>
    );
  }

  return <DiagnoseResult token={token} analysisId={analysisId} onRetry={handleRetry} />;
}

function DiagnoseResult({
  token,
  analysisId,
  onRetry,
}: {
  token: string;
  analysisId: number;
  onRetry: () => void;
}) {
  const progressQuery = useLeadProgress(token, analysisId);
  const isTerminal = progressQuery.data?.data.status !== "processing";
  const resultsQuery = useLeadResults(token, isTerminal ? analysisId : null);

  if (progressQuery.isLoading) {
    return <Skeleton className="h-40" />;
  }

  if (progressQuery.isError || !progressQuery.data) {
    if (isLeadTokenError(progressQuery.error)) {
      return <LeadTokenError />;
    }

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
      <LeadResults results={resultsQuery.data.data} token={token} analysisId={analysisId} onRetry={onRetry} />
    </div>
  );
}
