import { CONFIG } from '../config.js';
import { endpoints, liveEndpoints } from './endpoints.js';
import { http, ApiError } from './http.js';
import { mock } from './mock-api.js';

export const DEMO_ACCOUNTS = Object.freeze([
  { role: 'student', email: 'student@example.test', password: 'Student123!' },
  { role: 'admin', email: 'admin@example.test', password: 'Admin123!' }
]);
const adapter = CONFIG.API_MODE === 'mock' ? mock : CONFIG.API_MODE === 'live' ? http : null;
if (!adapter) throw new Error('API_MODE must be mock or live.');

export const api = Object.fromEntries(Object.entries(CONFIG.API_MODE === 'live' ? liveEndpoints : endpoints).map(([name, [method, template]]) => [name, async (...args) => {
  if (CONFIG.API_MODE === 'live' && name === 'session' && !http.hasToken()) return { data: { user: null, permissions: [] } };
  let path = template;
  if (path.includes(':id')) {
    const suppliedId = args.shift();
    const validType = typeof suppliedId === 'number' || (typeof suppliedId === 'string' && /^[1-9]\d*$/.test(suppliedId));
    const id = Number(suppliedId);
    if (!validType || !Number.isSafeInteger(id) || id < 1) throw new ApiError('A valid record identifier is required.', 400);
    path = path.replace(':id', encodeURIComponent(id));
  }
  const body = method === 'GET' || method === 'DELETE' ? undefined : args[0];
  const query = method === 'GET' ? args[0] || {} : {};
  try { return await adapter.request(method, path, body, query); }
  catch (error) {
    if (error.status === 401 && typeof window !== 'undefined') window.dispatchEvent(new CustomEvent('sportshub:unauthorized'));
    throw error;
  }
}]));
api.resetDemo = async () => {
  if (CONFIG.API_MODE !== 'mock') throw new ApiError('Demo reset is available only in Demo Mode.', 403);
  return mock.reset();
};
Object.freeze(api);
