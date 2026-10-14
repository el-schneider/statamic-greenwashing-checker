import statamic from '@statamic/cms/vite-plugin'
import laravel from 'laravel-vite-plugin'
import { defineConfig } from 'vite'

// Both bundles are built and committed; ServiceProvider loads only the one
// matching the running Statamic major. The v5 entry must not import
// @statamic/cms/* (that package does not exist on Statamic 5).
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/addon.ts', 'resources/js/v5/addon.ts'],
            publicDirectory: 'resources/dist',
        }),
        statamic(),
    ],
    server: { cors: true },
})
