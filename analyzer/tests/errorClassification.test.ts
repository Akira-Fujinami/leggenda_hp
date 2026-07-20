import { describe, expect, it } from "vitest";
import { classifyError } from "../src/errorClassification.js";

describe("classifyError", () => {
  it("classifies a Playwright navigation timeout", () => {
    const result = classifyError(new Error("page.goto: Timeout 60000ms exceeded."));

    expect(result.code).toBe("NAVIGATION_TIMEOUT");
    expect(result.message).toContain("タイムアウト");
  });

  it("classifies an access-denied response", () => {
    const result = classifyError(new Error("Navigation failed: 403 Forbidden"));

    expect(result.code).toBe("ACCESS_DENIED");
  });

  it("classifies too many redirects", () => {
    const result = classifyError(new Error("net::ERR_TOO_MANY_REDIRECTS at https://example.com/"));

    expect(result.code).toBe("TOO_MANY_REDIRECTS");
  });

  it("classifies a certificate error", () => {
    const result = classifyError(new Error("net::ERR_CERT_AUTHORITY_INVALID"));

    expect(result.code).toBe("SSL_ERROR");
  });

  it("classifies a DNS resolution failure", () => {
    const result = classifyError(new Error("net::ERR_NAME_NOT_RESOLVED"));

    expect(result.code).toBe("DNS_ERROR");
  });

  it("classifies a page crash", () => {
    const result = classifyError(new Error("Page crashed!"));

    expect(result.code).toBe("PAGE_CRASHED");
  });

  it("classifies a browser launch failure", () => {
    const result = classifyError(new Error("Failed to launch chromium browser!"));

    expect(result.code).toBe("BROWSER_LAUNCH_FAILED");
  });

  it("falls back to a generic unknown-error classification without leaking the raw message", () => {
    const result = classifyError(new Error("some_internal_stack_trace_detail_12345"));

    expect(result.code).toBe("UNKNOWN_ANALYZER_ERROR");
    expect(result.message).not.toContain("some_internal_stack_trace_detail_12345");
  });

  it("handles non-Error thrown values without crashing", () => {
    const result = classifyError("a plain string error");

    expect(result.code).toBe("UNKNOWN_ANALYZER_ERROR");
  });
});
