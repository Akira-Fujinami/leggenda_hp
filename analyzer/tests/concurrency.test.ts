import { describe, expect, it } from "vitest";
import { ConcurrencyLimiter, CONCURRENCY_LIMIT_EXCEEDED } from "../src/concurrency.js";

describe("ConcurrencyLimiter", () => {
  it("allows up to the configured concurrency", () => {
    const limiter = new ConcurrencyLimiter(2);

    expect(limiter.tryAcquire()).toBe(true);
    expect(limiter.tryAcquire()).toBe(true);
    expect(limiter.activeCount).toBe(2);
  });

  it("rejects once the limit is reached", () => {
    const limiter = new ConcurrencyLimiter(1);

    expect(limiter.tryAcquire()).toBe(true);
    expect(limiter.tryAcquire()).toBe(false);
  });

  it("frees a slot after release", () => {
    const limiter = new ConcurrencyLimiter(1);

    limiter.tryAcquire();
    limiter.release();

    expect(limiter.tryAcquire()).toBe(true);
  });

  it("run() releases the slot even when the task throws", async () => {
    const limiter = new ConcurrencyLimiter(1);

    await expect(
      limiter.run(async () => {
        throw new Error("boom");
      }),
    ).rejects.toThrow("boom");

    expect(limiter.activeCount).toBe(0);
    expect(limiter.tryAcquire()).toBe(true);
  });

  it("run() returns CONCURRENCY_LIMIT_EXCEEDED when no slot is available", async () => {
    const limiter = new ConcurrencyLimiter(1);
    limiter.tryAcquire();

    const result = await limiter.run(async () => "done");

    expect(result).toBe(CONCURRENCY_LIMIT_EXCEEDED);
  });
});
