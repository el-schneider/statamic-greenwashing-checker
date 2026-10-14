import { reactive } from 'vue'
import { initialState, transition, type Event } from './logic'

export const state = reactive(initialState())
export const overlay = reactive({
    review: false,
    inlineTarget: null as { path: string; id: string } | null,
    popover: null as { path: string; id: string; left: number; top: number } | null,
})
export function send(event: Event): void {
    Object.assign(state, transition(state, event))
}
export function openFindings(path: string) {
    return state.result.units[path]?.findings.filter((finding) => !finding.dismissed && !finding.approval) ?? []
}

export interface SavePayload {
    collection: string
    values?: Record<string, unknown>
    reference?: string
}
export const publishGuard: {
    current?: (event: 'saving' | 'publishing', payload: SavePayload) => Promise<void>
    saved?: (payload: SavePayload) => void
} = {}
