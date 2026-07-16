# syntax=docker/dockerfile:1
# ビルドコンテキストはリポジトリルート (compose.yaml の context: . を参照)。

FROM node:22-bookworm-slim AS base
WORKDIR /app

# 社内プロキシ/セキュリティソフトがTLSを傍受する環境向けに、
# 追加のルート証明書を docker/certs/*.pem|*.crt に置くと自動で信頼される。
# 通常の環境では docker/certs は空でよく、このステップは何もしない。
COPY docker/certs /tmp/extra-certs
RUN set -eux; \
    apt-get update && apt-get install -y --no-install-recommends ca-certificates; \
    : > /etc/node-extra-ca-certs.pem; \
    for f in /tmp/extra-certs/*.pem /tmp/extra-certs/*.crt; do \
        [ -f "$f" ] || continue; \
        name=$(basename "$f"); \
        cp "$f" "/usr/local/share/ca-certificates/${name%.*}.crt"; \
        cat "$f" >> /etc/node-extra-ca-certs.pem; \
    done; \
    update-ca-certificates; \
    rm -rf /tmp/extra-certs /var/lib/apt/lists/*
ENV NODE_EXTRA_CA_CERTS=/etc/node-extra-ca-certs.pem

# ---- 依存関係インストール専用ステージ (ビルドキャッシュ用) ----
FROM base AS deps
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

# ---- 開発用ステージ ----
# ソースコードはbind mountで供給される。node_modulesは名前付きVolumeに
# 永続化し、ホスト側node_modulesと衝突しないようにする (compose.yaml側で設定)。
FROM base AS dev
ENV NODE_ENV=development
ENV WATCHPACK_POLLING=true
ENV CHOKIDAR_USEPOLLING=true
EXPOSE 3000
CMD ["npm", "run", "dev"]

# ---- 本番ビルドステージ ----
# NEXT_PUBLIC_*はビルド時にJSバンドルへ埋め込まれるため、コンテナ起動時の
# environment:だけでは反映されない。build.argsとして明示的に渡す必要がある。
FROM deps AS builder
ARG NEXT_PUBLIC_API_URL=http://localhost:8000
ENV NEXT_PUBLIC_API_URL=$NEXT_PUBLIC_API_URL
COPY frontend/ .
RUN npm run build

# ---- 本番実行ステージ (Next.js standalone出力) ----
FROM node:22-bookworm-slim AS prod
WORKDIR /app
ENV NODE_ENV=production
# Dockerはコンテナごとに自動でHOSTNAME=<container-id>を設定するが、
# Next.js standaloneのserver.jsはHOSTNAME環境変数をbindアドレスとして使うため、
# 明示的に上書きしないと外部から (healthcheckからも) 到達できなくなる。
ENV HOSTNAME=0.0.0.0
COPY --from=builder /app/public ./public
COPY --from=builder /app/.next/standalone ./
COPY --from=builder /app/.next/static ./.next/static

EXPOSE 3000
CMD ["node", "server.js"]
