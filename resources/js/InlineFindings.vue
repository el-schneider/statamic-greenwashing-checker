<script setup lang="ts">
import { computed, ref, watch, nextTick } from 'vue'
import { Button, Icon } from '@statamic/cms/ui'
import FindingCard from './FindingCard.vue'
import { openFindings, overlay, state } from './store'
import type { Finding } from './logic'

const props = defineProps<{
    path: string
    norms: Record<string, string>
    collapsed: boolean
    busy: boolean
    readOnly: boolean
    canApprove: boolean
}>()
defineEmits<{
    toggle: []
    apply: [finding: Finding, suggestion: string]
    dismiss: [finding: Finding]
    restore: [finding: Finding]
    approve: [finding: Finding]
    revoke: [finding: Finding]
}>()
const findings = computed(() => openFindings(props.path))
const ignored = computed(() => state.result.units[props.path]?.findings.filter((f) => f.dismissed && !f.approval) ?? [])
const approved = computed(() => state.result.units[props.path]?.findings.filter((f) => f.approval) ?? [])
const expanded = ref<string | null>(null)
const root = ref<HTMLElement>()
const severity = computed(() => (findings.value.some((f) => f.severity === 'high') ? 'high' : 'medium'))
watch(
    () => overlay.inlineTarget,
    async (target) => {
        if (target?.path !== props.path) return
        expanded.value = target.id
        await nextTick()
        root.value?.querySelector<HTMLElement>(`[data-finding-id="${CSS.escape(target.id)}"]`)?.focus()
    },
    { immediate: true, flush: 'post' },
)
</script>

<template>
    <section
        ref="root"
        class="gwc-inline"
        :class="findings.length ? 'gwc-inline--' + severity : 'gwc-inline--ignored'"
        :aria-label="__('greenwashing-checker::messages.title')"
    >
        <button type="button" class="gwc-inline-toggle" :aria-expanded="!collapsed" @click="$emit('toggle')">
            <Icon :name="collapsed ? 'chevron-right' : 'chevron-down'" class="size-3 shrink-0" />
            <span v-if="findings.length" class="gwc-dot" :class="'gwc-dot--' + severity" aria-hidden="true"></span>
            <template v-if="findings.length">
                {{ __n('greenwashing-checker::messages.field_indicator', findings.length) }} ·
                {{ __('greenwashing-checker::messages.risk_short.' + severity) }}
            </template>
            <template v-if="ignored.length">
                {{ findings.length ? ' · ' : ''
                }}{{ __n('greenwashing-checker::messages.statuses.ignored', ignored.length) }}
            </template>
            <template v-if="approved.length"
                >{{ findings.length || ignored.length ? ' · ' : ''
                }}{{ __n('greenwashing-checker::messages.statuses.approved', approved.length) }}</template
            >
        </button>
        <div v-if="!collapsed" class="gwc-inline-list">
            <div v-for="finding in findings" :key="finding.id">
                <button
                    type="button"
                    class="gwc-inline-summary"
                    :data-finding-id="finding.id"
                    :aria-expanded="expanded === finding.id"
                    @click="expanded = expanded === finding.id ? null : finding.id"
                >
                    <span class="gwc-dot" :class="'gwc-dot--' + finding.severity" aria-hidden="true"></span>
                    <span class="sr-only"
                        >{{ __('greenwashing-checker::messages.severity.' + finding.severity) }}:</span
                    >
                    <span class="gwc-inline-category">{{
                        __('greenwashing-checker::messages.categories.' + finding.category)
                    }}</span>
                    <span class="gwc-inline-quote">“{{ finding.quote }}”</span>
                </button>
                <FindingCard
                    v-if="expanded === finding.id"
                    :finding="finding"
                    :norms="norms"
                    :busy="busy"
                    :read-only="readOnly"
                    :can-approve="canApprove"
                    @approve="(finding) => $emit('approve', finding)"
                    @revoke="(finding) => $emit('revoke', finding)"
                    @apply="(finding, suggestion) => $emit('apply', finding, suggestion)"
                    @dismiss="(finding) => $emit('dismiss', finding)"
                />
            </div>
            <div
                v-for="finding in ignored"
                :key="finding.id"
                class="gwc-inline-ignored gwc-muted"
                :data-finding-id="finding.id"
                tabindex="-1"
            >
                <span class="gwc-inline-category">{{
                    __('greenwashing-checker::messages.categories.' + finding.category)
                }}</span>
                <span class="gwc-inline-quote">“{{ finding.quote }}”</span>
                <span class="w-full text-xs">{{ norms[finding.category] }}</span>
                <Button size="sm" variant="ghost" :disabled="busy" @click="$emit('restore', finding)">{{
                    __('greenwashing-checker::messages.buttons.restore')
                }}</Button>
            </div>
            <div
                v-for="finding in approved"
                :key="finding.id"
                class="gwc-muted gwc-inline-approved"
                :data-finding-id="finding.id"
                tabindex="-1"
            >
                <p>
                    {{ __n('greenwashing-checker::messages.statuses.approved', 1) }} ·
                    {{ __('greenwashing-checker::messages.categories.' + finding.category) }} · “{{ finding.quote }}”
                </p>
                <p class="text-xs">{{ norms[finding.category] }}</p>
                <p>
                    {{
                        __('greenwashing-checker::messages.approved_by', {
                            user: finding.approval!.user_name,
                            reason: finding.approval!.reason,
                        })
                    }}
                    <time :datetime="finding.approval!.at">{{ new Date(finding.approval!.at).toLocaleString() }}</time>
                </p>
                <Button
                    v-if="canApprove && !readOnly"
                    size="sm"
                    variant="ghost"
                    :disabled="busy"
                    @click="$emit('revoke', finding)"
                    >{{ __('greenwashing-checker::messages.buttons.revoke') }}</Button
                >
            </div>
        </div>
    </section>
</template>
