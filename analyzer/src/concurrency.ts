/**
 * 同時実行数を制限するシンプルなセマフォ。
 * Playwright/Lighthouseはメモリ・CPUを大きく消費するため、
 * ANALYZER_MAX_CONCURRENCYを超えるリクエストは待たせず即座に拒否する
 * (無制限のキューイングによるメモリ枯渇を防ぐため)。
 */
export class ConcurrencyLimiter {
  private active = 0;

  constructor(private readonly maxConcurrency: number) {}

  get activeCount(): number {
    return this.active;
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
  }

  /**
   * 空きがあれば処理を実行し、無ければnullを返す(呼び出し側が429/503を返す)。
   * finallyでrelease()するため、処理中に例外が起きてもカウントが漏れない。
   */
  async run<T>(fn: () => Promise<T>): Promise<T | typeof CONCURRENCY_LIMIT_EXCEEDED> {
    if (!this.tryAcquire()) {
      return CONCURRENCY_LIMIT_EXCEEDED;
    }

    try {
      return await fn();
    } finally {
      this.release();
    }
  }
}

export const CONCURRENCY_LIMIT_EXCEEDED = Symbol("CONCURRENCY_LIMIT_EXCEEDED");
