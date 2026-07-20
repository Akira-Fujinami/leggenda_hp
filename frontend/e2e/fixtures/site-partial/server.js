// E2E専用の最小HTTPサーバー。/robots.txt へのリクエストだけは意図的に
// 接続を即座にリセットする(TCP RST)ことで、FetchRobotsJobだけを
// 確実に失敗させ、他のJobは正常に完了する「partial」状態を決定論的に
// 再現する。busybox httpdでは経路ごとの応答を出し分けられないため、
// この用途だけはNodeの素のhttpモジュールで実装している。
//
// あえて「応答せず放置する」方式(タイムアウト待ち)にしなかった理由:
// PHPのpcntl_alarmベースのジョブタイムアウトは、curlの同期的な
// ブロッキング呼び出し中は割り込めないことがあり、実際に検証したところ
// HTTPクライアント側のtimeout(20秒)が先に発火し、その結果queue:work
// ワーカープロセスごとSIGALRMでkillされる(1ジョブだけが失敗になるのではない)
// ケースが確認された。Redisのretry_afterが90秒と長いため、その後の
// 復旧・再試行を待つとE2Eとして極めて遅く不安定になる。即座に接続を
// 切ることで、SafeHttpFetcherが正規にConnectionExceptionとして捕捉できる
// 「速く・確実に失敗する」経路を使う。
const http = require("node:http");
const fs = require("node:fs");
const path = require("node:path");

const html = fs.readFileSync(path.join(__dirname, "index.html"), "utf8");

const server = http.createServer((req, res) => {
  const url = req.url ?? "/";

  if (url.startsWith("/robots.txt")) {
    req.socket.destroy();
    return;
  }

  res.writeHead(200, { "Content-Type": "text/html; charset=utf-8" });
  res.end(html);
});

server.listen(8080, "0.0.0.0", () => {
  console.log("e2e-fixture-partial listening on :8080");
});
