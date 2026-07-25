import { errors, type Page, type Response } from "playwright";
import { logger } from "./logger.js";

/**
 * ページ遷移の共通処理。render/screenshot/technology(内部render)の全てで
 * page.gotoを"networkidle"必須にしていたため、広告・解析タグ・遅延読込・
 * 定期通信を持つ大規模サイト(例: 大手ECサイト)ではnetworkidleに到達せず、
 * timeout一杯まで待ってNAVIGATION_TIMEOUTになっていた。
 *
 * 方針:
 * - "domcontentloaded"を基本到達条件にする(到達すればナビゲーション自体は成功)。
 * - その後の"load"待機・settle delayはベストエフォート(失敗を無視し先へ進む)。
 * - networkidleは待たない(継続的な通信があっても処理を続行する)。
 * - domcontentloaded自体がtimeoutした場合のみ、DOMの状態を検査して
 *   部分成功(partial)として継続可能か判定する。DNS/TLS/接続拒否/
 *   ブラウザクラッシュ等、TimeoutError以外の例外はそのまま再throwし、
 *   呼び出し元でclassifyError()に分類させる(継続不可)。
 */

// "load"完了を待つ上限(domcontentloaded後のベストエフォート)。
const POST_GOTO_LOAD_TIMEOUT_MS = 10_000;
// 描画の安定を待つための固定待機。無制限待機は行わない。
const SETTLE_DELAY_MS = 2_000;
// 部分成功として認めるための最低限のHTML長(空のプレースホルダー・
// about:blank相当を弾くための緩い閾値)。
const MIN_PARTIAL_HTML_LENGTH = 200;

export type NavigationStatus = "ok" | "partial";

export interface NavigationOutcome {
  response: Response | null;
  status: NavigationStatus;
  warning: string | null;
}

function isTimeoutError(err: unknown): boolean {
  return err instanceof errors.TimeoutError;
}

/**
 * page.goto()がTimeoutErrorで失敗した後、DOMが最低限利用可能な状態まで
 * 到達しているかを検査する。about:blankのまま・bodyが無い・readyStateが
 * loadingのまま等はfalseを返し、呼び出し元は失敗として扱う。
 */
async function canContinueAsPartial(page: Page): Promise<boolean> {
  const currentUrl = page.url();
  if (currentUrl === "about:blank" || currentUrl === "") {
    return false;
  }

  try {
    const state = await page.evaluate(() => ({
      readyState: document.readyState,
      hasBody: document.body !== null,
      htmlLength: document.documentElement?.outerHTML?.length ?? 0,
    }));

    if (!state.hasBody) {
      return false;
    }
    if (state.readyState !== "interactive" && state.readyState !== "complete") {
      return false;
    }
    if (state.htmlLength < MIN_PARTIAL_HTML_LENGTH) {
      return false;
    }

    return true;
  } catch {
    // page.evaluate自体が失敗する(=実質DOMへアクセスできない)場合は
    // 部分成功として扱わない。
    return false;
  }
}

function safeHost(url: string): string {
  try {
    return new URL(url).host;
  } catch {
    return "[unparseable-url]";
  }
}

export async function navigateResilient(page: Page, url: string, timeoutMs: number): Promise<NavigationOutcome> {
  try {
    const response = await page.goto(url, { waitUntil: "domcontentloaded", timeout: timeoutMs });

    // ベストエフォート: "load"完了まで少し待つ。analyticsの継続通信等で
    // networkidleにならなくても、ここでは失敗を無視して先へ進む。
    await page.waitForLoadState("load", { timeout: POST_GOTO_LOAD_TIMEOUT_MS }).catch(() => undefined);
    // 描画安定のための短い固定待機(上限付き)。
    await page.waitForTimeout(SETTLE_DELAY_MS);

    return { response, status: "ok", warning: null };
  } catch (err) {
    if (!isTimeoutError(err)) {
      // DNS/TLS/接続拒否/ブラウザクラッシュ等は継続不可。呼び出し元で
      // classifyError()により分類させるためそのまま再throwする。
      throw err;
    }

    const partial = await canContinueAsPartial(page);
    if (!partial) {
      throw err;
    }

    // URL全体(query等に機密情報を含み得る)ではなくhostのみ記録する。
    logger.warn({ code: "NAVIGATION_TIMEOUT", host: safeHost(url) }, "navigation_partial_success");

    return { response: null, status: "partial", warning: "NAVIGATION_TIMEOUT" };
  }
}
