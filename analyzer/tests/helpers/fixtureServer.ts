import http from "node:http";
import type { AddressInfo } from "node:net";

export interface FixtureServer {
  origin: string;
  hostAndPort: string;
  close: () => Promise<void>;
}

/**
 * テスト専用のローカルHTTP fixtureサーバー。127.0.0.1の空きポートにbindし、
 * 固定のHTML(またはカスタムのリクエストハンドラ)を返す。実際の外部サイトに
 * 依存しないことで、テストの安定性を確保する(analyzer自体のSSRF対策により、
 * テストコード側で env.SSRF_TEST_ALLOWLIST に明示的にこのオリジンを
 * 登録する必要がある)。
 */
export function startFixtureServer(htmlOrHandler: string | http.RequestListener): Promise<FixtureServer> {
  return new Promise((resolve) => {
    const listener: http.RequestListener =
      typeof htmlOrHandler === "string"
        ? (req, res) => {
            res.writeHead(200, { "Content-Type": "text/html; charset=utf-8" });
            res.end(htmlOrHandler);
          }
        : htmlOrHandler;

    const server = http.createServer(listener);

    server.listen(0, "127.0.0.1", () => {
      const { port } = server.address() as AddressInfo;
      const hostAndPort = `127.0.0.1:${port}`;

      resolve({
        origin: `http://${hostAndPort}`,
        hostAndPort,
        close: () => new Promise((closeResolve) => server.close(() => closeResolve())),
      });
    });
  });
}
