import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

export default defineConfig({
  root: fileURLToPath(new URL('./frontend', import.meta.url)),
  // PHP's public/ directory must never be copied into browser assets.
  publicDir: false,
  server: {
    host: '127.0.0.1',
    port: 5173,
    strictPort: true,
    proxy: {
      // A plain `/api` prefix would also intercept frontend assets such as `/api.js`.
      '^/api(?:/|$)': 'http://127.0.0.1:8000',
    },
  },
  build: {
    outDir: '../dist',
    emptyOutDir: true,
  },
});
