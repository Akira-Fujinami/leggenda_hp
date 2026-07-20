/// <reference lib="dom" />
import type { Page } from "playwright";
import { logger } from "./logger.js";

export interface FixedCtaResult {
  detected: boolean;
  text: string | null;
  href: string | null;
  position: string | null;
}

const NULL_RESULT: FixedCtaResult = { detected: false, text: null, href: null, position: null };

/**
 * 「常時表示される行動喚起(固定/追従CTA)」の検出。
 * 静的HTMLだけではCSSの適用結果(position: fixed/sticky)を判定できないため、
 * レンダリング後のDOM上でgetComputedStyle()を用いて検出する
 * (このため analyzer(Playwright)側でのみ実装できる。バックエンドのHTML解析には移せない)。
 *
 * 判定基準: 実際に画面に表示されており(display/visibility/opacityが有効)、
 * position:fixed または sticky で、かつ画面下端・右端付近に配置され、
 * リンクテキスト/href/aria-labelのいずれかに問い合わせ・予約・電話・LINE等の
 * キーワードを含む要素。URLやテキストの一部だけの一致で断定せず、
 * 「実際にその場所に固定表示されている」という描画結果そのものを根拠にする。
 */
export async function detectFixedCta(page: Page): Promise<FixedCtaResult> {
  try {
    return await page.evaluate(() => {
      const KEYWORDS = [
        "contact", "inquiry", "inquire", "reserve", "reservation", "booking", "book now",
        "call", "tel:", "line.me", "lin.ee", "chat",
        "お問い合わせ", "お問合せ", "問い合わせ", "ご相談", "相談する", "予約", "電話", "資料請求", "無料相談",
      ];

      const NEAR_EDGE_PX = 80;
      const viewportW = window.innerWidth;
      const viewportH = window.innerHeight;

      const elements = Array.from(document.querySelectorAll<HTMLElement>("a, button, [role='button']"));

      for (const el of elements) {
        const style = window.getComputedStyle(el);

        if (style.position !== "fixed" && style.position !== "sticky") {
          continue;
        }

        if (style.display === "none" || style.visibility === "hidden" || parseFloat(style.opacity || "1") === 0) {
          continue;
        }

        const rect = el.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) {
          continue;
        }

        const nearBottom = rect.bottom >= viewportH - NEAR_EDGE_PX && rect.top <= viewportH;
        const nearRight = rect.right >= viewportW - NEAR_EDGE_PX && rect.left <= viewportW;
        if (!nearBottom && !nearRight) {
          continue;
        }

        const text = (el.textContent || "").trim();
        const href = el.getAttribute("href") || "";
        const ariaLabel = el.getAttribute("aria-label") || "";
        const combined = `${text} ${href} ${ariaLabel}`.toLowerCase();

        const matched = KEYWORDS.some((keyword) => combined.includes(keyword));
        if (!matched) {
          continue;
        }

        return {
          detected: true,
          text: text.slice(0, 100) || null,
          href: href || null,
          position: style.position,
        };
      }

      return { detected: false, text: null, href: null, position: null };
    });
  } catch (err) {
    logger.warn({ err }, "fixed_cta_detection_failed");
    return NULL_RESULT;
  }
}
