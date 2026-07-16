import { LoginForm } from "@/features/auth/login-form";

export default function LoginPage() {
  return (
    <div className="space-y-6">
      <h2 className="text-lg font-semibold">ログイン</h2>
      <LoginForm />
    </div>
  );
}
