# Website Comparison

SEO分析・Webサイト比較ツール。Next.js (フロントエンド) + Laravel (API) + Node.js (analyzer) のモノレポ構成。

現在は **Phase 0: Docker基盤セットアップ** の段階です。認証・分析ロジックなどの業務機能は未実装です。

## 技術構成

| レイヤー | 技術 |
|---|---|
| フロントエンド | Next.js 16 / TypeScript / App Router / Tailwind CSS / shadcn/ui |
| バックエンドAPI | Laravel / PHP 8.4 / PostgreSQL / Redis / Laravel Queue |
| 分析ワーカー | Node.js / TypeScript / Fastify (Playwright/Lighthouseは今後追加) |
| 開発用メール確認 | Mailpit |

## ディレクトリ構成

```text
website-comparison/
├── frontend/     Next.js
├── backend/      Laravel API / キュー / DB
├── analyzer/     Node.js (Playwright/Lighthouse実行用の内部API)
├── docker/
│   ├── php/      php.ini / opcache.ini
│   ├── nginx/    本番用リバースプロキシ設定
│   ├── postgres/ (現状未使用。将来のinit SQL置き場)
│   ├── scripts/  コンテナ起動時のentrypointスクリプト
│   └── certs/    社内プロキシ等の追加ルート証明書 (任意, 詳細はREADME参照)
├── compose.yaml           共通定義
├── compose.override.yaml  ローカル開発用上書き (自動マージされる)
├── compose.prod.yaml      本番用上書き
├── .env.example           docker composeの配線用 (ポート・DB認証情報)
└── Makefile
```

## 事前準備

- Docker Desktop (Docker Engine 24+ / Compose v2)
- Windowsの場合、ファイル共有 (bind mount) が有効なDocker Desktop設定

## 初回セットアップ

### Makefileを使う場合

```bash
make setup
```

### Makefileが使えない場合 (Windows等) — 直接コマンド

```bash
cp .env.example .env
cp backend/.env.example backend/.env

docker compose build

docker compose run --rm backend composer install
docker compose run --rm frontend npm install
docker compose run --rm analyzer npm install

docker compose run --rm backend php artisan key:generate

docker compose up -d postgres redis
docker compose run --rm backend php artisan migrate --seed
docker compose run --rm backend php artisan storage:link

docker compose up -d
```

初回のみ `composer install` / `npm install` / `migrate` を実行します。
2回目以降は `docker compose up -d` (または `make up`) だけで起動できます。
依存関係やマイグレーションはコンテナ起動のたびには自動実行されません
(意図的にそうしています。壊れた状態のvendor/node_modulesで起動し続けるのを防ぐため)。

## 起動確認

| サービス | URL |
|---|---|
| フロントエンド | http://localhost:3000 |
| バックエンドAPI ヘルスチェック | http://localhost:8000/api/health |
| Mailpit (メール確認UI) | http://localhost:8025 |

## よく使うコマンド

```bash
make up               # docker compose up -d
make down             # docker compose down
make restart          # down && up
make logs             # docker compose logs -f
make migrate          # マイグレーション実行
make seed             # シーダー実行
make test             # backend(PHPUnit) / analyzer(Vitest) のテスト実行
make shell-backend    # backendコンテナにshellで入る
make shell-frontend
make shell-analyzer
make queue-restart    # キューワーカーの再起動 (コード変更後に反映させる)
```

Makefileを使わない場合は、上記の `docker compose ...` 部分を直接実行してください。

## サービス構成

| サービス | 役割 | ホスト公開 |
|---|---|---|
| frontend | Next.js | 3000 |
| backend | Laravel API (dev: `artisan serve` / prod: php-fpm) | 8000 (devのみ直接、prodはnginx経由) |
| queue-worker | `default,analysis,reports` キュー処理 | なし |
| queue-worker-external | `external-api,ai` (Semrush/AI Provider等の外部API呼び出し)キュー処理 | なし |
| queue-worker-heavy | `analysis-heavy` (Playwright/Lighthouse等の重い処理) キュー処理 | なし |
| scheduler | `php artisan schedule:work` | なし |
| analyzer | Playwright/Lighthouse実行用Node.js内部API | なし (Docker内部のみ、`http://analyzer:3001`) |
| postgres | PostgreSQL 17 | なし |
| redis | Redis 7 (キュー/キャッシュ/セッション) | なし |
| mailpit | 開発用SMTPキャッチャー | 8025 (UI) |
| nginx (本番のみ) | php-fpmの前段リバースプロキシ | 8000 |

PostgreSQL/Redis/analyzerはホストに公開していません。中身を確認したい場合は
`docker compose exec postgres psql -U app -d website_comparison` のように
コンテナ内から操作してください。analyzerの動作をホストから直接叩いて確認したい場合のみ、
`compose.override.yaml` のコメントアウトされた `ports:` を一時的に有効にしてください。

## 環境変数の責務分担

- **ルートの `.env`**: docker composeがサービス間の配線に使う値のみ
  (ホスト公開ポート、PostgreSQLの認証情報)。`compose.yaml` の `environment:` を経由して
  `backend` / `queue-worker` などに反映されるため、Laravel側の値と重複・矛盾しない。
- **`backend/.env`**: Laravel固有の設定 (APP_KEY, ログレベル, メール送信者名,
  SEO_PROVIDER/SEMRUSH_API_KEY等の外部API切り替え設定)。DB/Redis/ANALYZER_URL等の
  docker配線に関わる値も一応書かれているが、実行時は `compose.yaml` 側の値が優先される
  (Laravelは既に設定済みの環境変数を`.env`で上書きしない)。

## 外部Provider (Semrush / AI分析)

外部SEOデータ(Semrush)とAI分析(OpenAI)は、それぞれ独立したProvider切り替え式です。

| 環境変数 | 用途 | 通常利用時の値 |
|---|---|---|
| `SEO_PROVIDER` | `semrush`(実データ)または`mock`(デモデータ) | `semrush` |
| `SEMRUSH_API_KEY` | Semrush APIキー。未設定だと`semrush`指定時に起動時エラー | 実際のキー |
| `SEMRUSH_DATABASE` / `SEMRUSH_TIMEOUT` / `SEMRUSH_MAX_RETRIES` / `SEMRUSH_DAILY_UNIT_LIMIT` / `SEMRUSH_CACHE_TTL_HOURS` | Semrush呼び出しの詳細設定 | 用途に応じて調整 |
| `AI_PROVIDER` | `openai`(実データ)または`mock`(デモデータ)。`anthropic`は未実装 | `openai` |
| `OPENAI_API_KEY` / `OPENAI_MODEL` | OpenAI APIキー・使用モデル | 実際のキー |
| `AI_TIMEOUT` / `AI_MAX_RETRIES` / `AI_MAX_INPUT_TOKENS` / `AI_MAX_OUTPUT_TOKENS` | AI呼び出しの詳細設定 | 用途に応じて調整 |
| `ALLOW_MOCK_PROVIDERS` | `SEO_PROVIDER=mock` / `AI_PROVIDER=mock` の利用を許可するスイッチ | `false`(本番・通常利用) |

**方針**:
- production環境(`APP_ENV=production`)では、`ALLOW_MOCK_PROVIDERS`の値に関わらず`mock`は常に拒否されます
  (`MOCK_PROVIDER_NOT_ALLOWED_IN_PRODUCTION` / `AI_PROVIDER_MOCK_IN_PRODUCTION`)。
- production以外でも、`ALLOW_MOCK_PROVIDERS=true`を明示しない限り`mock`は拒否されます
  (`MOCK_PROVIDER_NOT_ALLOWED`)。通常起動で意図せずデモデータが使われることを防ぐためです。
- APIキー未設定時にモックへ自動フォールバックすることはありません。`semrush`/`openai`を
  指定してキーが無い場合は明確な設定エラー(`SEMRUSH_NOT_CONFIGURED` / `OPENAI_NOT_CONFIGURED`)になります。
- 外部APIの障害(認証失敗・レート制限・タイムアウト等)は、該当項目を`unavailable`にするだけで、
  Analysis全体やAI分析結果を`failed`にはしません。
- **このリポジトリの開発用 `backend/.env`・ルート `.env` は、APIキー無しでも動かせるよう
  `ALLOW_MOCK_PROVIDERS=true` + `SEO_PROVIDER=mock` + `AI_PROVIDER=mock` のままにしてあります。**
  実データで試す場合は、両方の`.env`で`SEMRUSH_API_KEY`/`OPENAI_API_KEY`を設定した上で
  `SEO_PROVIDER=semrush`・`AI_PROVIDER=openai`に変更してください
  (ルート`.env`の値は`compose.yaml`のenvironment:経由で`backend/.env`より優先されるため、
  両方を揃える必要があります)。

### Mockデータの確認・削除

開発環境でモック由来のデータ(`ExternalDataSnapshot.is_mock=true` / AI分析結果の`is_mock=true`、
および任意でE2Eテストが作成したProject一式)を確認・削除するには:

```bash
docker compose exec backend php artisan analysis:purge-mock-data                       # dry-run(既定、何も削除しない)
docker compose exec backend php artisan analysis:purge-mock-data --execute             # 確認プロンプト付きで削除
docker compose exec backend php artisan analysis:purge-mock-data --execute --include-e2e-projects  # E2E由来のProjectも対象に含める
```

production環境では`--execute`は常に拒否されます。

## リード向けセルフ診断機能

営業リード(メルマガ経由の未ログインユーザー)が、社内向けフル機能とは別の
制限付きモードでサイト診断を体験できる機能。採点エンジン・分析パイプライン・
URL検証(SSRF対策)は社内向け機能と完全に共用し、リード向けに緩めることはない。

- **リードの識別**: `lead_sessions`テーブルで完全に別管理する(ログイン概念を
  持たせない)。Project/Websiteは固定の「lead-service」sentinelユーザーが所有し、
  `projects.lead_session_id`で紐付ける。既存の`ProjectPolicy`等はuser_idの一致
  のみを見るため無変更で、社内ユーザーの一覧に混ざることもない。
- **認可**: `auth:sanctum`とは完全に独立した`lead.token`ミドルウェア
  (ハッシュ化して保存したワンタイムトークンを検証)。PolicyやGateは使わない。
- **公開API**: `POST /api/lead/onboarding`(フォーム受付)、
  `POST /api/lead/analyses`・`GET /api/lead/analyses/{id}/progress`・
  `GET /api/lead/analyses/{id}/results`(いずれも`lead.token`配下)。
  すべて`RateLimiter::for('lead-*')`でIP単位のレート制限を掛けている。
- **公開画面**: `frontend/src/app/(lead)/lead/start`(フォーム)、
  `frontend/src/app/(lead)/lead/diagnose`(URL入力→進捗→簡易結果)。
  既存の`(app)`/`(guest)`とは独立したroute groupで、RequireAuth/RequireGuestは使わない。
- **Lighthouse省略**: リード向け分析は`analyses.skip_lighthouse=true`で
  `AnalysisPipeline`のAnalyzerChainから`RunLighthouse`のみを除外する
  (実測: 含めると2サイトで約72-79秒、省略すると約53秒。単一Workerを長時間
  占有するLighthouseを避けることで、Analyzerの同時実行数1という制約下でも
  他の処理への影響を抑える)。内部向けフル機能は常に`skip_lighthouse=false`で
  挙動は一切変わらない。
- **保持期間**: `lead_sessions`とその配下のProject一式(Website/Analysis等は
  カスケード削除)を、有効期限切れから一定日数後に削除する。

```bash
docker compose exec backend php artisan lead:purge-expired-sessions                 # dry-run(既定、何も削除しない)
docker compose exec backend php artisan lead:purge-expired-sessions --execute       # 確認プロンプト付きで削除
```

production環境では`--execute`は常に拒否されます。

関連する環境変数(`backend/.env.example`参照): `LEAD_MAX_WEBSITES`(既定2件、
社内向けの`max_websites_per_analysis`=5件とは独立)、`LEAD_TOKEN_EXPIRY_DAYS`
(既定7日)、`LEAD_MAX_ANALYSES_PER_TOKEN`(既定1回)、
`LEAD_MAX_CONCURRENT_ANALYSES`(既定1件、Analyzerの同時実行数を踏まえた
混雑判定用)、`LEAD_SKIP_LIGHTHOUSE`(既定true)、
`LEAD_RETENTION_DAYS_AFTER_EXPIRY`(既定180日)。いずれも未設定でも安全な
既定値で動作するため必須ではない。

現時点(第1弾)ではリード獲得〜簡易結果表示までを実装済み。Word/PDFレポート
出力、および3〜5社比較の相談申込フォーム・社内通知は未実装(今後の弾で追加予定)。

## 開発時のホットリロード

- frontend: `next dev --webpack` をbind mountで実行。コード変更は即座に反映される。
  (Next.js 16のデフォルトであるTurbopackは、Docker Desktop + Windowsのbind mount環境で
  ファイル変更を検知できないことを確認したため、webpackモードを採用している。
  `next build` によるproductionビルドはTurbopackのまま。)
- backend: `artisan serve` をbind mountで実行。PHPはリクエストごとに読み込むため
  コード変更は即座に反映される。**ただし `queue-worker` はワーカープロセスが
  常駐するため、Jobのコードを変更したら `make queue-restart` を実行すること。**
- analyzer: `tsx watch` でファイル変更を監視し自動再起動する。Docker Desktop + Windowsの
  bind mount環境では素の`fs.watch`ベースの検知が効かないことを確認したため、
  `CHOKIDAR_USEPOLLING=true` をコンテナに設定し、ポーリング方式で確実に検知させている。

`frontend/node_modules` と `backend/vendor` はホスト側にも生成されますが、
実行時はDocker名前付きVolume側が優先されます (IDEの補完用にホスト側にも置いてあります)。
依存関係を追加した場合は `docker compose run --rm frontend npm install <pkg>` のように
コンテナ内で実行し、名前付きVolumeを更新してください。

## 本番構成

```bash
cp .env.example .env   # 本番用の値に書き換える
docker compose -f compose.yaml -f compose.prod.yaml build
docker compose -f compose.yaml -f compose.prod.yaml up -d
```

- ソースコードはbind mountせず、イメージにビルド済みのコードを使用する。
- frontendは `next build` の `standalone` 出力を使用する。
- backendはphp-fpm + nginx構成になり、`backend`コンテナ自体はホストに公開しない。
- `APP_ENV=production` / `APP_DEBUG=false` / OPcacheの`validate_timestamps=0`を適用する。
- マイグレーションはコンテナ起動時に自動実行しない。デプロイ手順として
  `docker compose -f compose.yaml -f compose.prod.yaml exec backend php artisan migrate --force`
  を明示的に実行すること。
- `NEXT_PUBLIC_API_URL` はNext.jsのビルド時にJSバンドルへ焼き込まれるため、
  本番でAPIのドメインが変わる場合は`.env`で明示的に上書きしてから
  `docker compose -f compose.yaml -f compose.prod.yaml build frontend` を実行すること
  (コンテナ起動時の環境変数だけでは反映されない)。

## Render本番デプロイ: 同一Origin BFFプロキシ / Sanctum Cookie認証

frontendとbackendは別々のRender Web Service(別onrender.comサブドメイン)として動くが、
ブラウザは常に **frontendと同じOrigin** にしかアクセスしない。frontendの
`frontend/src/app/backend/[...path]/route.ts` (Next.js Route Handler) が
同一Origin BFFプロキシとして、ブラウザからの `/backend/*` へのリクエストを
サーバーサイドでLaravel backendへ転送する。

```text
ブラウザ
  → https://<frontend-service>.onrender.com/backend/api/login  (同一Origin)
  → Next.js Route Handler (サーバーサイド)
  → https://<backend-service>.onrender.com/api/login            (別Origin, サーバー間通信)
```

この構成のため、XSRF-TOKEN/セッションCookieはいずれも**frontendのOriginの
ファーストパーティCookie**として保存される。別ドメインのXSRF-TOKEN Cookieを
ブラウザJSから読めない問題が解消され、`SameSite=None`もカスタムドメインも不要になる
(サーバー間のOrigin/Refererヘッダーは、Route Handlerが`FRONTEND_ORIGIN`の値で
明示的に付与し、Sanctumのstateful判定を成立させている)。

### env設定

backend側:

```env
APP_URL=https://<backend-service>.onrender.com
FRONTEND_URL=https://<frontend-service>.onrender.com
CORS_ALLOWED_ORIGINS=

SANCTUM_STATEFUL_DOMAINS=<frontend-service>.onrender.com

SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
# SESSION_DOMAINは設定しない(env自体を作らない)。
```

frontend側:

```env
NEXT_PUBLIC_API_URL=/backend
BACKEND_URL=https://<backend-service>.onrender.com
FRONTEND_ORIGIN=https://<frontend-service>.onrender.com
```

`BACKEND_URL`・`FRONTEND_ORIGIN`には`NEXT_PUBLIC_`を付けない(Route Handler内でのみ
サーバーサイドで使う値であり、ブラウザ/JSバンドルには一切含めない)。

### 設定上の注意点

- `SANCTUM_STATEFUL_DOMAINS`にはscheme(`https://`)を含めない。`FRONTEND_URL`・
  `FRONTEND_ORIGIN`にはschemeを含める(用途・書式が異なるため混同しないこと)。
- Route Handlerは転送先を`BACKEND_URL`のOriginに固定しており、パス・クエリ・
  ヘッダーなどユーザー入力から転送先ホストが変わることはない(任意プロキシ化の防止)。
- Route HandlerはOrigin/Refererヘッダーをブラウザから転送せず、常に`FRONTEND_ORIGIN`の
  値で明示的に上書きする(不正な外部Originを無条件でLaravelへ転送しないため)。
- Set-CookieはLaravelが返した複数のCookieを個別に維持しつつ、Domain属性のみを
  取り除いてfrontendのファーストパーティCookieとして保存されるようにする。
- `CORS_ALLOWED_ORIGINS`が空でも、`FRONTEND_URL`さえ設定されていれば
  production環境は正常に起動する。逆に`FRONTEND_URL`未設定のままproduction環境で
  起動しようとすると、コンテナ起動時(nginx/php-fpm起動前・queue worker起動前)に
  実行される`php artisan app:validate-production-env`が失敗し、起動しない
  (localhostへ黙ってフォールバックすることはない)。設定ファイル自体
  (`config/cors.php`・`config/session.php`)は副作用のない純粋な配列生成のみを行い、
  例外は投げない(`composer dump-autoload`のDockerビルド時にも安全に読み込めるようにするため)。
- 設定変更後は、frontend・backend(Web Service + 3種のqueue worker)の**両方を再デプロイ**
  する必要がある。特に`NEXT_PUBLIC_API_URL`はNext.jsのビルド時にJSバンドルへ
  焼き込まれるため、値を変えたら必ずfrontendを再ビルドすること。

## テスト

```bash
docker compose exec backend php artisan test   # PHPUnit
docker compose exec frontend npm test          # Vitest + Testing Library
docker compose exec analyzer npm test          # Vitest (SSRFガード等)
```

E2Eテスト (`frontend/e2e/`) はNext.js/Laravel/analyzer用のPlaywrightとは別に、
ブラウザで実際にユーザー登録→プロジェクト作成→サイト登録の流れを検証する。

```bash
cd frontend
npx playwright install chromium   # 初回のみ
npx playwright test               # docker composeで起動済みのfrontend/backendが対象
```

`next dev` は初回アクセス時にルートをオンデマンドでコンパイルするため、システム負荷が
高い状況ではタイムアウトすることがある。安定して実行したい場合は本番ビルド
(`compose.prod.yaml`) に対して `E2E_BASE_URL=http://localhost:3000 npx playwright test`
のように実行することを推奨する。

## トラブルシューティング

### `composer install` / `npm install` が証明書エラーで失敗する

社内プロキシやウイルス対策ソフト (Norton等) がTLS通信を代理検査する環境では、
コンテナ内からのHTTPS通信が `unable to get local issuer certificate` 等のエラーで
失敗することがあります。`docker/certs/README.md` を参照してください。

### Windowsでホットリロードが効かない

`frontend/package.json` の `dev` スクリプトは既に `next dev --webpack` にしてある
(Next.js 16のデフォルトであるTurbopackは、Docker Desktop + Windowsのbind mount環境で
ファイル変更を検知できず、コンテナ再起動しないと変更が反映されないことを確認済み)。
それでも改善しない場合は、`compose.override.yaml` の `WATCHPACK_POLLING=true` /
`CHOKIDAR_USEPOLLING=true` が効いているか確認してください。

## 現状の制約 (Phase 1時点)

- ユーザー登録・ログイン・Project/Website CRUDは実装済み (Sanctum SPA Cookie認証)。
- analyzerの `/analyze/*` エンドポイントはSSRF検証のみ実装済みで、実処理は501を返す。
- Semrush等の外部API連携、実際のサイト分析処理 (Playwright/Lighthouse) は未実装。
- 分析開始ボタンはUI上に用意されているが無効化されている (準備中の表示)。
