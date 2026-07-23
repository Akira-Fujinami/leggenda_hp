"use client";

import { useCallback } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";

/**
 * 選択中サイト(?site=<website_analysis_id>)と選択/アクティブなセクション
 * (?section=<key>)をURLクエリと同期させるためのフック。リロード・ブラウザの
 * 戻る/進むで状態が復元されるよう、履歴を積まないreplaceのみを行う。
 */
export function useResultsUrlState() {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  const site = searchParams.get("site");
  const section = searchParams.get("section");

  const setUrlState = useCallback(
    (next: { site?: string | null; section?: string | null }) => {
      // useSearchParams()のスナップショットではなく、実際に現在のURLを直接
      // 読む。短時間に複数回setUrlStateが呼ばれるとReactの再レンダーが
      // 追いつかず、useSearchParams()の値が古いままの状態で次のreplaceが
      // 実行され、直前の変更を消してしまう競合が起きるため
      // (comparisonページでのカテゴリ展開直後のフィルタ変更で実際に発見)。
      const params = new URLSearchParams(window.location.search);

      if (next.site !== undefined) {
        if (next.site === null) params.delete("site");
        else params.set("site", next.site);
      }
      if (next.section !== undefined) {
        if (next.section === null) params.delete("section");
        else params.set("section", next.section);
      }

      const query = params.toString();
      router.replace(query ? `${pathname}?${query}` : pathname, { scroll: false });
    },
    [pathname, router],
  );

  return { site, section, setUrlState };
}
