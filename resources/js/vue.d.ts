import 'vue'

declare module 'vue' {
    interface ComponentCustomProperties {
        __(key: string, replacements?: Record<string, string | number>): string
        __n(key: string, count: number, replacements?: Record<string, string | number>): string
    }
}
