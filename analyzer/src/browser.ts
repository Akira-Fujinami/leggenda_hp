import { chromium, type Browser, type BrowserContext, type Page } from "playwright";
import { env } from "./env.js";
import { logger } from "./logger.js";
import { assertSafeUrl, SsrfError } from "./ssrf.js";

let sharedBrowser: Browser | null = null;
let launching: Promise<Browser> | null = null;
let activeContextCount = 0;

async function getBrowser(): Promise<Browser> {
  if (sharedBrowser?.isConnected()) {
    return sharedBrowser;
  }

  if (!launching) {
    // lighthouse.tsのchrome-launcher起動と同じ堅牢化フラグを揃える。
    // 特に--disable-dev-shm-usageが無いと、Dockerの既定/dev/shm(64MB)を
    // 使おうとして重いページのレンダリング/フルページスクリーンショットで
    // "Target crashed"になりやすい(render/screenshot/technology detectionの
    // 失敗調査で判明した設定不備)。
    launching = chromium
      .launch({
        headless: true,
        args: ["--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage"],
      })
      .then((browser) => {
        sharedBrowser = browser;
        launching = null;

        // disconnectedはOOM-kill・クラッシュ・closeBrowser()呼び出し等
        // 様々な理由で発火し得る。次回getBrowser()呼び出し時に
        // isConnected()===falseとして検知され自動的に再生成されるため、
        // ここでは原因調査用のログのみ残す(再起動処理自体は不要)。
        browser.on("disconnected", () => {
          logger.warn({ event: "browser_disconnected" }, "shared_browser_disconnected");
          if (sharedBrowser === browser) {
            sharedBrowser = null;
          }
        });

        return browser;
      });
  }

  return launching;
}

export function isBrowserConnected(): boolean {
  return sharedBrowser?.isConnected() ?? false;
}

export function getActiveContextCount(): number {
  return activeContextCount;
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
  deviceScaleFactor?: number;
  // context.close()完了直後に呼ばれる任意フック。呼び出し側(screenshot.ts等)が
  // クローズ後のメモリ使用量を計測するためのもので、withPage自体の責務には
  // 含めない。
  afterClose?: () => void;
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
    // 撮影サイズの実効ピクセル数(幅×高さ×deviceScaleFactor²)の見積もりを
    // 単純化し、意図せず高解像度(Retina等)相当の撮影でメモリ消費が
    // 数倍になることを防ぐため、明示的に指定がない限り常に1に固定する。
    deviceScaleFactor: options.deviceScaleFactor ?? 1,
  });
  activeContextCount += 1;

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
    activeContextCount = Math.max(0, activeContextCount - 1);
    options.afterClose?.();
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
