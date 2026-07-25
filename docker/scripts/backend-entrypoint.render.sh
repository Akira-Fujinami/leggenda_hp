#!/bin/bash
# Render Web Service用entrypoint。
# nginx・php-fpm・Laravel queue workerを同一コンテナ内で常駐起動し、
# 0.0.0.0:$PORTでHTTPを待ち受ける。
#
# Renderの無料/Starterプランでは有料のBackground Worker Serviceを使わない
# (Redisも使わない)運用のため、queue:workをこのWeb Serviceプロセス自身の中で
# 動かす。queue:workはジョブの$timeout超過時にpcntl_alarm経由でプロセスごと
# 終了することがある(2026-07-24の本番障害の直接原因)ため、Worker専用の
# 再起動ループを実装し、Shell操作なしに自動復旧させる。
#
# nginx/php-fpmのどちらかが異常終了した場合は、Workerも含めて全て止めて
# コンテナごと終了する。逆にWorkerだけが落ちても(あるいは再起動ループ自体が
# 想定外に終了しても)nginx/php-fpmは継続し、Workerのみ自動的に再起動する
# (supervisordやs6を使わず、bashのジョブ制御とtrapのみで実装)。
set -euo pipefail

# storage/public は名前付きVolume利用時にroot所有へ戻ることがあるため、
# php-fpmのworkerプロセス(www-data)が書き込めるよう所有権を揃える。
chown -R www-data:www-data storage bootstrap/cache public

# ANALYSIS_STORAGE_PATH(Render Disk等の永続Volumeで、backend/analyzer間の
# 共有Volume)も同様にroot所有のままマウントされることがある。ここが
# www-data書き込み不可のままだと、FetchStaticPageJob/RenderPageJob等の
# Storage::put()がここだけ失敗し(2026-07-25の本番障害の一因)、原因の
# わかりにくいUNKNOWN_ERRORになる。storage/public同様、起動のたびに
# 必ず所有権を揃える(ディレクトリが無ければ作成してから)。
ANALYSIS_STORAGE_PATH="${ANALYSIS_STORAGE_PATH:-}"
if [ -n "$ANALYSIS_STORAGE_PATH" ]; then
    mkdir -p "$ANALYSIS_STORAGE_PATH"
    chown -R www-data:www-data "$ANALYSIS_STORAGE_PATH"
fi

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
# 上のsuブロックが失敗した場合(app:validate-production-envの検証失敗を含む)、
# set -euo pipefail によりこの行より下(nginx/php-fpm/queue workerの起動)は
# 実行されず、このentrypoint自体が非0で終了する。

# nginx設定ファイル内では環境変数を直接展開できないため、
# テンプレートからenvsubstで生成する。置換対象は明示的に${PORT}のみに限定し、
# nginx自身が使う$uri等の変数を誤って展開しないようにする。
export PORT="${PORT:-10000}"
envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf
nginx -t

# ---------------------------------------------------------------------------
# Queue worker設定 (すべてRender環境変数で上書き可能。値はRender側の
# ダッシュボードで設定するオペレーター管理下の値のみを想定しており、
# 外部入力やSecretそのものは含まれないためログにも出力してよい)。
# ---------------------------------------------------------------------------
ENABLE_EMBEDDED_QUEUE_WORKER="${ENABLE_EMBEDDED_QUEUE_WORKER:-true}"
QUEUE_WORKER_QUEUES="${QUEUE_WORKER_QUEUES:-analysis,external-api,analysis-heavy,ai}"
QUEUE_WORKER_SLEEP="${QUEUE_WORKER_SLEEP:-3}"
QUEUE_WORKER_TRIES="${QUEUE_WORKER_TRIES:-2}"
QUEUE_WORKER_TIMEOUT="${QUEUE_WORKER_TIMEOUT:-600}"
QUEUE_WORKER_MEMORY="${QUEUE_WORKER_MEMORY:-256}"
QUEUE_WORKER_RESTART_DELAY="${QUEUE_WORKER_RESTART_DELAY:-5}"
QUEUE_WORKER_SHUTDOWN_TIMEOUT="${QUEUE_WORKER_SHUTDOWN_TIMEOUT:-20}"

QUEUE_WORK_CMD="exec php artisan queue:work database --queue=${QUEUE_WORKER_QUEUES} --sleep=${QUEUE_WORKER_SLEEP} --tries=${QUEUE_WORKER_TRIES} --timeout=${QUEUE_WORKER_TIMEOUT} --memory=${QUEUE_WORKER_MEMORY}"

# 停止中(SIGTERM受信後)かどうかを、run_queue_worker()のバックグラウンド
# サブシェルからも判定できるようにするためのフラグファイル。
# (バックグラウンドの関数はfork由来の別プロセスのため、親スクリプトの
# 通常の変数代入だけではサブシェル側から見えない。)
SHUTDOWN_FLAG_FILE="$(mktemp -u /tmp/backend-shutting-down.XXXXXX)"

PHP_FPM_PID=""
NGINX_PID=""
QUEUE_LOOP_PID=""

# Laravelのqueue:work(pcntl拡張導入済み)はSIGTERMを受け取ると、実行中の
# ジョブを最後まで終わらせてから自分自身を終了する(Artisan組み込みの
# graceful stop)。suでwww-data権限に落として起動したphpプロセスへ直接
# SIGTERMを送るため、suのラッパープロセスの有無に依存せずpkillで
# コマンドラインパターン一致により対象プロセスへ直接届ける。
send_term_to_queue_worker() {
    pkill -TERM -u www-data -f 'artisan queue:work' 2>/dev/null || true
}

# Worker専用の再起動ループ。異常終了(timeoutによるプロセス終了・
# 予期しないクラッシュ)・正常終了いずれの場合も、シャットダウン中でなければ
# 5秒待ってから再起動する(高速無限再起動の防止)。exit codeは必ずログに出す。
# Secretやenv実値はログに出さない。
run_queue_worker() {
    while true; do
        if [ -f "$SHUTDOWN_FLAG_FILE" ]; then
            break
        fi

        # suラッパーとその子(実際のphp artisan queue:work)は通常セットで
        # 生死するが、万一suだけが先に終了した場合、子が孤児のまま生き残り
        # 次のイテレーションの新プロセスと二重に同じキューを処理してしまう。
        # 新しいWorkerを起動する前に必ず一度掃除しておく(通常は何もヒットしない)。
        if pgrep -u www-data -f 'artisan queue:work' >/dev/null 2>&1; then
            echo "[render-entrypoint] found a leftover queue worker process from a previous iteration; terminating it before restart" >&2
            pkill -KILL -u www-data -f 'artisan queue:work' 2>/dev/null || true
        fi

        echo "[render-entrypoint] starting queue worker (queues=${QUEUE_WORKER_QUEUES})" >&2

        su -s /bin/sh www-data -c "$QUEUE_WORK_CMD" &
        queue_su_pid=$!

        set +e
        wait "$queue_su_pid"
        exit_code=$?
        set -e

        if [ -f "$SHUTDOWN_FLAG_FILE" ]; then
            echo "[render-entrypoint] queue worker stopped for shutdown (exit code=${exit_code})" >&2
            break
        fi

        echo "[render-entrypoint] queue worker exited unexpectedly (exit code=${exit_code}); restarting in ${QUEUE_WORKER_RESTART_DELAY}s" >&2
        sleep "$QUEUE_WORKER_RESTART_DELAY"
    done
    echo "[render-entrypoint] queue worker restart loop ended" >&2
}

start_queue_worker_loop() {
    run_queue_worker &
    QUEUE_LOOP_PID=$!
}

# SIGTERM済みでもWorkerが規定時間内に終わらない場合に備えた強制終了込みの
# 停止処理。現在処理中のジョブがある場合はそれが終わるのを優先して待つが、
# Render側の停止猶予は無制限ではないため、際限なく待ち続けはしない。
stop_queue_worker() {
    if [ "$ENABLE_EMBEDDED_QUEUE_WORKER" != "true" ] || [ -z "$QUEUE_LOOP_PID" ]; then
        return 0
    fi

    : > "$SHUTDOWN_FLAG_FILE"
    echo "[render-entrypoint] stopping queue worker (waiting up to ${QUEUE_WORKER_SHUTDOWN_TIMEOUT}s for the current job to finish)..." >&2
    send_term_to_queue_worker

    waited=0
    while kill -0 "$QUEUE_LOOP_PID" 2>/dev/null; do
        if [ "$waited" -ge "$QUEUE_WORKER_SHUTDOWN_TIMEOUT" ]; then
            echo "[render-entrypoint] queue worker did not stop within ${QUEUE_WORKER_SHUTDOWN_TIMEOUT}s; forcing termination" >&2
            pkill -KILL -u www-data -f 'artisan queue:work' 2>/dev/null || true
            kill -KILL "$QUEUE_LOOP_PID" 2>/dev/null || true
            break
        fi
        sleep 1
        waited=$((waited + 1))
    done

    wait "$QUEUE_LOOP_PID" 2>/dev/null || true
    rm -f "$SHUTDOWN_FLAG_FILE" 2>/dev/null || true
    echo "[render-entrypoint] queue worker stopped" >&2
}

shutdown() {
    trap - TERM INT QUIT
    echo "[render-entrypoint] shutdown signal received, stopping worker/nginx/php-fpm gracefully..." >&2

    stop_queue_worker

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

if [ "$ENABLE_EMBEDDED_QUEUE_WORKER" = "true" ]; then
    start_queue_worker_loop
    echo "[render-entrypoint] php-fpm pid=${PHP_FPM_PID}, nginx pid=${NGINX_PID}, queue-worker-loop pid=${QUEUE_LOOP_PID}, listening on 0.0.0.0:${PORT}" >&2
else
    echo "[render-entrypoint] ENABLE_EMBEDDED_QUEUE_WORKER=false; queue worker not started. php-fpm pid=${PHP_FPM_PID}, nginx pid=${NGINX_PID}, listening on 0.0.0.0:${PORT}" >&2
fi

# php-fpm/nginxのいずれかが(意図せず)終了したら、Workerも含めて全て止めて
# コンテナを終了する。Worker再起動ループ自体が想定外に終了した場合は、
# php-fpm/nginxが健全な限りコンテナは終了させず、Workerだけを再起動する。
while true; do
    wait_targets=("$PHP_FPM_PID" "$NGINX_PID")
    [ -n "$QUEUE_LOOP_PID" ] && wait_targets+=("$QUEUE_LOOP_PID")

    set +e
    wait -n "${wait_targets[@]}"
    EXIT_CODE=$?
    set -e

    if ! kill -0 "$PHP_FPM_PID" 2>/dev/null; then
        echo "[render-entrypoint] php-fpm exited unexpectedly (code=${EXIT_CODE}); stopping the rest of the container..." >&2
        break
    fi

    if ! kill -0 "$NGINX_PID" 2>/dev/null; then
        echo "[render-entrypoint] nginx exited unexpectedly (code=${EXIT_CODE}); stopping the rest of the container..." >&2
        break
    fi

    # php-fpm/nginxは健全 = 終了したのはqueue workerの再起動ループ自身。
    # (通常はshutdown()経由でのみ終了するため、ここに来るのは想定外のケースのみ)
    echo "[render-entrypoint] queue worker supervisor loop exited unexpectedly (code=${EXIT_CODE}); restarting it" >&2
    start_queue_worker_loop
done

trap - TERM INT QUIT
stop_queue_worker
kill -TERM "$PHP_FPM_PID" 2>/dev/null || true
kill -QUIT "$NGINX_PID" 2>/dev/null || true
wait "$PHP_FPM_PID" 2>/dev/null || true
wait "$NGINX_PID" 2>/dev/null || true

exit "$EXIT_CODE"
