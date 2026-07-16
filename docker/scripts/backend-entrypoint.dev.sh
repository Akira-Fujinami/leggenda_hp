#!/bin/sh
set -e

if [ ! -f vendor/autoload.php ]; then
  echo "[backend] vendor/ が見つかりません。初回セットアップとして 'make setup' を実行してください。" >&2
fi

if [ ! -f .env ]; then
  cp .env.example .env
fi

exec "$@"
