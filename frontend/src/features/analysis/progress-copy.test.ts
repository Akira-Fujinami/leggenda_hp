import { describe, expect, it } from "vitest";
import {
  hasNoUsableResult,
  isRedirectableStatus,
  progressHeading,
  redirectAnnouncement,
  redirectDelayMs,
  resultButtonLabel,
  resultSummary,
  statusDescription,
} from "@/features/analysis/progress-copy";
import type { JobStatusSummary } from "@/types/analysis";

function jobs(overrides: Partial<JobStatusSummary> = {}): JobStatusSummary {
  return { total: 22, completed: 22, failed: 0, running: 0, pending: 0, skipped: 0, finished: 22, ...overrides };
}

describe("progressHeading", () => {
  it("uses '処理の進捗' rather than the ambiguous '全体の進捗'", () => {
    expect(progressHeading(65)).toBe("処理の進捗：65%");
    expect(progressHeading(100)).toBe("処理の進捗：100%");
  });
});

describe("resultSummary", () => {
  it("returns null while running (no final result yet)", () => {
    expect(resultSummary("running")).toBeNull();
    expect(resultSummary("pending")).toBeNull();
  });

  it("describes a completed analysis without a subtitle", () => {
    expect(resultSummary("completed")).toEqual({ title: "分析が完了しました", subtitle: null });
  });

  it("describes a partial analysis with a subtitle explaining the missing items", () => {
    expect(resultSummary("partial")).toEqual({
      title: "分析処理は完了しました",
      subtitle: "一部の分析項目を取得できませんでした",
    });
  });

  it("describes a failed analysis", () => {
    expect(resultSummary("failed")).toEqual({
      title: "分析処理は終了しました",
      subtitle: "分析結果を作成できませんでした",
    });
  });
});

describe("statusDescription", () => {
  it("gives a one-line caption for each terminal status, distinct from the badge label", () => {
    expect(statusDescription("completed")).toBe("すべての分析項目を取得しました。");
    expect(statusDescription("partial")).toBe("分析処理は完了しましたが、一部の項目を取得できませんでした。");
    expect(statusDescription("failed")).toBe("分析に必要なデータを取得できませんでした。");
  });

  it("has no caption while running", () => {
    expect(statusDescription("running")).toBeNull();
  });
});

describe("isRedirectableStatus / redirectDelayMs", () => {
  it("only completed/partial/failed are redirectable (not cancelled)", () => {
    expect(isRedirectableStatus("completed")).toBe(true);
    expect(isRedirectableStatus("partial")).toBe(true);
    expect(isRedirectableStatus("failed")).toBe(true);
    expect(isRedirectableStatus("cancelled")).toBe(false);
    expect(isRedirectableStatus("running")).toBe(false);
  });

  it("completed redirects after 1s, partial/failed after 2s", () => {
    expect(redirectDelayMs("completed")).toBe(1000);
    expect(redirectDelayMs("partial")).toBe(2000);
    expect(redirectDelayMs("failed")).toBe(2000);
  });
});

describe("redirectAnnouncement", () => {
  it("matches the exact copy specified for each status", () => {
    expect(redirectAnnouncement("completed")).toBe("分析が完了しました。結果画面へ移動します。");
    expect(redirectAnnouncement("partial")).toBe(
      "分析処理が完了しました。一部取得できなかった項目があります。取得済みの結果を表示します。"
    );
    expect(redirectAnnouncement("failed")).toBe("分析処理が終了しました。結果を確認します。");
  });
});

describe("resultButtonLabel", () => {
  it("labels the button per status", () => {
    expect(resultButtonLabel("completed")).toBe("結果を見る");
    expect(resultButtonLabel("partial")).toBe("取得済みの結果を見る");
    expect(resultButtonLabel("failed")).toBe("エラー詳細を見る");
    expect(resultButtonLabel("running")).toBe("途中結果を見る");
  });
});

describe("hasNoUsableResult", () => {
  it("is true only when failed and zero jobs ever completed", () => {
    expect(hasNoUsableResult("failed", jobs({ completed: 0, failed: 22 }))).toBe(true);
  });

  it("is false when failed but some jobs still completed", () => {
    expect(hasNoUsableResult("failed", jobs({ completed: 3, failed: 19 }))).toBe(false);
  });

  it("is false for non-failed statuses regardless of job counts", () => {
    expect(hasNoUsableResult("partial", jobs({ completed: 0, failed: 22 }))).toBe(false);
  });
});
