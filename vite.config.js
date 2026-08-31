import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

function directoryPrefix(value) {
    const trimmed = value.replace(/^\/+|\/+$/g, '');

    return trimmed === '' ? '' : `/${trimmed}`;
}

export default defineConfig(({ command, mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const assetUrl = (env.ASSET_URL ?? '').replace(/\/$/, '');
    const pathPrefix = directoryPrefix(env.API_PATH_PREFIX ?? '');
    // laravel-vite-plugin already uses ASSET_URL for CSS url() rewrites; fall back so one env var covers subdirectory deploys.
    const base =
        command === 'build' && pathPrefix !== '' && assetUrl === ''
            ? `${pathPrefix}/build/`
            : undefined;

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
                fonts: [
                    bunny('Instrument Sans', {
                        weights: [400, 500, 600],
                    }),
                ],
            }),
            tailwindcss(),
        ],
        ...(base ? { base } : {}),
        server: {
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
