import { RequireGuest } from "@/features/auth/require-guest";

export default function GuestLayout({ children }: { children: React.ReactNode }) {
  return (
    <RequireGuest>
      <div className="flex flex-1 items-center justify-center bg-zinc-50 px-4 py-16 dark:bg-black">
        <div className="w-full max-w-sm space-y-6">
          <div className="text-center">
            <h1 className="text-xl font-semibold tracking-tight">Website Comparison</h1>
          </div>
          <div className="rounded-lg border bg-card p-6 shadow-sm">{children}</div>
        </div>
      </div>
    </RequireGuest>
  );
}
