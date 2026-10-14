declare function __(key: string, replacements?: Record<string, string | number>): string
declare function __n(key: string, count: number, replacements?: Record<string, string | number>): string

declare const Statamic: {
    booting(callback: () => void): void
    $inertia: { register(name: string, component: unknown): void }
    $components: { register(name: string, component: unknown): void }
    $config: { get(key: string): unknown }
    $reveal: { element(element: HTMLElement): void }
    $toast: { error(message: string): void }
    $hooks: {
        on(
            key: string,
            callback: (
                resolve: () => void,
                reject: (error?: unknown) => void,
                payload: import('./store').SavePayload,
            ) => void,
        ): void
    }
    $fieldActions: { add(name: string, action: object): void }
    // Statamic owns these runtime objects. Importing Tiptap would bundle a second editor.
    $bard: { addExtension(callback: (context: { bard: any; tiptap: any }) => unknown): void }
}
