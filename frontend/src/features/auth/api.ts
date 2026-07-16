import { api, ApiEnvelope } from "@/lib/api-client";
import type { User } from "@/types/auth";

export interface RegisterInput {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}

export interface LoginInput {
  email: string;
  password: string;
  remember?: boolean;
}

export const authApi = {
  me: () => api.get<ApiEnvelope<User>>("/api/user"),
  register: (input: RegisterInput) => api.post<ApiEnvelope<User>>("/api/register", input),
  login: (input: LoginInput) => api.post<ApiEnvelope<User>>("/api/login", input),
  logout: () => api.post<ApiEnvelope<unknown>>("/api/logout"),
};
