import { describe, expect, it } from "vitest";
import { buildAttempts, maxHeightForPixelBudget } from "../src/screenshot.js";

describe("maxHeightForPixelBudget", () => {
  it("derives a smaller height cap for a wider viewport at the same pixel budget", () => {
    const desktopCap = maxHeightForPixelBudget(1440, 6_000_000);
    const mobileCap = maxHeightForPixelBudget(390, 6_000_000);

    expect(desktopCap).toBeLessThan(mobileCap);
    expect(desktopCap).toBe(Math.floor(6_000_000 / 1440));
    expect(mobileCap).toBe(Math.floor(6_000_000 / 390));
  });

  it("never returns less than 1px even for an absurdly small pixel budget", () => {
    expect(maxHeightForPixelBudget(1440, 10)).toBe(1);
  });

  it("scales linearly with the pixel budget", () => {
    const base = maxHeightForPixelBudget(1000, 1_000_000);
    const doubled = maxHeightForPixelBudget(1000, 2_000_000);

    expect(doubled).toBe(base * 2);
  });
});

describe("buildAttempts", () => {
  it("builds a bounded 4-step cascade for a truncated fullPage request: clip -> lower quality -> half height -> viewport", () => {
    const attempts = buildAttempts(true, 1000, 4000, true);

    expect(attempts).toHaveLength(4);
    expect(attempts.map((a) => a.label)).toEqual(["primary", "quality_retry", "height_halved", "viewport_fallback"]);

    const [primary, qualityRetry, heightHalved, viewportFallback] = attempts;

    expect(primary.captureMode).toBe("clip");
    expect(primary.fullPage).toBe(false);
    expect(primary.viewportHeight).toBe(4000);
    expect(primary.truncated).toBe(true);

    // quality_retryは高さを変えず、qualityだけ下げる。
    expect(qualityRetry.viewportHeight).toBe(4000);
    expect(qualityRetry.quality).toBeLessThan(primary.quality);

    // height_halvedはさらに高さを半分にし、常にclip扱いになる。
    expect(heightHalved.captureMode).toBe("clip");
    expect(heightHalved.viewportHeight).toBe(2000);

    // 最終手段はviewportのみ(スクロールしない1画面分)で、常にtruncated扱い。
    expect(viewportFallback.captureMode).toBe("viewport");
    expect(viewportFallback.fullPage).toBe(false);
    expect(viewportFallback.viewportHeight).toBe(1000);
    expect(viewportFallback.truncated).toBe(true);
  });

  it("keeps fullPage:true across the first two attempts when the page fits within the height/pixel cap", () => {
    const attempts = buildAttempts(true, 1000, 800, false);

    expect(attempts[0].captureMode).toBe("full");
    expect(attempts[0].fullPage).toBe(true);
    expect(attempts[0].truncated).toBe(false);
    expect(attempts[1].captureMode).toBe("full");
    expect(attempts[1].fullPage).toBe(true);
  });

  it("only builds a 2-step quality cascade (no height/viewport fallback) for a non-fullPage request", () => {
    const attempts = buildAttempts(false, 800, 800, false);

    expect(attempts).toHaveLength(2);
    expect(attempts.every((a) => a.captureMode === "viewport" && a.viewportHeight === 800)).toBe(true);
    expect(attempts[1].quality).toBeLessThan(attempts[0].quality);
  });

  it("never lowers quality below the safety floor of 40", () => {
    const attempts = buildAttempts(true, 1000, 4000, true);

    expect(attempts.every((a) => a.quality >= 40)).toBe(true);
  });
});
