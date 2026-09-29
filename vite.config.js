import process from 'node:process';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import {
    defineConfig
} from 'vite';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            ssr: 'resources/js/ssr.jsx',
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    esbuild: {
        jsx: 'automatic',
    },
    // Inside the Docker "node" service: listen on all interfaces, and tell the browser (and
    // Laravel's public/hot file) to reach the dev server via localhost.
    server: process.env.VITE_DOCKER
        ? { host: '0.0.0.0', port: 5173, strictPort: true, hmr: { host: 'localhost' }, cors: true }
        : undefined,
});