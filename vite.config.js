import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ]),
    server: {
        cors: true,
        // The dev server behind a domain (a remote dev box): VITE_DEV_ORIGIN is its
        // public https URL, written to public/hot and used by the HMR socket.
        ...(process.env.VITE_DEV_ORIGIN && {
            host: '0.0.0.0',
            origin: process.env.VITE_DEV_ORIGIN,
            allowedHosts: [new URL(process.env.VITE_DEV_ORIGIN).hostname],
            hmr: { host: new URL(process.env.VITE_DEV_ORIGIN).hostname, protocol: 'wss', clientPort: 443 },
        }),
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/storage/framework/views/**',
                '**/vendor/**',
            ],
        },
    },
});
