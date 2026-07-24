import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';

const root = path.dirname(fileURLToPath(import.meta.url));
const outputDirectory = path.join(root, 'public');

function contentHash(file) {
    return crypto.createHash('md5').update(fs.readFileSync(file)).digest('hex');
}

function normalizeGeneratedJavascript(directory) {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
        const file = path.join(directory, entry.name);

        if (entry.isDirectory()) {
            normalizeGeneratedJavascript(file);
        } else if (entry.isFile() && entry.name.endsWith('.js')) {
            const normalized = fs.readFileSync(file, 'utf8').replace(/[ \t]+$/gm, '');

            fs.writeFileSync(file, normalized);
        }
    }
}

function copyAssetsAndWriteManifest() {
    return {
        name: 'waterline-assets',
        buildStart() {
            fs.rmSync(path.join(outputDirectory, 'assets'), { force: true, recursive: true });
            fs.rmSync(path.join(outputDirectory, 'chunks'), { force: true, recursive: true });
        },
        closeBundle() {
            const imageSource = path.join(root, 'resources/img');
            const imageOutput = path.join(outputDirectory, 'img');

            fs.mkdirSync(imageOutput, { recursive: true });
            fs.cpSync(imageSource, imageOutput, { recursive: true });

            normalizeGeneratedJavascript(outputDirectory);

            const assets = [
                'app.js',
                'app-dark.css',
                'app.css',
                'components.css',
                ...fs.readdirSync(imageOutput)
                    .filter((file) => fs.statSync(path.join(imageOutput, file)).isFile())
                    .map((file) => `img/${file}`),
            ];
            const manifest = {};

            for (const asset of assets) {
                const key = `/${asset}`;
                manifest[key] = `${key}?id=${contentHash(path.join(outputDirectory, asset))}`;
            }

            fs.writeFileSync(
                path.join(outputDirectory, 'mix-manifest.json'),
                `${JSON.stringify(manifest, null, 4)}\n`,
            );
        },
    };
}

export default defineConfig({
    publicDir: false,
    plugins: [
        vue(),
        copyAssetsAndWriteManifest(),
    ],
    resolve: {
        alias: {
            '@': path.join(root, 'resources/js'),
            vue: 'vue/dist/vue.esm-bundler.js',
        },
    },
    define: {
        __VUE_OPTIONS_API__: true,
        __VUE_PROD_DEVTOOLS__: false,
        __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: false,
    },
    css: {
        preprocessorOptions: {
            scss: {
                quietDeps: true,
                silenceDeprecations: [
                    'abs-percent',
                    'color-functions',
                    'global-builtin',
                    'if-function',
                    'import',
                    'legacy-js-api',
                ],
            },
        },
    },
    build: {
        outDir: outputDirectory,
        emptyOutDir: false,
        chunkSizeWarningLimit: 2600,
        cssCodeSplit: true,
        rollupOptions: {
            input: {
                app: path.join(root, 'resources/js/app.js'),
                styles: path.join(root, 'resources/sass/app.scss'),
                'styles-dark': path.join(root, 'resources/sass/app-dark.scss'),
            },
            output: {
                entryFileNames: '[name].js',
                chunkFileNames: 'chunks/[name]-[hash].js',
                assetFileNames(asset) {
                    const source = asset.originalFileNames?.[0] || '';
                    const name = asset.names?.[0] || asset.name || '';

                    if (source.endsWith('app-dark.scss')) {
                        return 'app-dark.css';
                    }

                    if (source.endsWith('app.scss')) {
                        return 'app.css';
                    }

                    if (name.endsWith('.css')) {
                        return 'components.css';
                    }

                    return 'assets/[name]-[hash][extname]';
                },
            },
        },
    },
});
