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
'

exec "$@"
