import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';

/**
 * Build → assets/dist with a Vite manifest. WordPress (Admin/Menu.php) reads
 * the manifest to enqueue the hashed JS + CSS. Input paths are relative to the
 * project root (this config's directory).
 */
export default defineConfig({
  plugins: [svelte()],
  build: {
    outDir: 'assets/dist',
    emptyOutDir: true,
    manifest: true,
    target: 'es2020',
    rollupOptions: {
      input: {
        dashboard: 'src-svelte/dashboard/main.ts',
        frontend: 'src-svelte/frontend/main.ts',
        docs: 'src-svelte/docs/main.ts',
        editor: 'src-svelte/editor/main.ts',
        search: 'src-svelte/search/main.ts',
        staticfilters: 'src-svelte/static-filters/main.ts',
        'search-style': 'src-svelte/search/search.css',
      },
    },
  },
});
