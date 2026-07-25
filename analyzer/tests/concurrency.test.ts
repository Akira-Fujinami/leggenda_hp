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

  it("queues a second run() until the first releases its slot", async () => {
    const limiter = new ConcurrencyLimiter(1, 5_000, 4);
    let releaseFirst: () => void = () => {};
    const firstStarted = new Promise<void>((resolve) => {
      releaseFirst = resolve;
    });

    const first = limiter.run(async () => {
      await firstStarted;
      return "first";
    });

    // 1件目がまだ実行中の間は空きが無いため、2件目はqueuedCountに現れる。
    await new Promise((resolve) => setTimeout(resolve, 10));
    expect(limiter.queuedCount).toBe(0);

    const second = limiter.run(async () => "second");
    await new Promise((resolve) => setTimeout(resolve, 10));
    expect(limiter.queuedCount).toBe(1);

    releaseFirst();
    const [firstResult, secondResult] = await Promise.all([first, second]);

    expect(firstResult).toBe("first");
    expect(secondResult).toBe("second");
    expect(limiter.queuedCount).toBe(0);
  });

  it("returns CONCURRENCY_LIMIT_EXCEEDED after waiting past the queue timeout", async () => {
    const limiter = new ConcurrencyLimiter(1, 30, 4);
    limiter.tryAcquire(); // 唯一のスロットを埋めたままにする(解放しない)。

    const result = await limiter.run(async () => "should not run");

    expect(result).toBe(CONCURRENCY_LIMIT_EXCEEDED);
  });

  it("returns CONCURRENCY_LIMIT_EXCEEDED immediately once the wait queue itself is full", async () => {
    const limiter = new ConcurrencyLimiter(1, 5_000, 1);
    limiter.tryAcquire(); // アクティブ枠を埋める。

    const blockingWaiter = limiter.run(async () => "eventually runs");
    await new Promise((resolve) => setTimeout(resolve, 10));
    expect(limiter.queuedCount).toBe(1);

    // 待機列は既に1件で満杯(maxQueueSize=1)のため、即座に拒否される。
    const overflow = await limiter.run(async () => "never runs");
    expect(overflow).toBe(CONCURRENCY_LIMIT_EXCEEDED);

    limiter.release();
    await blockingWaiter;
  });
});
