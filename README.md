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

## リリース前チェックリスト

- **[未解決・最優先] `SEMRUSH_API_KEY`のローテーションと`backend/.env.example`の
  プレースホルダ化**。これが完了するまでpushしないこと。
- **[未解決・要実施の可能性あり] 本番の`metric_definitions`/`category_definitions`が
  最新のSeeder内容と一致しているか確認すること**(2026-08-01更新)。
  `recruit_title_present`等5指標(`422652d`, 2026-07-29)を含め、これらのSeederに
  触れるコミットの後、本番でクラス指定シードを実際に実行した記録はこのリポジトリの
  どこにも残っていない(`docker/scripts/backend-entrypoint.render.sh`は
  `migrate`/`db:seed`を一切自動実行しないため)。定義が欠けている/無効な場合、
  `recordMetric()`が無言で記録をスキップし、リード診断の該当項目が
  「計測対象外」「確認をおすすめします」のまま変化しない(2026-07-20の
  「採点マスタ0件」と同じ失敗経路、2026-08-01にローカルで再現・確認済み ―― 詳細は
  下記「デプロイ順序」を参照)。

  **正しい確認手順(「シーダーを再実行した」だけでは実行し忘れを検知できないため、
  必ず以下のコマンドで確認する)**:
  ```bash
  php artisan analysis:verify-metric-definitions
  ```
  読み取り専用で、本番でも安全に何度でも実行できる。`metric_definitions`/
  `category_definitions`が欠落・無効化・weight合計不一致の場合はexit code 1で
  該当キーを一覧表示する。デプロイ後の確認手順としては次項「デプロイ順序」を参照。

  ### `metric_definitions`/`category_definitions`に触れる変更のデプロイ順序(2026-08-01追記)

  この2つのSeederのいずれかに変更が入るデプロイでは、**必ず以下の順序**で行う
  (順序を守らないと、コードは直っているのにDBの定義が古いままで
  `recordMetric()`が無言でスキップし続け、「直したのに画面が変わらない」状態に
  なる ―― 2026-08-01にローカルで実際に再現・確認済み)。

  1. コードをデプロイする。
  2. `CategoryDefinitionSeeder` / `MetricDefinitionSeeder`をクラス指定で再実行する
     (`php artisan db:seed --class=CategoryDefinitionSeeder --force` /
     `--class=MetricDefinitionSeeder --force`)。
  3. `php artisan analysis:verify-metric-definitions`を実行し、exit code 0
     (欠落ゼロ)を確認する。
  4. リード診断を1件実行し(`php artisan lead:issue-test-session <自社URL> [競合URL]`、
     非production環境限定)、診断結果の①(採用ページ)が「良好」「確認をおすすめします」
     「改善の余地があります」のいずれかになり、「採用ページを検出できませんでした」
     (計測対象外)のままになっていないことを確認する。

  **副作用について**: `recruit_title_present`等、Phase 3で追加された採用ページ向け
  5指標(および元々の`company_info_link_present`等の"情報表示専用"指標群)は、
  `MetricDefinitionSeeder`上で`scoring_type: not_scored, points: 0`として意図的に
  登録されている(`backend/database/seeders/MetricDefinitionSeeder.php`の該当コメント
  参照)。`MetricScorer::score()`は`scoring_type`が`not_scored`の指標を採点対象から
  無条件に除外し(`MetricScoreOutcome::excluded()`)、`LeadScoreCalculator`も
  `is_informational`判定で`configured_max_score`への加算対象から明示的に除外している
  (`backend/app/Services/Lead/LeadScoreCalculator.php:72-80`)。**このため、これら5指標の
  シード有無は内部の7カテゴリ得点にもリード向けスコア(`configured_max_score`)にも
  一切影響しない**ことをコードから確認済み。仮に将来、採点対象(`scoring_type`が
  `not_scored`以外)の指標を同じカテゴリへ新規追加する場合は話が別で、その場合は
  `MetricDefinitionSeeder::seedCategory()`の「カテゴリのweightを`points`比で配分し、
  端数は最後の採点対象項目に寄せる」仕様により、同一カテゴリ内の既存の採点対象指標の
  `max_score`が再計算され、`configured_max_score`も変わる。過去の診断結果は
  記録時点の点数のまま残るため、新旧の点数基準が混在することになる(避けられないが、
  混在が起こり得ることは記録しておく)。

- **[未解決] `GET /up`で疎通確認すること**。`/api/health`はRedisを必須チェックにして
  いるため、本番では常に503になる。
- **`SANCTUM_STATEFUL_DOMAINS`と実際のfrontend Originの一致確認**(2026-07-29追加)。
  値が空でない・schemeを含まないことは`ProductionEnvironmentValidator`が起動時に
  検証するが、**`FRONTEND_URL`のホスト名と一致しているかどうかは検証していない**。
  一致していない場合、`EnsureFrontendRequestsAreStateful::fromFrontend()`が
  リクエストをstateful扱いせず、認証自体は通るのに次のリクエストで未認証に戻る
  (=誰もログインできない)という気づきにくい形で壊れる。デプロイ前に手動で
  確認すること(将来的に`ProductionEnvironmentValidator`へこの整合性チェックを
  追加することも検討する)。
- **カスタムドメイン導入時は、`SANCTUM_STATEFUL_DOMAINS`・`FRONTEND_URL`・
  `FRONTEND_ORIGIN`のいずれも値の更新が必要**(上記と同じ理由)。更新後は
  frontend・backend双方の再デプロイも必要。
- **ブランド・ホイール(Phase 4)機能が読む採用ページ/トップページの生HTMLの
  ディスク容量**(2026-07-29追加、実装ではなく実測・監視の課題として明記):
  - 概算: WebsiteAnalysis 1件あたり、トップページ(raw+rendered)・採用ページ
    (raw)・robots.txt・sitemap.xmlの合計でおおよそ0.3〜2MB程度(サイトの重さに
    よって数倍〜十倍程度まで振れ得る、あくまで概算)。1リード(自社+競合で
    最大`LEAD_MAX_WEBSITES`件のWebsiteAnalysis)あたりではこの数倍。
    Task #99の実サイト実測作業で、実測値に置き換えること。
  - この生HTMLは`PurgeExpiredLeadSessions`が対象とするLeadSessionの保持期間
    (`LEAD_RETENTION_DAYS_AFTER_EXPIRY`、既定180日)が過ぎるまで、削除されずに
    蓄積し続ける(下記参照)。Renderの永続ディスクは固定サイズで、埋まると
    書き込みが失敗し解析全体が停止するため、割当サイズと実際のリード流入
    ペースから見た消費見込みを事前に見積もり、使用率を監視すること
    (`du -sh $ANALYSIS_STORAGE_PATH`、またはRenderのディスク使用率メトリクス)。
  - **既知の未対応課題**: `PurgeExpiredLeadSessions`はDB行(Project以下カスケード)
    とWord/PDFレポートファイルは削除するが、`analysis_pages.raw_html_path`/
    `rendered_html_path`が指す生HTMLファイル自体は削除しない(孤児ファイルとして
    ディスクに残り続ける)。Phase 4完了後の課題として、この削除をコマンドへ
    追加することを検討する(現時点では意図的に未実装 ―― 参照中のファイルを
    誤って消すリスクを避けるため、実装は見送っている)。
- **ブランド・ホイール(Phase 4)デプロイ関連**(2026-07-30追加):
  - AIプロバイダ(OpenAI)のAPIキーがRenderに設定されているか(値そのものは
    確認・記載しないこと)。
  - `brand_wheel_ai`関連の環境変数(`BRAND_WHEEL_AI_PROVIDER`/`BRAND_WHEEL_AI_TIMEOUT`/
    `BRAND_WHEEL_AI_MAX_RETRIES`/`BRAND_WHEEL_AI_MAX_OUTPUT_TOKENS`/
    `BRAND_WHEEL_AI_TEMPERATURE`)が意図通り設定されているか。特に
    `BRAND_WHEEL_AI_TEMPERATURE`は判定システムのため既定0.0(未設定なら0.0が
    自動適用される)を推奨、意図的に上げる場合のみ明示的に設定すること。
  - **Dockerfileに`librsvg2-bin`と日本語フォント(`fonts-ipaexfont-gothic`)を
    追加したため、本番イメージの再ビルドが必須**。ローカル環境でもこれを
    忘れてイメージが古いまま(`rsvg-convert`が存在しない状態)になっていたことが
    あるため、`docker compose build`(または本番のビルドパイプライン)で
    イメージが実際に再ビルドされたことを確認すること。
  - **本番デプロイ後、実際にヘキサゴンPNGが日本語で正しく描画されることを、
    社員宛のテスト送信で確認すること**。ローカルで確認済みでも、本番イメージ
    (ビルド環境・OSパッケージの構成)での確認は別途必要(`rsvg-convert`が
    フォントを解決できない場合、コマンドはエラーを返さず豆腐(□□□)の画像を
    正常終了で生成するため、目視確認以外に検知手段が無い)。
  - `brand_wheel_analysis_results`のマイグレーション適用(`php artisan migrate`)。
  - **Resendのドメイン検証(SPF/DKIM)**。リード企業向けメール
    (`BrandWheelLeadAnalysisCompletedMail`)が新たに追加され、社外の受信者へ
    送るメールが増えたため、到達性の検証はこれまで以上に重要になっている。
  - **[2026-08-03変更] ブランド・ホイール生成タイミングとコストの母数が変わった**。
    従来は「相談ボタンを押したリードの自社サイト1件のみ」がOpenAI呼び出しの
    対象だった(=全診断のうち相談に至った割合分×1回)。2026-08-03以降は
    「**診断を開始した全リード**×自社・競合の2回」が対象になる ―― 母数・
    呼び出し回数のいずれも変わるため、単純な「1回→2回」以上のコスト増加に
    なりうる。相談ボタンのクリック率の実測データが無いため、事前の総コスト
    見積もりでは止めていない。運用開始後、`Log::info('Brand wheel analysis
    completed', ...)`が記録する`usage_input_tokens`/`usage_output_tokens`
    から実測すること(ログにサイト本文・evidence等の実内容は一切含まれない)。
    `skip_brand_wheel`の既定はtrue(実行しない)で、リード向け経路
    (`LeadAnalysisController::store()`)のみが明示的にfalseを渡す ――
    社内向けダッシュボード分析ではこれまでどおり一切呼び出されない。
  - **[2026-08-04追加] `config/brand_wheel.php`の`axes.*.sub_elements`を
    変更したら、`backend/resources/images/brand-wheel-framework.png`(6軸24項目の
    固定説明図、リード向けPDF/Wordの前置きページで使用)を必ず作り直すこと**。
    この画像は分析結果に依存しない静的アセットのため、サーバ側で自動生成
    していない ―― configの下位要素とこの画像の記載内容がずれても、
    ビルド・テストのいずれでも検知されない(目視確認以外に検知手段が無い)。

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

## 既知の課題

### 採用ページURL解決(resolveRecruitUrl)が静的HTML解析の結果に固定される競合状態(未修正, 2026-08-01時点)

**発火条件**: トップページのナビゲーションを**JavaScriptで生成しているサイト**(静的HTML取得時点では採用ページへのリンクが存在せず、レンダリング後にのみ現れる)でのみ発火する。leggenda.co.jp自身のグローバルナビは静的HTML内に既に存在するため、この条件には該当せず、現時点で実サイトによる再現はできていない。

**問題箇所**: `AnalysisPipeline::resolveRecruitUrl()`(`backend/app/Services/Analysis/AnalysisPipeline.php:218-249`)は、`AnalyzeHtmlSeoJob`の終端(`onWebsiteJobTerminal`)から呼ばれる`dispatchRecruitPageFetch()`(同ファイル`194-198行`)内で**1回だけ**実行され、その時点の`recruit_link_present`(静的HTML由来)の`raw_value['url']`を読んで`FetchRecruitPageJob`をディスパッチする。

一方、レンダリング済みHTMLによる`recruit_link_present`の上書きを行う`ReanalyzeRenderedHtmlJob`は、`RenderPageJob`を先頭とする別系統の並列チェーン(`ANALYZER_CHAIN`, 同ファイル`82-92行`)から起動され、`fetch_static_page → analyze_html_seo → dispatchRecruitPageFetch`のチェーンとは**同期していない**。

**結果として起こりうること**: 静的HTML解析の時点で採用リンクが見つからず`resolveRecruitUrl()`がnullを返した場合、後からレンダリング済みHTMLで正しい採用リンクが見つかっても、既に(URLなしで)確定した`FetchRecruitPageJob`/`AnalyzeRecruitPageJob`はやり直されない。画面上は`recruit_link_present`が(レンダリング後の結果で上書きされ)`present: true`になるのに、採用ページの中身は測定されていない、という食い違いが起きる可能性がある。

**修正しない理由**: 修正には`maybeFinalizeWebsiteAnalysis()`(`WebsiteAnalysis`の全ジョブ終端判定)のタイミングと`JobType::weight()`(進捗計算, `backend/app/Enums/JobType.php:45-65`)への変更が伴う。この2つは2026-07-24の本番障害の直接の原因となった機構であり、実サイトで再現できていない条件付きの欠陥のために触れるべきではないと判断し、今回は見送った。

**再修正時の設計方針(案、未実装)**:
- 常に2回実行(常に`ReanalyzeRenderedHtmlJob`終端からも`dispatchRecruitPageFetch()`を呼ぶ)は、採用ページへの重複HTTPアクセスになるため避ける。**静的HTMLで採用リンクが見つからなかった場合に限り**、レンダリング後の結果を待って`resolveRecruitUrl()`を再評価する方式が望ましい。
- `analysis_jobs_unique_target`(`analysis_id, website_analysis_id, job_type`一意)により、`fetch_recruit_page`/`analyze_recruit_page`のAnalysisJob行は1つしか持てない。`AnalysisPipeline::markRunning()`は既に`terminal`な行には何もしないため、再実行するには既存行を明示的に`pending`へ戻す処理が別途必要。
- `analysis_jobs.metadata`(既存のjsonbカラム)に「どのURLで取得したか」を記録し、レンダリング後に再評価した`resolveRecruitUrl()`の結果と比較して**URLが実際に変わった場合のみ**再取得する(同じURLなら再取得の意味もリスクも無いため)。
- 最大の懸念は`maybeFinalizeWebsiteAnalysis()`との競合: `ReanalyzeRenderedHtmlJob`自身の終端時にも`maybeFinalizeWebsiteAnalysis()`が呼ばれる(`BaseWebsiteAnalysisJob::handle()`のfinally節)。その時点で`fetch_recruit_page`/`analyze_recruit_page`が(誤った結果であれ)既に`completed`であれば、再取得を始める前に`WebsiteAnalysis`が確定してしまう可能性がある。再取得を開始する前に「確定済みなら再取得しない」ガードを入れるか、`maybeFinalizeWebsiteAnalysis()`側に「recruit再取得が保留中なら確定しない」という新しい待ち条件を追加する必要がある。

②を実際に再現できるサイト(ナビをJavaScriptで生成しており、かつ採用ページへのリンクがそこにしか無いサイト)が見つかった時点で着手する。

### ブランド・ホイールの判定件数(claimed_sub_element_count)が同一条件でも実行ごとにばらつく(未評価, 2026-08-04時点)

**2026-08-04夜に確認した事実**(推測は含まない):

- `website_analysis_id=355`(SmartHR、入力は毎回同一)に対し、config(`teaching_points`/`teacher_data_caveat`)を変えながら`brand-wheel:run --force`を実行したところ、`claimed_sub_element_count`の合計は次のように推移した: 2, 2, 4, 6→discarded後matched 3, 2, 1(この順で実行、各条件n=1回)。
- このうち「A: 現状のconfig」と「B: teaching_pointsのみ空」は結果が完全に同一(2件、同じ下位要素)だった。
- 「C: Bに加えてteacher_data_caveatの末尾一文を削除」で4件に増えたが、その後config をA(teaching_points復元・caveat末尾のみ削除)に戻して再実行したところ、matchedは1件(discarded 1件)となり、Aの2件を下回った。
- 上記はすべて**各条件1回ずつの実行結果**であり、同一条件を複数回実行して振れ幅を測定していない。

**課題として記録すること**: 判定件数が実行ごとにどの程度ばらつくのか(振れ幅)を、config を一切変更しない状態でまだ測定していない。振れ幅が分からないまま条件間の差を「効果」と解釈することはできない。上記の2→4→1という推移が config変更の効果なのか、単なる実行ごとの振れ幅なのかは、現時点では判別できない。

**次にやること(未着手)**: config を一切変更せず、`website_analysis_id=355`に対して`brand-wheel:run --force`を5回連続で実行し、`claimed_sub_element_count`・`matched_sub_elements`・discarded理由・state内訳を5回分並べて記録する。振れ幅が分かってから、プロンプトの調整に着手する。

### ブランド・ホイールv4(24項目チェックリスト化)は、見立てと違い検出数を伸ばさなかった(2026-08-05実測、記録として残す)

**事前の見立て**: 出力形式を「軸→該当するものを挙げる形式」から「24項目個別にtrue/falseを判定させるチェックリスト形式」(v4)へ変更すれば、カヤック新卒採用ページ(word_count 8,380、情報量が濃い)で15件以上まで検出数が伸びるはずだと予測した(軸あたり1件に強く偏る出力形式が、24項目を個別に吟味させることで解消されると考えたため)。

**実測結果**: 予測は外れた。カヤックの検出数はv3の9件→v4の10件(【1】〜【6】の一連の対応を経た最終版v6時点)で、1件しか増えていない。軸あたり1件への偏り自体は実際に構造的な問題として存在していた(v3のカヤック内訳は3/1/1/1/1/2)が、それを解消しても検出数の天井は変わらなかった。

**分かったこと**: 24項目個別判定への変更で実際に変わったのは検出数の「量」ではなく「質」だった ―― v4は判別力(情報量が多いサイトと少ないサイトの検出数の比)を悪化させ(v3の3.0倍→v4の1.3倍)、原因は見出し・リンクラベル文字列をそのまま根拠に転記する誤検出だった(label_only_evidence対策、v5〜v6で解消し判別比5.0倍まで回復)。「濃いページでなぜ検出数の天井が10件程度で頭打ちになるのか」自体への答えはまだ出ていない ―― 出力形式(チェックリスト化)が効かなかった以上、原因は別にある。

**次に疑うべき箇所(未着手、案)**:
- config側の24下位要素の定義自体が狭すぎて、実際にページに書かれている魅力的な記述の多くがどの項目にも該当しない可能性(定義文の再検討)
- 1回のAPI呼び出しで24項目同時に判定させること自体が、モデルの注意力を分散させている可能性(項目を分割して複数回呼び出す設計との比較実験)
- `max_output_tokens`(既定2000)が24項目分のevidence引用を書ききるには不足しており、後半の項目でモデルが判定を打ち切っている可能性(出力トークン数の実測未実施)

## 現状の制約 (Phase 1時点)

- ユーザー登録・ログイン・Project/Website CRUDは実装済み (Sanctum SPA Cookie認証)。
- analyzerの `/analyze/*` エンドポイントはSSRF検証のみ実装済みで、実処理は501を返す。
- Semrush等の外部API連携、実際のサイト分析処理 (Playwright/Lighthouse) は未実装。
- 分析開始ボタンはUI上に用意されているが無効化されている (準備中の表示)。
