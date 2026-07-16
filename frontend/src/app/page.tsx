import { HealthStatus } from "@/components/health-status";

export default function Home() {
  return (
    <div className="flex flex-1 flex-col items-center justify-center gap-6 bg-zinc-50 p-16 font-sans dark:bg-black">
      <h1 className="text-2xl font-semibold tracking-tight">Website Comparison</h1>
      <p className="text-muted-foreground">Phase 0: Docker基盤セットアップ</p>
      <HealthStatus />
    </div>
  );
}
