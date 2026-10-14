<script setup lang="ts">
import { Badge, Button, Card, Description, Heading } from '@statamic/cms/ui'
import type { Finding } from './logic'
defineProps<{
    finding: Finding
    norms: Record<string, string>
    busy?: boolean
    readOnly?: boolean
    jumpable?: boolean
    canApprove?: boolean
    flat?: boolean
}>()
defineEmits<{
    apply: [finding: Finding, suggestion: string]
    dismiss: [finding: Finding]
    restore: [finding: Finding]
    jump: [finding: Finding]
    approve: [finding: Finding]
    revoke: [finding: Finding]
}>()
</script>

<template>
    <Card
        :variant="flat ? 'flat' : 'default'"
        class="gwc-card"
        :class="{ 'gwc-muted': finding.dismissed || !!finding.approval }"
    >
        <Badge :color="finding.severity === 'high' ? 'red' : 'amber'" size="sm">
            {{ __('greenwashing-checker::messages.severity.' + finding.severity) }}
        </Badge>
        <Heading :level="4" class="mt-2">{{
            __('greenwashing-checker::messages.categories.' + finding.category)
        }}</Heading>
        <Description class="text-xs">{{ norms[finding.category] }}</Description>
        <blockquote class="gwc-quote">{{ finding.quote }}</blockquote>
        <p>{{ finding.explanation }}</p>
        <p v-if="!finding.anchored" class="gwc-muted">{{ __('greenwashing-checker::messages.unclear_position') }}</p>
        <div v-if="!finding.dismissed && !finding.approval" class="gwc-suggestions">
            <div v-for="suggestion in finding.suggestions" :key="suggestion" class="gwc-suggestion">
                <p>{{ suggestion }}</p>
                <Button
                    size="sm"
                    :disabled="!finding.anchored || busy || readOnly"
                    @click="$emit('apply', finding, suggestion)"
                >
                    {{ __('greenwashing-checker::messages.buttons.apply') }}
                </Button>
            </div>
        </div>
        <div class="gwc-card-actions">
            <Button
                v-if="!finding.dismissed && !finding.approval"
                size="sm"
                variant="ghost"
                :disabled="busy"
                :title="__('greenwashing-checker::messages.dismiss_description')"
                @click="$emit('dismiss', finding)"
            >
                {{ __('greenwashing-checker::messages.buttons.dismiss') }}
            </Button>
            <Button
                v-if="finding.dismissed && !finding.approval"
                size="sm"
                variant="ghost"
                :disabled="busy"
                @click="$emit('restore', finding)"
                >{{ __('greenwashing-checker::messages.buttons.restore') }}</Button
            >
            <Button
                v-if="canApprove && !readOnly"
                size="sm"
                variant="ghost"
                :disabled="busy"
                @click="finding.approval ? $emit('revoke', finding) : $emit('approve', finding)"
                >{{ __('greenwashing-checker::messages.buttons.' + (finding.approval ? 'revoke' : 'approve')) }}</Button
            >
            <Button v-if="jumpable" size="sm" variant="ghost" @click="$emit('jump', finding)">{{
                __('greenwashing-checker::messages.buttons.jump')
            }}</Button>
        </div>
        <p v-if="finding.approval" class="gwc-muted gwc-approval-record">
            {{
                __('greenwashing-checker::messages.approved_by', {
                    user: finding.approval.user_name,
                    reason: finding.approval.reason,
                })
            }}
            <time :datetime="finding.approval.at">{{ new Date(finding.approval.at).toLocaleString() }}</time>
        </p>
        <p v-if="finding.dismissed && !finding.approval" class="gwc-muted">
            {{ __n('greenwashing-checker::messages.statuses.ignored', 1) }}
        </p>
    </Card>
</template>
