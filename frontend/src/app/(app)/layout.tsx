import { RequireAuth } from "@/features/auth/require-auth";
import { AppHeader } from "@/components/app-header";

export default function AppLayout({ children }: { children: React.ReactNode }) {
  return (
    <RequireAuth>
      <div className="flex min-h-full flex-1 flex-col bg-zinc-50 dark:bg-black">
        <AppHeader />
        <main className="mx-auto w-full max-w-5xl flex-1 px-4 py-8">{children}</main>
      </div>
    </RequireAuth>
  );
}
