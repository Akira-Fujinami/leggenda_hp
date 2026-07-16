import { RegisterForm } from "@/features/auth/register-form";

export default function RegisterPage() {
  return (
    <div className="space-y-6">
      <h2 className="text-lg font-semibold">新規登録</h2>
      <RegisterForm />
    </div>
  );
}
