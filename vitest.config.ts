import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

// Tests do not boot Laravel's development server, including on CI.
export default defineConfig({ plugins: [vue()], test: { include: ['resources/js/**/*.test.ts'] } })
