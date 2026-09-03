"use client";

import { Suspense, useEffect, useRef, useState } from "react";
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

  // 依頼AS-3(2026-09-03): 進捗が"processing"から終端状態(completed/partial/
  // failed)へ切り替わった瞬間にブラウザ通知を1件出す。ポーリング(2.5秒間隔)
  // のたびにuseLeadProgress()の再取得は起こるが、同じanalysisIdについては
  // このrefで一度だけに絞る(タブを開いている間だけの通知、Web Pushは対象外)。
  const notifiedAnalysisIdRef = useRef<number | null>(null);
  const progressStatus = progressQuery.data?.data.status;
  useEffect(() => {
    if (!progressStatus || progressStatus === "processing") return;
    if (notifiedAnalysisIdRef.current === analysisId) return;
    notifiedAnalysisIdRef.current = analysisId;
    notifyDiagnosisComplete();
  }, [analysisId, progressStatus]);

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

/**
 * 依頼AS-3(2026-09-03): タブを開いている間だけのブラウザ通知(Web Push・
 * Service Worker・購読情報の保存は対象外)。以下のいずれかに該当する場合は
 * 何もしない(画面には一切影響させない、失敗は無視する):
 * - ブラウザが通知に未対応、または許可されていない
 * - タブがアクティブ(document.visibilityState === "visible")なとき ――
 *   画面を見ている人に通知は不要
 * 本文には会社名・担当者名・メールアドレスを一切含めない。
 */
function notifyDiagnosisComplete(): void {
  if (typeof window === "undefined" || !("Notification" in window)) return;
  if (Notification.permission !== "granted") return;
  if (typeof document !== "undefined" && document.visibilityState === "visible") return;

  try {
    new Notification("採用サイト診断が完了しました", {
      body: "結果画面でご確認いただけます。",
    });
  } catch {
    // 通知の生成に失敗しても画面には影響させない。
  }
}
