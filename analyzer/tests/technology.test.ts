import { describe, expect, it } from "vitest";
import { detectTechnologies } from "../src/technology.js";

describe("detectTechnologies", () => {
  it("detects WordPress from strong generator signal", () => {
    const html = '<html><head><meta name="generator" content="WordPress 6.4"></head></html>';
    const matches = detectTechnologies(html);

    expect(matches.some((m) => m.name === "WordPress")).toBe(true);
  });

  it("detects WordPress from combined weaker path signals", () => {
    const html = '<link rel="stylesheet" href="/wp-content/themes/x/style.css">'
      + '<script src="/wp-includes/js/jquery.js"></script>';
    const matches = detectTechnologies(html);

    expect(matches.some((m) => m.name === "WordPress")).toBe(true);
  });

  it("does not flag a technology from a single very weak signal alone", () => {
    // Tailwindのユーティリティクラスパターンのみ(重み0.3)は閾値0.5未満なので検出されない。
    const html = '<div class="flex items-center justify-between"></div>';
    const matches = detectTechnologies(html);

    expect(matches.some((m) => m.name === "Tailwind CSS")).toBe(false);
  });

  it("detects multiple independent technologies in one page", () => {
    const html = `
      <html><head>
        <script src="https://www.googletagmanager.com/gtm.js"></script>
        <script src="https://cdn.shopify.com/s/files/x.js"></script>
      </head><body>
        <div class="g-recaptcha"></div>
      </body></html>
    `;
    const matches = detectTechnologies(html);
    const names = matches.map((m) => m.name);

    expect(names).toContain("Google Tag Manager");
    expect(names).toContain("Shopify");
    expect(names).toContain("reCAPTCHA");
  });

  it("returns no matches for plain html with no known signatures", () => {
    const html = "<html><body><p>Hello world</p></body></html>";

    expect(detectTechnologies(html)).toEqual([]);
  });

  it("caps confidence at 1.0 and includes evidence labels", () => {
    const html = '<meta name="generator" content="WordPress"><div class="wp-content/">'
      + '<script src="/wp-includes/foo.js"></script>';
    const match = detectTechnologies(html).find((m) => m.name === "WordPress");

    expect(match).toBeDefined();
    expect(match!.confidence).toBeLessThanOrEqual(1);
    expect(match!.evidence.length).toBeGreaterThan(0);
  });
});
