import { afterEach, expect, it, vi } from 'vitest'
import { createSSRApp, defineComponent, h } from 'vue'
import { renderToString } from '@vue/server-renderer'
import FindingCard from './FindingCard.vue'
import type { Finding } from './logic'

vi.mock('@statamic/cms/ui', () => {
    const component = defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                h('button', slots.default?.()),
    })
    return { Badge: component, Button: component, Card: component, Description: component, Heading: component }
})
afterEach(() => vi.unstubAllGlobals())

it('shows the approval reason as text with author/date and no apply or ignore actions', async () => {
    vi.stubGlobal('__', (key: string, values?: Record<string, string>) =>
        key.endsWith('approved_by') ? `Approved by ${values?.user}: ${values?.reason}` : key,
    )
    const finding: Finding = {
        id: 'id',
        path: 'title',
        quote: 'grün',
        anchored: true,
        category: 'generic_claim',
        severity: 'high',
        explanation: 'Risk',
        suggestions: ['specific'],
        dismissed: true,
        approval: {
            user: 'id',
            user_name: 'Editor',
            at: '2026-10-13T12:00:00Z',
            reason: '<img src=x onerror=alert(1)>',
        },
    }
    const app = createSSRApp(FindingCard, { finding, norms: {}, canApprove: true })
    app.config.globalProperties.__ = __
    app.config.globalProperties.__n = (key: string) => key
    const html = await renderToString(app)
    for (const text of ['Approved by Editor:', '&lt;img', 'datetime="2026-10-13T12:00:00Z"', 'buttons.revoke'])
        expect(html).toContain(text)
    for (const text of ['<img', 'buttons.dismiss', 'buttons.apply', 'statuses.ignored'])
        expect(html).not.toContain(text)
})

it('hides approval controls from editors without decision permission', async () => {
    vi.stubGlobal('__', (key: string) => key)
    const finding: Finding = {
        id: 'id',
        path: 'title',
        quote: 'grün',
        anchored: true,
        category: 'generic_claim',
        severity: 'high',
        explanation: 'Risk',
        suggestions: [],
        dismissed: false,
    }
    const app = createSSRApp(FindingCard, {
        finding,
        norms: { generic_claim: 'UCPD Annex I point 4a · DE: UWG Annex no. 4a' },
        canApprove: false,
    })
    app.config.globalProperties.__ = __
    const html = await renderToString(app)
    expect(html).not.toContain('buttons.approve')
    expect(html).not.toContain('buttons.revoke')
    expect(html).toContain('buttons.dismiss')
    expect(html).toContain('UCPD Annex I point 4a · DE: UWG Annex no. 4a')
})
