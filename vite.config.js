import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'path';

export default defineConfig({
  plugins: [react()],
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
  build: {
    outDir: 'dist',
    lib: {
      entry: resolve(__dirname, 'src/main.jsx'),
      name: 'MapCN',
      formats: ['umd'],
      fileName: 'mapcn',
    },
    rollupOptions: {
      external: [],
      output: {
        globals: {},
        assetFileNames: 'mapcn.[ext]',
      },
    },
  },
});
