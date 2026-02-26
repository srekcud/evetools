<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import type { GroupProject } from '@/stores/group-industry/types'

const { t } = useI18n()
const { getTypeIconUrl, onImageError } = useEveImages()

const props = defineProps<{
  project: GroupProject
}>()

const emit = defineEmits<{
  join: [shortLinkCode: string]
}>()

const totalRuns = computed(() =>
  props.project.items.reduce((sum, item) => sum + item.runs, 0)
)

const displayName = computed(() => {
  if (props.project.name) return props.project.name
  if (props.project.items.length === 1) {
    const item = props.project.items[0]
    return `${item.runs}x ${item.typeName}`
  }
  return `${props.project.items.length} items`
})

const firstItem = computed(() => props.project.items[0] ?? null)

const statusConfig: Record<string, { classes: string; label: string }> = {
  draft: { classes: 'bg-slate-500/15 text-slate-400 border-slate-500/20', label: 'Draft' },
  published: { classes: 'bg-sky-500/15 text-sky-400 border-sky-500/20', label: 'Published' },
  in_progress: { classes: 'bg-cyan-500/15 text-cyan-400 border-cyan-500/20', label: 'In Progress' },
  selling: { classes: 'bg-violet-500/15 text-violet-400 border-violet-500/20', label: 'Selling' },
  completed: { classes: 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20', label: 'Completed' },
}

const statusStyle = computed(() =>
  statusConfig[props.project.status] ?? statusConfig.draft
)

function bomColor(): string {
  const pct = props.project.fulfillmentPercent
  if (pct >= 75) return 'text-emerald-400'
  if (pct >= 25) return 'text-amber-400'
  return 'text-red-400'
}

function handleJoin(event: Event) {
  event.stopPropagation()
  emit('join', props.project.shortLinkCode)
}
</script>

<template>
  <div class="project-card bg-slate-900/80 rounded-xl border border-slate-800 p-5 card-enter">
    <!-- Header: icon + name + owner -->
    <div class="flex items-start gap-4 mb-4">
      <div class="w-16 h-16 rounded-lg bg-slate-800 border border-slate-700 flex items-center justify-center overflow-hidden flex-shrink-0">
        <img
          v-if="firstItem"
          :src="getTypeIconUrl(firstItem.typeId, 64)"
          :alt="firstItem.typeName"
          class="w-full h-full object-cover"
          @error="onImageError"
        />
      </div>
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 mb-1">
          <h3 class="text-lg font-semibold text-slate-100 truncate">{{ displayName }}</h3>
          <span
            class="text-xs px-2 py-0.5 rounded border flex-shrink-0"
            :class="statusStyle.classes"
          >
            {{ statusStyle.label }}
          </span>
        </div>
        <div class="flex items-center gap-2 mt-1">
          <span class="text-sm text-slate-400">{{ project.ownerCharacterName }}</span>
        </div>
      </div>
    </div>

    <!-- Stats grid -->
    <div class="grid grid-cols-3 gap-3 mb-4 text-center">
      <div class="bg-slate-800/50 rounded-lg px-3 py-2">
        <p class="text-xs text-slate-500 uppercase tracking-wider mb-0.5">{{ t('groupIndustry.available.runs') }}</p>
        <p class="text-sm font-mono font-semibold text-slate-200" style="font-variant-numeric: tabular-nums;">
          {{ totalRuns }}
        </p>
      </div>
      <div class="bg-slate-800/50 rounded-lg px-3 py-2">
        <p class="text-xs text-slate-500 uppercase tracking-wider mb-0.5">ME / TE</p>
        <p class="text-sm font-mono font-semibold text-slate-200" style="font-variant-numeric: tabular-nums;">
          <template v-if="firstItem">{{ firstItem.meLevel }} / {{ firstItem.teLevel }}</template>
          <template v-else>-</template>
        </p>
      </div>
      <div class="bg-slate-800/50 rounded-lg px-3 py-2">
        <p class="text-xs text-slate-500 uppercase tracking-wider mb-0.5">BOM</p>
        <p class="text-sm font-mono font-semibold" :class="bomColor()" style="font-variant-numeric: tabular-nums;">
          {{ Math.round(project.fulfillmentPercent) }}%
        </p>
      </div>
    </div>

    <!-- Members count + optional progress bar -->
    <div class="flex items-center justify-between mb-4">
      <span class="text-xs px-2 py-0.5 rounded bg-slate-800 text-slate-500 border border-slate-700 flex items-center gap-1">
        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
        {{ project.membersCount }} {{ t('groupIndustry.available.members') }}
      </span>
      <div v-if="project.fulfillmentPercent > 0" class="h-1.5 w-24 bg-slate-700 rounded-full overflow-hidden">
        <div
          class="h-full bg-amber-500/60 rounded-full"
          :style="{ width: `${Math.min(project.fulfillmentPercent, 100)}%` }"
        ></div>
      </div>
    </div>

    <!-- Join button -->
    <button
      class="btn-action w-full py-2.5 bg-emerald-600 hover:bg-emerald-500 rounded-lg text-white text-sm font-medium flex items-center justify-center gap-2"
      @click="handleJoin"
    >
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
      </svg>
      {{ t('groupIndustry.available.joinProject') }}
    </button>
  </div>
</template>

<style scoped>
.project-card {
  transition: all 0.2s ease;
}
.project-card:hover {
  border-color: rgba(56, 189, 248, 0.3);
  box-shadow: 0 0 20px rgba(56, 189, 248, 0.05);
}

.btn-action {
  transition: all 0.15s ease;
}
.btn-action:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}
.btn-action:active {
  transform: translateY(0);
}

@keyframes cardSlideUp {
  from { opacity: 0; transform: translateY(16px); }
  to { opacity: 1; transform: translateY(0); }
}
.card-enter {
  animation: cardSlideUp 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94) backwards;
}
</style>
