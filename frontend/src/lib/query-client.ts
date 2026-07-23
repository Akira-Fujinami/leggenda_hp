import { QueryCache, QueryClient } from "@tanstack/react-query";
import { ApiError } from "@/lib/api-client";
import { userQueryKey } from "@/features/auth/hooks";

export function createQueryClient(): QueryClient {
  const queryCache = new QueryCache({
    onError: (error, query) => {
      // セッションが切れている(401)ことを、失敗したクエリがproject一覧等
      // 「認証済みAPI」であっても即座に検知できるようにする。userクエリを
      // invalidateすることで useUser() が再取得され、RequireAuth が
      // user===null を検知して自動的に/loginへ遷移する
      // (個々のページ側で401を捕まえて手動リダイレクトする必要がない)。
      const isUserQuery = query.queryKey[0] === "auth" && query.queryKey[1] === "user";

      if (error instanceof ApiError && error.status === 401 && !isUserQuery) {
        queryClient.invalidateQueries({ queryKey: userQueryKey });
      }
    },
  });

  const queryClient = new QueryClient({
    queryCache,
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        retry: (failureCount, error) => {
          if (error instanceof ApiError && (error.status === 401 || error.status === 403)) {
            return false;
          }
          return failureCount < 2;
        },
      },
      mutations: {
        retry: false,
      },
    },
  });

  return queryClient;
}
