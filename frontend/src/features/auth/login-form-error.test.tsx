import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { LoginForm } from "@/features/auth/login-form";
import { ApiError } from "@/lib/api-client";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
}));

vi.mock("@/features/auth/hooks", () => ({
  useLogin: () => ({
    mutate: vi.fn(),
    isPending: false,
    error: new ApiError(401, "メールアドレスまたはパスワードが正しくありません。", {}, "INVALID_CREDENTIALS"),
  }),
}));

describe("LoginForm API error display", () => {
  it("shows the server error message returned by the API", () => {
    render(<LoginForm />);

    expect(screen.getByText("メールアドレスまたはパスワードが正しくありません。")).toBeInTheDocument();
  });
});
