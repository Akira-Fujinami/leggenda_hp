import { describe, expect, it } from "vitest";
import { classifyError, PhaseError, ScreenshotResourceExhaustedError } from "../src/errorClassification.js";

describe("classifyError", () => {
  it("classifies a Playwright navigation timeout", () => {
    const result = classifyError(new Error("page.goto: Timeout 60000ms exceeded."));

    expect(result.code).toBe("NAVIGATION_TIMEOUT");
    expect(result.message).toContain("タイムアウト");
  });

  it("classifies a page.screenshot() timeout as SCREENSHOT_TIMEOUT, not NAVIGATION_TIMEOUT", () => {
    // 本番ログの実例: ナビゲーション・フォント読込は完了済みで、
    // page.screenshot()自体が30秒でタイムアウトしたケース。
    const cause = new Error("page.screenshot: Timeout 30000ms exceeded.");
    const result = classifyError(new PhaseError("capture", cause), "screenshot");

    expect(result.code).toBe("SCREENSHOT_TIMEOUT");
    expect(result.code).not.toBe("NAVIGATION_TIMEOUT");
  });

  it("still classifies a DNS/TLS/connection failure correctly even when wrapped in a capture-phase PhaseError", () => {
    const cause = new Error("net::ERR_CONNECTION_REFUSED");
    const result = classifyError(new PhaseError("capture", cause), "screenshot");

    expect(result.code).toBe("CONNECTION_REFUSED");
  });

  it("classifies an access-denied response", () => {
    const result = classifyError(new Error("Navigation failed: 403 Forbidden"));

    expect(result.code).toBe("ACCESS_DENIED");
  });

  it("classifies too many redirects", () => {
    const result = classifyError(new Error("net::ERR_TOO_MANY_REDIRECTS at https://example.com/"));

    expect(result.code).toBe("TOO_MANY_REDIRECTS");
  });

  it("classifies a certificate error as TLS_ERROR", () => {
    const result = classifyError(new Error("net::ERR_CERT_AUTHORITY_INVALID"));

    expect(result.code).toBe("TLS_ERROR");
    expect(result.retryable).toBe(false);
  });

  it("classifies a DNS resolution failure", () => {
    const result = classifyError(new Error("net::ERR_NAME_NOT_RESOLVED"));

    expect(result.code).toBe("DNS_ERROR");
    expect(result.retryable).toBe(false);
  });

  it("classifies a connection refused error as retryable", () => {
    const result = classifyError(new Error("net::ERR_CONNECTION_REFUSED"));

    expect(result.code).toBe("CONNECTION_REFUSED");
    expect(result.retryable).toBe(true);
  });

  it("classifies a page/target crash as TARGET_CRASHED", () => {
    const result = classifyError(new Error("Page crashed!"));

    expect(result.code).toBe("TARGET_CRASHED");
  });

  it("classifies a browser disconnect", () => {
    const result = classifyError(new Error("browser has disconnected (pid=1234)"));

    expect(result.code).toBe("BROWSER_DISCONNECTED");
  });

  it("classifies a context closure distinctly from a plain target closure", () => {
    const contextResult = classifyError(new Error("Target page, context or browser has been closed"));
    expect(contextResult.code).toBe("CONTEXT_CLOSED");

    const pageResult = classifyError(new Error("Target closed"));
    expect(pageResult.code).toBe("PAGE_CLOSED");
  });

  it("classifies a browser launch failure", () => {
    const result = classifyError(new Error("Failed to launch chromium browser!"));

    expect(result.code).toBe("BROWSER_LAUNCH_FAILED");
  });

  it("classifies a lighthouse-operation timeout distinctly from a navigation timeout", () => {
    const lighthouse = classifyError(new Error("Timeout 60000ms exceeded."), "lighthouse");
    expect(lighthouse.code).toBe("LIGHTHOUSE_TIMEOUT");

    const render = classifyError(new Error("page.goto: Timeout 60000ms exceeded."), "render");
    expect(render.code).toBe("NAVIGATION_TIMEOUT");
  });

  it("falls back to an operation-specific failure code when nothing else matches", () => {
    const screenshot = classifyError(new Error("disk write failed"), "screenshot");
    expect(screenshot.code).toBe("SCREENSHOT_FAILED");

    const technology = classifyError(new Error("unexpected parser error"), "technology");
    expect(technology.code).toBe("TECHNOLOGY_FAILED");
  });

  it("falls back to a generic unknown-error classification without leaking the raw message", () => {
    const result = classifyError(new Error("some_internal_stack_trace_detail_12345"));

    expect(result.code).toBe("UNKNOWN_ANALYZER_ERROR");
    expect(result.message).not.toContain("some_internal_stack_trace_detail_12345");
    expect(result.retryable).toBe(false);
  });

  it("handles non-Error thrown values without crashing", () => {
    const result = classifyError("a plain string error");

    expect(result.code).toBe("UNKNOWN_ANALYZER_ERROR");
  });

  it("classifies exhaustion of the screenshot size-reduction fallback ladder as SCREENSHOT_RESOURCE_LIMIT", () => {
    const result = classifyError(new ScreenshotResourceExhaustedError(new Error("still too large after all fallbacks")), "screenshot");

    expect(result.code).toBe("SCREENSHOT_RESOURCE_LIMIT");
    expect(result.retryable).toBe(true);
  });

  it("classifies a connection reset mid-request the same as connection refused", () => {
    const result = classifyError(new Error("read ECONNRESET"));

    expect(result.code).toBe("CONNECTION_REFUSED");
    expect(result.retryable).toBe(true);
  });

  it("classifies a raw out-of-memory failure distinctly from a generic unknown error", () => {
    const result = classifyError(new Error("spawn ENOMEM"));

    expect(result.code).toBe("OUT_OF_MEMORY");
    expect(result.retryable).toBe(true);
  });
});
