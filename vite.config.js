import { defineConfig } from 'vite';
import { viteStaticCopy } from 'vite-plugin-static-copy';

export default defineConfig({
  // Disable public dir auto-copy
  publicDir: false,

  build: {
    // Output directory
    outDir: 'public/dist',

    // Generate manifest for PHP to read
    manifest: true,

    // Rollup options
    rollupOptions: {
      input: {
        // CSS files
        'app': './src/tailwind.css',

        // JS files - Chart.js for admin stats
        'charts': './src/js/charts.js'
      },
      output: {
        // Hash format: [name].[hash].ext
        entryFileNames: '[name].[hash].js',
        chunkFileNames: '[name].[hash].js',
        assetFileNames: '[name].[hash].[ext]'
      }
    },

    // Minify for production
    minify: 'esbuild',

    // Source maps for debugging
    sourcemap: false,

    // Clear output dir before build
    emptyOutDir: true
  },

  // CSS processing
  css: {
    postcss: './postcss.config.js'
  },

  plugins: [
    // No static copy needed - assets stay in public/assets
  ]
});
