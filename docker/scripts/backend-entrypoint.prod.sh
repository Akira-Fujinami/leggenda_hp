#!/bin/sh
set -e

# storage/public は名前付きVolumeのため、初回起動時はroot所有になっている。
# php-fpmのworkerプロセス(www-data)が書き込めるよう所有権を揃えてから
# www-dataに権限を落として起動する。
chown -R www-data:www-data storage bootstrap/cache public

su -s /bin/sh www-data -c '
    set -e
    if [ ! -L public/storage ]; then
        php artisan storage:link
    fi
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan app:validate-production-env
'
# このステージはBackend用php-fpm(ローカルcomposeのnginx分離構成)と、
# Render Background Worker(Docker Commandでphp artisan queue:work ...を上書き)の
# 両方で共有されるentrypoint。ここで検証しておくことで、Worker側のRender設定
# (Docker Command)を一切変更せずに、Workerでも起動前検証が効く。
# 検証が失敗した場合、上のsuブロックが非0で終了し、set -eによりこのexecには
# 到達しない(=queue:work等の実プロセスは一切起動しない)。

# 依頼Y-1(2026-08-26): memory_limitをenvで調整できるようにする
# (backend-entrypoint.render.shと同じ方針・同じ根拠、詳細はそちらのコメント
# 参照)。このentrypointはphp-fpmとqueue:work双方から起動されうるため、
# ここで書けば両方に効く。
PHP_MEMORY_LIMIT="${PHP_MEMORY_LIMIT:-512M}"
printf 'memory_limit = %s\n' "$PHP_MEMORY_LIMIT" > /usr/local/etc/php/conf.d/zz-runtime-memory-limit.ini
echo "[backend-entrypoint] PHP memory_limit set to ${PHP_MEMORY_LIMIT}" >&2

exec "$@"
