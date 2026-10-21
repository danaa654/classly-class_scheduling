import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    // PERFORMANCE — CDN: no `base` override here on purpose. laravel-vite-plugin
    // reads ASSET_URL from .env itself and builds '<ASSET_URL>/build/'.
    // Forcing base:'/' broke chunk/CSS preload URLs (/assets/... 404).
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    esbuild: {
        // Strip debug-only logging from production bundles.
        pure: ['console.debug'],
        legalComments: 'none',
    },
    build: {
        // PERFORMANCE — minify JS/CSS for production builds (esbuild
        // is Vite's default and is already on, but pinned explicitly
        // here so it can't be quietly turned off later).
        minify: 'esbuild',
        cssMinify: true,
        cssCodeSplit: true,
        // Each Inertia page under resources/js/Pages is already its
        // own chunk (see app.js's import.meta.glob('./Pages/**/*.vue')
        // — that's the "split code into chunks" / route-based lazy
        // loading). This adds VENDOR chunking on top of that: heavy
        // third-party libraries that not every page needs (charts,
        // the PrimeVue component kit, icon sets) get pulled into
        // their own cacheable chunks instead of bloating every page's
        // bundle or getting duplicated across pages.
        rollupOptions: {
            output: {
                manualChunks: {
                    'vendor-vue': ['vue', '@inertiajs/vue3'],
                    'vendor-primevue': ['primevue/config', 'primevue/toastservice', '@primeuix/themes'],
                    'vendor-icons': ['primeicons'],
                    'vendor-utils': ['sweetalert2', 'axios'],
                },
            },
        },
        // Assets under this size get base64-inlined into the CSS/JS
        // instead of becoming a separate file+request — fewer round
        // trips for the small icons/spinners the UI uses.
        assetsInlineLimit: 4096,
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
        hmr: {
            host: '192.168.1.7',
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});