import { describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { LeadOnboardingForm } from "@/features/lead/lead-onboarding-form";

const mutateMock = vi.fn();
const replaceMock = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: replaceMock, push: vi.fn() }),
}));

vi.mock("@/features/lead/hooks", () => ({
  useSubmitLeadOnboarding: () => ({
    mutate: mutateMock,
    isPending: false,
    error: null,
  }),
}));

describe("LeadOnboardingForm", () => {
  it("shows validation errors when required fields and privacy agreement are missing", async () => {
    const user = userEvent.setup();
    render(<LeadOnboardingForm />);

    await user.click(screen.getByRole("button", { name: "無料で診断をはじめる" }));

    expect(await screen.findByText("会社名を入力してください。")).toBeInTheDocument();
    expect(await screen.findByText("ご担当者名を入力してください。")).toBeInTheDocument();
    expect(await screen.findByText("メールアドレスを入力してください。")).toBeInTheDocument();
    expect(await screen.findByText("プライバシーポリシーへの同意が必要です。")).toBeInTheDocument();
    expect(mutateMock).not.toHaveBeenCalled();
  });

  it("shows a validation error for an invalid email format", async () => {
    const user = userEvent.setup();
    render(<LeadOnboardingForm />);

    await user.type(screen.getByLabelText("会社名"), "株式会社サンプル");
    await user.type(screen.getByLabelText("ご担当者名"), "山田太郎");
    await user.type(screen.getByLabelText("メールアドレス"), "not-an-email");
    await user.click(screen.getByLabelText("プライバシーポリシーに同意します"));
    await user.click(screen.getByRole("button", { name: "無料で診断をはじめる" }));

    expect(await screen.findByText("メールアドレスの形式が正しくありません。")).toBeInTheDocument();
    expect(mutateMock).not.toHaveBeenCalled();
  });

  it("submits with valid values including the privacy policy agreement", async () => {
    const user = userEvent.setup();
    render(<LeadOnboardingForm />);

    await user.type(screen.getByLabelText("会社名"), "株式会社サンプル");
    await user.type(screen.getByLabelText("ご担当者名"), "山田太郎");
    await user.type(screen.getByLabelText("メールアドレス"), "lead@example.com");
    await user.click(screen.getByLabelText("プライバシーポリシーに同意します"));
    await user.click(screen.getByRole("button", { name: "無料で診断をはじめる" }));

    await waitFor(() => {
      expect(mutateMock).toHaveBeenCalledWith(
        expect.objectContaining({
          company_name: "株式会社サンプル",
          contact_name: "山田太郎",
          email: "lead@example.com",
          privacy_policy_agreed: true,
        }),
        expect.anything(),
      );
    });
  });
});
