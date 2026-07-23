import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import DashboardPage from "./page";
import { ApiError, ApiNetworkError } from "@/lib/api-client";

const useProjectsMock = vi.fn();

vi.mock("@/features/projects/hooks", () => ({
  useProjects: (...args: unknown[]) => useProjectsMock(...args),
}));

describe("DashboardPage empty state", () => {
  it("shows a call-to-action instead of fabricated analysis data when there are no projects", () => {
    useProjectsMock.mockReturnValue({
      data: { data: [], meta: { pagination: { current_page: 1, last_page: 1, per_page: 12, total: 0 } } },
      isLoading: false,
      isError: false,
      error: null,
      refetch: vi.fn(),
    });

    render(<DashboardPage />);

    expect(
      screen.getByText("まだ比較プロジェクトがありません。サイトを登録して分析を開始しましょう。"),
    ).toBeInTheDocument();
  });
});

describe("DashboardPage success state", () => {
  it("renders project cards when the list loads with data", () => {
    useProjectsMock.mockReturnValue({
      data: {
        data: [
          {
            id: 1,
            name: "自社サイト比較",
            description: null,
            industry: null,
            purpose: null,
            websites_count: 2,
            created_at: "2026-07-01T00:00:00Z",
            updated_at: "2026-07-01T00:00:00Z",
          },
        ],
        meta: { pagination: { current_page: 1, last_page: 1, per_page: 12, total: 1 } },
      },
      isLoading: false,
      isError: false,
      error: null,
      refetch: vi.fn(),
    });

    render(<DashboardPage />);

    expect(screen.getByText("自社サイト比較")).toBeInTheDocument();
  });
});

describe("DashboardPage error state", () => {
  it("shows a re-login prompt (not a generic message) when the session has expired (401)", () => {
    useProjectsMock.mockReturnValue({
      data: undefined,
      isLoading: false,
      isError: true,
      error: new ApiError(401, "ログインが必要です。", {}, "UNAUTHENTICATED", "req-1", "/api/projects"),
      refetch: vi.fn(),
    });

    render(<DashboardPage />);

    expect(screen.getByText("セッションの有効期限が切れました。再度ログインしてください。")).toBeInTheDocument();
    // shadcn/ui(base-ui)のButtonは<Link>と合成してもrole="button"を明示するため、
    // アクセシビリティ上の役割は"link"ではなく"button"になる(register-project-website.spec.tsと同じ注意点)。
    expect(screen.getByRole("button", { name: "ログイン画面へ" })).toBeInTheDocument();
  });

  it("shows a retry button and the request id (not raw SQL/stack traces) on a server error (500)", () => {
    useProjectsMock.mockReturnValue({
      data: undefined,
      isLoading: false,
      isError: true,
      error: new ApiError(500, "Server Error", {}, null, "req-abc-123", "/api/projects"),
      refetch: vi.fn(),
    });

    render(<DashboardPage />);

    expect(screen.getByText("サーバーでエラーが発生しました。時間をおいて再度お試しください。")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "再試行する" })).toBeInTheDocument();
    expect(screen.getByText(/req-abc-123/)).toBeInTheDocument();
  });

  it("shows a network error message when fetch itself fails (network/CORS)", () => {
    useProjectsMock.mockReturnValue({
      data: undefined,
      isLoading: false,
      isError: true,
      error: new ApiNetworkError("/api/projects"),
      refetch: vi.fn(),
    });

    render(<DashboardPage />);

    expect(screen.getByText(/ネットワークエラーが発生しました/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "再試行する" })).toBeInTheDocument();
  });
});
