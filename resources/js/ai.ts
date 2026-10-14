export interface AiError {
    code: string
    message: string
    retryable: boolean
}

export type JobState<T> =
    | { id: string; status: 'queued' | 'running' }
    | { id: string; status: 'completed'; result: T }
    | { id: string; status: 'failed'; error: AiError }

export class AiKitError extends Error {
    constructor(
        public readonly status: number,
        public readonly code: string,
        message: string,
        public readonly retryable: boolean,
    ) {
        super(message)
        this.name = 'AiKitError'
    }
}

export function cpUrl(path: string): string {
    const config = (window as unknown as { Statamic?: typeof Statamic }).Statamic?.$config
    const root = config?.get('cpUrl') || config?.get('cpRoot')
    if (typeof root !== 'string' || root === '') {
        throw new AiKitError(0, 'configuration', 'The Statamic control panel URL is not configured.', false)
    }
    return `${root.replace(/\/+$/, '')}/${path.replace(/^\/+/, '')}`
}

function isObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function httpError(status: number, text: string): AiKitError {
    let payload: unknown
    try {
        payload = JSON.parse(text)
    } catch {
        // A non-JSON HTTP error body is a supported transport response.
        return new AiKitError(
            status,
            'http_error',
            text || `Request failed (${status}).`,
            status === 429 || status >= 500,
        )
    }
    const error = isObject(payload) && isObject(payload.error) ? payload.error : payload
    if (isObject(error)) {
        const validation = isObject(error.errors)
            ? Object.values(error.errors)
                  .flat()
                  .filter((v) => typeof v === 'string')
            : []
        return new AiKitError(
            status,
            typeof error.code === 'string' ? error.code : validation.length ? 'validation' : 'http_error',
            typeof error.message === 'string' ? error.message : validation.join('\n') || `Request failed (${status}).`,
            typeof error.retryable === 'boolean' ? error.retryable : status === 429 || status >= 500,
        )
    }
    return new AiKitError(status, 'http_error', `Request failed (${status}).`, status === 429 || status >= 500)
}

async function sendJson<T>(method: string, url: string, body?: unknown, signal?: AbortSignal): Promise<T> {
    const token = (window as unknown as { Statamic?: typeof Statamic }).Statamic?.$config.get('csrfToken')
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    }
    if (typeof token === 'string') headers['X-CSRF-TOKEN'] = token
    if (body !== undefined) headers['Content-Type'] = 'application/json'

    const encodedBody = body === undefined ? undefined : JSON.stringify(body)
    let response: Response
    let text: string
    try {
        response = await fetch(url, {
            method,
            headers,
            credentials: 'same-origin',
            body: encodedBody,
            signal,
        })
        text = await response.text()
    } catch (error) {
        if (signal?.aborted || (error instanceof Error && error.name === 'AbortError')) {
            throw new AiKitError(0, 'aborted', 'The AI job polling was aborted.', false)
        }
        if (error instanceof TypeError) {
            throw new AiKitError(0, 'network', 'The request could not reach the server.', true)
        }
        throw error
    }
    if (!response.ok) throw httpError(response.status, text)
    try {
        return JSON.parse(text) as T
    } catch {
        throw new AiKitError(response.status, 'invalid_response', 'The server returned invalid JSON.', false)
    }
}

export function requestJson<T>(method: string, url: string, body?: unknown): Promise<T> {
    return sendJson<T>(method, url, body)
}

export interface PollOptions<T> {
    intervalMs?: number
    maxIntervalMs?: number
    timeoutMs?: number
    signal?: AbortSignal
    onUpdate?: (state: JobState<T>) => void
}

function parseJob<T>(value: unknown): JobState<T> {
    if (isObject(value) && typeof value.id === 'string') {
        if (value.status === 'queued' || value.status === 'running') return { id: value.id, status: value.status }
        if (value.status === 'completed' && 'result' in value) {
            return { id: value.id, status: 'completed', result: value.result as T }
        }
        if (
            value.status === 'failed' &&
            isObject(value.error) &&
            typeof value.error.code === 'string' &&
            typeof value.error.message === 'string' &&
            typeof value.error.retryable === 'boolean'
        ) {
            return {
                id: value.id,
                status: 'failed',
                error: { code: value.error.code, message: value.error.message, retryable: value.error.retryable },
            }
        }
    }
    throw new AiKitError(200, 'invalid_response', 'The server returned an invalid job state.', false)
}

export function pollJob<T>(url: string, options: PollOptions<T> = {}): Promise<JobState<T>> {
    const { intervalMs = 1000, maxIntervalMs = 3000, timeoutMs = 120000, signal, onUpdate } = options
    if (![intervalMs, maxIntervalMs, timeoutMs].every((n) => Number.isFinite(n) && n > 0)) {
        return Promise.reject(new AiKitError(0, 'configuration', 'Polling durations must be positive numbers.', false))
    }

    return new Promise((resolve, reject) => {
        let attempts = 0
        let consecutiveErrors = 0
        let settled = false
        let next: ReturnType<typeof setTimeout> | undefined
        const controller = new AbortController()

        const finish = (error?: unknown, state?: JobState<T>): void => {
            if (settled) return
            settled = true
            clearTimeout(deadline)
            clearTimeout(next)
            signal?.removeEventListener('abort', abort)
            controller.abort()
            if (error !== undefined) reject(error)
            else if (state !== undefined) resolve(state)
        }
        const abort = (): void => finish(new AiKitError(0, 'aborted', 'The AI job polling was aborted.', false))
        const deadline = setTimeout(
            () => finish(new AiKitError(0, 'timeout', 'The AI job polling timed out. Try again.', true)),
            timeoutMs,
        )
        signal?.addEventListener('abort', abort, { once: true })
        if (signal?.aborted) {
            abort()
            return
        }

        const poll = async (): Promise<void> => {
            attempts++
            try {
                const state = parseJob<T>(await sendJson<unknown>('GET', url, undefined, controller.signal))
                if (settled) return
                consecutiveErrors = 0
                onUpdate?.(state)
                if (state.status === 'completed') {
                    finish(undefined, state)
                    return
                }
                if (state.status === 'failed') {
                    finish(new AiKitError(200, state.error.code, state.error.message, state.error.retryable))
                    return
                }
            } catch (error) {
                if (settled) return
                if (!(error instanceof AiKitError) || error.code !== 'network' || ++consecutiveErrors > 5) {
                    finish(error)
                    return
                }
            }
            if (!settled)
                next = setTimeout(
                    () => void poll(),
                    Math.min(attempts >= 10 ? intervalMs * 2 : intervalMs, maxIntervalMs),
                )
        }
        void poll()
    })
}
