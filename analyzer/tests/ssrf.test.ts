import { describe, expect, it } from "vitest";
import { assertSafeUrl, SsrfError } from "../src/ssrf.js";

describe("assertSafeUrl", () => {
  it("allows a normal public https URL", async () => {
    const result = await assertSafeUrl("https://example.com/path");
    expect(result.url.hostname).toBe("example.com");
  });

  it("rejects non-http(s) protocols", async () => {
    await expect(assertSafeUrl("file:///etc/passwd")).rejects.toThrow(SsrfError);
    await expect(assertSafeUrl("ftp://example.com")).rejects.toThrow(SsrfError);
  });

  it("rejects blocked docker service hostnames", async () => {
    await expect(assertSafeUrl("http://backend:8000")).rejects.toThrow(SsrfError);
    await expect(assertSafeUrl("http://postgres:5432")).rejects.toThrow(SsrfError);
    await expect(assertSafeUrl("http://host.docker.internal")).rejects.toThrow(SsrfError);
  });

  it("rejects loopback and private IP literals", async () => {
    await expect(assertSafeUrl("http://127.0.0.1")).rejects.toThrow(SsrfError);
    await expect(assertSafeUrl("http://10.0.0.5")).rejects.toThrow(SsrfError);
    await expect(assertSafeUrl("http://172.16.0.5")).rejects.toThrow(SsrfError);
    await expect(assertSafeUrl("http://192.168.1.1")).rejects.toThrow(SsrfError);
    await expect(assertSafeUrl("http://169.254.169.254")).rejects.toThrow(SsrfError);
    await expect(assertSafeUrl("http://[::1]")).rejects.toThrow(SsrfError);
  });

  it("rejects malformed URLs", async () => {
    await expect(assertSafeUrl("not-a-url")).rejects.toThrow(SsrfError);
  });
});
