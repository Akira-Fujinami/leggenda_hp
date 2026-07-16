import { api, ApiEnvelope } from "@/lib/api-client";
import type { Pagination, Project, ProjectDetail } from "@/types/project";

export interface ProjectInput {
  name: string;
  description?: string | null;
  industry?: string | null;
  purpose?: string | null;
}

export const projectsApi = {
  list: (page = 1) => api.get<ApiEnvelope<Project[]> & { meta: { pagination: Pagination } }>(`/api/projects?page=${page}`),
  get: (id: number) => api.get<ApiEnvelope<ProjectDetail>>(`/api/projects/${id}`),
  create: (input: ProjectInput) => api.post<ApiEnvelope<Project>>("/api/projects", input),
  update: (id: number, input: Partial<ProjectInput>) => api.patch<ApiEnvelope<Project>>(`/api/projects/${id}`, input),
  delete: (id: number) => api.delete<ApiEnvelope<unknown>>(`/api/projects/${id}`),
};
