/**
 * MetricDefinition.unitに保存されている内部(英語)単位名を、日本語UIラベルに
 * 変換する共通テーブル。個々のMetricを見て場当たり的に変換するのではなく、
 * unit文字列だけを見て一律に変換することで、結果画面・比較画面のどちらでも
 * 同じ表記になるようにする。
 */
const UNIT_LABELS: Record<string, string> = {
  characters: "文字",
  chars: "文字",
  words: "単語",
  count: "件",
  links: "件",
  fields: "項目",
  requests: "件",
  keywords: "件",
  domains: "件",
  hops: "回",
  pt: "pt",
};

export function unitLabel(unit: string | null | undefined): string {
  if (!unit) return "";
  return UNIT_LABELS[unit] ?? unit;
}

export function formatInteger(value: number): string {
  return Math.round(value).toLocaleString("ja-JP");
}

export function formatNumber(value: number): string {
  return Number.isInteger(value) ? formatInteger(value) : value.toLocaleString("ja-JP", { maximumFractionDigits: 2 });
}

export function formatBytes(bytes: number): string {
  if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)}MB`;
  if (bytes >= 1024) return `${(bytes / 1024).toFixed(1)}KB`;
  return `${Math.round(bytes)}B`;
}

export function formatMilliseconds(ms: number): string {
  return ms >= 1000 ? `${(ms / 1000).toFixed(1)}秒` : `${Math.round(ms)}ms`;
}

/**
 * unit="visits/mo"のような複合単位は個別に扱う(UNIT_LABELSの単純な
 * 1対1変換では表現しにくいため)。
 */
export function formatNumberWithUnit(value: number, unit: string | null | undefined): string {
  if (unit === "bytes") return formatBytes(value);
  if (unit === "ms") return formatMilliseconds(value);
  if (unit === "visits/mo") return `${formatInteger(value)}訪問/月`;

  const label = unitLabel(unit);
  return `${formatNumber(value)}${label}`;
}
