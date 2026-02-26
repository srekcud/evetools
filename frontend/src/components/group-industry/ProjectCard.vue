<script setup lang="ts">
import { computed } from 'vue'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import type { GroupProject } from '@/stores/group-industry/types'

const { formatIsk, formatDate } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

const props = defineProps<{
  project: GroupProject
}>()

const emit = defineEmits<{
  click: [project: GroupProject]
}>()

const RING_RADIUS = 22
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS

const ringTarget = computed(() =>
  RING_CIRCUMFERENCE * (1 - props.project.fulfillmentPercent / 100)
)

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

const subtitle = computed(() => {
  if (props.project.items.length === 1) {
    return `${totalRuns.value} runs`
  }
  return `${props.project.items.length} items \u00B7 ${totalRuns.value} runs`
})

const isMultiItem = computed(() => props.project.items.length > 1)

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

function ringColor(): string {
  const pct = props.project.fulfillmentPercent
  if (pct >= 100) return '#34d399'
  if (pct >= 50) return '#22d3ee'
  return '#fbbf24'
}

function ringGlowClass(): string {
  const pct = props.project.fulfillmentPercent
  if (pct >= 100) return 'ring-glow-emerald'
  if (pct >= 50) return 'ring-glow-cyan'
  return 'ring-glow-amber'
}

function ringTextClass(): string {
  const pct = props.project.fulfillmentPercent
  if (pct >= 100) return 'text-emerald-400'
  if (pct >= 50) return 'text-cyan-400'
  return 'text-amber-400'
}

function progressBarClass(): string {
  const pct = props.project.fulfillmentPercent
  if (pct >= 100) return 'bg-emerald-500/60'
  if (pct >= 50) return 'bg-cyan-500/60'
  return 'bg-amber-500/60'
}
</script>

<template>
  <div
    class="project-card bg-slate-900/80 rounded-xl border border-slate-800 p-5 cursor-pointer card-enter"
    @click="emit('click', project)"
  >
    <!-- Header: icons + name + status -->
    <div class="flex items-start gap-4 mb-4">
      <!-- Multi-item: stacked small icons -->
      <div v-if="isMultiItem" class="flex flex-col gap-1 flex-shrink-0">
        <div
          v-for="item in project.items.slice(0, 3)"
          :key="item.typeId"
          class="w-8 h-8 rounded bg-slate-800 border border-slate-700 overflow-hidden"
        >
          <img
            :src="getTypeIconUrl(item.typeId, 32)"
            :alt="item.typeName"
            class="w-full h-full object-cover"
            @error="onImageError"
          />
        </div>
      </div>
      <!-- Single item: larger icon -->
      <div v-else class="w-16 h-16 rounded-lg bg-slate-800 border border-slate-700 flex items-center justify-center overflow-hidden flex-shrink-0">
        <img
          v-if="project.items.length > 0"
          :src="getTypeIconUrl(project.items[0].typeId, 64)"
          :alt="project.items[0].typeName"
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
        <p class="text-sm text-slate-500">{{ subtitle }}</p>
      </div>
    </div>

    <!-- Ring chart + progress bar -->
    <div class="flex items-center gap-4 mb-4">
      <div class="relative w-14 h-14 flex-shrink-0" :class="ringGlowClass()">
        <svg viewBox="0 0 56 56" class="w-14 h-14" style="transform: rotate(-90deg)">
          <circle cx="28" cy="28" :r="RING_RADIUS" fill="none" stroke="rgb(30 41 59)" stroke-width="5" />
          <circle
            cx="28" cy="28" :r="RING_RADIUS" fill="none"
            :stroke="ringColor()"
            stroke-width="5"
            stroke-linecap="round"
            :stroke-dasharray="RING_CIRCUMFERENCE"
            :style="{ '--ring-circumference': RING_CIRCUMFERENCE, '--ring-target': ringTarget }"
            class="ring-animated"
            :stroke-dashoffset="RING_CIRCUMFERENCE"
          />
        </svg>
        <div class="absolute inset-0 flex items-center justify-center">
          <span class="text-xs font-mono font-bold" :class="ringTextClass()">
            {{ Math.round(project.fulfillmentPercent) }}%
          </span>
        </div>
      </div>
      <div class="flex-1">
        <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">BOM Fulfillment</p>
        <div class="h-2 bg-slate-700 rounded-full overflow-hidden">
          <div
            class="h-full rounded-full relative"
            :class="progressBarClass()"
            :style="{ width: `${Math.min(project.fulfillmentPercent, 100)}%` }"
          >
            <div v-if="project.fulfillmentPercent < 100" class="absolute inset-0 progress-shimmer rounded-full"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Footer: owner + members + estimated value -->
    <div class="flex items-center justify-between text-xs">
      <div class="flex items-center gap-2">
        <span class="text-slate-400">{{ project.ownerCharacterName }}</span>
      </div>
      <div class="flex items-center gap-3">
        <div class="flex items-center gap-1 text-slate-500">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
          <span>{{ project.membersCount }}</span>
        </div>
        <span class="font-mono text-emerald-400" style="font-variant-numeric: tabular-nums;">
          {{ formatIsk(project.totalBomValue, 1) }}
        </span>
      </div>
    </div>

    <!-- Completed footer -->
    <div v-if="project.status === 'completed'" class="mt-3 pt-3 border-t border-slate-800 flex items-center justify-between">
      <span class="text-xs text-slate-500">{{ formatDate(project.createdAt) }}</span>
    </div>
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

@keyframes ringFill {
  from { stroke-dashoffset: var(--ring-circumference); }
  to { stroke-dashoffset: var(--ring-target); }
}
.ring-animated {
  animation: ringFill 1.4s cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards;
}

.ring-glow-cyan { filter: drop-shadow(0 0 6px rgba(34, 211, 238, 0.15)); }
.ring-glow-amber { filter: drop-shadow(0 0 6px rgba(251, 191, 36, 0.15)); }
.ring-glow-emerald { filter: drop-shadow(0 0 6px rgba(52, 211, 153, 0.15)); }

@keyframes shimmer {
  0% { background-position: -200% 0; }
  100% { background-position: 200% 0; }
}
.progress-shimmer {
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.06), transparent);
  background-size: 200% 100%;
  animation: shimmer 3s ease-in-out infinite;
}

@keyframes cardSlideUp {
  from { opacity: 0; transform: translateY(16px); }
  to { opacity: 1; transform: translateY(0); }
}
.card-enter {
  animation: cardSlideUp 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94) backwards;
}
</style>
