<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { PinnedContainer } from '@/types'

const props = defineProps<{
  entry: PinnedContainer
}>()

const emit = defineEmits<{
  open: [itemId: number]
}>()

const { t } = useI18n()

function open(): void {
  if (props.entry.found && props.entry.itemId !== null) {
    emit('open', props.entry.itemId)
  }
}
</script>

<template>
  <button
    type="button"
    data-testid="pinned-entry"
    :class="['w-full px-5 py-2 flex items-center justify-between gap-4 text-left transition-colors', props.entry.found ? 'hover:bg-slate-800/50 cursor-pointer' : 'opacity-50 cursor-default']"
    @click="open"
  >
    <span class="min-w-0">
      <span class="block text-sm text-slate-200 truncate">{{ props.entry.name }}</span>
      <span v-if="props.entry.found" class="block text-xs text-slate-500 truncate">
        {{ props.entry.locationName }}<template v-if="props.entry.divisionName"> · {{ props.entry.divisionName }}</template>
      </span>
      <span v-else class="block text-xs text-amber-400">{{ t('assets.pinned.notFound') }}</span>
    </span>
    <span v-if="props.entry.itemCount !== null" class="shrink-0 text-xs font-mono text-cyan-400">{{ t('assets.itemsCount', { count: props.entry.itemCount.toLocaleString() }, props.entry.itemCount) }}</span>
  </button>
</template>
