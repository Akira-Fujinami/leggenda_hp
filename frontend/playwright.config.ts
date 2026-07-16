import { defineConfig, devices } from "@playwright/test";

// これはE2Eテスト用のPlaywright設定であり、analyzer(サイト分析用)の
// Playwrightとは無関係。docker composeで起動済みのfrontend/backendに対して
// 実行する前提で、ここではブラウザの自動起動(webServer)は行わない。
export default defineConfig({
  testDir: "./e2e",
  fullyParallel: false,
  retries: 0,
  workers: 1,
  reporter: "list",
  // next dev はルートへの初回アクセス時にオンデマンドでコンパイルするため、
  // 本番ビルドより初回遷移が遅くなることがある。それを見込んで長めに設定する。
  timeout: 60_000,
  expect: { timeout: 15_000 },
  use: {
    baseURL: process.env.E2E_BASE_URL ?? "http://localhost:3000",
    trace: "retain-on-failure",
    navigationTimeout: 30_000,
  },
  projects: [
    {
      name: "chromium",
      use: { ...devices["Desktop Chrome"] },
    },
  ],
});
