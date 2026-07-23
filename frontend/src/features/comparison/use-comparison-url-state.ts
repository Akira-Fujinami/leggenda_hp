"use client";

import { useCallback } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";

/**
 * 展開中カテゴリ(?category=<key>)と選択中フィルタ(?filter=<key>)をURLクエリと
 * 同期させるフック。resultsページのuse-results-url-stateと同じパターン
 * (履歴を積まないreplaceのみ)。
 */
export function useComparisonUrlState() {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  const category = searchParams.get("category");
  const filter = searchParams.get("filter");

  const setUrlState = useCallback(
    (next: { category?: string | null; filter?: string | null }) => {
      // useSearchParams()のスナップショットではなく、実際に現在のURLを直接
      // 読む。カテゴリ展開直後にフィルタを続けて変更する等、短時間に複数回
      // setUrlStateが呼ばれるとReactの再レンダーが追いつかず、
      // useSearchParams()の値が古いままの状態で次のreplaceが実行され、
      // 直前の変更を消してしまう競合が起きるため。
      const params = new URLSearchParams(window.location.search);

      if (next.category !== undefined) {
        if (next.category === null) params.delete("category");
        else params.set("category", next.category);
      }
      if (next.filter !== undefined) {
        if (next.filter === null) params.delete("filter");
        else params.set("filter", next.filter);
      }

      const query = params.toString();
      router.replace(query ? `${pathname}?${query}` : pathname, { scroll: false });
    },
    [pathname, router],
  );

  return { category, filter, setUrlState };
}
