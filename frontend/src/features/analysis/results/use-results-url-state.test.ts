import { act, renderHook } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { useResultsUrlState } from "@/features/analysis/results/use-results-url-state";

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
  usePathname: () => "/analyses/1/results",
  useSearchParams: () => new URLSearchParams(currentSearch),
}));

// setUrlStateはuseSearchParams()のスナップショットではなく実際のwindow.location
// を読むため(短時間の連続呼び出しでの競合を避ける実装)、書き込み系のテストでは
// jsdomの実URLも同期させておく。
function setCurrentUrl(search: string) {
  currentSearch = search;
  window.history.pushState({}, "", search ? `/analyses/1/results?${search}` : "/analyses/1/results");
}

describe("useResultsUrlState", () => {
  beforeEach(() => {
    replaceMock.mockClear();
    setCurrentUrl("");
  });

  it("reads site and section from the current query string", () => {
    currentSearch = "site=123&section=seo";
    const { result } = renderHook(() => useResultsUrlState());

    expect(result.current.site).toBe("123");
    expect(result.current.section).toBe("seo");
  });

  it("returns null for missing params", () => {
    const { result } = renderHook(() => useResultsUrlState());

    expect(result.current.site).toBeNull();
    expect(result.current.section).toBeNull();
  });

  it("sets a new query param without disturbing others already present", () => {
    setCurrentUrl("site=123");
    const { result } = renderHook(() => useResultsUrlState());

    act(() => {
      result.current.setUrlState({ section: "seo" });
    });

    expect(replaceMock).toHaveBeenCalledWith("/analyses/1/results?site=123&section=seo", { scroll: false });
  });

  it("removes a param when set to null", () => {
    setCurrentUrl("site=123&section=seo");
    const { result } = renderHook(() => useResultsUrlState());

    act(() => {
      result.current.setUrlState({ section: null });
    });

    expect(replaceMock).toHaveBeenCalledWith("/analyses/1/results?site=123", { scroll: false });
  });

  it("navigates to the bare pathname when no params remain", () => {
    setCurrentUrl("section=seo");
    const { result } = renderHook(() => useResultsUrlState());

    act(() => {
      result.current.setUrlState({ section: null });
    });

    expect(replaceMock).toHaveBeenCalledWith("/analyses/1/results", { scroll: false });
  });

  it("does not clobber a param set by an earlier call even if useSearchParams has not re-rendered yet", () => {
    setCurrentUrl("");
    const { result } = renderHook(() => useResultsUrlState());

    act(() => {
      result.current.setUrlState({ site: "123" });
    });
    act(() => {
      result.current.setUrlState({ section: "seo" });
    });

    expect(replaceMock).toHaveBeenLastCalledWith("/analyses/1/results?site=123&section=seo", { scroll: false });
  });
});
