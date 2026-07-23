import { act, renderHook } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { useComparisonUrlState } from "@/features/comparison/use-comparison-url-state";

const replaceMock = vi.fn((url: string) => {
  // 実ブラウザのrouter.replaceはwindow.locationを即座に書き換える
  // (Reactのuseサーチparamsの再レンダーより先行し得る)。ここではその挙動を
  // 再現する一方で、意図的にcurrentSearch(モックされたuseSearchParams()の
  // 値)はここでは更新しない ―― 「Reactの再レンダーがまだ追いついていない」
  // 状況を固定して再現するため。
  window.history.pushState({}, "", url);
});
let currentSearch = "";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: replaceMock }),
  usePathname: () => "/analyses/177/comparison",
  useSearchParams: () => new URLSearchParams(currentSearch),
}));

// setUrlStateはuseSearchParams()のスナップショットではなく実際のwindow.location
// を読むため(短時間の連続呼び出しでの競合を避ける実装、詳細はフック本体の
// コメント参照)、書き込み系のテストではjsdomの実URLも同期させておく。
function setCurrentUrl(search: string) {
  currentSearch = search;
  window.history.pushState({}, "", search ? `/analyses/177/comparison?${search}` : "/analyses/177/comparison");
}

describe("useComparisonUrlState", () => {
  beforeEach(() => {
    replaceMock.mockClear();
    setCurrentUrl("");
  });

  it("reads category and filter from the current query string", () => {
    currentSearch = "category=performance&filter=differences";
    const { result } = renderHook(() => useComparisonUrlState());

    expect(result.current.category).toBe("performance");
    expect(result.current.filter).toBe("differences");
  });

  it("returns null for missing params", () => {
    const { result } = renderHook(() => useComparisonUrlState());

    expect(result.current.category).toBeNull();
    expect(result.current.filter).toBeNull();
  });

  it("sets a new query param without disturbing others already present", () => {
    setCurrentUrl("category=performance");
    const { result } = renderHook(() => useComparisonUrlState());

    act(() => {
      result.current.setUrlState({ filter: "differences" });
    });

    expect(replaceMock).toHaveBeenCalledWith("/analyses/177/comparison?category=performance&filter=differences", { scroll: false });
  });

  it("removes a param when set to null", () => {
    setCurrentUrl("category=performance&filter=differences");
    const { result } = renderHook(() => useComparisonUrlState());

    act(() => {
      result.current.setUrlState({ filter: null });
    });

    expect(replaceMock).toHaveBeenCalledWith("/analyses/177/comparison?category=performance", { scroll: false });
  });

  it("does not clobber a param set by an earlier call even if useSearchParams has not re-rendered yet", () => {
    setCurrentUrl("");
    const { result } = renderHook(() => useComparisonUrlState());

    act(() => {
      result.current.setUrlState({ category: "performance" });
    });
    // 実URL(window.location)は最初のreplace呼び出しの時点で既に更新されている
    // 前提(react-domのuseSearchParams再レンダーを待たずに)、続けて2回目を呼ぶ。
    act(() => {
      result.current.setUrlState({ filter: "all" });
    });

    expect(replaceMock).toHaveBeenLastCalledWith("/analyses/177/comparison?category=performance&filter=all", { scroll: false });
  });
});
