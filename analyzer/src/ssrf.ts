import dns from "node:dns/promises";
import ipaddr from "ipaddr.js";

export class SsrfError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "SsrfError";
  }
}

const ALLOWED_PROTOCOLS = new Set(["http:", "https:"]);

// Docker Compose上の内部サービス名・特殊ホスト名への直接アクセスを拒否する。
const BLOCKED_HOSTNAMES = new Set([
  "localhost",
  "backend",
  "postgres",
  "redis",
  "mailpit",
  "analyzer",
  "host.docker.internal",
  "gateway.docker.internal",
]);

function isBlockedIp(address: string): boolean {
  const addr = ipaddr.parse(address);
  const range = addr.range();
  // ipaddr.jsのrange(): unicast以外(loopback, private, linkLocal, uniqueLocal,
  // multicast, reserved 等)をすべて拒否し、明示的にpublicのみ許可する。
  if (range !== "unicast") {
    return true;
  }
  if (addr.kind() === "ipv4" && address.startsWith("169.254.")) {
    return true; // AWS/GCPメタデータエンドポイント等のリンクローカル
  }
  return false;
}

export interface SafeUrlResult {
  url: URL;
  resolvedAddresses: string[];
}

/**
 * ユーザー指定URLがSSRFの踏み台にならないことを検証する。
 * ホスト名の許可判定だけでなくDNS解決後の実IPも検査することで、
 * DNSリバインディングによる検証バイパスを防ぐ。
 */
export async function assertSafeUrl(rawUrl: string): Promise<SafeUrlResult> {
  let url: URL;
  try {
    url = new URL(rawUrl);
  } catch {
    throw new SsrfError(`不正なURLです: ${rawUrl}`);
  }

  if (!ALLOWED_PROTOCOLS.has(url.protocol)) {
    throw new SsrfError(`許可されていないプロトコルです: ${url.protocol}`);
  }

  const hostname = url.hostname.toLowerCase();
  if (BLOCKED_HOSTNAMES.has(hostname)) {
    throw new SsrfError(`アクセスが禁止されているホストです: ${hostname}`);
  }

  if (ipaddr.isValid(hostname) && isBlockedIp(hostname)) {
    throw new SsrfError(`アクセスが禁止されているIPアドレスです: ${hostname}`);
  }

  let addresses: string[];
  try {
    const results = await dns.lookup(hostname, { all: true, verbatim: true });
    addresses = results.map((r) => r.address);
  } catch {
    throw new SsrfError(`ホスト名を解決できません: ${hostname}`);
  }

  if (addresses.length === 0) {
    throw new SsrfError(`ホスト名を解決できません: ${hostname}`);
  }

  for (const address of addresses) {
    if (isBlockedIp(address)) {
      throw new SsrfError(`解決先IPへのアクセスが禁止されています: ${hostname} -> ${address}`);
    }
  }

  return { url, resolvedAddresses: addresses };
}
