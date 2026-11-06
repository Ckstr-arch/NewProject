import { CONFIG as nativeConfig } from './config.native.js';

// The Docker website and PHP API share one origin, including custom host ports.
export const CONFIG = Object.freeze({
  ...nativeConfig,
  API_MODE: 'live',
  API_BASE_URL: '/api/v1'
});
