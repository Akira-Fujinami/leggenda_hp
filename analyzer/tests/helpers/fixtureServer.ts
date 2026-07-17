import http from "node:http";
import type { AddressInfo } from "node:net";

export interface FixtureServer {
  origin: string;
  hostAndPort: string;
  close: () => Promise<void>;
}

/**
 * テスト専用のローカルHTTP fixtureサーバー。127.0.0.1の空きポートにbindし、
 * 固定のHTMLを返す。実際の外部サイトに依存しないことで、テストの
 * 安定性を確保する(analyzer自体のSSRF対策により、テストコード側で
 * env.SSRF_TEST_ALLOWLIST に明示的にこのオリジンを登録する必要がある)。
 */
export function startFixtureServer(html: string): Promise<FixtureServer> {
  return new Promise((resolve) => {
    const server = http.createServer((req, res) => {
      res.writeHead(200, { "Content-Type": "text/html; charset=utf-8" });
      res.end(html);
    });

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
