import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ProjectInput, projectsApi } from "@/features/projects/api";

export function projectsQueryKey(page: number) {
  return ["projects", "list", page] as const;
}

export function projectQueryKey(id: number) {
  return ["projects", "detail", id] as const;
}

export function useProjects(page = 1) {
  return useQuery({
    queryKey: projectsQueryKey(page),
    queryFn: () => projectsApi.list(page),
  });
}

export function useProject(id: number) {
  return useQuery({
    queryKey: projectQueryKey(id),
    queryFn: () => projectsApi.get(id),
    enabled: Number.isFinite(id),
  });
}

export function useCreateProject() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: ProjectInput) => projectsApi.create(input),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["projects", "list"] });
    },
  });
}

export function useUpdateProject(id: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: Partial<ProjectInput>) => projectsApi.update(id, input),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["projects", "list"] });
      queryClient.invalidateQueries({ queryKey: projectQueryKey(id) });
    },
  });
}

export function useDeleteProject() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => projectsApi.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["projects", "list"] });
    },
  });
}
