import { describe, expect, it, vi } from "vitest";
import { QueryClient } from "@tanstack/react-query";
import { createQueryClient } from "@/lib/query-client";
import { ApiError } from "@/lib/api-client";
import { userQueryKey } from "@/features/auth/hooks";

describe("createQueryClient", () => {
  it("invalidates the user query when any other query fails with 401 (so RequireAuth can redirect to /login)", async () => {
    const queryClient = createQueryClient();
    const invalidateSpy = vi.spyOn(queryClient, "invalidateQueries");

    queryClient.setQueryData(userQueryKey, { id: 1, name: "Test User", email: "test@example.com" });

    await queryClient
      .fetchQuery({
        queryKey: ["projects", "list", 1],
        queryFn: () => {
          throw new ApiError(401, "ログインが必要です。", {}, "UNAUTHENTICATED", null, "/api/projects");
        },
        retry: false,
      })
      .catch(() => {});

    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: userQueryKey });
  });

  it("does not invalidate the user query when the user query itself fails with 401 (avoids an infinite refetch loop)", async () => {
    const queryClient = createQueryClient();
    const invalidateSpy = vi.spyOn(queryClient, "invalidateQueries");

    await queryClient
      .fetchQuery({
        queryKey: userQueryKey,
        queryFn: () => {
          throw new ApiError(401, "ログインが必要です。", {}, "UNAUTHENTICATED", null, "/api/user");
        },
        retry: false,
      })
      .catch(() => {});

    expect(invalidateSpy).not.toHaveBeenCalled();
  });

  it("does not invalidate the user query for non-401 errors", async () => {
    const queryClient = createQueryClient();
    const invalidateSpy = vi.spyOn(queryClient, "invalidateQueries");

    await queryClient
      .fetchQuery({
        queryKey: ["projects", "list", 1],
        queryFn: () => {
          throw new ApiError(500, "Server Error", {}, null, null, "/api/projects");
        },
        retry: false,
      })
      .catch(() => {});

    expect(invalidateSpy).not.toHaveBeenCalled();
  });

  it("returns a real QueryClient instance", () => {
    expect(createQueryClient()).toBeInstanceOf(QueryClient);
  });
});
