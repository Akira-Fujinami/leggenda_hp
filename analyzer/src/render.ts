import { withPage } from "./browser.js";
import { detectFixedCta, type FixedCtaResult } from "./fixedCta.js";

export interface RenderResult {
  html: string;
  finalUrl: string;
  httpStatus: number | null;
  loadTimeMs: number;
  fixedCta: FixedCtaResult;
}

export interface RenderOptions {
  timeoutMs: number;
  maxHtmlBytes: number;
}

/**
 * JS実行後のHTMLを取得する。ページ自体のダウンロード/ポップアップ/内部IPへの
 * 二次リクエストのブロックは browser.ts の withPage 側で一括して行う。
 */
export async function renderPage(url: string, options: RenderOptions): Promise<RenderResult> {
  return withPage({ viewport: { width: 1440, height: 1000 } }, async (page) => {
    const started = Date.now();

    const response = await page.goto(url, {
      waitUntil: "networkidle",
      timeout: options.timeoutMs,
    });

    const html = await page.content();
    const truncated = Buffer.byteLength(html, "utf8") > options.maxHtmlBytes
      ? Buffer.from(html, "utf8").subarray(0, options.maxHtmlBytes).toString("utf8")
      : html;

    // レンダリング後のCSS適用状態(position: fixed/sticky)はJS実行後のDOMでしか
    // 判定できないため、静的HTML解析(HtmlSeoAnalyzer)ではなくここで検出する。
    // 失敗してもレンダリング自体は成功として扱う(取得できなかった、として null)。
    const fixedCta = await detectFixedCta(page);

    return {
      html: truncated,
      finalUrl: page.url(),
      httpStatus: response?.status() ?? null,
      loadTimeMs: Date.now() - started,
      fixedCta,
    };
  });
}
