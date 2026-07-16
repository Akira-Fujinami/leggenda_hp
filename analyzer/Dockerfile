# syntax=docker/dockerfile:1
# ビルドコンテキストはリポジトリルート (compose.yaml の context: . を参照)。
#
# Playwright公式イメージをベースにする。Node.js/Chromium/Firefox/WebKitの
# 依存関係が同梱されているため、ブラウザ依存関係を毎回手動インストールする
# 必要がない。Phase 3でplaywrightをpackage.jsonに追加する際は、このタグを
# インストールするplaywrightのバージョンと一致させること。
FROM mcr.microsoft.com/playwright:v1.49.1-noble AS base
WORKDIR /app

# 社内プロキシ/セキュリティソフトがTLSを傍受する環境向けに、
# 追加のルート証明書を docker/certs/*.pem|*.crt に置くと自動で信頼される。
# 通常の環境では docker/certs は空でよく、このステップは何もしない。
COPY docker/certs /tmp/extra-certs
RUN set -eux; \
    : > /etc/node-extra-ca-certs.pem; \
    for f in /tmp/extra-certs/*.pem /tmp/extra-certs/*.crt; do \
        [ -f "$f" ] || continue; \
        name=$(basename "$f"); \
        cp "$f" "/usr/local/share/ca-certificates/${name%.*}.crt"; \
        cat "$f" >> /etc/node-extra-ca-certs.pem; \
    done; \
    update-ca-certificates; \
    rm -rf /tmp/extra-certs
ENV NODE_EXTRA_CA_CERTS=/etc/node-extra-ca-certs.pem

# ---- 依存関係インストール専用ステージ (ビルドキャッシュ用) ----
FROM base AS deps
COPY analyzer/package.json analyzer/package-lock.json ./
RUN npm ci

# ---- 開発用ステージ ----
# ソースコードはbind mountで供給される。node_modulesは名前付きVolumeに
# 永続化し、ホスト側node_modulesと衝突しないようにする (compose.yaml側で設定)。
FROM base AS dev
ENV NODE_ENV=development
EXPOSE 3001
CMD ["npm", "run", "dev"]

# ---- 本番ビルドステージ ----
FROM deps AS builder
COPY analyzer/ .
RUN npm run build

# ---- 本番実行ステージ ----
FROM base AS prod
ENV NODE_ENV=production
COPY analyzer/package.json analyzer/package-lock.json ./
RUN npm ci --omit=dev
COPY --from=builder /app/dist ./dist

EXPOSE 3001
CMD ["node", "dist/index.js"]
