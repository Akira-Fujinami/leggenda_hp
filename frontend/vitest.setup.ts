import "@testing-library/jest-dom/vitest";

// jsdomはIntersectionObserver/scrollIntoViewを実装していない。
// セクションナビのスクロール連動(use-active-section)を使うコンポーネントの
// テストがエラーにならないよう、最小限のno-op実装を用意する。
if (typeof window !== "undefined") {
  window.HTMLElement.prototype.scrollIntoView ??= () => {};

  class MockIntersectionObserver implements IntersectionObserver {
    readonly root: Element | Document | null = null;
    readonly rootMargin: string = "";
    readonly thresholds: ReadonlyArray<number> = [];
    observe = () => {};
    unobserve = () => {};
    disconnect = () => {};
    takeRecords = () => [];
  }

  window.IntersectionObserver ??= MockIntersectionObserver as unknown as typeof IntersectionObserver;
}
