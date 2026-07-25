import { describe, expect, it } from "vitest";
import { errors, type Page } from "playwright";
import { navigateResilient } from "../src/navigation.js";

/**
 * navigateResilient()の分岐ロジックを、実ブラウザに依存しない軽量な
 * フェイクPageで検証する(タイミング依存の実ブラウザテストは
 * server.test.ts側の統合テストで別途カバーする)。
 */
function fakePage(opts: {
  gotoError?: Error;
  url?: string;
  evaluateResult?: { readyState: string; hasBody: boolean; htmlLength: number };
  evaluateError?: Error;
}): Page {
  return {
    goto: async () => {
      if (opts.gotoError) throw opts.gotoError;
      return { status: () => 200 };
    },
    waitForLoadState: async () => undefined,
    waitForTimeout: async () => undefined,
    url: () => opts.url ?? "http://example.test/",
    evaluate: async () => {
      if (opts.evaluateError) throw opts.evaluateError;
      return opts.evaluateResult ?? { readyState: "complete", hasBody: true, htmlLength: 1000 };
    },
  } as unknown as Page;
}

describe("navigateResilient", () => {
  it("returns status=ok when goto succeeds without waiting for networkidle", async () => {
    const page = fakePage({});

    const result = await navigateResilient(page, "http://example.test/", 5000);

    expect(result.status).toBe("ok");
    expect(result.warning).toBeNull();
    expect(result.response).not.toBeNull();
  });

  it("re-throws non-timeout errors immediately without inspecting the DOM (DNS/TLS/connection-refused etc.)", async () => {
    const page = fakePage({ gotoError: new Error("net::ERR_NAME_NOT_RESOLVED") });

    await expect(navigateResilient(page, "http://example.test/", 5000)).rejects.toThrow("ERR_NAME_NOT_RESOLVED");
  });

  it("returns status=partial when goto times out but the DOM is usable", async () => {
    const page = fakePage({
      gotoError: new errors.TimeoutError("page.goto: Timeout 60000ms exceeded."),
      url: "http://example.test/landing",
      evaluateResult: { readyState: "interactive", hasBody: true, htmlLength: 5000 },
    });

    const result = await navigateResilient(page, "http://example.test/", 60_000);

    expect(result.status).toBe("partial");
    expect(result.warning).toBe("NAVIGATION_TIMEOUT");
    expect(result.response).toBeNull();
  });

  it("re-throws the timeout error when the page stayed on about:blank", async () => {
    const page = fakePage({
      gotoError: new errors.TimeoutError("page.goto: Timeout 60000ms exceeded."),
      url: "about:blank",
    });

    await expect(navigateResilient(page, "http://example.test/", 60_000)).rejects.toBeInstanceOf(errors.TimeoutError);
  });

  it("re-throws the timeout error when there is no body", async () => {
    const page = fakePage({
      gotoError: new errors.TimeoutError("page.goto: Timeout 60000ms exceeded."),
      evaluateResult: { readyState: "complete", hasBody: false, htmlLength: 0 },
    });

    await expect(navigateResilient(page, "http://example.test/", 60_000)).rejects.toBeInstanceOf(errors.TimeoutError);
  });

  it("re-throws the timeout error when readyState never left loading", async () => {
    const page = fakePage({
      gotoError: new errors.TimeoutError("page.goto: Timeout 60000ms exceeded."),
      evaluateResult: { readyState: "loading", hasBody: true, htmlLength: 5000 },
    });

    await expect(navigateResilient(page, "http://example.test/", 60_000)).rejects.toBeInstanceOf(errors.TimeoutError);
  });

  it("re-throws the timeout error when the html is too short to be meaningful", async () => {
    const page = fakePage({
      gotoError: new errors.TimeoutError("page.goto: Timeout 60000ms exceeded."),
      evaluateResult: { readyState: "complete", hasBody: true, htmlLength: 10 },
    });

    await expect(navigateResilient(page, "http://example.test/", 60_000)).rejects.toBeInstanceOf(errors.TimeoutError);
  });

  it("re-throws the timeout error when evaluate() itself fails (DOM inaccessible)", async () => {
    const page = fakePage({
      gotoError: new errors.TimeoutError("page.goto: Timeout 60000ms exceeded."),
      evaluateError: new Error("Execution context was destroyed"),
    });

    await expect(navigateResilient(page, "http://example.test/", 60_000)).rejects.toBeInstanceOf(errors.TimeoutError);
  });
});
