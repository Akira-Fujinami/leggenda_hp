"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ApiError } from "@/lib/api-client";
import { useRegister } from "@/features/auth/hooks";

const registerSchema = z
  .object({
    name: z.string().min(1, "お名前を入力してください。").max(255, "お名前は255文字以内で入力してください。"),
    email: z.string().min(1, "メールアドレスを入力してください。").email("メールアドレスの形式が正しくありません。"),
    password: z.string().min(8, "パスワードは8文字以上で入力してください。"),
    password_confirmation: z.string().min(1, "確認用パスワードを入力してください。"),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: "パスワード（確認）が一致しません。",
    path: ["password_confirmation"],
  });

type RegisterFormValues = z.infer<typeof registerSchema>;

export function RegisterForm() {
  const router = useRouter();
  const registerUser = useRegister();
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<RegisterFormValues>({
    resolver: zodResolver(registerSchema),
    defaultValues: { name: "", email: "", password: "", password_confirmation: "" },
  });

  const onSubmit = (values: RegisterFormValues) => {
    registerUser.mutate(values, {
      onSuccess: () => router.replace("/dashboard"),
      onError: (error) => {
        if (error instanceof ApiError && error.errorCode === "VALIDATION_ERROR") {
          for (const [field, messages] of Object.entries(error.errors)) {
            if (field in values) {
              setError(field as keyof RegisterFormValues, { message: messages[0] });
            }
          }
        }
      },
    });
  };

  const generalError =
    registerUser.error instanceof ApiError && registerUser.error.errorCode !== "VALIDATION_ERROR"
      ? registerUser.error.message
      : null;

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
      {generalError && (
        <Alert variant="destructive">
          <AlertDescription>{generalError}</AlertDescription>
        </Alert>
      )}

      <div className="space-y-2">
        <Label htmlFor="name">お名前</Label>
        <Input id="name" autoComplete="name" {...register("name")} />
        {errors.name && <p className="text-sm text-destructive">{errors.name.message}</p>}
      </div>

      <div className="space-y-2">
        <Label htmlFor="email">メールアドレス</Label>
        <Input id="email" type="email" autoComplete="email" {...register("email")} />
        {errors.email && <p className="text-sm text-destructive">{errors.email.message}</p>}
      </div>

      <div className="space-y-2">
        <Label htmlFor="password">パスワード</Label>
        <Input id="password" type="password" autoComplete="new-password" {...register("password")} />
        {errors.password && <p className="text-sm text-destructive">{errors.password.message}</p>}
      </div>

      <div className="space-y-2">
        <Label htmlFor="password_confirmation">パスワード（確認）</Label>
        <Input
          id="password_confirmation"
          type="password"
          autoComplete="new-password"
          {...register("password_confirmation")}
        />
        {errors.password_confirmation && (
          <p className="text-sm text-destructive">{errors.password_confirmation.message}</p>
        )}
      </div>

      <Button type="submit" className="w-full" disabled={registerUser.isPending}>
        {registerUser.isPending ? "登録中…" : "登録する"}
      </Button>

      <p className="text-center text-sm text-muted-foreground">
        すでにアカウントをお持ちの方は{" "}
        <Link href="/login" className="font-medium text-primary underline-offset-4 hover:underline">
          ログイン
        </Link>
      </p>
    </form>
  );
}
