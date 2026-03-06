import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import path from 'path';

export default defineConfig({
  plugins: [svelte({
    onwarn(warning, handler) {
      if (warning.code.startsWith('a11y') || warning.code === 'css_unused_selector') return;
      handler(warning);
    }
  })],
  base: './',
  build: {
    outDir: path.resolve(__dirname, 'php/admin'),
    emptyOutDir: true,
    rollupOptions: {
      output: {
        entryFileNames: 'assets/app-[hash].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash][extname]',
        manualChunks: {
          codemirror: [
            'codemirror',
            '@codemirror/state',
            '@codemirror/view',
            '@codemirror/language',
            '@codemirror/autocomplete',
            '@codemirror/lang-html',
            '@codemirror/lang-css',
            '@codemirror/lang-javascript',
            '@codemirror/lang-php',
            '@codemirror/theme-one-dark',
          ],
        },
      }
    }
  },
  resolve: {
    alias: {
      '$lib': path.resolve(__dirname, 'src/lib'),
      '$components': path.resolve(__dirname, 'src/components'),
      '$pages': path.resolve(__dirname, 'src/pages')
    }
  },
  server: {
    proxy: {
      '/outpost/api.php': {
        target: 'http://localhost:8080',
        changeOrigin: true
      },
      '/outpost/media.php': {
        target: 'http://localhost:8080',
        changeOrigin: true
      }
    }
  }
});
