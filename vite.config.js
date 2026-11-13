import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
        // libheif-js (LGPL-3.0) is shipped as its own file; the notice and the way to its licence and source go with it.
        {
            name: 'libheif-notice',
            generateBundle(_options, bundle) {
                for (const file of Object.values(bundle)) {
                    if (file.type === 'chunk' && file.fileName.includes('libheif-bundle')) {
                        file.code =
                            '/*! libheif-js 1.23.5 (libheif 1.23.5, libde265 1.0.15), unmodified. GNU LGPL-3.0. ' +
                            'Licence, copyright and source: /licenses/libheif-js/NOTICE.txt */\n' +
                            file.code;
                    }
                }
            },
        },
    ],
    resolve: {
        alias: {
            '@': path.resolve(import.meta.dirname, 'resources/js'),
        },
    },
    server: {
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.{ts,tsx}'],
    },
});
