<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { Badge, Button, Description, Heading } from '@statamic/cms/ui'
import FindingCard from './FindingCard.vue'
import type { Finding, groupFindings } from './logic'

const props = defineProps<{
    groups: ReturnType<typeof groupFindings>
    norms: Record<string, string>
    busy: boolean
    readOnly: boolean
    canApprove: boolean
}>()
defineEmits<{
    apply: [finding: Finding, suggestion: string]
    dismiss: [finding: Finding]
    restore: [finding: Finding]
    approve: [finding: Finding]
    revoke: [finding: Finding]
    jump: [finding: Finding]
}>()
const root = ref<HTMLElement>()
const expanded = ref<string | null>(null)
const key = (finding: Finding) => `${finding.path}:${finding.id}`
const findings = computed(() => props.groups.flatMap((tab) => tab.fields.flatMap((field) => field.findings)))
watch(findings, async (current, previous) => {
    const index = previous.findIndex((finding) => key(finding) === expanded.value)
    if (index === -1 || current.some((finding) => key(finding) === expanded.value)) return
    // Disabling a decision button can move focus to the native scope or body before its row disappears.
    const active = document.activeElement
    const hadFocus =
        root.value?.contains(active) ||
        active === document.body ||
        active?.matches('.stack-container') ||
        active?.closest('.gwc-approval')
    expanded.value = null
    await nextTick()
    // Resolving a row removes its controls. Keep keyboard users at the next finding, not the page body.
    if (hadFocus) {
        const buttons = root.value?.querySelectorAll<HTMLElement>('[data-gwc-review-toggle]')
        if (buttons?.length) buttons[Math.min(index, buttons.length - 1)].focus()
        else
            root.value?.closest('.gwc-review')?.querySelector<HTMLElement>('[role="tab"][data-state="active"]')?.focus()
    }
})
</script>

<template>
    <div ref="root" class="space-y-6">
        <section v-for="tab in groups" :key="tab.handle" class="space-y-3">
            <Heading :level="3" size="lg">{{ __(tab.display) }}</Heading>
            <section
                v-for="field in tab.fields"
                :key="field.path"
                :data-gwc-review-field="field.path"
                class="space-y-1 border-b border-gray-200 dark:border-gray-700 pb-2"
            >
                <Description class="font-medium">{{ field.breadcrumb }}</Description>
                <div v-for="finding in field.findings" :key="key(finding)">
                    <Button
                        variant="ghost"
                        class="gwc-review-toggle w-full h-auto justify-start text-left whitespace-normal py-2"
                        :icon="expanded === key(finding) ? 'chevron-down' : 'chevron-right'"
                        :aria-expanded="expanded === key(finding)"
                        :aria-controls="`gwc-review-${key(finding)}`"
                        data-gwc-review-toggle
                        @click="expanded = expanded === key(finding) ? null : key(finding)"
                    >
                        <span class="flex-1 min-w-0 space-y-1">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{
                                    __('greenwashing-checker::messages.categories.' + finding.category)
                                }}</span>
                                <Badge :color="finding.severity === 'high' ? 'red' : 'amber'" size="sm">{{
                                    __('greenwashing-checker::messages.severity.' + finding.severity)
                                }}</Badge>
                            </span>
                            <span class="block truncate text-gray-600 dark:text-gray-400">{{ finding.quote }}</span>
                        </span>
                    </Button>
                    <div v-show="expanded === key(finding)" :id="`gwc-review-${key(finding)}`" class="pb-3">
                        <FindingCard
                            v-if="expanded === key(finding)"
                            :finding="finding"
                            :norms="norms"
                            :busy="busy"
                            :read-only="readOnly"
                            :can-approve="canApprove"
                            flat
                            jumpable
                            @apply="(finding, suggestion) => $emit('apply', finding, suggestion)"
                            @dismiss="$emit('dismiss', $event)"
                            @restore="$emit('restore', $event)"
                            @approve="$emit('approve', $event)"
                            @revoke="$emit('revoke', $event)"
                            @jump="$emit('jump', $event)"
                        />
                    </div>
                </div>
            </section>
        </section>
    </div>
</template>
