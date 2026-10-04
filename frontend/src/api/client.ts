import axios from 'axios';

// API-клиент локального приложения: аутентификации нет, все запросы публичны.
export const api = axios.create({
  baseURL: '/api/v1',
  headers: { 'X-Requested-With': 'XMLHttpRequest' },
});

export function apiErrorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as { message?: string; error?: string } | undefined;
    return data?.message ?? data?.error ?? error.message;
  }
  return String(error);
}
