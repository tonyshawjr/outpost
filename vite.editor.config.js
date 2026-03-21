import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import path from 'path';

export default defineConfig({
  plugins: [svelte({
    onwarn(warning, handler) {
      if (warning.code.startsWith('a11y') || warning.code === 'css_unused_selector') return;
      handler(warning);
    },
    compilerOptions: {
      css: 'injected',
    }
  })],
  build: {
    lib: {
      entry: path.resolve(__dirname, 'src/editor/main.js'),
      formats: ['iife'],
      name: 'OutpostEditor',
      fileName: () => 'on-page-editor.js',
    },
    outDir: path.resolve(__dirname, 'php/admin'),
    emptyOutDir: false,
    rollupOptions: {
      output: {
        assetFileNames: 'on-page-editor[extname]',
      }
    }
  }
});
