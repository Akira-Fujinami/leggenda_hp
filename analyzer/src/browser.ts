import { chromium, type Browser, type BrowserContext, type Page } from "playwright";
import { env } from "./env.js";
import { logger } from "./logger.js";
import { assertSafeUrl, SsrfError } from "./ssrf.js";

let sharedBrowser: Browser | null = null;
let launching: Promise<Browser> | null = null;

async function getBrowser(): Promise<Browser> {
  if (sharedBrowser?.isConnected()) {
    return sharedBrowser;
  }

  if (!launching) {
    launching = chromium.launch({ headless: true }).then((browser) => {
      sharedBrowser = browser;
      launching = null;
      return browser;
    });
  }

  return launching;
}

export async function closeBrowser(): Promise<void> {
  if (sharedBrowser) {
    await sharedBrowser.close();
    sharedBrowser = null;
  }
}

export interface WithPageOptions {
  viewport: { width: number; height: number };
  userAgent?: string;
}

/**
 * ページ単位のBrowserContext/Pageを確実に破棄しつつ処理を行う共通ヘルパー。
 *
 * - acceptDownloads: false でダウンロード開始を抑止する
 * - 新規タブ/ポップアップは即座に閉じる (target=_blank等の悪用対策)
 * - page.route()でページ内の全リクエスト(画像/script/fetch/iframe等)を検査し、
 *   内部IP・許可外スキームへのアクセスをブロックする(ユーザー指定URL自体は
 *   呼び出し側で事前にassertSafeUrl済みだが、レンダリング後にページが
 *   発行する二次リクエストは別途ここで検査する必要がある)
 * - 例外の有無に関わらずfinallyでcontext.close()する(ブラウザリソースリーク防止)
 */
export async function withPage<T>(
  options: WithPageOptions,
  fn: (page: Page, context: BrowserContext) => Promise<T>,
): Promise<T> {
  const browser = await getBrowser();
  const context = await browser.newContext({
    viewport: options.viewport,
    userAgent: options.userAgent ?? env.CRAWLER_USER_AGENT,
    acceptDownloads: false,
    ignoreHTTPSErrors: false,
  });

  try {
    const page = await context.newPage();

    // 'page'イベントは新規タブ/ポップアップだけでなく、context.newPage()で
    // 作成した最初のページ自身に対しても発火する。先にnewPage()を待ってから
    // リスナーを登録し、かつ念のためmainページ自身は対象から除外することで、
    // 作成直後のページを誤って閉じてしまわないようにする。
    context.on("page", (popup) => {
      if (popup === page) {
        return;
      }
      popup.close().catch(() => {
        // ポップアップが既に閉じられている等は無視してよい。
      });
    });

    await context.route("**/*", async (route) => {
      const requestUrl = route.request().url();

      try {
        await assertSafeUrl(requestUrl);
      } catch (err) {
        if (err instanceof SsrfError) {
          logger.warn({ blockedUrl: safeLogUrl(requestUrl) }, "in_page_request_blocked");
          await route.abort("blockedbyclient");
          return;
        }
        throw err;
      }

      await route.continue();
    });

    return await fn(page, context);
  } finally {
    await context.close().catch((err) => {
      logger.error({ err }, "failed_to_close_browser_context");
    });
  }
}

function safeLogUrl(url: string): string {
  try {
    const parsed = new URL(url);
    return `${parsed.protocol}//${parsed.host}${parsed.pathname}`;
  } catch {
    return "[unparseable-url]";
  }
}
