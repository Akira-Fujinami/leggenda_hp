export interface TechnologyMatch {
  name: string;
  category: string;
  confidence: number;
  evidence: string[];
}

interface Signal {
  pattern: RegExp;
  weight: number;
  label: string;
}

interface Detector {
  name: string;
  category: string;
  signals: Signal[];
}

// 弱いシグナル1つだけでの誤検出を避けるため、複数シグナルの重みを合算し、
// 合計confidenceが閾値(0.5)以上の場合のみ「検出」とする。
const DETECTORS: Detector[] = [
  {
    name: "WordPress",
    category: "cms",
    signals: [
      { pattern: /wp-content\//i, weight: 0.6, label: "wp-content path" },
      { pattern: /wp-includes\//i, weight: 0.5, label: "wp-includes path" },
      { pattern: /name="generator" content="WordPress/i, weight: 0.9, label: "generator meta tag" },
    ],
  },
  {
    name: "Shopify",
    category: "cms",
    signals: [
      { pattern: /cdn\.shopify\.com/i, weight: 0.8, label: "cdn.shopify.com" },
      { pattern: /Shopify\.theme/i, weight: 0.7, label: "Shopify.theme inline script" },
    ],
  },
  {
    name: "Wix",
    category: "cms",
    signals: [
      { pattern: /static\.wixstatic\.com/i, weight: 0.8, label: "static.wixstatic.com" },
      { pattern: /wix\.com/i, weight: 0.4, label: "wix.com reference" },
    ],
  },
  {
    name: "Squarespace",
    category: "cms",
    signals: [
      { pattern: /static1\.squarespace\.com/i, weight: 0.8, label: "static1.squarespace.com" },
      { pattern: /squarespace-cdn\.com/i, weight: 0.7, label: "squarespace-cdn.com" },
    ],
  },
  {
    name: "Next.js",
    category: "framework",
    signals: [
      { pattern: /__NEXT_DATA__/, weight: 0.9, label: "__NEXT_DATA__" },
      { pattern: /\/_next\//, weight: 0.6, label: "/_next/ asset path" },
    ],
  },
  {
    name: "Nuxt",
    category: "framework",
    signals: [
      { pattern: /__NUXT__/, weight: 0.9, label: "__NUXT__" },
      { pattern: /\/_nuxt\//, weight: 0.6, label: "/_nuxt/ asset path" },
    ],
  },
  {
    name: "React",
    category: "framework",
    signals: [
      { pattern: /data-reactroot/i, weight: 0.6, label: "data-reactroot attribute" },
      { pattern: /react-dom(\.production|\.min)?\.js/i, weight: 0.5, label: "react-dom script" },
    ],
  },
  {
    name: "Vue.js",
    category: "framework",
    signals: [
      { pattern: /data-v-[0-9a-f]{6,}/i, weight: 0.6, label: "scoped data-v-* attribute" },
      { pattern: /vue(\.global)?(\.min)?\.js/i, weight: 0.5, label: "vue.js script" },
    ],
  },
  {
    name: "jQuery",
    category: "library",
    signals: [
      { pattern: /jquery(-[\d.]+)?(\.min)?\.js/i, weight: 0.7, label: "jquery script" },
    ],
  },
  {
    name: "Bootstrap",
    category: "library",
    signals: [
      { pattern: /bootstrap(\.min)?\.css/i, weight: 0.6, label: "bootstrap.css" },
      { pattern: /bootstrap(\.bundle)?(\.min)?\.js/i, weight: 0.4, label: "bootstrap.js" },
    ],
  },
  {
    name: "Tailwind CSS",
    category: "library",
    signals: [
      { pattern: /tailwindcss/i, weight: 0.5, label: "tailwindcss reference" },
      { pattern: /class="[^"]*\b(flex|grid)\b[^"]*\b(items-center|justify-between)\b/i, weight: 0.3, label: "utility class pattern" },
    ],
  },
  {
    name: "Google Analytics",
    category: "analytics",
    signals: [
      { pattern: /www\.google-analytics\.com\/analytics\.js/i, weight: 0.8, label: "analytics.js" },
      { pattern: /gtag\(['"]config['"],\s*['"]G-/i, weight: 0.8, label: "gtag config" },
    ],
  },
  {
    name: "Google Tag Manager",
    category: "analytics",
    signals: [
      { pattern: /googletagmanager\.com\/gtm\.js/i, weight: 0.8, label: "gtm.js" },
    ],
  },
  {
    name: "Meta Pixel",
    category: "analytics",
    signals: [
      { pattern: /connect\.facebook\.net\/[^"']*\/fbevents\.js/i, weight: 0.8, label: "fbevents.js" },
    ],
  },
  {
    name: "Hotjar",
    category: "analytics",
    signals: [
      { pattern: /static\.hotjar\.com/i, weight: 0.8, label: "static.hotjar.com" },
    ],
  },
  {
    name: "Microsoft Clarity",
    category: "analytics",
    signals: [
      { pattern: /www\.clarity\.ms\/tag/i, weight: 0.8, label: "clarity.ms/tag" },
    ],
  },
  {
    name: "Cloudflare",
    category: "infrastructure",
    signals: [
      { pattern: /cdnjs\.cloudflare\.com/i, weight: 0.4, label: "cdnjs.cloudflare.com" },
      { pattern: /__cf_bm|cf-ray/i, weight: 0.6, label: "cloudflare marker" },
    ],
  },
  {
    name: "reCAPTCHA",
    category: "security",
    signals: [
      { pattern: /www\.google\.com\/recaptcha/i, weight: 0.8, label: "google.com/recaptcha" },
      { pattern: /g-recaptcha/i, weight: 0.6, label: "g-recaptcha element" },
    ],
  },
];

/**
 * HTML(可能ならレンダリング後)を対象に既知の技術シグネチャを検出する。
 * 単一の弱いシグナルのみでは誤検出になりやすいため、シグナルの重みを合算し
 * 閾値以上の場合のみ結果に含める。
 */
export function detectTechnologies(html: string): TechnologyMatch[] {
  const matches: TechnologyMatch[] = [];

  for (const detector of DETECTORS) {
    let confidence = 0;
    const evidence: string[] = [];

    for (const signal of detector.signals) {
      if (signal.pattern.test(html)) {
        confidence += signal.weight;
        evidence.push(signal.label);
      }
    }

    if (confidence >= 0.5) {
      matches.push({
        name: detector.name,
        category: detector.category,
        confidence: Math.min(1, Math.round(confidence * 100) / 100),
        evidence,
      });
    }
  }

  return matches;
}
