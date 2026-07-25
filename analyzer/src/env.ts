import { z } from "zod";

const envSchema = z.object({
  PORT: z.coerce.number().int().positive().default(3001),
  HOST: z.string().default("0.0.0.0"),
  LOG_LEVEL: z.string().default("info"),
  NODE_ENV: z.enum(["development", "test", "production"]).default("development"),
  // analyzer内部API (Docker内部ネットワークのみ) の共有シークレット認証トークン。
  // 未設定の場合、開発環境以外では起動時に警告する(認証なしでの起動を避けるため)。
  ANALYZER_TOKEN: z.string().optional(),
  // 同時に処理できるPlaywright/Lighthouseセッション数の上限。低メモリの
  // Render環境を前提に既定値は1(単独実行)とする。超過したリクエストは
  // 即503にはせず、ANALYZER_QUEUE_MAX_WAIT_MS/ANALYZER_QUEUE_MAX_SIZEの
  // 範囲で短時間だけ待機してから処理する(大規模なキュー基盤は導入せず、
  // 同一Fastifyプロセス内のメモリ上の待機列のみで実装する)。
  ANALYZER_MAX_CONCURRENCY: z.coerce.number().int().positive().default(1),
  // 空きが無い場合に待機する上限時間。これを超えたら503 TOO_BUSYを返す
  // (無制限待機は行わない)。
  ANALYZER_QUEUE_MAX_WAIT_MS: z.coerce.number().int().nonnegative().default(15_000),
  // 待機列に積める最大件数。これを超えたリクエストは待機列に入れず即503にする
  // (無制限なキューイングによるメモリ増加を防ぐ)。Backend側をrender→
  // desktop screenshot→mobile screenshot→technology→lighthouseの順次
  // dispatchへ変更したため、同一サイトからは基本的に1件ずつしか届かない。
  // 既定値は複数サイト/複数分析が重なった場合の緩衝分のみを見込み、
  // 低メモリ環境向けに4から2へ引き下げた。
  ANALYZER_QUEUE_MAX_SIZE: z.coerce.number().int().nonnegative().default(2),
  BROWSER_TIMEOUT_MS: z.coerce.number().int().positive().default(30_000),
  // page.screenshot()自体の明示的timeout。Playwrightの既定(暗黙の30秒action
  // timeout)に任せると、巨大ページのfullPage撮影で"page.screenshot: Timeout
  // ...exceeded"がpage.goto側のNAVIGATION_TIMEOUTと誤分類される事故が起きる
  // (2026-07-25 ユニクロ調査)。Backend AnalyzerClient::SCREENSHOT_TIMEOUT_SECONDS
  // (90秒)より十分短く保つこと。
  ANALYZER_SCREENSHOT_TIMEOUT_MS: z.coerce.number().int().positive().max(60_000).default(45_000),
  // fullPageスクリーンショットで撮影する最大高さ(px)の第一次上限。
  // 2026-07-25時点では10000pxを既定としていたが、ユニクロ等の画像点数が
  // 多いECサイトで実際にRenderのメモリ上限(Analyzer exceeded its memory
  // limit)を超過する事故が発生した。原因は高さそのものよりも、viewportを
  // 高さ10000pxまで拡張することで「画面内」と判定される商品画像が
  // 一度に大量に遅延読込・デコードされることだと判断し、既定値を4000pxへ
  // 引き下げた。最終的な撮影高さはANALYZER_SCREENSHOT_MAX_PIXELSによる
  // 総ピクセル数上限でさらに縮小され得る(幅×高さの実効ピクセル数が
  // 優先される)。超過するページはviewport幅×この高さでクリップし、
  // truncated=trueとして部分成功で返す(無制限のfullPage撮影や画像の
  // 分割合成は行わない)。
  ANALYZER_SCREENSHOT_MAX_HEIGHT: z.coerce.number().int().positive().default(4_000),
  // 撮影する画像の実効ピクセル数(幅×高さ×deviceScaleFactor²、
  // deviceScaleFactorは常に1に固定するため実質的に幅×高さ)の上限。
  // モバイルは幅が狭い分、高さ上限だけでは長大ページのピクセル数を
  // 十分に抑えられないため、高さ上限とは独立にこちらでも縮小する
  // (どちらか小さい方が最終的な撮影高さになる)。
  ANALYZER_SCREENSHOT_MAX_PIXELS: z.coerce.number().int().positive().default(6_000_000),
  // JPEGエンコード後のファイルサイズ上限(byte)。超過時はquality低下→
  // captured height半減→viewportのみ撮影、の順に1回ずつ再試行し
  // (無制限リトライは行わない)、それでも超える場合は
  // SCREENSHOT_RESOURCE_LIMITとして失敗させる。
  ANALYZER_SCREENSHOT_MAX_BYTES: z.coerce.number().int().positive().default(3_000_000),
  // JPEGの初期quality。SEO比較用の確認画像であり印刷品質は不要なため、
  // 低メモリ環境での安全性を優先し80より低い値を既定にする。
  ANALYZER_SCREENSHOT_QUALITY: z.coerce.number().int().min(1).max(100).default(70),
  MAX_HTML_BYTES: z.coerce.number().int().positive().default(5 * 1024 * 1024),
  MAX_REDIRECTS: z.coerce.number().int().nonnegative().default(3),
  // Laravelと共有するDockerボリュームのマウント先。screenshotの保存先。
  ANALYSIS_STORAGE_PATH: z.string().default("/var/analysis-storage"),
  CRAWLER_USER_AGENT: z.string().default("WebsiteComparisonBot/0.1 (+https://example.com/bot)"),
  // テスト専用のSSRF許可リスト(例: "127.0.0.1:41234")。本番では未設定のまま
  // 運用すること。ローカルfixtureサーバーに対してレンダリング/スクリーンショット
  // パイプライン全体を安全にテストするための踏み台で、デフォルトでは何も
  // 許可しない(空文字列 = 効果なし)。
  SSRF_TEST_ALLOWLIST: z.string().default(""),
});

export const env = envSchema.parse(process.env);
