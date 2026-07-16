import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { RequireAuth } from "@/features/auth/require-auth";

const replaceMock = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: replaceMock }),
}));

vi.mock("@/features/auth/hooks", () => ({
  useUser: () => ({ data: null, isLoading: false }),
}));

describe("RequireAuth", () => {
  it("does not render protected content and redirects to /login when unauthenticated", () => {
    render(
      <RequireAuth>
        <p>secret dashboard content</p>
      </RequireAuth>,
    );

    expect(screen.queryByText("secret dashboard content")).not.toBeInTheDocument();
    expect(replaceMock).toHaveBeenCalledWith("/login");
  });
});
