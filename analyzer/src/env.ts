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
  // (無制限なキューイングによるメモリ増加を防ぐ)。
  ANALYZER_QUEUE_MAX_SIZE: z.coerce.number().int().nonnegative().default(4),
  BROWSER_TIMEOUT_MS: z.coerce.number().int().positive().default(30_000),
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
