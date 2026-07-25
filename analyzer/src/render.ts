import { withPage } from "./browser.js";
import { detectFixedCta, type FixedCtaResult } from "./fixedCta.js";
import { navigateResilient, type NavigationStatus } from "./navigation.js";

export interface RenderResult {
  html: string;
  finalUrl: string;
  httpStatus: number | null;
  loadTimeMs: number;
  fixedCta: FixedCtaResult;
  navigationStatus: NavigationStatus;
  warning: string | null;
}

export interface RenderOptions {
  timeoutMs: number;
  maxHtmlBytes: number;
}

/**
 * JS実行後のHTMLを取得する。ページ自体のダウンロード/ポップアップ/内部IPへの
 * 二次リクエストのブロックは browser.ts の withPage 側で一括して行う。
 * ナビゲーションは navigation.ts の共通ヘルパーに委譲し、networkidleを
 * 必須条件にしない(大規模ECサイト等、継続通信があるページでも
 * domcontentloaded到達で処理を続行する)。
 */
export async function renderPage(url: string, options: RenderOptions): Promise<RenderResult> {
  return withPage({ viewport: { width: 1440, height: 1000 } }, async (page) => {
    const started = Date.now();

    const navigation = await navigateResilient(page, url, options.timeoutMs);

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
      httpStatus: navigation.response?.status() ?? null,
      loadTimeMs: Date.now() - started,
      fixedCta,
      navigationStatus: navigation.status,
      warning: navigation.warning,
    };
  });
}
