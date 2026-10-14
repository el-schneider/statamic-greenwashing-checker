import { describe, expect, it, vi } from 'vitest'
import { Schema, type Node } from 'prosemirror-model'
import {
    analysisSnapshot,
    publishCheckIsCurrent,
    publishCheckWithinBudget,
    fieldBreadcrumb,
    groupFindings,
    highFindings,
    shouldWarnPublish,
    type PublishTab,
    fieldPath,
    fieldElementId,
    findingFields,
    valueAt,
    initialState,
    quoteRange,
    replaceQuote,
    transition,
    type AnalysisResult,
    type Finding,
} from './logic'

const schema = new Schema({
    nodes: {
        doc: { content: 'block+' },
        paragraph: { group: 'block', content: 'inline*' },
        text: { group: 'inline' },
        hardBreak: { inline: true, group: 'inline' },
        set: { group: 'block', content: 'block*' },
    },
    marks: { bold: {} },
})
const p = (...content: Node[]) => schema.node('paragraph', null, content)
const text = (value: string) => schema.text(value)

describe('quoteRange', () => {
    it('finds the first occurrence across inline formatting with exact document positions', () => {
        const doc = schema.node('doc', null, [
            p(text('Unser '), schema.text('grüner', [schema.mark('bold')]), text(' Kaffee. grüner')),
        ])
        expect(quoteRange(doc, 'grüner Kaffee')).toEqual({ from: 7, to: 20 })
    })
    it('uses positions after earlier blocks and normalizes whitespace and hard breaks', () => {
        const doc = schema.node('doc', null, [
            p(text('Hi')),
            p(text('gut  fürs'), schema.node('hardBreak'), text(' Klima')),
        ])
        const range = quoteRange(doc, 'gut fürs Klima')
        expect(range).toEqual({ from: 5, to: 21 })
        expect(doc.textBetween(range!.from, range!.to, '', '\n')).toBe('gut  fürs\n Klima')
    })
    it('does not join separate blocks or inspect Bard sets', () => {
        const doc = schema.node('doc', null, [
            schema.node('set', null, [p(text('grün'))]),
            p(text('guter')),
            p(text('Kaffee')),
        ])
        expect(quoteRange(doc, 'grün')).toBeNull()
        expect(quoteRange(doc, 'guter Kaffee')).toBeNull()
        expect(quoteRange(doc, '')).toBeNull()
    })
    it('rejects a quote removed from the live document', () => {
        expect(quoteRange(schema.node('doc', null, [p(text('Kaffee'))]), 'nachhaltiger Kaffee')).toBeNull()
    })
})

describe('replaceQuote', () => {
    it('replaces only the first occurrence and treats dollar replacement tokens as plain text', () => {
        expect(replaceQuote('grün und grün', 'grün', '$& Kaffee')).toBe('$& Kaffee und grün')
    })
    it('rejects missing and empty quotes without changing text', () => {
        expect(replaceQuote('Kaffee', 'grün', 'fair')).toBeNull()
        expect(replaceQuote('Kaffee', '', 'fair')).toBeNull()
    })
})

const finding: Finding = {
    id: 'one',
    path: 'blocks.0.body',
    quote: 'grün',
    anchored: true,
    category: 'generic_claim',
    severity: 'high',
    explanation: 'Risk',
    suggestions: ['Kaffee'],
    dismissed: false,
}
const result: AnalysisResult = {
    units: { 'blocks.0.body': { hash: 'hash', findings: [finding] } },
    analyzed_at: 'today',
}
describe('inline finding fields', () => {
    it('targets core IDs for top-level, replicator, grid and group paths', () => {
        expect(fieldElementId('title')).toBe('field_title')
        expect(fieldElementId('blocks.2.body')).toBe('field_blocks_2_body')
        expect(fieldElementId('rows.1.details.text')).toBe('field_rows_1_details_text')
        expect(fieldElementId('sections.0.body.1.attrs.values.quote')).toBe(
            'field_sections_0_body_1_attrs_values_quote',
        )
    })
    it('keeps inline fields for open and ignored findings and removes resolved fields', () => {
        const mixed: AnalysisResult = {
            ...result,
            units: {
                title: { hash: 'title', findings: [{ ...finding, path: 'title', dismissed: true }] },
                empty: { hash: 'empty', findings: [] },
                'blocks.0.body': {
                    hash: 'body',
                    findings: [finding, { ...finding, id: 'two', severity: 'medium' }],
                },
                'rows.1.text': { hash: 'row', findings: [{ ...finding, path: 'rows.1.text', severity: 'medium' }] },
            },
        }
        expect(findingFields(mixed)).toEqual([
            { path: 'title', severity: null },
            { path: 'blocks.0.body', severity: 'high' },
            { path: 'rows.1.text', severity: 'medium' },
        ])
        const state = { ...initialState(), result: mixed }
        const dismissed = transition(state, { type: 'dismiss', path: finding.path, id: finding.id })
        expect(findingFields(dismissed.result)[1]).toEqual({ path: 'blocks.0.body', severity: 'medium' })
        const removed = transition(dismissed, { type: 'remove', path: finding.path, id: 'two' })
        expect(findingFields(removed.result)).toEqual([
            { path: 'title', severity: null },
            { path: 'blocks.0.body', severity: null },
            { path: 'rows.1.text', severity: 'medium' },
        ])
    })
})

describe('checker transitions', () => {
    it('shows results and clears errors on a successful retry', () => {
        let state = transition(initialState(), { type: 'start' })
        state = transition(state, { type: 'fail', request: state.request, error: 'Network error' })
        expect(state.status).toBe('failed')
        state = transition(state, { type: 'start' })
        expect(state.error).toBeNull()
        state = transition(state, { type: 'complete', request: state.request, result })
        expect(state.status).toBe('completed')
        expect(state.result).toEqual(result)
    })
    it('ignores superseded success and failure responses, including after changing entry', () => {
        const first = transition(initialState(), { type: 'start' })
        const next = transition(first, { type: 'start' })
        expect(transition(next, { type: 'complete', request: first.request, result })).toEqual(next)
        expect(transition(next, { type: 'fail', request: first.request, error: 'late' })).toEqual(next)
        const reset = transition(next, { type: 'reset' })
        expect(reset.status).toBe('unchecked')
        expect(reset.result.units).toEqual({})
        expect(transition(reset, { type: 'complete', request: next.request, result })).toEqual(reset)
    })
    it('keeps dismissed findings for the toggle and removes applied findings without mutating the server result', () => {
        const state = transition(transition(initialState(), { type: 'start' }), {
            type: 'complete',
            request: 1,
            result,
        })
        const dismissed = transition(state, { type: 'dismiss', path: finding.path, id: finding.id })
        expect(dismissed.result.units[finding.path].findings[0].dismissed).toBe(true)
        expect(result.units[finding.path].findings[0].dismissed).toBe(false)
        expect(
            transition(state, { type: 'remove', path: finding.path, id: finding.id }).result.units[finding.path]
                .findings,
        ).toEqual([])
    })
    it('dismisses identical cached text at every path but applies a replacement only at its edited path', () => {
        const repeated = {
            ...result,
            units: { ...result.units, teaser: { hash: 'hash', findings: [{ ...finding, path: 'teaser' }] } },
        }
        const state = transition(transition(initialState(), { type: 'start' }), {
            type: 'complete',
            request: 1,
            result: repeated,
        })
        const dismissed = transition(state, { type: 'dismiss', path: finding.path, id: finding.id })
        expect(dismissed.result.units.teaser.findings[0].dismissed).toBe(true)
        expect(dismissed.result.units[finding.path].findings[0].dismissed).toBe(true)
        const applied = transition(state, { type: 'remove', path: finding.path, id: finding.id })
        expect(applied.result.units[finding.path].findings).toEqual([])
        expect(applied.result.units.teaser.findings).toHaveLength(1)
    })
    it('combines nested prefixes with handles without losing replicator row indices', () => {
        expect(fieldPath({ fieldPathPrefix: 'blocks.2', handle: 'body' })).toBe('blocks.2.body')
        expect(fieldPath({ handle: 'body' })).toBe('body')
    })
})

it('resolves full Bard set paths for suggestion replacement inside nested replicators', () => {
    const values = {
        builder: [
            { type: 'paragraph' },
            { type: 'set', attrs: { values: { type: 'cards', items: [{ type: 'card', copy: 'grün' }] } } },
        ],
    }
    const path = 'builder.1.attrs.values.items.0.copy'
    expect(valueAt(values, path)).toBe('grün')
    expect(fieldElementId(path)).toBe('field_builder_1_attrs_values_items_0_copy')
    expect(fieldPath({ fieldPathPrefix: 'builder.1.attrs.values.items.0', handle: 'copy' })).toBe(path)
    expect(replaceQuote(valueAt(values, path) as string, 'grün', 'Jacke')).toBe('Jacke')
})

const tabs: PublishTab[] = [
    { handle: 'main', display: 'Inhalt', sections: [{ fields: [{ handle: 'title', display: 'Titel' }] }] },
    {
        handle: 'blocks',
        display: 'Bausteine',
        sections: [
            {
                fields: [
                    {
                        handle: 'blocks',
                        display: 'Abschnitte',
                        sets: [
                            {
                                fields: [
                                    {
                                        handle: 'body',
                                        display: 'Text',
                                        sets: [{ fields: [{ handle: 'quote', display: 'Zitat' }] }],
                                    },
                                ],
                            },
                        ],
                    },
                ],
            },
        ],
    },
]
it('groups nested findings in blueprint tab order, hides dismissed fields and keeps field breadcrumbs', () => {
    const r = {
        ...result,
        units: {
            ...result.units,
            title: { hash: 'title', findings: [{ ...finding, path: 'title', dismissed: true }] },
        },
    }
    expect(groupFindings(r, tabs).map((tab) => [tab.display, tab.fields[0].breadcrumb])).toEqual([
        ['Bausteine', 'Abschnitte › #1 › Text'],
    ])
    expect(groupFindings(r, tabs, 'ignored').map((tab) => tab.display)).toEqual(['Inhalt'])
    expect(fieldBreadcrumb('blocks.0.body.1.attrs.values.quote', tabs)).toBe('Abschnitte › #1 › Text › #2 › Zitat')
})
it('warns only for explicit publication, never working copies, drafts, autosaves or off mode', () => {
    expect(shouldWarnPublish('saving', { published: true }, false, false, 'warn')).toBe(true)
    expect(shouldWarnPublish('saving', { published: false }, false, false, 'warn')).toBe(false)
    expect(shouldWarnPublish('saving', { published: true }, true, false, 'warn')).toBe(false)
    expect(shouldWarnPublish('saving', { published: true }, false, true, 'warn')).toBe(false)
    expect(shouldWarnPublish('publishing', undefined, true, true, 'warn')).toBe(true)
    expect(shouldWarnPublish('publishing', undefined, true, false, 'off')).toBe(false)
    expect(
        highFindings({
            ...result,
            units: {
                a: {
                    hash: 'a',
                    findings: [finding, { ...finding, dismissed: true }, { ...finding, severity: 'medium' }],
                },
            },
        }),
    ).toEqual([finding])
})
it('invalidates results when nested text changes but not when publication state changes', () => {
    expect(analysisSnapshot({ blocks: [{ copy: 'grün' }], published: true })).toBe(
        analysisSnapshot({ blocks: [{ copy: 'grün' }], published: false }),
    )
    expect(analysisSnapshot({ blocks: [{ copy: 'grün' }] })).not.toBe(
        analysisSnapshot({ blocks: [{ copy: 'Kaffee' }] }),
    )
})

it('does not warn from old HIGH results after provider failure or from missing or changed results', () => {
    const values = { title: 'grün' }
    const snapshot = analysisSnapshot(values)
    const completed = { ...initialState(), status: 'completed' as const, result }
    expect(publishCheckIsCurrent(completed, snapshot, values)).toBe(true)
    expect(publishCheckIsCurrent(completed, snapshot, { title: 'Kaffee' })).toBe(false)
    expect(publishCheckIsCurrent(initialState(), snapshot, values)).toBe(false)
    const started = transition(completed, { type: 'start' })
    const failed = transition(started, {
        type: 'fail',
        request: started.request,
        error: 'Provider rejected credentials',
    })
    expect(highFindings(failed.result)).toHaveLength(1)
    expect(publishCheckIsCurrent(failed, snapshot, values)).toBe(false)
})
it('releases the publish check at the budget even if a sync queue HTTP response never returns', async () => {
    vi.useFakeTimers()
    try {
        const pending = publishCheckWithinBudget(() => new Promise<void>(() => {}))
        await vi.advanceTimersByTimeAsync(9999)
        let released = false
        void pending.then(() => {
            released = true
        })
        expect(released).toBe(false)
        await vi.advanceTimersByTimeAsync(1)
        expect(await pending).toBe(false)
        expect(vi.getTimerCount()).toBe(0)
        expect(await publishCheckWithinBudget(() => Promise.resolve())).toBe(true)
        expect(vi.getTimerCount()).toBe(0)
    } finally {
        vi.useRealTimers()
    }
})
it('keeps duplicate field handles scoped to their own parent in breadcrumbs', () => {
    const duplicate: PublishTab[] = [
        {
            handle: 'main',
            display: 'Main',
            sections: [
                {
                    fields: [
                        { handle: 'body', display: 'Beschreibung' },
                        {
                            handle: 'blocks',
                            display: 'Blöcke',
                            fields: [{ handle: 'body', field: { display: 'Text' } }],
                        },
                    ],
                },
            ],
        },
    ]
    expect(fieldBreadcrumb('body', duplicate)).toBe('Beschreibung')
    expect(fieldBreadcrumb('blocks.0.body', duplicate)).toBe('Blöcke › #1 › Text')
})

it('keeps ignored-only inline fields neutral and restores identical text at every path', () => {
    const ignored = {
        ...result,
        units: {
            'blocks.0.body': { hash: 'hash', findings: [{ ...finding, dismissed: true }] },
            teaser: { hash: 'hash', findings: [{ ...finding, path: 'teaser', dismissed: true }] },
        },
    }
    expect(findingFields(ignored)).toEqual([
        { path: 'blocks.0.body', severity: null },
        { path: 'teaser', severity: null },
    ])
    const restored = transition(
        { ...initialState(), result: ignored },
        { type: 'restore', path: finding.path, id: finding.id },
    )
    expect(restored.result.units['blocks.0.body'].findings[0].dismissed).toBe(false)
    expect(restored.result.units.teaser.findings[0].dismissed).toBe(false)
    expect(ignored.units.teaser.findings[0].dismissed).toBe(true)
})
it('includes ignored HIGH findings in publishing but excludes them from open HIGH badges', () => {
    const mixed = {
        ...result,
        units: {
            one: {
                hash: 'a',
                findings: [
                    finding,
                    { ...finding, id: 'ignored-high', dismissed: true },
                    { ...finding, id: 'ignored-medium', dismissed: true, severity: 'medium' as const },
                ],
            },
        },
    }
    expect(highFindings(mixed).map((f) => f.id)).toEqual(['one'])
    expect(highFindings(mixed, true).map((f) => f.id)).toEqual(['one', 'ignored-high'])
})

it('identifies a manually placed non-entry checker before any request', async () => {
    const { checkerSetupState } = await import('./logic')
    expect(checkerSetupState(null, true)).toBe('entries_only')
    expect(checkerSetupState('products', false)).toBe('not_configured')
    expect(checkerSetupState('products', true)).toBeNull()
})

it('excludes approved risks from open groups and publication but retains their documented group', () => {
    const approved = {
        id: 'approved',
        path: 'title',
        quote: 'grün',
        anchored: true,
        category: 'generic_claim',
        severity: 'high' as const,
        explanation: 'Risk',
        suggestions: [],
        dismissed: true,
        approval: {
            reason: '<img src=x onerror=alert(1)>',
            user: 'user',
            user_name: 'Editor',
            at: '2026-10-13T12:00:00Z',
        },
    }
    const result = { units: { title: { hash: 'hash', findings: [approved] } }, analyzed_at: '' }
    const tabs = [{ handle: 'main', display: 'Main', sections: [{ fields: [{ handle: 'title' }] }] }]
    expect(highFindings(result, true)).toEqual([])
    expect(groupFindings(result, tabs)).toEqual([])
    expect(groupFindings(result, tabs, 'ignored')).toEqual([])
    expect(groupFindings(result, tabs, 'approved')[0].fields[0].findings).toEqual([approved])
    expect(findingFields(result)).toEqual([{ path: 'title', severity: null }])
})

it('shares approval and revocation across identical text paths without erasing ignore state', () => {
    const finding = {
        id: 'same',
        path: 'title',
        quote: 'grün',
        anchored: true,
        category: 'generic_claim',
        severity: 'high' as const,
        explanation: 'Risk',
        suggestions: [],
        dismissed: true,
    }
    const current = {
        ...initialState(),
        result: {
            units: {
                title: { hash: 'same-text', findings: [finding] },
                teaser: { hash: 'same-text', findings: [{ ...finding, path: 'teaser' }] },
            },
            analyzed_at: '',
        },
    }
    const approval = { reason: 'Reviewed', user: 'owner', user_name: 'Owner', at: '2026-10-13T12:00:00Z' }
    const approved = transition(current, { type: 'approval', path: 'title', id: 'same', approval })
    expect(approved.result.units.teaser.findings[0].approval).toEqual(approval)
    const revoked = transition(approved, { type: 'approval', path: 'teaser', id: 'same', approval: null })
    expect(revoked.result.units.title.findings[0]).toMatchObject({ approval: null, dismissed: true })
})
