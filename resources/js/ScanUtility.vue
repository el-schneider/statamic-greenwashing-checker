<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
    Badge,
    Button,
    Card,
    Description,
    Header,
    Heading,
    Icon,
    Panel,
    PanelHeader,
    Pagination,
    Select,
    Table,
    TableColumns,
    TableColumn,
    TableRows,
    TableRow,
    TableCell,
} from '@statamic/cms/ui'
import { Head } from '@statamic/cms/inertia'
import { cpUrl, pollJob, requestJson } from './ai'

const props = defineProps<{ collections: { handle: string; title: string }[] }>()
interface Counts {
    open: number
    high: number
    ignored: number
    approved: number
}
interface Row {
    id: string
    title: string
    collection_title: string
    site_name: string
    counts: Counts
    analysed_at: string
    stale: boolean
    error?: string
    edit_url: string
}
interface Page {
    rows: Row[]
    meta: {
        current_page: number
        last_page: number
        per_page: number
        total: number
        from: number | null
        to: number | null
    }
}
interface Preview {
    totals: { entries: number; units: number; uncached: number; characters: number }
    entries: { id: string; title: string; error?: string }[]
}
interface ScanJob {
    id: string
    title: string
    poll: string
    status: 'queued' | 'running' | 'completed' | 'failed'
    error?: string
    skipped?: boolean
    message?: string
}
const collection = ref('*')
const collectionOptions = computed(() => [
    { value: '*', label: __('greenwashing-checker::scan.all_collections') },
    ...props.collections.map((item) => ({ value: item.handle, label: item.title })),
])
const rows = ref<Row[]>([])
const page = ref(1)
const meta = ref<Page['meta']>()
const cachedSkipped = ref<number>()
const preview = ref<Preview>()
const jobs = ref<ScanJob[]>([])
const busy = ref(false)
const loading = ref(false)
const error = ref('')
const abort = new AbortController()
const finished = computed(
    () => jobs.value.filter((job) => job.status === 'completed' || job.status === 'failed').length,
)
const failed = computed(() => jobs.value.filter((job) => job.status === 'failed'))
const skipped = computed(() => (cachedSkipped.value ?? 0) + jobs.value.filter((job) => job.skipped).length)
let generation = 0
function url(action: string): string {
    return `${cpUrl(`utilities/greenwashing-scan/${action}`)}?collection=${encodeURIComponent(collection.value === '*' ? '' : collection.value)}&page=${page.value}`
}
async function refresh(): Promise<void> {
    const current = ++generation
    loading.value = true
    error.value = ''
    preview.value = undefined
    try {
        const [entries, estimate] = await Promise.all([
            requestJson<Page>('GET', url('entries')),
            requestJson<Preview>('GET', url('preview')),
        ])
        if (current !== generation) return
        rows.value = entries.rows
        meta.value = entries.meta
        preview.value = estimate
    } catch (e) {
        if (current === generation) error.value = e instanceof Error ? e.message : String(e)
    } finally {
        if (current === generation) loading.value = false
    }
}
async function selectPage(selected: number): Promise<void> {
    if (busy.value || loading.value) return
    page.value = selected
    const current = ++generation
    loading.value = true
    error.value = ''
    try {
        const entries = await requestJson<Page>('GET', url('entries'))
        if (current !== generation) return
        rows.value = entries.rows
        meta.value = entries.meta
    } catch (e) {
        if (current === generation) error.value = e instanceof Error ? e.message : String(e)
    } finally {
        if (current === generation) loading.value = false
    }
}
async function start(): Promise<void> {
    busy.value = true
    error.value = ''
    jobs.value = []
    cachedSkipped.value = undefined
    try {
        const response = await requestJson<{ jobs: Omit<ScanJob, 'status'>[]; skipped: number }>(
            'POST',
            url('start'),
            {},
        )
        cachedSkipped.value = response.skipped
        jobs.value = response.jobs.map((job) => ({ ...job, status: 'queued' }))
        await Promise.all(
            jobs.value.map(async (job) => {
                try {
                    const result = await pollJob<{ skipped?: boolean; message?: string }>(job.poll, {
                        signal: abort.signal,
                        timeoutMs: 1800000,
                        onUpdate: (state) => (job.status = state.status),
                    })
                    if (result.status === 'completed') {
                        job.skipped = result.result.skipped
                        job.message = result.result.message
                    }
                } catch (e) {
                    if (abort.signal.aborted) return
                    job.status = 'failed'
                    job.error = e instanceof Error ? e.message : String(e)
                }
            }),
        )
        if (!abort.signal.aborted) await refresh()
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e)
    } finally {
        busy.value = false
    }
}
watch(collection, () => {
    page.value = 1
    void refresh()
})
onMounted(() => void refresh())
onBeforeUnmount(() => abort.abort())
</script>

<template>
    <Head :title="__('greenwashing-checker::messages.title')" />
    <div class="max-w-5xl mx-auto">
        <Header :title="__('greenwashing-checker::messages.title')" icon="magnifying-glass">
            <Button :disabled="busy || loading" @click="refresh">{{ __('greenwashing-checker::scan.refresh') }}</Button>
        </Header>
        <Panel>
            <PanelHeader
                ><Heading>{{ __('greenwashing-checker::scan.saved_content') }}</Heading></PanelHeader
            >
            <Card>
                <Description>{{ __('greenwashing-checker::scan.description') }}</Description>
                <div class="flex flex-wrap items-end gap-3 py-3">
                    <fieldset class="flex-1 min-w-0">
                        <legend class="block mb-2 text-sm font-medium">
                            {{ __('greenwashing-checker::scan.collection') }}
                        </legend>
                        <Select v-model="collection" :options="collectionOptions" :disabled="busy || loading" />
                    </fieldset>
                    <Button :disabled="busy || loading || !preview?.totals.entries" variant="primary" @click="start">{{
                        __('greenwashing-checker::scan.start')
                    }}</Button>
                </div>
                <template v-if="preview">
                    <div class="flex flex-wrap gap-2">
                        <Badge>{{ __n('greenwashing-checker::scan.entries', preview.totals.entries) }}</Badge>
                        <Badge>{{ __n('greenwashing-checker::scan.units', preview.totals.units) }}</Badge>
                        <Badge>{{ __n('greenwashing-checker::scan.uncached', preview.totals.uncached) }}</Badge>
                        <Badge>{{ __n('greenwashing-checker::scan.characters', preview.totals.characters) }}</Badge>
                    </div>
                    <Description>{{ __('greenwashing-checker::scan.cost_help') }}</Description>
                    <Description>{{ __('greenwashing-checker::scan.queue_help') }}</Description>
                    <p
                        v-for="entry in preview.entries.filter((entry) => entry.error)"
                        :key="entry.id"
                        role="alert"
                        class="gwc-error"
                    >
                        {{ entry.title }}: {{ entry.error }}
                    </p>
                </template>
                <p v-if="loading" role="status">{{ __('greenwashing-checker::scan.loading') }}</p>
                <p v-if="error" role="alert" class="gwc-error">{{ error }}</p>
                <Description v-if="cachedSkipped !== undefined" role="status">
                    <Badge>{{ __n('greenwashing-checker::scan.skipped', skipped) }}</Badge>
                </Description>
                <div v-if="jobs.length" aria-live="polite" class="space-y-2">
                    <label>
                        {{ __('greenwashing-checker::scan.progress', { done: finished, total: jobs.length }) }}
                        <progress class="block w-full my-2" :max="jobs.length" :value="finished" />
                    </label>
                    <p v-if="!busy">{{ __n('greenwashing-checker::scan.failures', failed.length) }}</p>
                    <ul>
                        <li v-for="job in jobs" :key="job.id">
                            {{ job.title }} —
                            {{ job.message || __('greenwashing-checker::messages.statuses.' + job.status)
                            }}<span v-if="job.error">: {{ job.error }}</span>
                        </li>
                    </ul>
                </div>
            </Card>
        </Panel>
        <Panel>
            <PanelHeader
                ><Heading>{{ __('greenwashing-checker::scan.results') }}</Heading></PanelHeader
            >
            <Card>
                <div v-if="!loading && !rows.length" class="py-8 text-center space-y-2">
                    <Icon name="magnifying-glass" class="size-6 mx-auto text-gray-500" />
                    <Heading>{{ __('greenwashing-checker::scan.no_findings') }}</Heading>
                    <Description>{{ __('greenwashing-checker::scan.empty') }}</Description>
                </div>
                <div v-else class="overflow-x-auto">
                    <Table>
                        <TableColumns>
                            <TableColumn>{{ __('greenwashing-checker::scan.entry') }}</TableColumn>
                            <TableColumn>{{ __('greenwashing-checker::scan.collection') }}</TableColumn>
                            <TableColumn>{{ __('greenwashing-checker::scan.site') }}</TableColumn>
                            <TableColumn>{{ __('greenwashing-checker::scan.open') }}</TableColumn>
                            <TableColumn>{{ __('greenwashing-checker::scan.high') }}</TableColumn>
                            <TableColumn>{{ __('greenwashing-checker::scan.ignored') }}</TableColumn>
                            <TableColumn>{{ __('greenwashing-checker::scan.approved') }}</TableColumn>
                            <TableColumn>{{ __('greenwashing-checker::scan.analysed_at') }}</TableColumn>
                        </TableColumns>
                        <TableRows>
                            <TableRow v-for="row in rows" :key="row.id">
                                <TableCell>
                                    <a :href="row.edit_url" class="font-medium hover:underline">{{ row.title }}</a>
                                    <Badge v-if="row.stale" size="sm" color="amber">{{
                                        __('greenwashing-checker::scan.stale')
                                    }}</Badge>
                                    <p v-if="row.error" role="alert" class="gwc-error">{{ row.error }}</p>
                                </TableCell>
                                <TableCell>{{ row.collection_title }}</TableCell>
                                <TableCell>{{ row.site_name }}</TableCell>
                                <TableCell>{{ row.counts.open }}</TableCell>
                                <TableCell
                                    ><Badge v-if="row.counts.high" color="red" size="sm">{{ row.counts.high }}</Badge
                                    ><span v-else>0</span></TableCell
                                >
                                <TableCell>{{ row.counts.ignored }}</TableCell>
                                <TableCell>{{ row.counts.approved }}</TableCell>
                                <TableCell
                                    ><time :datetime="row.analysed_at">{{
                                        new Date(row.analysed_at).toLocaleString()
                                    }}</time></TableCell
                                >
                            </TableRow>
                        </TableRows>
                    </Table>
                </div>
                <div v-if="meta && rows.length" :inert="busy || loading">
                    <Pagination :resource-meta="meta" :show-per-page-selector="false" @page-selected="selectPage" />
                </div>
            </Card>
        </Panel>
        <Description>{{ __('greenwashing-checker::messages.disclaimer') }}</Description>
    </div>
</template>
