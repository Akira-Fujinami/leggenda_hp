#!/bin/sh
set -e

if [ ! -f vendor/autoload.php ]; then
  echo "[backend] vendor/ が見つかりません。初回セットアップとして 'make setup' を実行してください。" >&2
fi

if [ ! -f .env ]; then
  cp .env.example .env
fi

# 本番モードでの動作確認 (php artisan config:cache 等) をローカルで行った際に
# bootstrap/cache/*.php がbind mount経由でdevコンテナ側にも残ってしまうことがある。
# devでキャッシュされた設定/ルートを使うと変更が反映されず気づきにくいため、
# 起動のたびに必ずクリアする。
if [ -f vendor/autoload.php ]; then
  php artisan config:clear >/dev/null 2>&1 || true
  php artisan route:clear >/dev/null 2>&1 || true
  php artisan view:clear >/dev/null 2>&1 || true
fi

exec "$@"
