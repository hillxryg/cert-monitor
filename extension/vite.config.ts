import { crx } from '@crxjs/vite-plugin';
import { defineConfig } from 'vite';
import manifest from './src/manifest.json';

export default defineConfig({
  // The JSON manifest is typed as plain strings/numbers, so cast it to the plugin's expected shape.
  plugins: [crx({ manifest } as unknown as Parameters<typeof crx>[0])],
  server: {
    port: 5173,
    strictPort: true,
    // Required so the extension's HMR client can talk to the dev server.
    cors: { origin: [/chrome-extension:\/\//] },
  },
});
