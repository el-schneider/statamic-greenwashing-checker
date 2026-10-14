import { afterEach, expect, it, vi } from 'vitest'
import { createSSRApp, defineComponent, h } from 'vue'
import { renderToString } from '@vue/server-renderer'
import ReviewFindings from './ReviewFindings.vue'
import { groupFindings, type Finding, type PublishTab } from './logic'

vi.mock('@statamic/cms/ui', () => {
    const element = (tag: string) =>
        defineComponent({
            setup:
                (_, { slots }) =>
                () =>
                    h(tag, slots.default?.()),
        })
    return {
        Badge: element('span'),
        Button: element('button'),
        Card: element('article'),
        Description: element('p'),
        Heading: element('h3'),
    }
})
afterEach(() => vi.unstubAllGlobals())

const finding: Finding = {
    id: 'same-text-id',
    path: 'title',
    quote: '<img src=x onerror=alert(1)>',
    anchored: true,
    category: 'generic_claim',
    severity: 'high',
    explanation: 'Detailed explanation',
    suggestions: ['Specific replacement'],
    dismissed: false,
}
const tabs: PublishTab[] = [
    { handle: 'main', display: 'Main', sections: [{ fields: [{ handle: 'title', display: 'Title' }] }] },
    { handle: 'more', display: 'More', sections: [{ fields: [{ handle: 'other', display: 'Other field' }] }] },
]
async function render(groups: ReturnType<typeof groupFindings>) {
    vi.stubGlobal('__', (key: string) => key)
    const app = createSSRApp(ReviewFindings, { groups, norms: {}, busy: false, readOnly: false, canApprove: true })
    app.config.globalProperties.__ = __
    app.config.globalProperties.__n = (key: string) => key
    return renderToString(app)
}

it('starts with compact, escaped quotes grouped by tab and field instead of every detail and action', async () => {
    const groups = groupFindings({ analyzed_at: '', units: { title: { hash: 'hash', findings: [finding] } } }, tabs)
    const html = await render(groups)
    for (const text of [
        'Main',
        'Title',
        'categories.generic_claim',
        'severity.high',
        '&lt;img',
        'aria-expanded="false"',
    ])
        expect(html).toContain(text)
    for (const text of ['<img', finding.explanation, finding.suggestions[0], 'buttons.dismiss', 'buttons.approve'])
        expect(html).not.toContain(text)
})

it('gives identical findings in different fields distinct disclosure targets', async () => {
    const groups = groupFindings(
        {
            analyzed_at: '',
            units: {
                title: { hash: 'hash', findings: [finding] },
                other: { hash: 'hash', findings: [{ ...finding, path: 'other' }] },
            },
        },
        tabs,
    )
    const html = await render(groups)
    const targets = [...html.matchAll(/aria-controls="([^"]+)"/g)].map((match) => match[1])
    expect(targets).toHaveLength(2)
    expect(new Set(targets).size).toBe(2)
    for (const target of targets) expect(html).toContain(`id="${target}"`)
    expect(html.indexOf('Main')).toBeLessThan(html.indexOf('More'))
    expect(html).toContain('Other field')
})

it('renders ignored and approved rows through the same grouped review boundary', async () => {
    const approved = {
        ...finding,
        id: 'approved',
        quote: 'Approved text',
        approval: { user: 'editor', user_name: 'Editor', reason: 'Evidence checked', at: '2026-10-13T12:00:00Z' },
    }
    const result = {
        analyzed_at: '',
        units: {
            title: {
                hash: 'hash',
                findings: [finding, { ...finding, id: 'ignored', quote: 'Ignored text', dismissed: true }, approved],
            },
        },
    }
    const ignoredHtml = await render(groupFindings(result, tabs, 'ignored'))
    const approvedHtml = await render(groupFindings(result, tabs, 'approved'))
    expect(ignoredHtml).toContain('Ignored text')
    expect(ignoredHtml).not.toContain('Approved text')
    expect(approvedHtml).toContain('Approved text')
    expect(approvedHtml).not.toContain('Ignored text')
})
