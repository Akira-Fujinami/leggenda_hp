import { useMutation, useQueryClient } from "@tanstack/react-query";
import { WebsiteInput, websitesApi } from "@/features/websites/api";
import { projectQueryKey } from "@/features/projects/hooks";

export function useCreateWebsite(projectId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: WebsiteInput) => websitesApi.create(projectId, input),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: projectQueryKey(projectId) });
      queryClient.invalidateQueries({ queryKey: ["projects", "list"] });
    },
  });
}

export function useDeleteWebsite(projectId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (websiteId: number) => websitesApi.delete(websiteId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: projectQueryKey(projectId) });
      queryClient.invalidateQueries({ queryKey: ["projects", "list"] });
    },
  });
}
