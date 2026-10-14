<script setup lang="ts">
import {
    computed,
    createApp,
    getCurrentInstance,
    h,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
    type App,
    type Ref,
} from 'vue'
import {
    Badge,
    Button,
    Description,
    Heading,
    Modal,
    Stack,
    StackContent,
    StackHeader,
    StackFooter,
    Tabs,
    TabList,
    TabTrigger,
    TabContent,
    Textarea,
    injectPublishContext,
} from '@statamic/cms/ui'
import { AiKitError, cpUrl, pollJob, requestJson } from './ai'
import FindingCard from './FindingCard.vue'
import ReviewFindings from './ReviewFindings.vue'
import InlineFindings from './InlineFindings.vue'
import {
    analysisSnapshot,
    checkerSetupState,
    fieldBreadcrumb,
    publishCheckWithinBudget,
    publishCheckIsCurrent,
    groupFindings,
    highFindings,
    shouldWarnPublish,
    type PublishTab,
    fieldElementId,
    findingFields,
    quoteRange,
    replaceQuote,
    valueAt,
    type AnalysisResult,
    type Finding,
    type Approval,
} from './logic'
import { editors } from './bard'
import { openFindings, overlay, send, state, publishGuard, type SavePayload } from './store'

const props = defineProps<{
    meta: {
        collection: string | null
        revisions: boolean
        autosave: boolean
        normsBySite: Record<string, Record<string, string>>
    }
}>()
interface PublishContext {
    values: Ref<Record<string, unknown>>
    blueprint: Ref<{ handle: string; tabs: PublishTab[] }>
    reference: Ref<string | undefined>
    site: Ref<string>
    readOnly: Ref<boolean>
    setFieldValue(path: string, value: unknown): void
}
const context = (injectPublishContext as unknown as () => PublishContext)()
const norms = computed(() => props.meta.normsBySite[context.site.value])
const settings = Statamic.$config.get('greenwashingChecker') as {
    configured: boolean
    canApprove: boolean
    checkOnBlur: boolean
    onPublish: string
}
const setupState = computed(() => checkerSetupState(props.meta.collection, settings.configured))
const approvalTarget = ref<Finding | null>(null)
const approvalReason = ref('')
const root = ref<HTMLElement>()
const popover = ref<HTMLElement>()
const busy = ref(false)
const groups = computed(() => groupFindings(state.result, context.blueprint.value.tabs))
const approvedGroups = computed(() => groupFindings(state.result, context.blueprint.value.tabs, 'approved'))
const approvedCount = computed(
    () =>
        Object.values(state.result.units)
            .flatMap((unit) => unit.findings)
            .filter((f) => f.approval).length,
)
const ignoredGroups = computed(() => groupFindings(state.result, context.blueprint.value.tabs, 'ignored'))
const tabCounts = computed(() =>
    groupFindings(state.result, context.blueprint.value.tabs).map((tab) => ({
        ...tab,
        count: tab.fields.reduce((sum, field) => sum + field.findings.length, 0),
    })),
)
const highCount = computed(() => highFindings(state.result).length)
const count = computed(() => Object.keys(state.result.units).reduce((sum, path) => sum + openFindings(path).length, 0))
const dismissed = computed(
    () =>
        Object.values(state.result.units)
            .flatMap((unit) => unit.findings)
            .filter((f) => f.dismissed && !f.approval).length,
)
const reviewMode = ref('open')
const reviewSections = computed(() => [
    { mode: 'open', count: count.value, groups: groups.value },
    { mode: 'ignored', count: dismissed.value, groups: ignoredGroups.value },
    { mode: 'approved', count: approvedCount.value, groups: approvedGroups.value },
])
const popoverFinding = computed(() =>
    overlay.popover ? openFindings(overlay.popover.path).find((f) => f.id === overlay.popover?.id) : undefined,
)
const status = computed(() => {
    if (state.status === 'completed' && snapshot !== analysisSnapshot(context.values.value))
        return __('greenwashing-checker::messages.statuses.changed')
    if (state.status === 'completed') {
        const parts = [
            count.value
                ? __n('greenwashing-checker::messages.statuses.findings', count.value)
                : __('greenwashing-checker::messages.statuses.none'),
        ]
        if (highCount.value) parts.push(__n('greenwashing-checker::messages.high_count', highCount.value))
        if (dismissed.value) parts.push(__n('greenwashing-checker::messages.statuses.ignored', dismissed.value))
        if (approvedCount.value)
            parts.push(__n('greenwashing-checker::messages.statuses.approved', approvedCount.value))
        return parts.join(' · ')
    }
    return __('greenwashing-checker::messages.statuses.' + state.status)
})
let controller: AbortController | undefined
let debounce: ReturnType<typeof setTimeout> | undefined
let snapshot = ''
let form: HTMLElement | undefined
let reviewReturnFocus: HTMLElement | null = null
const appContext = getCurrentInstance()!.appContext
const collapsedFields = ref<Record<string, boolean>>({})
const inlineApps = new Map<string, { app: App; container: HTMLElement; field: HTMLElement; ignoredOnly: boolean }>()
let observer: MutationObserver | undefined
let frame: number | undefined
let missingFieldLogged = false
function removeInline(path: string) {
    const mounted = inlineApps.get(path)!
    mounted.app.unmount()
    mounted.container.remove()
    inlineApps.delete(path)
}
function clearInline() {
    if (frame !== undefined) cancelAnimationFrame(frame)
    frame = undefined
    for (const path of inlineApps.keys()) removeInline(path)
    collapsedFields.value = {}
}
function reconcileInline() {
    frame = undefined
    if (!form) return
    const fields = findingFields(state.result)
    const targets = new Map(
        fields.map(({ path }) => [path, fieldElement(path)?.closest<HTMLElement>('.form-group, td.grid-cell')]),
    )
    for (const [path, mounted] of inlineApps) {
        if (targets.get(path) !== mounted.field || !form.contains(mounted.container)) removeInline(path)
    }
    for (const { path, severity } of fields) {
        const field = targets.get(path)
        if (!field || !form.contains(field)) {
            if (!missingFieldLogged) {
                console.debug('Greenwashing Checker: field not mounted; findings remain in the review stack.')
                missingFieldLogged = true
            }
            continue
        }
        const ignoredOnly = severity === null
        const mounted = inlineApps.get(path)
        if (ignoredOnly && !mounted?.ignoredOnly) collapsedFields.value[path] = true
        if (mounted) {
            mounted.ignoredOnly = ignoredOnly
            continue
        }
        const container = document.createElement('div')
        container.dataset.gwdField = path
        container.className = 'gwc-inline-host'
        field.append(container)
        const app = createApp({
            render: () =>
                h(InlineFindings, {
                    path,
                    norms: norms.value,
                    busy: busy.value,
                    readOnly: context.readOnly.value,
                    canApprove: settings.canApprove,
                    onApprove: beginApproval,
                    onRevoke: revoke,
                    collapsed: collapsedFields.value[path] !== false,
                    onToggle: () => {
                        collapsedFields.value[path] = collapsedFields.value[path] === false
                    },
                    onApply: apply,
                    onDismiss: dismiss,
                    onRestore: (finding: Finding) => dismiss(finding, false),
                }),
        })
        // Native Statamic components need the CP's globals and root providers.
        Object.assign(app.config.globalProperties, appContext.config.globalProperties)
        for (const key of Reflect.ownKeys(appContext.provides)) app.provide(key, appContext.provides[key])
        inlineApps.set(path, { app, container, field, ignoredOnly })
        app.mount(container)
    }
}
function scheduleInline() {
    if (frame === undefined) frame = requestAnimationFrame(reconcileInline)
}
watch(() => state.result, scheduleInline, { deep: true, flush: 'post' })
function reset() {
    clearInline()
    controller?.abort()
    clearTimeout(debounce)
    send({ type: 'reset' })
    overlay.popover = null
    overlay.inlineTarget = null
    overlay.review = false
    approvalTarget.value = null
    snapshot = analysisSnapshot(context.values.value)
}
watch(() => [context.reference.value, context.site.value], reset, { immediate: true })

function message(error: unknown): string {
    if (!(error instanceof AiKitError)) {
        console.error(error)
        return __('greenwashing-checker::messages.errors.unexpected')
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
}
async function analyze(budget?: number) {
    if (setupState.value) return
    clearTimeout(debounce)
    controller?.abort()
    controller = new AbortController()
    const currentController = controller
    const signal = currentController.signal
    send({ type: 'start' })
    const request = state.request
    const timeout = budget
        ? setTimeout(() => {
              currentController.abort()
              send({ type: 'fail', request, error: __('greenwashing-checker::messages.errors.timeout') })
          }, budget)
        : undefined
    try {
        // Publish inputs debounce context updates by 150ms; inspect the committed value, not the last keystroke.
        await new Promise((resolve) => setTimeout(resolve, 151))
        if (signal.aborted) return
        const values = JSON.parse(analysisSnapshot(context.values.value))
        snapshot = analysisSnapshot(values)
        const response = await requestJson<
            { status: 'completed'; result: AnalysisResult } | { status: 'queued'; job: string; poll: string }
        >('POST', cpUrl('greenwashing-checker/analyze'), {
            reference: context.reference.value ?? null,
            collection: props.meta.collection,
            blueprint: context.blueprint.value.handle,
            site: context.site.value,
            values,
        })
        if (signal.aborted) return
        if (response.status === 'completed') send({ type: 'complete', request, result: response.result })
        else {
            const job = await pollJob<AnalysisResult>(response.poll, { signal })
            if (job.status === 'completed') send({ type: 'complete', request, result: job.result })
        }
    } catch (error) {
        if (!signal.aborted) send({ type: 'fail', request, error: message(error) })
    } finally {
        clearTimeout(timeout)
    }
}
function onBlur(event: FocusEvent) {
    if (setupState.value || !settings.checkOnBlur || !(event.target instanceof Element)) return
    if (root.value?.contains(event.target) || !event.target.closest('[id^="field_"]')) return
    if (analysisSnapshot(context.values.value) === snapshot) return
    clearTimeout(debounce)
    debounce = setTimeout(() => void analyze(), 1000)
}
function fieldElement(path: string) {
    return form?.querySelector<HTMLElement>('#' + CSS.escape(fieldElementId(path)))
}
async function jump(path: string, id?: string) {
    overlay.review = false
    collapsedFields.value[path] = false
    const element = fieldElement(path)
    if (!element) return
    const field = element.closest<HTMLElement>('.form-group, td.grid-cell') ?? element
    // Core reveal activates publish tabs and expands every containing replicator/Bard set.
    Statamic.$reveal.element(field)
    await nextTick()
    // Set reveals animate for 250ms; a revealed grid may also replace its stacked controls with table cells.
    await new Promise((resolve) => setTimeout(resolve, 300))
    const revealed = fieldElement(path)
    if (!revealed) return
    const target = revealed.closest<HTMLElement>('.form-group, td.grid-cell') ?? revealed
    Statamic.$reveal.element(target)
    await nextTick()
    const control = revealed.matches('input:not(.sr-only), textarea:not(.sr-only), [contenteditable="true"]')
        ? revealed
        : target.querySelector<HTMLElement>('input:not(.sr-only), textarea:not(.sr-only), [contenteditable="true"]')
    // Statamic's textarea can have an own `focus` prop that shadows the native method.
    if (control) HTMLElement.prototype.focus.call(control, { preventScroll: true })
    reconcileInline()
    if (id) overlay.inlineTarget = { path, id }
}
function closePopover() {
    const path = overlay.popover?.path
    overlay.popover = null
    if (path) editors.get(path)?.commands.focus()
}
function apply(finding: Finding, suggestion: string) {
    if (context.readOnly.value) return
    const editor = editors.get(finding.path)
    let applied = false
    if (editor) {
        const range = quoteRange(editor.state.doc, finding.quote)
        if (range) applied = editor.chain().focus().insertContentAt(range, { type: 'text', text: suggestion }).run()
    } else {
        const value = valueAt(context.values.value, finding.path)
        const replacement = typeof value === 'string' ? replaceQuote(value, finding.quote, suggestion) : null
        if (replacement !== null) {
            context.setFieldValue(finding.path, replacement)
            applied = true
        }
    }
    if (!applied) {
        Statamic.$toast.error(__('greenwashing-checker::messages.errors.changed_text'))
        return
    }
    send({ type: 'remove', path: finding.path, id: finding.id })
    closePopover()
}
async function dismiss(finding: Finding, dismissed = true) {
    busy.value = true
    const request = state.request
    try {
        await requestJson('POST', cpUrl('greenwashing-checker/dismiss'), {
            unit_hash: state.result.units[finding.path].hash,
            finding_id: finding.id,
            dismissed,
            reference: context.reference.value ?? null,
            collection: props.meta.collection,
            site: context.site.value,
        })
        if (request === state.request)
            send({ type: dismissed ? 'dismiss' : 'restore', path: finding.path, id: finding.id })
        closePopover()
    } catch (error) {
        Statamic.$toast.error(message(error))
    } finally {
        busy.value = false
    }
}
function beginApproval(finding: Finding) {
    if (!settings.canApprove || context.readOnly.value) return
    approvalReason.value = ''
    approvalTarget.value = finding
    closePopover()
    nextTick(() => document.querySelector<HTMLTextAreaElement>('.gwc-approval textarea')?.focus())
}
async function setApproval(finding: Finding, reason: string | null) {
    busy.value = true
    const request = state.request
    try {
        const response = await requestJson<{ approval: Approval | null }>(
            reason === null ? 'DELETE' : 'POST',
            cpUrl('greenwashing-checker/approve'),
            {
                reference: context.reference.value ?? null,
                collection: props.meta.collection,
                site: context.site.value,
                unit_hash: state.result.units[finding.path].hash,
                finding_id: finding.id,
                ...(reason === null ? {} : { reason }),
            },
        )
        if (request === state.request)
            send({ type: 'approval', path: finding.path, id: finding.id, approval: response.approval })
        approvalTarget.value = null
    } catch (error) {
        Statamic.$toast.error(message(error))
    } finally {
        busy.value = false
    }
}
function revoke(finding: Finding) {
    return setApproval(finding, null)
}
function viewportChanged(event: Event) {
    // Capture also sees scrolling inside the popover itself, which must not close it.
    if (event.target instanceof Node && popover.value?.contains(event.target)) return
    if (overlay.popover) closePopover()
}
function outside(event: PointerEvent) {
    if (!overlay.popover || !(event.target instanceof Element)) return
    if (!popover.value?.contains(event.target) && !event.target.closest('[data-gwc-id]')) closePopover()
}
function focusReview() {
    document.querySelector<HTMLElement>('.gwc-review button')?.focus()
}
function trap(event: KeyboardEvent, selector: string) {
    const buttons = Array.from(
        document
            .querySelector(selector)
            ?.querySelectorAll<HTMLElement>(
                'button:not(:disabled), a[href], input:not(:disabled), textarea:not(:disabled), summary',
            ) ?? [],
    ).filter((element) => {
        // Closed details can retain layout rectangles for controls that cannot receive focus.
        const closed = element.closest('details:not([open])')
        return (
            element.getClientRects().length > 0 &&
            (!closed || (element.tagName === 'SUMMARY' && element.parentElement === closed))
        )
    })
    if (!buttons?.length) return
    const first = buttons[0],
        last = buttons[buttons.length - 1]
    if (
        !document.querySelector(selector)?.contains(document.activeElement) ||
        (event.shiftKey && document.activeElement === first)
    ) {
        event.preventDefault()
        ;(event.shiftKey ? last : first).focus()
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
    }
}
function keys(event: KeyboardEvent) {
    if (approvalTarget.value && event.key === 'Escape') {
        event.preventDefault()
        event.stopImmediatePropagation()
        if (!busy.value) approvalTarget.value = null
        return
    }
    if (warningCount.value !== null && event.key === 'Escape') {
        event.preventDefault()
        event.stopImmediatePropagation()
        choosePublish(false)
        return
    }
    if (overlay.popover && event.key === 'Escape') {
        event.preventDefault()
        event.stopPropagation()
        closePopover()
    }
    if (event.key === 'Tab') {
        if (approvalTarget.value) trap(event, '.gwc-approval')
        else if (warningCount.value !== null) trap(event, '.gwc-publish-warning')
    }
}
const warningCount = ref<number | null>(null)
const warningIgnored = computed(() => highFindings(state.result, true).filter((finding) => finding.dismissed).length)
let warningResolve: ((proceed: boolean) => void) | undefined
function choosePublish(proceed: boolean) {
    warningCount.value = null
    warningResolve?.(proceed)
    warningResolve = undefined
}
async function guardPublish(event: 'saving' | 'publishing', payload: SavePayload) {
    if (payload.collection !== props.meta.collection || (payload.values && payload.values !== context.values.value))
        return
    if (!shouldWarnPublish(event, payload.values, props.meta.revisions, props.meta.autosave, settings.onPublish)) return
    if (setupState.value) {
        Statamic.$toast.error(__('greenwashing-checker::messages.publish_unchecked'))
        return
    }
    if (!publishCheckIsCurrent(state, snapshot, context.values.value)) {
        await publishCheckWithinBudget(() => analyze(10000))
    }
    if (!publishCheckIsCurrent(state, snapshot, context.values.value)) {
        Statamic.$toast.error(__('greenwashing-checker::messages.publish_unchecked'))
        return
    }
    const findings = highFindings(state.result, true)
    if (!findings.length) return
    const choice = new Promise<boolean>((resolve) => {
        warningResolve = resolve
    })
    warningCount.value = findings.length
    await nextTick()
    await nextTick()
    document.querySelector<HTMLElement>('.gwc-publish-warning button')?.focus()
    const proceed = await choice
    if (proceed) return
    if (event === 'publishing') {
        overlay.review = true
        throw __('greenwashing-checker::messages.publish_cancelled')
    }
    // Core reads visibleValues after this hook resolves. Never reject its before-save pipeline.
    context.setFieldValue('published', false)
    reviewAfterSave = true
}
let reviewAfterSave = false
let reviewAfterSaveTimer: ReturnType<typeof setTimeout> | undefined
function saved(payload: SavePayload) {
    if (!reviewAfterSave || payload.collection !== props.meta.collection) return
    reviewAfterSave = false
    // Let core finish its optional redirect; only the still-mounted edit page can show a stack.
    reviewAfterSaveTimer = setTimeout(() => {
        if (root.value?.isConnected) overlay.review = true
    }, 500)
}
watch(popoverFinding, (finding) => {
    if (!finding && overlay.popover) closePopover()
})
watch(
    () => overlay.popover,
    async (value) => {
        if (value) {
            await nextTick()
            if (!popover.value || overlay.popover !== value) return
            const bounds = popover.value.getBoundingClientRect()
            value.left = Math.max(8, Math.min(value.left, window.innerWidth - bounds.width - 8))
            value.top = Math.max(8, Math.min(value.top, window.innerHeight - bounds.height - 8))
            popover.value.querySelector<HTMLElement>('button')?.focus()
        }
    },
)
watch(
    () => overlay.review,
    (open, previous) => {
        if (open && !previous) {
            reviewReturnFocus = document.activeElement as HTMLElement
            reviewMode.value = 'open'
        }
        if (!open) nextTick(() => reviewReturnFocus?.isConnected && reviewReturnFocus.focus())
    },
)
onMounted(() => {
    publishGuard.current = guardPublish
    publishGuard.saved = saved
    form =
        root.value?.closest<HTMLElement>('.publish-form') ??
        root.value?.closest('aside')?.parentElement ??
        root.value?.closest<HTMLElement>('.content-card') ??
        undefined
    form?.addEventListener('focusout', onBlur, true)
    if (form) {
        observer = new MutationObserver(scheduleInline)
        // Observe structural changes and reordered field IDs, not callout styles.
        observer.observe(form, { childList: true, subtree: true, attributes: true, attributeFilter: ['id'] })
        scheduleInline()
    }
    window.addEventListener('scroll', viewportChanged, true)
    window.addEventListener('resize', viewportChanged, true)
    document.addEventListener('pointerdown', outside, true)
    document.addEventListener('keydown', keys, true)
})
onBeforeUnmount(() => {
    if (publishGuard.current === guardPublish) publishGuard.current = undefined
    if (publishGuard.saved === saved) publishGuard.saved = undefined
    clearTimeout(reviewAfterSaveTimer)
    choosePublish(true)
    controller?.abort()
    clearTimeout(debounce)
    form?.removeEventListener('focusout', onBlur, true)
    observer?.disconnect()
    clearInline()
    window.removeEventListener('scroll', viewportChanged, true)
    window.removeEventListener('resize', viewportChanged, true)
    document.removeEventListener('pointerdown', outside, true)
    document.removeEventListener('keydown', keys, true)
    send({ type: 'reset' })
    overlay.popover = null
    overlay.review = false
})
</script>

<template>
    <section ref="root" class="gwc-panel" :aria-label="__('greenwashing-checker::messages.title')">
        <Heading :level="3">{{ __('greenwashing-checker::messages.title') }}</Heading>
        <template v-if="!setupState">
            <p role="status" aria-live="polite">{{ status }}</p>
            <p v-if="state.error" role="alert" class="gwc-error">{{ state.error }}</p>
            <div
                v-if="
                    context.blueprint.value.tabs.filter(
                        (tab) => tab.handle !== 'sidebar' && tab.handle !== 'greenwashing',
                    ).length > 1
                "
                class="gwc-tab-counts"
            >
                <Button
                    v-for="tab in tabCounts"
                    :key="tab.handle"
                    variant="ghost"
                    size="sm"
                    @click="jump(tab.fields[0].path, tab.fields[0].findings[0].id)"
                >
                    {{ __(tab.display) }} · {{ __n('greenwashing-checker::messages.statuses.findings', tab.count) }}
                </Button>
            </div>
            <div class="gwc-toolbar">
                <Button size="sm" :disabled="state.status === 'checking'" @click="analyze()">{{
                    __('greenwashing-checker::messages.buttons.' + (state.status === 'failed' ? 'retry' : 'check'))
                }}</Button>
                <Button size="sm" :disabled="!count && !dismissed && !approvedCount" @click="overlay.review = true">{{
                    __('greenwashing-checker::messages.buttons.review')
                }}</Button>
            </div>
        </template>
        <div v-else role="status">
            <p>{{ __('greenwashing-checker::messages.' + setupState) }}</p>
            <a
                v-if="setupState === 'not_configured'"
                class="gwc-doc-link"
                href="https://github.com/el-schneider/statamic-greenwashing-checker#configuration"
                target="_blank"
                rel="noopener noreferrer"
                >{{ __('greenwashing-checker::messages.buttons.documentation') }}</a
            >
        </div>
        <Description class="mt-4">{{ __('greenwashing-checker::messages.disclaimer') }}</Description>
    </section>
    <Stack
        v-if="overlay.review"
        :open="true"
        :wrap-slot="false"
        :show-close-button="false"
        @opened="nextTick(focusReview)"
        @closed="overlay.review = false"
        v-slot="{ close }"
    >
        <section
            class="gwc-review flex h-full min-h-0 flex-col"
            role="dialog"
            aria-modal="true"
            :aria-label="__('greenwashing-checker::messages.title')"
        >
            <StackHeader :title="__('greenwashing-checker::messages.title')" :show-close-button="false">
                <template #actions>
                    <Button
                        size="sm"
                        :loading="state.status === 'checking'"
                        :disabled="state.status === 'checking'"
                        @click="analyze()"
                    >
                        {{
                            __(
                                'greenwashing-checker::messages.buttons.' +
                                    (state.status === 'failed' ? 'retry' : 'check'),
                            )
                        }}
                    </Button>
                    <Button
                        icon="x"
                        variant="ghost"
                        :aria-label="__('greenwashing-checker::messages.buttons.close')"
                        @click="close"
                    />
                </template>
            </StackHeader>
            <Tabs v-model="reviewMode" class="flex flex-1 min-h-0 flex-col">
                <TabList class="px-6 shrink-0">
                    <TabTrigger v-for="section in reviewSections" :key="section.mode" :name="section.mode">
                        {{ __('greenwashing-checker::messages.review_' + section.mode) }}
                        <Badge size="sm">{{ section.count }}</Badge>
                    </TabTrigger>
                </TabList>
                <StackContent>
                    <p v-if="state.error" role="alert" class="gwc-error">{{ state.error }}</p>
                    <TabContent v-for="section in reviewSections" :key="section.mode" :name="section.mode">
                        <ReviewFindings
                            :groups="section.groups"
                            :norms="norms"
                            :busy="busy"
                            :read-only="context.readOnly.value"
                            :can-approve="settings.canApprove"
                            @apply="apply"
                            @dismiss="dismiss"
                            @restore="(finding) => dismiss(finding, false)"
                            @approve="beginApproval"
                            @revoke="revoke"
                            @jump="(finding) => jump(finding.path, finding.id)"
                        />
                        <div v-if="!section.count" class="py-12 text-center space-y-2">
                            <Heading>{{ __('greenwashing-checker::messages.review_empty_' + section.mode) }}</Heading>
                            <Description v-if="section.mode === 'open'">{{
                                __('greenwashing-checker::messages.review_empty_help')
                            }}</Description>
                        </div>
                    </TabContent>
                </StackContent>
            </Tabs>
            <StackFooter>
                <div class="flex flex-wrap items-center gap-2" role="status" aria-live="polite">
                    <Description>{{ status }}</Description>
                </div>
                <Description class="mt-2">{{ __('greenwashing-checker::messages.disclaimer') }}</Description>
            </StackFooter>
        </section>
    </Stack>
    <Modal
        v-if="warningCount !== null"
        :open="true"
        :title="__('greenwashing-checker::messages.publish_title')"
        @update:open="(open) => !open && choosePublish(false)"
    >
        <div class="gwc-publish-warning">
            <p>
                {{ __n('greenwashing-checker::messages.publish_high', warningCount)
                }}<span v-if="warningIgnored">
                    · {{ __n('greenwashing-checker::messages.statuses.ignored', warningIgnored) }}</span
                >
            </p>
            <ul class="gwc-warning-findings">
                <li v-for="finding in highFindings(state.result, true).slice(0, 5)" :key="finding.path + finding.id">
                    <strong
                        >{{ fieldBreadcrumb(finding.path, context.blueprint.value.tabs) }} ·
                        {{ __('greenwashing-checker::messages.categories.' + finding.category) }}</strong
                    >
                    <Description>{{ norms[finding.category] }}</Description>
                    <p>
                        “{{ finding.quote }}”
                        <span v-if="finding.dismissed" class="gwc-muted"
                            >· {{ __n('greenwashing-checker::messages.statuses.ignored', 1) }}</span
                        >
                    </p>
                </li>
            </ul>
            <p v-if="warningCount > 5">{{ __n('greenwashing-checker::messages.more_findings', warningCount - 5) }}</p>
            <p class="gwc-muted">
                {{
                    __(
                        'greenwashing-checker::messages.' +
                            (meta.revisions ? 'publish_escape_review' : 'publish_escape_draft'),
                    )
                }}
            </p>
            <div class="gwc-toolbar">
                <Button @click="choosePublish(false)">{{
                    __('greenwashing-checker::messages.buttons.' + (meta.revisions ? 'view' : 'draft'))
                }}</Button>
                <Button variant="primary" @click="choosePublish(true)">{{
                    __('greenwashing-checker::messages.buttons.publish_anyway')
                }}</Button>
            </div>
        </div>
    </Modal>
    <Modal
        v-if="approvalTarget"
        :open="true"
        :title="__('greenwashing-checker::messages.approval_title')"
        @update:open="(open) => !open && !busy && (approvalTarget = null)"
    >
        <form class="gwc-approval" @submit.prevent="setApproval(approvalTarget!, approvalReason.trim())">
            <blockquote class="gwc-quote">{{ approvalTarget.quote }}</blockquote>
            <label for="gwc-approval-reason">{{ __('greenwashing-checker::messages.approval_reason') }}</label>
            <Textarea
                id="gwc-approval-reason"
                v-model="approvalReason"
                required
                :limit="2000"
                maxlength="2000"
                rows="4"
                :disabled="busy"
            />
            <p class="gwc-muted">{{ __('greenwashing-checker::messages.approval_help') }}</p>
            <div class="gwc-toolbar">
                <Button :disabled="busy" @click="approvalTarget = null">{{
                    __('greenwashing-checker::messages.buttons.cancel')
                }}</Button>
                <Button type="submit" variant="primary" :disabled="busy || !approvalReason.trim()">{{
                    __('greenwashing-checker::messages.buttons.confirm_approval')
                }}</Button>
            </div>
        </form>
    </Modal>
    <Teleport to="body">
        <div
            v-if="overlay.popover && popoverFinding"
            ref="popover"
            class="gwc-popover"
            role="dialog"
            :aria-label="__('greenwashing-checker::messages.title')"
            :style="{ left: overlay.popover.left + 'px', top: overlay.popover.top + 'px' }"
        >
            <Button size="sm" variant="ghost" @click="closePopover">{{
                __('greenwashing-checker::messages.buttons.close')
            }}</Button>
            <FindingCard
                :finding="popoverFinding"
                :norms="norms"
                :busy="busy"
                :read-only="context.readOnly.value"
                :can-approve="settings.canApprove"
                @approve="beginApproval"
                @revoke="revoke"
                @apply="apply"
                @dismiss="dismiss"
            />
        </div>
    </Teleport>
</template>
