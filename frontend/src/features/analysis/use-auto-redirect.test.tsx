import { StrictMode } from "react";
import { act, renderHook } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { useAutoRedirectToResults } from "@/features/analysis/use-auto-redirect";

const replaceMock = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: replaceMock }),
}));

describe("useAutoRedirectToResults", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    replaceMock.mockClear();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("does not schedule a redirect while the analysis is still running", () => {
    renderHook(() => useAutoRedirectToResults(42, "running"));

    act(() => {
      vi.advanceTimersByTime(5000);
    });

    expect(replaceMock).not.toHaveBeenCalled();
  });

  it("redirects to the results page 1 second after completing", () => {
    renderHook(() => useAutoRedirectToResults(42, "completed"));

    act(() => {
      vi.advanceTimersByTime(999);
    });
    expect(replaceMock).not.toHaveBeenCalled();

    act(() => {
      vi.advanceTimersByTime(1);
    });
    expect(replaceMock).toHaveBeenCalledWith("/analyses/42/results");
    expect(replaceMock).toHaveBeenCalledTimes(1);
  });

  it("redirects 2 seconds after a partial completion", () => {
    renderHook(() => useAutoRedirectToResults(7, "partial"));

    act(() => {
      vi.advanceTimersByTime(1999);
    });
    expect(replaceMock).not.toHaveBeenCalled();

    act(() => {
      vi.advanceTimersByTime(1);
    });
    expect(replaceMock).toHaveBeenCalledWith("/analyses/7/results");
  });

  it("redirects exactly once even under React Strict Mode's double-invoked effects", () => {
    // Strict Modeはmount -> cleanup -> 再mountを疑似的に行う。素朴な実装
    // (予約時にrefを立てる)だとcleanupで最初のタイマーが消えた後、
    // 再mount時にrefが既にtrueで二度と予約されず、永久に遷移しなくなる。
    renderHook(() => useAutoRedirectToResults(1, "completed"), { wrapper: StrictMode });

    act(() => {
      vi.advanceTimersByTime(1000);
    });

    expect(replaceMock).toHaveBeenCalledTimes(1);
    expect(replaceMock).toHaveBeenCalledWith("/analyses/1/results");
  });

  it("clears the pending timer on unmount so no redirect fires afterward", () => {
    const { unmount } = renderHook(() => useAutoRedirectToResults(1, "completed"));

    unmount();

    act(() => {
      vi.advanceTimersByTime(5000);
    });

    expect(replaceMock).not.toHaveBeenCalled();
  });

  it("redirectNow() redirects immediately and prevents the scheduled timer from firing again", () => {
    const { result } = renderHook(() => useAutoRedirectToResults(9, "partial"));

    act(() => {
      result.current.redirectNow();
    });

    act(() => {
      vi.advanceTimersByTime(5000);
    });

    expect(replaceMock).toHaveBeenCalledTimes(1);
    expect(replaceMock).toHaveBeenCalledWith("/analyses/9/results");
  });

  it("cancel() stops the pending auto-redirect but does not disable manual navigation", () => {
    const { result, rerender } = renderHook(({ status }) => useAutoRedirectToResults(3, status), {
      initialProps: { status: "partial" as const },
    });

    expect(result.current.pending).toBe(true);

    act(() => {
      result.current.cancel();
    });
    rerender({ status: "partial" });

    expect(result.current.cancelled).toBe(true);
    expect(result.current.pending).toBe(false);

    act(() => {
      vi.advanceTimersByTime(5000);
    });
    expect(replaceMock).not.toHaveBeenCalled();

    // 停止後も手動遷移は引き続き可能。
    act(() => {
      result.current.redirectNow();
    });
    expect(replaceMock).toHaveBeenCalledWith("/analyses/3/results");
  });
});
