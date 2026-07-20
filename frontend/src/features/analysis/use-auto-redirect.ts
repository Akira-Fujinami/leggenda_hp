"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { isRedirectableStatus, redirectDelayMs } from "@/features/analysis/progress-copy";
import type { AnalysisStatus } from "@/types/analysis";

export interface AutoRedirectState {
  /** 自動遷移が予約されている(カウントダウン中)かどうか。 */
  pending: boolean;
  /** ユーザーが「自動遷移を停止」した後かどうか。 */
  cancelled: boolean;
  /** 自動遷移までの秒数。 */
  delaySeconds: number;
  /** 今すぐ結果画面へ遷移する。 */
  redirectNow: () => void;
  /** 自動遷移を停止する(「結果を見る」ボタンは引き続き利用可能)。 */
  cancel: () => void;
}

/**
 * Analysisがterminal status(completed/partial/failed)に到達したら、
 * 一定時間後に結果画面へ自動遷移する。
 *
 * Strict Mode対策: 「予約時にhasRedirectedRef.currentを立てる」実装だと、
 * Strict Modeの疑似アンマウント(cleanup)でタイマーが消えた後、再マウント時に
 * ref が既にtrueのため2度と予約されず、永久に遷移しなくなる。
 * そのため、ref を立てるのは「実際にsetTimeoutのコールバックが発火した時点」
 * にする ―― こうすればcleanupで消えたタイマーはrefに触れないまま消え、
 * 再マウント後の新しいタイマーが正しく予約される。実際に発火したタイマーが
 * router.replace()を呼ぶのは常に1回だけになる。
 */
export function useAutoRedirectToResults(analysisId: number, status: AnalysisStatus | null): AutoRedirectState {
  const router = useRouter();
  const hasRedirectedRef = useRef(false);
  const [cancelled, setCancelled] = useState(false);

  const redirectable = status !== null && isRedirectableStatus(status);
  const delayMs = redirectable ? redirectDelayMs(status) : 0;
  const pending = redirectable && !cancelled;

  const redirectNow = useCallback(() => {
    if (hasRedirectedRef.current) {
      return;
    }
    hasRedirectedRef.current = true;
    router.replace(`/analyses/${analysisId}/results`);
  }, [analysisId, router]);

  useEffect(() => {
    if (!pending) {
      return;
    }

    const timer = window.setTimeout(() => {
      if (hasRedirectedRef.current) {
        return;
      }
      hasRedirectedRef.current = true;
      router.replace(`/analyses/${analysisId}/results`);
    }, delayMs);

    return () => window.clearTimeout(timer);
  }, [pending, analysisId, router, delayMs]);

  const cancel = useCallback(() => {
    setCancelled(true);
  }, []);

  return {
    pending,
    cancelled,
    delaySeconds: Math.round(delayMs / 1000),
    redirectNow,
    cancel,
  };
}
