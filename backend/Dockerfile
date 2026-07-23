# syntax=docker/dockerfile:1
# ビルドコンテキストはリポジトリルート (compose.yaml の context: . を参照)。

FROM php:8.4-fpm-bookworm AS base

# 社内プロキシ/セキュリティソフトがTLSを傍受する環境向けに、
# 追加のルート証明書を docker/certs/*.pem|*.crt に置くと自動で信頼される。
# 通常の環境では docker/certs は空でよく、このステップは何もしない。
COPY docker/certs /tmp/extra-certs

RUN set -eux; \
    apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
        netcat-openbsd \
        procps \
        ca-certificates \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libfreetype6-dev \
    ; \
    for f in /tmp/extra-certs/*.pem /tmp/extra-certs/*.crt; do \
        [ -f "$f" ] || continue; \
        name=$(basename "$f"); \
        cp "$f" "/usr/local/share/ca-certificates/${name%.*}.crt"; \
    done; \
    update-ca-certificates; \
    rm -rf /tmp/extra-certs; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql pcntl bcmath intl zip gd opcache; \
    rm -rf /var/lib/apt/lists/*

# redis拡張: pecl.php.netの証明書チェーンが不安定で `pecl install redis` が
# ビルド環境によっては失敗することがあるため、ソースtarballを
# docker/vendor/ にベンダリングして使う (ビルド時のネットワーク依存を排除する)。
# バージョンを上げる場合は docker/vendor/README.md の手順を参照。
ARG PHPREDIS_VERSION=6.3.0
COPY docker/vendor/phpredis-${PHPREDIS_VERSION}.tar.gz /tmp/phpredis.tar.gz
RUN set -eux; \
    mkdir -p /tmp/phpredis; \
    tar xzf /tmp/phpredis.tar.gz -C /tmp/phpredis --strip-components=1; \
    ( cd /tmp/phpredis && phpize && ./configure && make -j"$(nproc)" && make install ); \
    docker-php-ext-enable redis; \
    rm -rf /tmp/phpredis /tmp/phpredis.tar.gz

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ---- 開発用ステージ ----
# ソースコードはbind mountで供給される想定。vendor/はcomposer installで
# 別途生成し、名前付きVolumeに永続化する (compose.yaml側で設定)。
FROM base AS dev

COPY docker/scripts/backend-entrypoint.dev.sh /usr/local/bin/backend-entrypoint.sh
RUN chmod +x /usr/local/bin/backend-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/backend-entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

# ---- 本番用ステージ ----
FROM base AS vendor

COPY backend/composer.json backend/composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader

FROM base AS prod

COPY --from=vendor /var/www/html/vendor ./vendor
COPY backend/ .
COPY docker/php/opcache.prod.ini /usr/local/etc/php/conf.d/zz-opcache.ini
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/scripts/backend-entrypoint.prod.sh /usr/local/bin/backend-entrypoint.sh
RUN chmod +x /usr/local/bin/backend-entrypoint.sh

# entrypointはroot権限で名前付きVolumeの所有権を修正した後、
# www-dataに権限を落としてアプリを起動する (php-fpm本体はcontainer内でrootのまま
# 待機し、workerプロセスはwww.confの設定通りwww-dataとしてforkされる)。
#
# このprodステージ自体はphp-fpmのみを起動しHTTPを直接待受けない
# (fastcgi:9000のみ)。Render Background Worker (queue:work) や
# ローカルcomposeのnginx分離構成 (compose.prod.yaml) はこのステージをそのまま使う。
# Render Web Serviceのように単一プロセスが0.0.0.0:$PORTでHTTPを
# 待ち受ける必要がある場合は、下のrenderステージを使うこと。
EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/backend-entrypoint.sh"]
CMD ["php-fpm"]

# ---- Render Web Service用ステージ (nginx + php-fpmを1コンテナに同梱) ----
# RenderのWeb Serviceは単一コンテナが0.0.0.0:$PORTで直接HTTPを待ち受けることを
# 要求するため、prodステージ(php-fpmのみ、fastcgi:9000)の前段にnginxを
# 同じコンテナ内で追加起動する。Background Worker用イメージを太らせない/
# 壊さないよう、nginx関連の追加は独立したこのステージにのみ行う。
FROM prod AS render

RUN set -eux; \
    apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        gettext-base \
    ; \
    rm -rf /var/lib/apt/lists/*; \
    # Debianのnginxパッケージが標準で有効化するport 80のデフォルトsiteを外す。
    # $PORT以外を意図せずlistenしないようにするため。
    rm -f /etc/nginx/sites-enabled/default

COPY docker/nginx/render.conf.template /etc/nginx/templates/default.conf.template
COPY docker/scripts/backend-entrypoint.render.sh /usr/local/bin/backend-entrypoint.render.sh
RUN chmod +x /usr/local/bin/backend-entrypoint.render.sh

ENV PORT=10000
EXPOSE 10000

# ベースのphp-fpmイメージは STOPSIGNAL SIGQUIT を設定している
# (php-fpm単体をPID1として直接動かす想定のため)。このrenderステージの
# PID1はentrypointスクリプト(bash)であり、SIGTERMをtrapしてnginx/php-fpm
# 双方へgraceful stopを配る設計のため、Renderや `docker stop` が送る
# 標準のSIGTERMで正しく終了するよう明示的に上書きする。
STOPSIGNAL SIGTERM

ENTRYPOINT ["/usr/local/bin/backend-entrypoint.render.sh"]
