import type { Node } from 'prosemirror-model'

export interface Approval {
    reason: string
    user: string
    user_name: string
    at: string
}
export interface Finding {
    approval?: Approval | null
    id: string
    path: string
    quote: string
    anchored: boolean
    category: string
    severity: 'high' | 'medium'
    explanation: string
    suggestions: string[]
    dismissed: boolean
}
export interface AnalysisResult {
    units: Record<string, { hash: string; findings: Finding[] }>
    analyzed_at: string
}
export interface CheckerState {
    status: 'unchecked' | 'checking' | 'completed' | 'failed'
    result: AnalysisResult
    error: string | null
    request: number
}
export function initialState(): CheckerState {
    return { status: 'unchecked', result: { units: {}, analyzed_at: '' }, error: null, request: 0 }
}
export type Event =
    | { type: 'start' }
    | { type: 'complete'; request: number; result: AnalysisResult }
    | { type: 'fail'; request: number; error: string }
    | { type: 'approval'; path: string; id: string; approval: Approval | null }
    | { type: 'restore'; path: string; id: string }
    | { type: 'dismiss'; path: string; id: string }
    | { type: 'remove'; path: string; id: string }
    | { type: 'reset' }

export function transition(state: CheckerState, event: Event): CheckerState {
    if (event.type === 'reset') return { ...initialState(), request: state.request + 1 }
    if (event.type === 'start') return { ...state, status: 'checking', error: null, request: state.request + 1 }
    if (event.type === 'complete' || event.type === 'fail') {
        if (event.request !== state.request) return state
        return event.type === 'complete'
            ? { ...state, status: 'completed', result: event.result, error: null }
            : { ...state, status: 'failed', error: event.error }
    }
    const unit = state.result.units[event.path]
    if (!unit) return state
    const units = { ...state.result.units }
    for (const [path, current] of Object.entries(units)) {
        if (event.type === 'remove' ? path !== event.path : current.hash !== unit.hash) continue
        const findings =
            event.type === 'remove'
                ? current.findings.filter((f) => f.id !== event.id)
                : current.findings.map((f) =>
                      f.id === event.id
                          ? event.type === 'approval'
                              ? { ...f, approval: event.approval }
                              : { ...f, dismissed: event.type === 'dismiss' }
                          : f,
                  )
        units[path] = { ...current, findings }
    }
    return { ...state, result: { ...state.result, units } }
}

export function replaceQuote(value: string, quote: string, suggestion: string): string | null {
    if (!quote || !value.includes(quote)) return null
    return value.replace(quote, () => suggestion)
}

// Positions come from the live document, including inline marks and hard breaks.
// Search one prose textblock at a time; never descend into Bard sets.
export function quoteRange(doc: Node, quote: string): { from: number; to: number } | null {
    const needle = quote.replace(/\s+/g, ' ').trim()
    if (!needle) return null
    let range: { from: number; to: number } | null = null
    doc.descendants((node, pos) => {
        if (range || node.type.name === 'set') return false
        if (!node.isTextblock) return true
        let text = ''
        const positions: number[] = []
        node.descendants((child, offset) => {
            const content = child.isText ? child.text! : child.type.name === 'hardBreak' ? '\n' : ''
            for (let i = 0; i < content.length; i++) {
                const character = /\s/.test(content[i]) ? ' ' : content[i]
                if (character === ' ' && text.endsWith(' ')) continue
                text += character
                positions.push(pos + 1 + offset + i)
            }
        })
        const index = text.indexOf(needle)
        if (index !== -1) range = { from: positions[index], to: positions[index + needle.length - 1] + 1 }
        return false
    })
    return range
}

export function fieldPath(payload: { fieldPathPrefix?: string; handle: string }): string {
    return payload.fieldPathPrefix ? `${payload.fieldPathPrefix}.${payload.handle}` : payload.handle
}

// Matches Statamic's publish field IDs, including nested row indices.
export function fieldElementId(path: string): string {
    return 'field_' + path.replaceAll('.', '_')
}

export function findingFields(result: AnalysisResult): { path: string; severity: Finding['severity'] | null }[] {
    return Object.entries(result.units).flatMap(([path, unit]) => {
        const findings = unit.findings.filter((finding) => !finding.dismissed && !finding.approval)
        return unit.findings.length
            ? [
                  {
                      path,
                      severity: findings.length
                          ? findings.some((finding) => finding.severity === 'high')
                              ? 'high'
                              : 'medium'
                          : null,
                  },
              ]
            : []
    })
}

export function valueAt(values: Record<string, unknown>, path: string): unknown {
    return path.split('.').reduce<unknown>((value, key) => (value as Record<string, unknown>)?.[key], values)
}

export interface PublishTab {
    handle: string
    display: string
    sections: { fields: { handle: string; display?: string; [key: string]: unknown }[] }[]
}

// Numeric path segments identify visible rows. Labels belong to the blueprint, not the provider.
export function fieldBreadcrumb(path: string, tabs: PublishTab[]): string {
    function findField(value: unknown, handle: string): Record<string, unknown> | undefined {
        if (!value || typeof value !== 'object') return
        const field = value as Record<string, unknown>
        if (field.handle === handle) return field
        for (const child of Object.values(field)) {
            const found = findField(child, handle)
            if (found) return found
        }
    }
    let scope: unknown = tabs.flatMap((tab) => tab.sections.flatMap((section) => section.fields))
    return path
        .split('.')
        .filter((segment) => !['attrs', 'values'].includes(segment))
        .map((segment) => {
            if (/^\d+$/.test(segment)) return `#${Number(segment) + 1}`
            const field = findField(scope, segment)
            if (!field) return segment
            scope = field.field ?? field
            return String((scope as Record<string, unknown>).display || field.display || segment)
        })
        .join(' › ')
}

export function groupFindings(
    result: AnalysisResult,
    tabs: PublishTab[],
    mode: 'open' | 'ignored' | 'approved' = 'open',
) {
    return tabs
        .map((tab) => ({
            handle: tab.handle,
            display: tab.display,
            fields: Object.entries(result.units)
                .filter(([path]) =>
                    tab.sections.some((section) => section.fields.some((field) => field.handle === path.split('.')[0])),
                )
                .map(([path, unit]) => ({
                    path,
                    breadcrumb: fieldBreadcrumb(path, tabs),
                    findings: unit.findings.filter((finding) =>
                        mode === 'approved'
                            ? !!finding.approval
                            : !finding.approval && finding.dismissed === (mode === 'ignored'),
                    ),
                }))
                .filter((field) => field.findings.length),
        }))
        .filter((tab) => tab.fields.length)
}

export function highFindings(result: AnalysisResult, includeIgnored = false): Finding[] {
    return Object.values(result.units)
        .flatMap((unit) => unit.findings)
        .filter((f) => !f.approval && (includeIgnored || !f.dismissed) && f.severity === 'high')
}

export function shouldWarnPublish(
    event: 'saving' | 'publishing',
    values: Record<string, unknown> | undefined,
    revisions: boolean,
    autosave: boolean,
    mode: string,
): boolean {
    return (
        mode === 'warn' && (event === 'publishing' ? revisions : !revisions && !autosave && values?.published === true)
    )
}

// Changing publication state does not invalidate the text check.
export function analysisSnapshot(values: Record<string, unknown>): string {
    const { published: _published, greenwashing_checker: _panel, ...content } = values
    return JSON.stringify(content)
}

// A failed or slow provider check is a warning, never a reason to lose an editor's save.
export async function publishCheckWithinBudget(check: () => Promise<void>, budgetMs = 10000): Promise<boolean> {
    let timeout: ReturnType<typeof setTimeout> | undefined
    try {
        return await Promise.race([
            check().then(() => true),
            new Promise<boolean>((resolve) => {
                timeout = setTimeout(() => resolve(false), budgetMs)
            }),
        ])
    } finally {
        clearTimeout(timeout)
    }
}

export function publishCheckIsCurrent(state: CheckerState, snapshot: string, values: Record<string, unknown>): boolean {
    return state.status === 'completed' && snapshot === analysisSnapshot(values)
}

export function checkerSetupState(
    collection: string | null,
    configured: boolean,
): 'entries_only' | 'not_configured' | null {
    return collection === null ? 'entries_only' : configured ? null : 'not_configured'
}
