import { defineConfig } from 'vite';
import path from 'path';

export default defineConfig({
  build: {
    lib: {
      entry: path.resolve(__dirname, 'src/editor/on-page-editor.js'),
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
