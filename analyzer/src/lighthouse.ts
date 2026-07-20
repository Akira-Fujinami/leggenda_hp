import * as chromeLauncher from "chrome-launcher";
import lighthouse from "lighthouse";
import { chromium } from "playwright";

export interface LighthouseResult {
  scores: {
    performance: number | null;
    accessibility: number | null;
    best_practices: number | null;
    seo: number | null;
  };
  metrics: {
    fcp_ms: number | null;
    lcp_ms: number | null;
    cls: number | null;
    speed_index_ms: number | null;
    tbt_ms: number | null;
    inp_ms: number | null;
    request_count: number | null;
    transfer_size_bytes: number | null;
  };
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  rawReport: any;
}

/**
 * Lighthouseはchrome-launcherで専用のChromeプロセスを都度起動する
 * (レンダリング用の共有Playwrightブラウザとは別プロセス)。
 * 取得できなかった指標はnullのままとし、0にフォールバックしない。
 * finallyで必ずchrome.kill()し、プロセスがリークしないようにする。
 */
export async function runLighthouse(url: string, timeoutMs: number): Promise<LighthouseResult> {
  const chrome = await chromeLauncher.launch({
    chromePath: chromium.executablePath(),
    chromeFlags: ["--headless=new", "--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage"],
  });

  try {
    const result = await lighthouse(url, {
      port: chrome.port,
      output: "json",
      logLevel: "silent",
      onlyCategories: ["performance", "accessibility", "best-practices", "seo"],
      maxWaitForLoad: timeoutMs,
    });

    if (!result?.lhr) {
      throw new Error("lighthouseが結果を返しませんでした。");
    }

    const lhr = result.lhr;

    const categoryScore = (key: string): number | null => {
      const score = lhr.categories[key]?.score;
      return score == null ? null : Math.round(score * 100);
    };

    const auditValue = (id: string): number | null => {
      const value = lhr.audits[id]?.numericValue;
      return typeof value === "number" ? Math.round(value) : null;
    };

    // "network-requests"/"total-byte-weight" はLighthouseのバージョンによって
    // audit IDやdetails構造が変わり得るため、optional chainingで安全に取り出し、
    // 取得できない場合は0にフォールバックせずnullのままにする。
    const requestCount = (() => {
      // "network-requests" auditのdetailsは複数のバリアント型のunionであり、
      // "items"を持つ型に静的に絞り込めないため、実行時にArray.isArray()で確認する。
      const details = lhr.audits["network-requests"]?.details as { items?: unknown[] } | undefined;
      return Array.isArray(details?.items) ? details.items.length : null;
    })();

    return {
      scores: {
        performance: categoryScore("performance"),
        accessibility: categoryScore("accessibility"),
        best_practices: categoryScore("best-practices"),
        seo: categoryScore("seo"),
      },
      metrics: {
        fcp_ms: auditValue("first-contentful-paint"),
        lcp_ms: auditValue("largest-contentful-paint"),
        cls: lhr.audits["cumulative-layout-shift"]?.numericValue ?? null,
        speed_index_ms: auditValue("speed-index"),
        tbt_ms: auditValue("total-blocking-time"),
        inp_ms: auditValue("interaction-to-next-paint") ?? auditValue("experimental-interaction-to-next-paint"),
        request_count: requestCount,
        transfer_size_bytes: auditValue("total-byte-weight"),
      },
      rawReport: lhr,
    };
  } finally {
    await chrome.kill();
  }
}
