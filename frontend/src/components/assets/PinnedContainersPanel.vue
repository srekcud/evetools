<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import PinnedContainerRow from '@/components/assets/PinnedContainerRow.vue'
import { groupPinnedEntries } from '@/utils/pinnedContainers'
import type { PinnedContainer } from '@/types'

const props = defineProps<{
  entries: PinnedContainer[]
}>()

const emit = defineEmits<{
  open: [itemId: number]
}>()

const { t } = useI18n()
const grouped = computed(() => groupPinnedEntries(props.entries))

function watchedCount(entries: PinnedContainer[]): number {
  return entries.filter(entry => entry.found).length
}
</script>

<template>
  <section v-if="props.entries.length > 0" data-testid="pinned-panel" class="bg-slate-900 rounded-lg border border-slate-800 overflow-hidden">
    <div v-for="group in grouped.projects" :key="group.projectId" data-testid="pinned-project-group" class="border-b border-slate-800 last:border-b-0">
      <h3 class="px-5 py-2 bg-slate-800/50 text-xs font-medium uppercase tracking-wide text-cyan-400">
        {{ t('assets.pinned.projectWatched', { project: group.projectName, count: watchedCount(group.entries) }, watchedCount(group.entries)) }}
      </h3>
      <ul class="divide-y divide-slate-800">
        <li v-for="entry in group.entries" :key="entry.id">
          <PinnedContainerRow :entry="entry" @open="emit('open', $event)" />
        </li>
      </ul>
    </div>
    <div v-if="grouped.manual.length > 0" data-testid="pinned-manual-group">
      <h3 class="px-5 py-2 bg-slate-800/50 text-xs font-medium uppercase tracking-wide text-cyan-400">{{ t('assets.pinned.title') }}</h3>
      <ul class="divide-y divide-slate-800">
        <li v-for="entry in grouped.manual" :key="entry.id">
          <PinnedContainerRow :entry="entry" @open="emit('open', $event)" />
        </li>
      </ul>
    </div>
  </section>
</template>
