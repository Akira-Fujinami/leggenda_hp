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
| queue-worker | `default,analysis,external-api,reports` キュー処理 | なし |
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
