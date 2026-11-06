import { CONFIG } from '../config.js';

export class ApiError extends Error {
  constructor(message, status = 0, errors = {}, code = 'API_ERROR') {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors && typeof errors === 'object' ? errors : {};
    this.code = code;
  }
}

// Bearer tokens are scoped to this browser tab. No database credentials belong here.
function browserTokenStore() {
  const key = 'sportshub-access-token';
  return {
    get() { try { return sessionStorage.getItem(key); } catch { return null; } },
    set(value) { try { value ? sessionStorage.setItem(key, value) : sessionStorage.removeItem(key); } catch { /* In-memory token below still works. */ } }
  };
}
export function createHttpClient({ baseUrl = CONFIG.API_BASE_URL, timeout = CONFIG.REQUEST_TIMEOUT, fetcher = (...args) => fetch(...args), tokenStore = browserTokenStore() } = {}) {
  let accessToken = tokenStore.get();
  return {
    hasToken: () => Boolean(accessToken),
    clearToken() { accessToken = null; tokenStore.set(null); },
    async request(method, path, body, query = {}) {
      const params = new URLSearchParams();
      Object.entries(query).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') params.set(key, value);
      });
      const url = `${baseUrl.replace(/\/$/, '')}${path}${params.size ? `?${params}` : ''}`;
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), timeout);
      try {
        const headers = { Accept: 'application/json' };
        if (body !== undefined) headers['Content-Type'] = 'application/json';
        if (accessToken) headers.Authorization = `Bearer ${accessToken}`;
        const response = await fetcher(url, {
          method, headers, credentials: 'omit', cache: 'no-store', signal: controller.signal,
          ...(body !== undefined ? { body: JSON.stringify(body) } : {})
        });
        if (response.status === 204) return { data: null };
        const text = await response.text();
        let payload;
        try { payload = JSON.parse(text); }
        catch { throw new ApiError('The server returned an invalid response.', response.status, {}, 'INVALID_RESPONSE'); }
        if (!response.ok || payload?.error) {
          if (response.status === 401) this.clearToken();
          const message = response.status >= 500 ? 'The backend is unavailable. Please try again later.'
            : typeof payload?.error?.message === 'string' ? payload.error.message.slice(0, 240) : 'The request could not be completed.';
          throw new ApiError(message, response.status, payload?.error?.details, payload?.error?.code);
        }
        if (!payload || !Object.hasOwn(payload, 'data')) throw new ApiError('The server returned an invalid response.', response.status, {}, 'INVALID_RESPONSE');
        if (path === '/auth/login' && typeof payload.data?.access_token === 'string') {
          accessToken = payload.data.access_token;
          tokenStore.set(accessToken);
        }
        return payload;
      } catch (error) {
        if (error instanceof ApiError) throw error;
        if (controller.signal.aborted) throw new ApiError('The request timed out. Please try again.', 0, {}, 'TIMEOUT');
        throw new ApiError('Unable to reach the backend. Check your connection and API URL.', 0, {}, 'NETWORK_ERROR');
      } finally {
        clearTimeout(timer);
        // Stateless logout discards the local JWT; it cannot revoke an issued JWT.
        if (path === '/auth/logout') this.clearToken();
      }
    }
  };
}
export const http = createHttpClient();
