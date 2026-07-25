/**
 * 同時実行数を制限するシンプルなセマフォ+上限付き待機キュー。
 * Playwright/Lighthouseはメモリ・CPUを大きく消費するため、低メモリの
 * Render環境では ANALYZER_MAX_CONCURRENCY=1 を既定とし、無条件に
 * 上限を引き上げることはしない。
 *
 * 上限到達時は即座に拒否せず、maxQueueSize件までを最大maxQueueWaitMsだけ
 * 待たせる(大規模なキュー基盤は使わず、プロセス内メモリの配列とtimerのみで
 * 実装する)。待機列が満杯、または待機がmaxQueueWaitMsを超えた場合のみ
 * CONCURRENCY_LIMIT_EXCEEDED(呼び出し側で503 TOO_BUSY)を返す。
 */
export class ConcurrencyLimiter {
  private active = 0;
  private readonly waiters: Array<() => void> = [];

  constructor(
    private readonly maxConcurrency: number,
    private readonly maxQueueWaitMs: number = 0,
    private readonly maxQueueSize: number = 0,
  ) {}

  get activeCount(): number {
    return this.active;
  }

  get queuedCount(): number {
    return this.waiters.length;
  }

  tryAcquire(): boolean {
    if (this.active >= this.maxConcurrency) {
      return false;
    }
    this.active += 1;
    return true;
  }

  release(): void {
    this.active = Math.max(0, this.active - 1);

    const next = this.waiters.shift();
    if (next) {
      this.active += 1;
      next();
    }
  }

  /**
   * 空きがあれば即座に処理を実行する。空きが無い場合、待機列に余裕があれば
   * maxQueueWaitMsを上限に待ち、それでも空かなければnullを返す
   * (呼び出し側が429/503を返す)。finallyでrelease()するため、処理中に
   * 例外が起きてもカウントが漏れない。
   */
  async run<T>(fn: () => Promise<T>): Promise<T | typeof CONCURRENCY_LIMIT_EXCEEDED> {
    const acquired = this.tryAcquire() ? true : await this.waitForSlot();

    if (!acquired) {
      return CONCURRENCY_LIMIT_EXCEEDED;
    }

    try {
      return await fn();
    } finally {
      this.release();
    }
  }

  private waitForSlot(): Promise<boolean> {
    if (this.maxQueueWaitMs <= 0 || this.waiters.length >= this.maxQueueSize) {
      return Promise.resolve(false);
    }

    return new Promise<boolean>((resolve) => {
      let settled = false;

      const onReady = () => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        resolve(true);
      };

      const timer = setTimeout(() => {
        if (settled) return;
        settled = true;
        const idx = this.waiters.indexOf(onReady);
        if (idx >= 0) this.waiters.splice(idx, 1);
        resolve(false);
      }, this.maxQueueWaitMs);

      this.waiters.push(onReady);
    });
  }
}

export const CONCURRENCY_LIMIT_EXCEEDED = Symbol("CONCURRENCY_LIMIT_EXCEEDED");
