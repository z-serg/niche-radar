import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Продакшен-сборка отдаётся Nginx; dev-сервер проксирует API на web-контейнер.
export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'dist',
    chunkSizeWarningLimit: 1200,
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost:8180',
        changeOrigin: true,
      },
    },
  },
});
