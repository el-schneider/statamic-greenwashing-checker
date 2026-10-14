import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AiKitError, cpUrl, pollJob, requestJson } from './ai'

const fetchMock = vi.fn<typeof fetch>()
const config = vi.fn((key: string): unknown => ({ cpUrl: '/custom-cp', csrfToken: 'csrf-test' })[key as 'cpUrl'])
const json = (value: unknown, status = 200): Response => new Response(JSON.stringify(value), { status })
const queued = { id: 'job', status: 'queued' }
const completed = { id: 'job', status: 'completed', result: { findings: [] } }

beforeEach(() => {
    vi.useFakeTimers()
    vi.stubGlobal('window', { Statamic: { $config: { get: config } } })
    vi.stubGlobal('fetch', fetchMock)
    config.mockImplementation((key) => ({ cpUrl: '/custom-cp', csrfToken: 'csrf-test' })[key as 'cpUrl'])
})

afterEach(() => {
    vi.clearAllTimers()
    vi.useRealTimers()
    vi.resetAllMocks()
    vi.unstubAllGlobals()
})

describe('cpUrl', () => {
    it('uses the CP config and handles separators and a subdirectory', () => {
        config.mockImplementation((key) => (key === 'cpUrl' ? 'https://example.test/subdir/admin/' : undefined))
        expect(cpUrl('/addon/jobs')).toBe('https://example.test/subdir/admin/addon/jobs')
    })
    it('falls back to cpRoot in older installations', () => {
        config.mockImplementation((key) => (key === 'cpRoot' ? '/admin' : undefined))
        expect(cpUrl('addon')).toBe('/admin/addon')
    })
    it('fails when neither config URL is available', () => {
        config.mockReturnValue(undefined)
        expect(() => cpUrl('addon')).toThrow(AiKitError)
    })
})

describe('requestJson', () => {
    it('sends JSON, CSRF, same-origin credentials, and AJAX headers', async () => {
        fetchMock.mockResolvedValue(json({ ok: true }))
        await expect(requestJson('POST', cpUrl('addon'), { text: 'Hello' })).resolves.toEqual({ ok: true })
        expect(fetchMock).toHaveBeenCalledWith('/custom-cp/addon', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': 'csrf-test',
                'Content-Type': 'application/json',
            },
            body: '{"text":"Hello"}',
            signal: undefined,
        })
    })
    it('does not send a body or content type for GET', async () => {
        fetchMock.mockResolvedValue(json(queued))
        await requestJson('GET', '/jobs/job')
        expect(fetchMock.mock.calls[0][1]?.body).toBeUndefined()
        expect(fetchMock.mock.calls[0][1]?.headers).not.toHaveProperty('Content-Type')
    })
    it.each([
        [
            409,
            { code: 'configuration', message: 'Configure a model.', retryable: false },
            'configuration',
            'Configure a model.',
            false,
        ],
        [
            429,
            { error: { code: 'rate_limited', message: 'Slow down.', retryable: true } },
            'rate_limited',
            'Slow down.',
            true,
        ],
        [
            422,
            { message: 'Invalid text.', errors: { text: ['Text is required.'] } },
            'validation',
            'Invalid text.',
            false,
        ],
        [422, { errors: { text: ['Text is required.'] } }, 'validation', 'Text is required.', false],
        [503, 'Service unavailable', 'http_error', 'Service unavailable', true],
    ])('normalises HTTP errors (%s)', async (status, payload, code, message, retryable) => {
        fetchMock.mockResolvedValue(
            typeof payload === 'string' ? new Response(payload, { status }) : json(payload, status),
        )
        await expect(requestJson('GET', '/jobs/job')).rejects.toMatchObject({ status, code, message, retryable })
    })
    it('rejects invalid success JSON instead of silently returning a default', async () => {
        fetchMock.mockResolvedValue(new Response('not json'))
        await expect(requestJson('GET', '/jobs/job')).rejects.toMatchObject({
            code: 'invalid_response',
            retryable: false,
        })
    })
    it('normalises network errors', async () => {
        fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
        await expect(requestJson('GET', '/jobs/job')).rejects.toMatchObject({
            status: 0,
            code: 'network',
            retryable: true,
        })
    })
    it('does not treat a circular request body as a network failure', async () => {
        const body: { self?: unknown } = {}
        body.self = body
        await expect(requestJson('POST', '/jobs/job', body)).rejects.toBeInstanceOf(TypeError)
        expect(fetchMock).not.toHaveBeenCalled()
    })
})

describe('pollJob', () => {
    it('polls immediately and resolves the completed result with updates', async () => {
        const onUpdate = vi.fn()
        fetchMock.mockResolvedValueOnce(json(queued)).mockResolvedValueOnce(json(completed))
        const result = pollJob('/jobs/job', { onUpdate })
        await vi.advanceTimersByTimeAsync(0)
        expect(fetchMock).toHaveBeenCalledTimes(1)
        await vi.advanceTimersByTimeAsync(1000)
        await expect(result).resolves.toEqual(completed)
        expect(onUpdate.mock.calls.map(([state]) => state.status)).toEqual(['queued', 'completed'])
        expect(vi.getTimerCount()).toBe(0)
    })
    it('resolves on the first poll for sync queues', async () => {
        fetchMock.mockResolvedValue(json(completed))
        await expect(pollJob('/jobs/job')).resolves.toEqual(completed)
        expect(fetchMock).toHaveBeenCalledTimes(1)
        expect(vi.getTimerCount()).toBe(0)
    })
    it('rejects failed jobs with a typed AI error', async () => {
        fetchMock.mockResolvedValue(
            json({ id: 'job', status: 'failed', error: { code: 'stale', message: 'Stopped.', retryable: true } }),
        )
        await expect(pollJob('/jobs/job')).rejects.toMatchObject({
            status: 200,
            code: 'stale',
            message: 'Stopped.',
            retryable: true,
        })
        expect(vi.getTimerCount()).toBe(0)
    })
    it('backs off after ten attempts and respects maxIntervalMs', async () => {
        fetchMock.mockImplementation(async () => json(queued))
        const controller = new AbortController()
        const result = pollJob('/jobs/job', { intervalMs: 100, maxIntervalMs: 150, signal: controller.signal })
        const rejection = expect(result).rejects.toMatchObject({ code: 'aborted' })
        await vi.advanceTimersByTimeAsync(900)
        expect(fetchMock).toHaveBeenCalledTimes(10)
        await vi.advanceTimersByTimeAsync(149)
        expect(fetchMock).toHaveBeenCalledTimes(10)
        await vi.advanceTimersByTimeAsync(1)
        expect(fetchMock).toHaveBeenCalledTimes(11)
        controller.abort()
        await rejection
        expect(vi.getTimerCount()).toBe(0)
    })
    it('tolerates five consecutive network errors then recovers', async () => {
        for (let i = 0; i < 5; i++) fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))
        fetchMock.mockResolvedValueOnce(json(completed))
        const result = pollJob('/jobs/job')
        await vi.advanceTimersByTimeAsync(5000)
        await expect(result).resolves.toEqual(completed)
        expect(fetchMock).toHaveBeenCalledTimes(6)
    })
    it('rejects the sixth consecutive network error', async () => {
        fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
        const result = pollJob('/jobs/job')
        const rejection = expect(result).rejects.toMatchObject({ code: 'network' })
        await vi.advanceTimersByTimeAsync(5000)
        await rejection
        expect(fetchMock).toHaveBeenCalledTimes(6)
        expect(vi.getTimerCount()).toBe(0)
    })
    it('resets the consecutive network error count after a successful update', async () => {
        for (let i = 0; i < 5; i++) fetchMock.mockRejectedValueOnce(new TypeError('offline'))
        fetchMock.mockResolvedValueOnce(json(queued))
        for (let i = 0; i < 5; i++) fetchMock.mockRejectedValueOnce(new TypeError('offline'))
        fetchMock.mockResolvedValueOnce(json(completed))
        const result = pollJob('/jobs/job', { intervalMs: 10 })
        await vi.advanceTimersByTimeAsync(150)
        await expect(result).resolves.toEqual(completed)
        expect(fetchMock).toHaveBeenCalledTimes(12)
    })
    it('does not retry HTTP errors', async () => {
        fetchMock.mockResolvedValue(json({ message: 'Forbidden' }, 403))
        await expect(pollJob('/jobs/job')).rejects.toMatchObject({ status: 403 })
        expect(fetchMock).toHaveBeenCalledTimes(1)
    })
    it.each([
        { status: 'unknown', id: 'job' },
        { status: 'completed', id: 'job' },
        { status: 'failed', id: 'job', error: {} },
    ])('rejects malformed job states: %j', async (state) => {
        fetchMock.mockResolvedValue(json(state))
        await expect(pollJob('/jobs/job')).rejects.toMatchObject({ code: 'invalid_response' })
    })
    it('times out even when fetch never settles and aborts the transport', async () => {
        fetchMock.mockImplementation(() => new Promise(() => {}))
        const result = pollJob('/jobs/job', { timeoutMs: 2500 })
        const rejection = expect(result).rejects.toMatchObject({ code: 'timeout', retryable: true })
        await vi.advanceTimersByTimeAsync(2500)
        await rejection
        expect(fetchMock.mock.calls[0][1]?.signal?.aborted).toBe(true)
        expect(vi.getTimerCount()).toBe(0)
    })
    it('aborts a pending request and ignores late responses', async () => {
        let respond: (response: Response) => void = () => {
            throw new Error('No pending request')
        }
        fetchMock.mockImplementation(
            () =>
                new Promise((resolve) => {
                    respond = resolve
                }),
        )
        const controller = new AbortController()
        const onUpdate = vi.fn()
        const result = pollJob('/jobs/job', { signal: controller.signal, onUpdate })
        const rejection = expect(result).rejects.toMatchObject({ code: 'aborted', retryable: false })
        controller.abort()
        await rejection
        respond(json(completed))
        await vi.advanceTimersByTimeAsync(1000)
        expect(onUpdate).not.toHaveBeenCalled()
        expect(fetchMock).toHaveBeenCalledTimes(1)
        expect(vi.getTimerCount()).toBe(0)
    })
    it('rejects an already aborted signal without making a request', async () => {
        const controller = new AbortController()
        controller.abort()
        await expect(pollJob('/jobs/job', { signal: controller.signal })).rejects.toMatchObject({ code: 'aborted' })
        expect(fetchMock).not.toHaveBeenCalled()
        expect(vi.getTimerCount()).toBe(0)
    })
})
