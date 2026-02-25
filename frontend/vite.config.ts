/// <reference types="vitest/config" />
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
  test: {
    environment: 'happy-dom',
    globals: true,
    include: ['tests/**/*.spec.ts'],
  },
  appType: 'spa',
  server: {
    port: 5173,
    allowedHosts: ['localhost', 'evetools.local', 'frontend'],
    proxy: {
      '/api': {
        target: 'http://app:80',
        changeOrigin: true,
      },
      // Mercure real-time hub
      '/.well-known/mercure': {
        target: 'http://app:80',
        changeOrigin: true,
      },
      // Proxy auth routes to backend, but NOT /auth/eve/callback (handled by Vue router)
      '/auth': {
        target: 'http://app:80',
        changeOrigin: true,
        bypass: (req) => {
          // Don't proxy the OAuth callback - let Vue router handle it
          if (req.url?.startsWith('/auth/eve/callback')) {
            return '/index.html'
          }
          return undefined
        },
      },
    },
  },
})
