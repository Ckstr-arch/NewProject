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

// The PHP backend must issue/rotate csrfToken in auth/session, login and logout.
export function createHttpClient({ baseUrl = CONFIG.API_BASE_URL, timeout = CONFIG.REQUEST_TIMEOUT, fetcher = (...args) => fetch(...args) } = {}) {
  let csrfToken = null;
  return {
    async request(method, path, body, query = {}) {
      const params = new URLSearchParams();
      Object.entries(query).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') params.set(key, value);
      });
      const url = `${baseUrl.replace(/\/$/, '')}${path}${params.size ? `?${params}` : ''}`;
      const writes = !['GET', 'HEAD'].includes(method);
      if (writes && !csrfToken) throw new ApiError('Your security session is unavailable. Reload and sign in again.', 403, {}, 'CSRF_REQUIRED');
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), timeout);
      try {
        const headers = { Accept: 'application/json' };
        if (body !== undefined) headers['Content-Type'] = 'application/json';
        if (writes) headers['X-CSRF-Token'] = csrfToken;
        const response = await fetcher(url, {
          method, headers, credentials: 'include', cache: 'no-store', signal: controller.signal,
          ...(body !== undefined ? { body: JSON.stringify(body) } : {})
        });
        if (response.status === 204) {
          if (path === '/auth/logout') csrfToken = null;
          return { success: true, data: null, message: 'Deleted successfully' };
        }
        const text = await response.text();
        let payload = null;
        if (text.trim()) {
          try { payload = JSON.parse(text); }
          catch { throw new ApiError(response.ok ? 'The server returned an invalid response.' : 'The server could not complete your request.', response.status, {}, 'INVALID_RESPONSE'); }
        }
        if (!response.ok || payload?.success === false) {
          if (response.status === 401) csrfToken = null;
          // Do not display arbitrary server HTML, stack traces or internal error details.
          const message = response.status >= 500 ? 'The backend is unavailable. Please try again later.'
            : response.status === 401 ? 'Your session has expired. Please sign in.'
              : response.status === 403 ? 'Access denied. Check your permissions or refresh your security session.'
                : typeof payload?.message === 'string' ? payload.message.slice(0, 240) : 'The request could not be completed.';
          throw new ApiError(message, response.status, payload?.errors);
        }
        if (!payload || payload.success !== true || !Object.hasOwn(payload, 'data')) throw new ApiError('The server returned an invalid response.', response.status, {}, 'INVALID_RESPONSE');
        if (payload.data?.csrfToken) csrfToken = payload.data.csrfToken;
        return payload;
      } catch (error) {
        if (error instanceof ApiError) throw error;
        if (controller.signal.aborted) throw new ApiError('The request timed out. Please try again.', 0, {}, 'TIMEOUT');
        throw new ApiError('Unable to reach the backend. Check your connection and API URL.', 0, {}, 'NETWORK_ERROR');
      } finally { clearTimeout(timer); }
    }
  };
}

export const http = createHttpClient();
