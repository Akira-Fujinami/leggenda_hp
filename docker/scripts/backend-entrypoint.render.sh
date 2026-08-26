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
#
# 依頼T-1(2026-08-26): QUEUE_WORKER_COUNT(既定1)で、同一コンテナ内に
# 複数本のWorkerを並行起動できる(Renderの永続ディスクは複数インスタンス・
# 別サービス間で共有できないため、Background Worker Serviceを別サービスと
# して追加する案は使えない ―― 追加してもこのコンテナがマウントしている
# /var/analysis-storage(analyzerとの共有ディスク)が見えず、保存済みHTMLを
# 読めなくなる)。各Workerは独立した再起動ループ・PID管理を持ち、1本が
# 異常終了しても他のWorkerには一切影響しない(旧実装は'artisan queue:work'
# という全Worker共通のコマンドラインパターンでpkillしていたため、1本の
# 孤児プロセス掃除が他の全Workerを巻き添えにしていた不具合があった)。
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
# PHPのmemory_limit (依頼Y-1、2026-08-26)。
#
# docker/php/php.ini(ビルド時にzz-app.iniとしてイメージへ焼き込まれる、
# 既定512M)はphp-fpm・CLI(キューワーカー)の両方に効くが、値を変えるたびに
# イメージの再ビルド・デプロイが必要になる。5件同時のリード診断完了直後、
# PDF生成ジョブが複数本同時に立ち上がりコンテナがOOM Killされた事故
# (2026-08-26本番)の再発防止として、実測ベースで再デプロイ無しに調整
# できるようにする(依頼A/U/Vと同じ「env化して再デプロイを不要にする」方針)。
#
# /usr/local/etc/php/conf.d/ はphp-fpm・CLIどちらのSAPIも同じディレクトリを
# 読む(`php --ini`/`php-fpm -i`のどちらも"Scan for additional .ini files in"が
# 同一パスであることを確認済み ―― SAPIごとに分かれていないビルドのため)。
# ここに1ファイル書けば両方に反映される。ファイル名をzz-app.ini(既定512M
# 固定)よりアルファベット順で後に読ませることで、そちらのmemory_limitを
# 上書きする(未設定時も同じ512Mを書き込むため、挙動は従来と完全に同一)。
PHP_MEMORY_LIMIT="${PHP_MEMORY_LIMIT:-512M}"
printf 'memory_limit = %s\n' "$PHP_MEMORY_LIMIT" > /usr/local/etc/php/conf.d/zz-runtime-memory-limit.ini
echo "[render-entrypoint] PHP memory_limit set to ${PHP_MEMORY_LIMIT} (applies to php-fpm and CLI/queue workers)" >&2

# ---------------------------------------------------------------------------
# Queue worker設定 (すべてRender環境変数で上書き可能。値はRender側の
# ダッシュボードで設定するオペレーター管理下の値のみを想定しており、
# 外部入力やSecretそのものは含まれないためログにも出力してよい)。
# ---------------------------------------------------------------------------
ENABLE_EMBEDDED_QUEUE_WORKER="${ENABLE_EMBEDDED_QUEUE_WORKER:-true}"
QUEUE_WORKER_QUEUES="${QUEUE_WORKER_QUEUES:-analysis,external-api,analysis-heavy,ai,reports,notifications}"
QUEUE_WORKER_SLEEP="${QUEUE_WORKER_SLEEP:-3}"
QUEUE_WORKER_TRIES="${QUEUE_WORKER_TRIES:-2}"
QUEUE_WORKER_TIMEOUT="${QUEUE_WORKER_TIMEOUT:-600}"
QUEUE_WORKER_MEMORY="${QUEUE_WORKER_MEMORY:-256}"
QUEUE_WORKER_RESTART_DELAY="${QUEUE_WORKER_RESTART_DELAY:-5}"
QUEUE_WORKER_SHUTDOWN_TIMEOUT="${QUEUE_WORKER_SHUTDOWN_TIMEOUT:-20}"
# 依頼T-1(2026-08-26): 同一コンテナ内で複数本のqueue workerを動かす
# (Renderの永続ディスクは複数インスタンス・別サービス間で共有できないため、
# Background Worker Serviceを別途追加する案は使えない。同一コンテナ内で
# プロセスを増やす方式のみ採用)。既定は1 ―― envを設定しない限り、
# 依頼T以前と完全に同一の挙動(ワーカー1本)であることを保証する。
QUEUE_WORKER_COUNT="${QUEUE_WORKER_COUNT:-1}"

# 依頼T-1: 全ワーカーが同じキュー一覧(QUEUE_WORKER_QUEUES)を見る
# (キューを分割しない、依頼者指定)。ワーカーごとの差分は--nameのみ
# (Laravel組み込みのオプション、Worker::$nameに保持されるだけで
# キュー選択・restart信号には一切影響しない ―― 動作を変えずに
# プロセスを識別するためだけに使う)。
queue_work_cmd() {
    echo "exec php artisan queue:work database --queue=${QUEUE_WORKER_QUEUES} --sleep=${QUEUE_WORKER_SLEEP} --tries=${QUEUE_WORKER_TRIES} --timeout=${QUEUE_WORKER_TIMEOUT} --memory=${QUEUE_WORKER_MEMORY} --name=queue-worker-$1"
}

# 依頼T-1最重要: 全ワーカーのコマンドラインは--name以外すべて同一のため、
# 'artisan queue:work'だけをpkillの-fパターンにすると全ワーカーが一致して
# しまう(旧実装の巻き添え問題の原因)。--name=queue-worker-<id>を末尾に
# 固定し、$で終端アンカーすることで、ID違いのワーカー同士(例:
# queue-worker-1とqueue-worker-10)を取り違えないようにする。
queue_worker_pkill_pattern() {
    echo "artisan queue:work.*--name=queue-worker-$1\$"
}

# 停止中(SIGTERM受信後)かどうかを、run_queue_worker()のバックグラウンド
# サブシェルからも判定できるようにするためのフラグファイル。
# (バックグラウンドの関数はfork由来の別プロセスのため、親スクリプトの
# 通常の変数代入だけではサブシェル側から見えない。)
SHUTDOWN_FLAG_FILE="$(mktemp -u /tmp/backend-shutting-down.XXXXXX)"

PHP_FPM_PID=""
NGINX_PID=""
# 依頼T-1: ワーカーIDごとの再起動ループPIDを保持する連想配列。
# 1本だけを再起動する(他ワーカーを巻き込まない)ために、単一の
# QUEUE_LOOP_PID変数ではなくID→PIDの対応を保持する。
declare -A QUEUE_LOOP_PIDS=()

# Worker専用の再起動ループ(ワーカーIDごとに1つ、独立して動く)。異常終了
# (timeoutによるプロセス終了・予期しないクラッシュ)・正常終了いずれの
# 場合も、シャットダウン中でなければ5秒待ってから再起動する(高速無限
# 再起動の防止)。exit codeは必ずログに出す。Secretやenv実値はログに
# 出さない。
#
# 依頼T-1: 巻き添え問題の解消 ―― 旧実装は'artisan queue:work'という
# 全ワーカー共通のパターンでpkillしていたため、1本の再起動(孤児掃除)が
# 他の全ワーカーを巻き込んで殺していた。ここでは自分のワーカーID専用の
# パターン(queue_worker_pkill_pattern)だけを対象にする。
run_queue_worker() {
    worker_id="$1"
    cmd="$(queue_work_cmd "$worker_id")"
    pattern="$(queue_worker_pkill_pattern "$worker_id")"

    while true; do
        if [ -f "$SHUTDOWN_FLAG_FILE" ]; then
            break
        fi

        # suラッパーとその子(実際のphp artisan queue:work)は通常セットで
        # 生死するが、万一suだけが先に終了した場合、子が孤児のまま生き残り
        # 次のイテレーションの新プロセスと二重に同じキューを処理してしまう。
        # 新しいWorkerを起動する前に必ず一度掃除しておく(通常は何も
        # ヒットしない)。対象はこのワーカーID専用のパターンのみ ――
        # 他のワーカー(queue-worker-2等)には一切触れない。
        if pgrep -u www-data -f "$pattern" >/dev/null 2>&1; then
            echo "[render-entrypoint] worker #${worker_id}: found a leftover queue worker process from a previous iteration; terminating it before restart" >&2
            pkill -KILL -u www-data -f "$pattern" 2>/dev/null || true
        fi

        echo "[render-entrypoint] worker #${worker_id}: starting queue worker (queues=${QUEUE_WORKER_QUEUES})" >&2

        su -s /bin/sh www-data -c "$cmd" &
        queue_su_pid=$!

        set +e
        wait "$queue_su_pid"
        exit_code=$?
        set -e

        if [ -f "$SHUTDOWN_FLAG_FILE" ]; then
            echo "[render-entrypoint] worker #${worker_id}: stopped for shutdown (exit code=${exit_code})" >&2
            break
        fi

        echo "[render-entrypoint] worker #${worker_id}: exited unexpectedly (exit code=${exit_code}); restarting in ${QUEUE_WORKER_RESTART_DELAY}s" >&2
        sleep "$QUEUE_WORKER_RESTART_DELAY"
    done
    echo "[render-entrypoint] worker #${worker_id}: restart loop ended" >&2
}

start_queue_worker_loop() {
    worker_id="$1"
    run_queue_worker "$worker_id" &
    QUEUE_LOOP_PIDS["$worker_id"]=$!
}

# SIGTERM済みでもWorkerが規定時間内に終わらない場合に備えた強制終了込みの
# 停止処理。現在処理中のジョブがある場合はそれが終わるのを優先して待つが、
# Render側の停止猶予は無制限ではないため、際限なく待ち続けはしない。
#
# 依頼T-1: QUEUE_WORKER_SHUTDOWN_TIMEOUTは全ワーカー共通の1つの猶予時間
# として扱う(ワーカーごとに直列で20秒ずつ待つと合計の停止時間が本数分
# 伸びてしまうため、全ワーカーへ同時にSIGTERMを送り、共通の1つのタイマーで
# 全員の終了を待つ)。
stop_queue_worker() {
    if [ "$ENABLE_EMBEDDED_QUEUE_WORKER" != "true" ] || [ "${#QUEUE_LOOP_PIDS[@]}" -eq 0 ]; then
        return 0
    fi

    : > "$SHUTDOWN_FLAG_FILE"
    echo "[render-entrypoint] stopping ${QUEUE_WORKER_COUNT} queue worker(s) (waiting up to ${QUEUE_WORKER_SHUTDOWN_TIMEOUT}s for the current jobs to finish)..." >&2

    i=1
    while [ "$i" -le "$QUEUE_WORKER_COUNT" ]; do
        pkill -TERM -u www-data -f "$(queue_worker_pkill_pattern "$i")" 2>/dev/null || true
        i=$((i + 1))
    done

    waited=0
    while true; do
        any_alive=false
        for pid in "${QUEUE_LOOP_PIDS[@]}"; do
            if kill -0 "$pid" 2>/dev/null; then
                any_alive=true
                break
            fi
        done

        if [ "$any_alive" = false ]; then
            break
        fi

        if [ "$waited" -ge "$QUEUE_WORKER_SHUTDOWN_TIMEOUT" ]; then
            echo "[render-entrypoint] one or more queue workers did not stop within ${QUEUE_WORKER_SHUTDOWN_TIMEOUT}s; forcing termination" >&2
            j=1
            while [ "$j" -le "$QUEUE_WORKER_COUNT" ]; do
                pkill -KILL -u www-data -f "$(queue_worker_pkill_pattern "$j")" 2>/dev/null || true
                j=$((j + 1))
            done
            for pid in "${QUEUE_LOOP_PIDS[@]}"; do
                kill -KILL "$pid" 2>/dev/null || true
            done
            break
        fi
        sleep 1
        waited=$((waited + 1))
    done

    for pid in "${QUEUE_LOOP_PIDS[@]}"; do
        wait "$pid" 2>/dev/null || true
    done
    rm -f "$SHUTDOWN_FLAG_FILE" 2>/dev/null || true
    echo "[render-entrypoint] all queue workers stopped" >&2
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
    i=1
    while [ "$i" -le "$QUEUE_WORKER_COUNT" ]; do
        start_queue_worker_loop "$i"
        i=$((i + 1))
    done
    echo "[render-entrypoint] php-fpm pid=${PHP_FPM_PID}, nginx pid=${NGINX_PID}, started ${QUEUE_WORKER_COUNT} queue worker(s) (loop pids: ${QUEUE_LOOP_PIDS[*]}), listening on 0.0.0.0:${PORT}" >&2
else
    echo "[render-entrypoint] ENABLE_EMBEDDED_QUEUE_WORKER=false; queue worker not started. php-fpm pid=${PHP_FPM_PID}, nginx pid=${NGINX_PID}, listening on 0.0.0.0:${PORT}" >&2
fi

# php-fpm/nginxのいずれかが(意図せず)終了したら、Workerも含めて全て止めて
# コンテナを終了する。Worker再起動ループのうち1つが想定外に終了した場合は、
# php-fpm/nginxが健全な限りコンテナは終了させず、その1本だけを再起動する
# (依頼T-1: 他のワーカーへは一切影響させない)。
while true; do
    wait_targets=("$PHP_FPM_PID" "$NGINX_PID")
    if [ "$ENABLE_EMBEDDED_QUEUE_WORKER" = "true" ]; then
        for pid in "${QUEUE_LOOP_PIDS[@]}"; do
            wait_targets+=("$pid")
        done
    fi

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

    # php-fpm/nginxは健全 = 終了したのはqueue workerの再起動ループのいずれか。
    # (通常はshutdown()経由でのみ終了するため、ここに来るのは想定外のケースのみ)
    # どのワーカーIDの再起動ループが死んだかを個別に判定し、その1本だけを
    # 再起動する ―― 他のワーカーはwait_targetsに残ったまま生き続ける。
    if [ "$ENABLE_EMBEDDED_QUEUE_WORKER" = "true" ]; then
        i=1
        while [ "$i" -le "$QUEUE_WORKER_COUNT" ]; do
            pid="${QUEUE_LOOP_PIDS[$i]:-}"
            if [ -n "$pid" ] && ! kill -0 "$pid" 2>/dev/null; then
                echo "[render-entrypoint] worker #${i} supervisor loop exited unexpectedly (code=${EXIT_CODE}); restarting it" >&2
                start_queue_worker_loop "$i"
            fi
            i=$((i + 1))
        done
    fi
done

trap - TERM INT QUIT
stop_queue_worker
kill -TERM "$PHP_FPM_PID" 2>/dev/null || true
kill -QUIT "$NGINX_PID" 2>/dev/null || true
wait "$PHP_FPM_PID" 2>/dev/null || true
wait "$NGINX_PID" 2>/dev/null || true

exit "$EXIT_CODE"
