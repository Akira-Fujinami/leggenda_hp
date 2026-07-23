#!/bin/bash
# Render Web Service用entrypoint。
# nginxとphp-fpmを同一コンテナ内で起動し、0.0.0.0:$PORTでHTTPを待ち受ける。
# どちらかが異常終了した場合は、もう一方も止めてコンテナごと終了する
# (supervisordやs6を使わず、bashのジョブ制御とtrapのみで実装)。
set -euo pipefail

# storage/public は名前付きVolume利用時にroot所有へ戻ることがあるため、
# php-fpmのworkerプロセス(www-data)が書き込めるよう所有権を揃える。
chown -R www-data:www-data storage bootstrap/cache public

su -s /bin/sh www-data -c '
    set -e
    if [ ! -L public/storage ]; then
        php artisan storage:link
    fi
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
'

# nginx設定ファイル内では環境変数を直接展開できないため、
# テンプレートからenvsubstで生成する。置換対象は明示的に${PORT}のみに限定し、
# nginx自身が使う$uri等の変数を誤って展開しないようにする。
export PORT="${PORT:-10000}"
envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf
nginx -t

PHP_FPM_PID=""
NGINX_PID=""

shutdown() {
    trap - TERM INT QUIT
    echo "[render-entrypoint] shutdown signal received, stopping nginx/php-fpm gracefully..." >&2
    [ -n "$NGINX_PID" ] && kill -QUIT "$NGINX_PID" 2>/dev/null || true
    [ -n "$PHP_FPM_PID" ] && kill -QUIT "$PHP_FPM_PID" 2>/dev/null || true
    wait "$NGINX_PID" 2>/dev/null || true
    wait "$PHP_FPM_PID" 2>/dev/null || true
    exit 0
}
# STOPSIGNALをSIGTERMへ上書き済みだが、念のため万一SIGQUITが直接
# 送られた場合(手動操作など)も同じgraceful shutdown経路に乗せる。
trap shutdown TERM INT QUIT

php-fpm --nodaemonize &
PHP_FPM_PID=$!

nginx -g 'daemon off;' &
NGINX_PID=$!

echo "[render-entrypoint] php-fpm pid=${PHP_FPM_PID}, nginx pid=${NGINX_PID}, listening on 0.0.0.0:${PORT}" >&2

# どちらかのプロセスが(意図せず)終了したら、もう一方も止めてコンテナを終了する。
set +e
wait -n "$PHP_FPM_PID" "$NGINX_PID"
EXIT_CODE=$?
set -e

echo "[render-entrypoint] one of php-fpm/nginx exited unexpectedly (code=${EXIT_CODE}), stopping the other..." >&2
kill -TERM "$PHP_FPM_PID" 2>/dev/null || true
kill -QUIT "$NGINX_PID" 2>/dev/null || true
wait "$PHP_FPM_PID" 2>/dev/null || true
wait "$NGINX_PID" 2>/dev/null || true

exit "$EXIT_CODE"
