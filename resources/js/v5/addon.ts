/**
 * Greenwashing Checker — Statamic 5 entry point (Vue 2.7 / Options API).
 *
 * No SFCs and no `@statamic/cms/*` imports: Statamic 5 has neither. The panel
 * is a plain object with a runtime template string (compiled by the Vue 2
 * template compiler Statamic ships), styled with core CP classes verified
 * against the v5 compiled CP stylesheet (`card`, `btn`, `btn-primary`,
 * `badge-pill`, `help-block`, `text-xs`).
 *
 * Scope on v5 is deliberately smaller than v6: status, Check/Retry, and a
 * review sheet (Statamic 5's own global `<stack>`) with the same information
 * design as the v6 stack — open/ignored switcher, compact rows grouped by
 * tab and field breadcrumb, one expanded row at a time. No Bard marks,
 * inline boxes, publish warning, or approvals — see README "Statamic 5".
 *
 * Analysis behaviour is shared with v6 through the framework-free modules
 * `logic.ts` (state machine, grouping, quote replacement) and `ai.ts`
 * (CP JSON + job polling). `store.ts` itself is Vue 3 `reactive()`-based, so
 * the v5 panel holds the same CheckerState in Vue 2 data and drives it with
 * the same `transition()` from `logic.ts`.
 */
import { AiKitError, cpUrl, pollJob, requestJson } from '../ai'
import {
    analysisSnapshot,
    checkerSetupState,
    groupFindings,
    highFindings,
    initialState,
    replaceQuote,
    transition,
    valueAt,
    type AnalysisResult,
    type CheckerState,
    type Finding,
    type PublishTab,
} from '../logic'

interface PublishState {
    blueprint: { handle: string; tabs: PublishTab[] }
    values: Record<string, unknown>
    reference?: string
    site: string
}

interface PanelSelf {
    $store: { state: { publish: Record<string, PublishState> }; dispatch(action: string, payload: unknown): void }
    $el: HTMLElement
    $refs: { reviewSheet?: HTMLElement }
    $nextTick(callback: () => void): void
    storeName: string
    meta: { collection: string | null; normsBySite: Record<string, Record<string, string>> }
    readOnly: boolean
    checker: CheckerState
    busy: boolean
    snapshot: string
    controller?: AbortController
    review: boolean
    reviewMode: string
    expanded: string | null
    reviewReturnFocus: HTMLElement | null
    publishState: PublishState
    values: Record<string, unknown>
    tabs: PublishTab[]
    site: string
    norms: Record<string, string>
    settings: { configured: boolean; checkOnBlur: boolean }
    setupState: 'entries_only' | 'not_configured' | null
    statusText: string
    openGroups: ReturnType<typeof groupFindings>
    ignoredGroups: ReturnType<typeof groupFindings>
    reviewGroups: ReturnType<typeof groupFindings>
    openCount: number
    ignoredCount: number
    send(event: Parameters<typeof transition>[1]): void
    message(error: unknown): string
    analyze(): Promise<void>
    dismiss(finding: Finding, dismissed?: boolean): Promise<void>
    apply(finding: Finding, suggestion: string): void
    jump(path: string): void
    canApply(finding: Finding): boolean
    findingKey(finding: Finding): string
    modeKeys(): string[]
    focusRow(index: number): void
    afterTransport(callback: () => void): void
    openReview(): void
    closedReview(): void
    reviewJump(path: string, close: () => void): void
    toggleFinding(finding: Finding): void
    t(key: string, replacements?: Record<string, string | number>): string
}

const APPLYABLE_TYPES = ['text', 'textarea', 'markdown']

const GreenwashingCheckerPanel = {
    name: 'GreenwashingCheckerPanelV5',

    // Statamic 5 fieldtypes receive the publish container's Vuex store name
    // via provide/inject; v6 uses injectPublishContext() instead.
    inject: ['storeName'],

    props: {
        value: { default: null },
        meta: { type: Object, required: true },
        config: { type: Object, default: () => ({}) },
        handle: { type: String, default: '' },
        readOnly: { type: Boolean, default: false },
    },

    data() {
        return {
            checker: initialState(),
            busy: false,
            snapshot: '',
            controller: undefined as AbortController | undefined,
            // Review sheet state: open flag, active switcher mode, the single
            // expanded row (`<path>:<id>`), and the element to refocus on close.
            review: false,
            reviewMode: 'open',
            expanded: null as string | null,
            reviewReturnFocus: null as HTMLElement | null,
        }
    },

    computed: {
        // Core's publish Field reads `fieldActions` off the fieldtype component
        // (normally provided by its Fieldtype mixin) and throws without it.
        fieldActions(): unknown[] {
            return []
        },
        publishState(): PublishState {
            const self = this as unknown as PanelSelf
            return self.$store.state.publish[self.storeName]
        },
        values(): Record<string, unknown> {
            return (this as unknown as PanelSelf).publishState.values
        },
        tabs(): PublishTab[] {
            return (this as unknown as PanelSelf).publishState.blueprint.tabs
        },
        site(): string {
            return (this as unknown as PanelSelf).publishState.site
        },
        norms(): Record<string, string> {
            const self = this as unknown as PanelSelf
            return self.meta.normsBySite[self.site] ?? {}
        },
        settings(): { configured: boolean; checkOnBlur: boolean } {
            return Statamic.$config.get('greenwashingChecker') as PanelSelf['settings']
        },
        setupState() {
            const self = this as unknown as PanelSelf
            return checkerSetupState(self.meta.collection, self.settings.configured)
        },
        openGroups() {
            const self = this as unknown as PanelSelf
            return groupFindings(self.checker.result, self.tabs, 'open')
        },
        ignoredGroups() {
            const self = this as unknown as PanelSelf
            return groupFindings(self.checker.result, self.tabs, 'ignored')
        },
        reviewGroups() {
            const self = this as unknown as PanelSelf
            return self.reviewMode === 'ignored' ? self.ignoredGroups : self.openGroups
        },
        openCount(): number {
            const self = this as unknown as PanelSelf
            return self.openGroups.reduce(
                (sum, tab) => sum + tab.fields.reduce((n, field) => n + field.findings.length, 0),
                0,
            )
        },
        ignoredCount(): number {
            const self = this as unknown as PanelSelf
            return self.ignoredGroups.reduce(
                (sum, tab) => sum + tab.fields.reduce((n, field) => n + field.findings.length, 0),
                0,
            )
        },
        statusText(): string {
            const self = this as unknown as PanelSelf
            if (self.checker.status === 'completed' && self.snapshot !== analysisSnapshot(self.values)) {
                return __('greenwashing-checker::messages.statuses.changed')
            }
            if (self.checker.status === 'completed') {
                const parts = [
                    self.openCount
                        ? __n('greenwashing-checker::messages.statuses.findings', self.openCount)
                        : __('greenwashing-checker::messages.statuses.none'),
                ]
                const high = highFindings(self.checker.result).length
                if (high) parts.push(__n('greenwashing-checker::messages.high_count', high))
                return parts.join(' · ')
            }
            return __('greenwashing-checker::messages.statuses.' + self.checker.status)
        },
    },

    created() {
        const self = this as unknown as PanelSelf
        self.snapshot = analysisSnapshot(self.values)
    },

    beforeDestroy() {
        ;(this as unknown as PanelSelf).controller?.abort()
    },

    methods: {
        t(key: string, replacements?: Record<string, string | number>): string {
            return __('greenwashing-checker::messages.' + key, replacements)
        },
        send(event: Parameters<typeof transition>[1]) {
            const self = this as unknown as PanelSelf
            self.checker = transition(self.checker, event)
        },
        message(error: unknown): string {
            const self = this as unknown as PanelSelf
            if (!(error instanceof AiKitError)) {
                console.error(error)
                return self.t('errors.unexpected')
            }
            const code =
                error.status === 403
                    ? 'forbidden'
                    : error.status === 404
                      ? 'not_found'
                      : error.code === 'validation'
                        ? 'invalid_values'
                        : error.code
            const key = 'greenwashing-checker::messages.errors.' + code
            const translated = __(key)
            return translated === key ? error.message : translated
        },
        async analyze() {
            const self = this as unknown as PanelSelf
            if (self.setupState) return
            self.controller?.abort()
            self.controller = new AbortController()
            const signal = self.controller.signal
            self.send({ type: 'start' })
            const request = self.checker.request
            try {
                // Publish inputs debounce Vuex updates; inspect committed values.
                await new Promise((resolve) => setTimeout(resolve, 151))
                if (signal.aborted) return
                const values = JSON.parse(analysisSnapshot(self.values)) as Record<string, unknown>
                self.snapshot = analysisSnapshot(values)
                const response = await requestJson<
                    { status: 'completed'; result: AnalysisResult } | { status: 'queued'; job: string; poll: string }
                >('POST', cpUrl('greenwashing-checker/analyze'), {
                    reference: self.publishState.reference ?? null,
                    collection: self.meta.collection,
                    blueprint: self.publishState.blueprint.handle,
                    site: self.site,
                    values,
                })
                if (signal.aborted) return
                if (response.status === 'completed') {
                    self.send({ type: 'complete', request, result: response.result })
                } else {
                    const job = await pollJob<AnalysisResult>(response.poll, { signal })
                    if (job.status === 'completed') self.send({ type: 'complete', request, result: job.result })
                }
            } catch (error) {
                if (!signal.aborted) self.send({ type: 'fail', request, error: self.message(error) })
            }
        },
        // Apply is limited to top-level text-like fields on v5: flat
        // `field_<handle>` ids and Vuex setFieldValue only address those.
        canApply(finding: Finding): boolean {
            const self = this as unknown as PanelSelf
            if (self.readOnly || finding.path.includes('.')) return false
            const field = self.tabs
                .flatMap((tab) => tab.sections.flatMap((section) => section.fields))
                .find((candidate) => candidate.handle === finding.path)
            return (
                !!field &&
                APPLYABLE_TYPES.includes(String(field.type)) &&
                typeof valueAt(self.values, finding.path) === 'string'
            )
        },
        apply(finding: Finding, suggestion: string) {
            const self = this as unknown as PanelSelf
            const value = valueAt(self.values, finding.path)
            const replacement = typeof value === 'string' ? replaceQuote(value, finding.quote, suggestion) : null
            if (replacement === null) {
                Statamic.$toast.error(self.t('errors.changed_text'))
                return
            }
            self.$store.dispatch(`publish/${self.storeName}/setFieldValue`, {
                handle: finding.path,
                value: replacement,
            })
            const index = self.modeKeys().indexOf(self.findingKey(finding))
            self.send({ type: 'remove', path: finding.path, id: finding.id })
            // Resolving a row removes its controls. Keep keyboard users at the
            // next finding instead of dropping them back on the page body.
            if (self.expanded === self.findingKey(finding)) self.expanded = null
            if (self.review) self.afterTransport(() => self.focusRow(index))
        },
        async dismiss(finding: Finding, dismissed = true) {
            const self = this as unknown as PanelSelf
            self.busy = true
            const request = self.checker.request
            let focusIndex = -1
            try {
                await requestJson('POST', cpUrl('greenwashing-checker/dismiss'), {
                    unit_hash: self.checker.result.units[finding.path].hash,
                    finding_id: finding.id,
                    dismissed,
                    reference: self.publishState.reference ?? null,
                    collection: self.meta.collection,
                    site: self.site,
                })
                if (request === self.checker.request) {
                    focusIndex = self.modeKeys().indexOf(self.findingKey(finding))
                    self.send({ type: dismissed ? 'dismiss' : 'restore', path: finding.path, id: finding.id })
                    if (self.expanded === self.findingKey(finding)) self.expanded = null
                }
            } catch (error) {
                Statamic.$toast.error(self.message(error))
            } finally {
                self.busy = false
                // Buttons are disabled while busy; move focus only once they work again.
                if (focusIndex !== -1 && self.review) self.afterTransport(() => self.focusRow(focusIndex))
            }
        },
        jump(path: string) {
            // v5 field ids are flat `field_<handle>`; nested paths cannot be
            // addressed unambiguously, so only top-level fields offer a jump.
            if (path.includes('.')) return
            // Text inputs carry the flat id on the control itself; markdown/Bard
            // only expose it via the wrapper's `publish-field__<handle>` class.
            const element =
                document.getElementById('field_' + path) ??
                document.querySelector('.publish-field__' + CSS.escape(path))
            if (!element) return
            const field = (element.closest('.form-group') as HTMLElement | null) ?? element
            try {
                Statamic.$reveal.element(field)
            } catch {
                // Statamic 5's tabs component can throw inside $reveal for fields
                // in the active tab; scrolling and focusing below is the fallback.
            }
            field.scrollIntoView({ behavior: 'smooth', block: 'center' })
            const control = element.matches('input, textarea, [contenteditable="true"]')
                ? element
                : // Prefer the visible editor surface: markdown wrappers also contain
                  // hidden file inputs that would swallow the focus.
                  field.querySelector<HTMLElement>(
                      'textarea:not(.sr-only), [contenteditable="true"], input:not(.sr-only):not(.hidden):not([type="hidden"]):not([type="file"])',
                  )
            control?.focus({ preventScroll: true })
        },
        findingKey(finding: Finding): string {
            return finding.path + ':' + finding.id
        },
        // Flat keys of the findings in the active switcher mode, in row order.
        modeKeys(): string[] {
            const self = this as unknown as PanelSelf
            return self.reviewGroups.flatMap((tab) =>
                tab.fields.flatMap((field) => field.findings.map((finding) => self.findingKey(finding))),
            )
        },
        focusRow(index: number) {
            const self = this as unknown as PanelSelf
            const sheet = self.$refs.reviewSheet
            const rows = sheet?.querySelectorAll<HTMLElement>('[data-gwc-review-toggle]') ?? []
            if (rows.length) {
                rows[Math.min(Math.max(index, 0), rows.length - 1)].focus()
                return
            }
            // The mode just ran empty: land on its switcher button, not the body.
            sheet?.querySelector<HTMLElement>('[data-gwc-review-modes] [aria-pressed="true"]')?.focus()
        },
        // Core's stack renders through portal-vue, which transports the
        // sheet DOM a tick after the panel render. Focusing after a single
        // tick can land on a node the transport then replaces, dropping the
        // user back on the page body; wait for the transported DOM instead.
        afterTransport(callback: () => void) {
            const self = this as unknown as PanelSelf
            self.$nextTick(() => self.$nextTick(callback))
        },
        toggleFinding(finding: Finding) {
            const self = this as unknown as PanelSelf
            const key = self.findingKey(finding)
            self.expanded = self.expanded === key ? null : key
        },
        openReview() {
            const self = this as unknown as PanelSelf
            self.reviewReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null
            self.reviewMode = 'open'
            self.review = true
            self.afterTransport(() => {
                self.$refs.reviewSheet?.querySelector<HTMLElement>('button')?.focus()
            })
        },
        // Core's stack emits `closed` after its fade; only then unmount and
        // hand focus back to the button that opened the sheet.
        closedReview() {
            const self = this as unknown as PanelSelf
            self.review = false
            self.expanded = null
            const target = self.reviewReturnFocus
            self.reviewReturnFocus = null
            self.$nextTick(() => {
                if (target?.isConnected) target.focus()
            })
        },
        // Go to field closes the sheet first; the jump focuses the field, so
        // the close handler must not steal focus back to the Review button.
        reviewJump(path: string, close: () => void) {
            const self = this as unknown as PanelSelf
            self.reviewReturnFocus = null
            close()
            self.jump(path)
        },
    },

    // Core v5 sidebar markup: the panel lives inside a `.card`-styled publish
    // sidebar section; buttons/badges use core CP utility classes only. The
    // sidebar stays a summary (status, counts, Check, Review all) because the
    // narrow publish column cannot fit finding details; those live in the
    // review sheet, mirroring the minimal v6 sidebar plus stack.
    // The sheet uses Statamic 5's own global `<stack>` (no import: core
    // registers it globally) with core v5 stack chrome: the relationship
    // selector's tree-view header bar (`bg-white dark:bg-dark-550 shadow`,
    // `text-xl` title, actions right) and footer bar (`border-t`,
    // `bg-gray-200 dark:bg-dark-500`), `slot-scope="{ close }"` like core's
    // own relationship selector, and `data-table` rows for findings.
    // Severity uses core's `little-dot` with red/orange (no colored badge
    // variants exist in v5 core); the text label stays for screen readers.
    template: /* html */ `
        <div class="gwc-v5">
        <section class="gwc-panel-v5" :aria-label="t('title')">
            <h3>{{ t('title') }}</h3>
            <template v-if="!setupState">
                <p role="status" aria-live="polite" class="text-sm">{{ statusText }}</p>
                <p v-if="checker.error" role="alert" class="text-sm" style="color: #c0392b">{{ checker.error }}</p>
                <div style="margin: 12px 0; display: flex; gap: 8px; flex-wrap: wrap">
                    <button
                        type="button"
                        class="btn btn-primary btn-sm"
                        :disabled="checker.status === 'checking'"
                        @click="analyze"
                    >
                        {{ t('buttons.' + (checker.status === 'failed' ? 'retry' : 'check')) }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-sm"
                        :disabled="!openCount && !ignoredCount"
                        @click="openReview"
                    >
                        {{ t('buttons.review') }}
                    </button>
                </div>
            </template>
            <div v-else role="status">
                <p class="text-sm">{{ t(setupState) }}</p>
                <a
                    v-if="setupState === 'not_configured'"
                    href="https://github.com/el-schneider/statamic-greenwashing-checker#configuration"
                    target="_blank"
                    rel="noopener noreferrer"
                    >{{ t('buttons.documentation') }}</a
                >
            </div>
            <p class="help-block" style="margin-top: 12px">{{ t('disclaimer') }}</p>
        </section>
        <stack v-if="review" name="greenwashing-review" @closed="closedReview">
            <div
                ref="reviewSheet"
                slot-scope="{ close }"
                class="h-full overflow-auto bg-white dark:bg-dark-600 relative"
                role="dialog"
                aria-modal="true"
                :aria-label="t('title')"
            >
                <div class="bg-white dark:bg-dark-550 shadow px-4 py-2 flex items-center">
                    <div class="flex-1">
                        <h2 class="text-xl font-bold">{{ t('title') }}</h2>
                        <p role="status" aria-live="polite" class="text-sm text-gray-700 dark:text-dark-150">
                            {{ statusText }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="btn btn-sm"
                        :disabled="checker.status === 'checking'"
                        @click="analyze"
                    >
                        {{ t('buttons.' + (checker.status === 'failed' ? 'retry' : 'check')) }}
                    </button>
                    <button
                        type="button"
                        class="btn-close ltr:ml-2 rtl:mr-2"
                        @click="close"
                        :aria-label="t('buttons.close')"
                    >&times;</button>
                </div>
                <div class="p-4">
                    <p v-if="checker.error" role="alert" class="text-sm text-red-500">{{ checker.error }}</p>
                    <div data-gwc-review-modes class="btn-group" role="group" :aria-label="t('title')">
                        <button
                            type="button"
                            class="btn btn-sm"
                            :class="{ active: reviewMode === 'open' }"
                            :aria-pressed="reviewMode === 'open' ? 'true' : 'false'"
                            @click="reviewMode = 'open'"
                        >
                            {{ t('review_open') }} ({{ openCount }})
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm"
                            :class="{ active: reviewMode === 'ignored' }"
                            :aria-pressed="reviewMode === 'ignored' ? 'true' : 'false'"
                            @click="reviewMode = 'ignored'"
                        >
                            {{ t('review_ignored') }} ({{ ignoredCount }})
                        </button>
                    </div>
                <div v-for="tab in reviewGroups" :key="tab.handle" class="mt-4">
                    <h3 class="text-sm text-gray-800 dark:text-dark-150" style="font-weight: 600">{{ tab.display }}</h3>
                    <div v-for="field in tab.fields" :key="field.path" class="card overflow-hidden p-0 mt-2">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>{{ field.breadcrumb }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="finding in field.findings">
                                    <tr :key="'row-' + finding.id">
                                        <td>
                                            <button
                                                type="button"
                                                class="cursor-pointer"
                                                style="display: block; width: 100%; text-align: start; padding: 0"
                                                data-gwc-review-toggle
                                                :aria-expanded="expanded === findingKey(finding) ? 'true' : 'false'"
                                                :aria-controls="'gwc-review-' + finding.path + '-' + finding.id"
                                                @click="toggleFinding(finding)"
                                            >
                                                <span
                                                    :class="
                                                        'little-dot ' +
                                                        (finding.severity === 'high' ? 'bg-red-500' : 'bg-orange-light')
                                                    "
                                                    aria-hidden="true"
                                                ></span>
                                                {{ ' ' }}<span class="text-gray-800 dark:text-dark-150" style="font-weight: 600">{{
                                                    t('categories.' + finding.category)
                                                }}</span>
                                                <span class="sr-only">{{ t('severity.' + finding.severity) }}</span>
                                                <span
                                                    style="display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 2px"
                                                    >{{ finding.quote }}</span
                                                >
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="expanded === findingKey(finding)" :key="'detail-' + finding.id">
                                        <td :id="'gwc-review-' + finding.path + '-' + finding.id">
                                            <p class="help-block">{{ norms[finding.category] }}</p>
                                            <p
                                                class="text-sm text-gray-800 dark:text-dark-150 mt-2"
                                                style="text-align: start"
                                            >
                                                &ldquo;{{ finding.quote }}&rdquo;
                                            </p>
                                            <p class="text-sm mt-2">{{ finding.explanation }}</p>
                                            <p v-if="!finding.anchored" class="help-block">{{ t('unclear_position') }}</p>
                                            <div v-for="suggestion in finding.suggestions" :key="suggestion" class="mt-2">
                                                <p class="text-sm">
                                                    <span class="text-gray-800 dark:text-dark-150" style="font-weight: 600"
                                                        >{{ t('suggestion') }}:</span
                                                    >
                                                    {{ suggestion }}
                                                    <button
                                                        v-if="canApply(finding)"
                                                        type="button"
                                                        class="btn btn-xs"
                                                        :disabled="!finding.anchored || busy"
                                                        @click="apply(finding, suggestion)"
                                                    >
                                                        {{ t('buttons.apply') }}
                                                    </button>
                                                </p>
                                            </div>
                                            <div class="mt-2 flex">
                                                <button
                                                    v-if="reviewMode === 'ignored'"
                                                    type="button"
                                                    class="btn btn-sm"
                                                    :disabled="busy"
                                                    @click="dismiss(finding, false)"
                                                >
                                                    {{ t('buttons.restore') }}
                                                </button>
                                                <button
                                                    v-else
                                                    type="button"
                                                    class="btn btn-sm"
                                                    :disabled="busy"
                                                    @click="dismiss(finding, true)"
                                                >
                                                    {{ t('buttons.dismiss') }}
                                                </button>
                                                <button
                                                    v-if="!finding.path.includes('.')"
                                                    type="button"
                                                    class="btn btn-sm ltr:ml-2 rtl:mr-2"
                                                    @click="reviewJump(finding.path, close)"
                                                >
                                                    {{ t('buttons.jump') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div v-if="reviewMode === 'open' && !openCount" class="mt-4 text-center">
                    <p class="text-sm" style="font-weight: 600">{{ t('review_empty_open') }}</p>
                    <p class="help-block">{{ t('review_empty_help') }}</p>
                </div>
                <div v-if="reviewMode === 'ignored' && !ignoredCount" class="mt-4 text-center">
                    <p class="text-sm" style="font-weight: 600">{{ t('review_empty_ignored') }}</p>
                </div>
                </div>
                <div
                    class="p-4 border-t dark:border-dark-200 flex items-center justify-between bg-gray-200 dark:bg-dark-500"
                >
                    <p role="status" aria-live="polite" class="text-sm text-gray-700 dark:text-dark-150">
                        {{ statusText }}
                    </p>
                    <p class="text-sm text-gray-700 dark:text-dark-150 ltr:ml-2 rtl:mr-2">{{ t('disclaimer') }}</p>
                </div>
            </div>
        </stack>
        </div>
    `,
}

Statamic.booting(() => {
    Statamic.$components.register('greenwashing_checker-fieldtype', GreenwashingCheckerPanel)
})
