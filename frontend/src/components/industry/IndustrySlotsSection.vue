<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { useSlotsStore } from '@/stores/industry/slots'
import type { SlotActivity, SlotTrackerCharacter, FreeSlotEntry, TimelineJob } from '@/stores/industry/types'

const emit = defineEmits<{
  'open-config': [section: 'skills']
}>()

const { t } = useI18n()
const { getTypeIconUrl, getCharacterPortraitUrl, onImageError } = useEveImages()
const slotsStore = useSlotsStore()

function navigateToSettings() {
  emit('open-config', 'skills')
}

const SLOT_ACTIVITIES: SlotActivity[] = ['manufacturing', 'reaction', 'science']
const CIRCUMFERENCE = 2 * Math.PI * 34 // ~213.63
const TIMELINE_HOURS = 72

// Activity badge colors
const ACTIVITY_BADGE_CLASSES: Record<string, { bg: string; text: string; border: string }> = {
  manufacturing: { bg: 'bg-cyan-500/10', text: 'text-cyan-400', border: 'border-cyan-500/20' },
  reaction: { bg: 'bg-purple-500/10', text: 'text-purple-400', border: 'border-purple-500/20' },
  copying: { bg: 'bg-amber-500/10', text: 'text-amber-400', border: 'border-amber-500/20' },
  invention: { bg: 'bg-blue-500/10', text: 'text-blue-400', border: 'border-blue-500/20' },
  research_me: { bg: 'bg-indigo-500/10', text: 'text-indigo-400', border: 'border-indigo-500/20' },
  research_te: { bg: 'bg-indigo-500/10', text: 'text-indigo-400', border: 'border-indigo-500/20' },
  reverse_engineering: { bg: 'bg-slate-500/10', text: 'text-slate-400', border: 'border-slate-500/20' },
}

// Gantt bar colors
const GANTT_BAR_CLASSES: Record<string, { bg: string; border: string; labelText: string }> = {
  manufacturing: { bg: 'bg-cyan-500/50', border: 'border-cyan-500/30', labelText: 'text-cyan-200' },
  reaction: { bg: 'bg-purple-500/50', border: 'border-purple-500/30', labelText: 'text-purple-200' },
  copying: { bg: 'bg-amber-500/50', border: 'border-amber-500/30', labelText: 'text-amber-200' },
  invention: { bg: 'bg-blue-500/50', border: 'border-blue-500/30', labelText: 'text-blue-200' },
  research_me: { bg: 'bg-indigo-500/50', border: 'border-indigo-500/30', labelText: 'text-indigo-200' },
  research_te: { bg: 'bg-indigo-500/50', border: 'border-indigo-500/30', labelText: 'text-indigo-200' },
  reverse_engineering: { bg: 'bg-slate-500/50', border: 'border-slate-500/30', labelText: 'text-slate-200' },
}

// KPI ring colors by utilization percent
function kpiColor(percent: number): { stroke: string; text: string; dot: string; border: string; label: string } {
  if (percent >= 80) return {
    stroke: '#34d399', text: 'text-emerald-400', dot: 'bg-emerald-400',
    border: 'border-emerald-500/20', label: t('industry.slots.kpi.high'),
  }
  if (percent >= 50) return {
    stroke: '#fbbf24', text: 'text-amber-400', dot: 'bg-amber-400',
    border: 'border-amber-500/20', label: t('industry.slots.kpi.moderate'),
  }
  return {
    stroke: '#f87171', text: 'text-red-400', dot: 'bg-red-400',
    border: 'border-red-500/20', label: t('industry.slots.kpi.low'),
  }
}

function dashOffset(percent: number): number {
  return CIRCUMFERENCE * (1 - percent / 100)
}

function formatDuration(seconds: number): string {
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  if (hours > 0) return `${hours}h ${String(minutes).padStart(2, '0')}m`
  return `${minutes}m`
}

function activityLabel(activityType: string): string {
  const key = `industry.slots.activity.${activityType}`
  return t(key)
}

function badgeClasses(activityType: string): { bg: string; text: string; border: string } {
  return ACTIVITY_BADGE_CLASSES[activityType] ?? ACTIVITY_BADGE_CLASSES['manufacturing']
}

function ganttClasses(activityType: string): { bg: string; border: string; labelText: string } {
  return GANTT_BAR_CLASSES[activityType] ?? GANTT_BAR_CLASSES['manufacturing']
}

function ganttBarWidth(timeLeftSeconds: number): number {
  const totalSeconds = TIMELINE_HOURS * 3600
  return Math.min((timeLeftSeconds / totalSeconds) * 100, 100)
}

// Slot summary styling for character header per activity type
const SLOT_ACTIVITY_COLORS: Record<SlotActivity, { text: string; dot: string }> = {
  manufacturing: { text: 'text-cyan-400', dot: 'bg-cyan-400' },
  reaction: { text: 'text-purple-400', dot: 'bg-purple-400' },
  science: { text: 'text-blue-400', dot: 'bg-blue-400' },
}

function isSlotActive(char: SlotTrackerCharacter, activity: SlotActivity): boolean {
  return char.slots[activity].used > 0
}

// Free slots footer: static class maps to avoid dynamic Tailwind class generation
type FreeSlotsClasses = { icon: string; text: string; bg: string }

const FREE_SLOTS_STYLES: Record<'red' | 'amber', FreeSlotsClasses> = {
  red: { icon: 'text-red-400', text: 'text-red-400', bg: 'bg-red-500/5' },
  amber: { icon: 'text-amber-400', text: 'text-amber-400', bg: 'bg-amber-500/5' },
}

function freeSlotsStyle(entry: FreeSlotEntry): FreeSlotsClasses {
  return entry.count >= 5 ? FREE_SLOTS_STYLES.red : FREE_SLOTS_STYLES.amber
}

function freeSlotSuggestionLabel(entry: FreeSlotEntry): string {
  // Science activities suggest "Invent", manufacturing suggests "Build"
  const scienceTypes = ['invention', 'copying', 'research_me', 'research_te', 'reverse_engineering', 'science']
  if (scienceTypes.includes(entry.activityType)) {
    return t('industry.slots.inventSuggestion')
  }
  return t('industry.slots.buildSuggestion')
}

// Slot types where all slots are occupied (used >= max and max > 0), excluding types already shown in freeSlots
function occupiedSlotTypes(char: SlotTrackerCharacter): SlotActivity[] {
  return SLOT_ACTIVITIES.filter(a => char.slots[a].used > 0 && char.slots[a].used >= char.slots[a].max && char.slots[a].max > 0)
}

// "Last sync" display
const lastSyncLabel = computed(() => {
  if (!slotsStore.lastFetchAt) return ''
  const diffMs = Date.now() - slotsStore.lastFetchAt.getTime()
  const minutes = Math.floor(diffMs / 60000)
  if (minutes < 1) return t('common.time.justNow')
  return t('industry.slots.minutesAgo', { n: minutes })
})

// Timeline groups: group timeline jobs by characterName
const timelineGroups = computed(() => {
  if (!slotsStore.data?.timeline) return []
  const groups: { characterName: string; jobs: TimelineJob[] }[] = []
  const map = new Map<string, TimelineJob[]>()
  for (const job of slotsStore.data.timeline) {
    const existing = map.get(job.characterName)
    if (existing) {
      existing.push(job)
    } else {
      const arr = [job]
      map.set(job.characterName, arr)
      groups.push({ characterName: job.characterName, jobs: arr })
    }
  }
  return groups
})

// Next slot opens: the job with minimum timeLeftSeconds
const nextSlotJob = computed(() => {
  if (!slotsStore.data?.timeline?.length) return null
  return slotsStore.data.timeline.reduce((min, job) =>
    job.timeLeftSeconds < min.timeLeftSeconds ? job : min,
  )
})

// Time axis labels
const timeAxisLabels = computed(() => {
  const labels: string[] = ['Now']
  for (let h = 6; h <= TIMELINE_HOURS; h += 6) {
    labels.push(`+${h}h`)
  }
  return labels
})

// 13 vertical grid lines (0h, 6h, 12h, ..., 72h)
const gridLines = computed(() => {
  return Array.from({ length: 13 }, (_, i) => {
    // Full opacity at 0, 24, 48, 72; half for others
    const isMain = i % 4 === 0
    return isMain ? 'bg-slate-800' : 'bg-slate-800/50'
  })
})

onMounted(() => {
  slotsStore.fetchSlots()
})
</script>

<template>
  <!-- Loading state -->
  <div v-if="slotsStore.loading && !slotsStore.data" class="space-y-6">
    <div class="bg-slate-900 rounded-xl border border-slate-800 p-8 flex items-center justify-center">
      <div class="flex items-center gap-3 text-slate-400">
        <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        <span class="text-sm">{{ t('common.actions.loading') }}</span>
      </div>
    </div>
  </div>

  <!-- Error state -->
  <div v-else-if="slotsStore.error && !slotsStore.data" class="space-y-6">
    <div class="bg-slate-900 rounded-xl border border-red-800/30 p-6 text-center">
      <p class="text-red-400 text-sm">{{ slotsStore.error }}</p>
      <button
        class="mt-3 px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 text-sm rounded-lg transition-colors"
        @click="slotsStore.fetchSlots()"
      >
        {{ t('common.actions.retry') }}
      </button>
    </div>
  </div>

  <!-- Empty state: no characters / no jobs -->
  <div v-else-if="slotsStore.data && slotsStore.data.characters.length === 0" class="space-y-6">
    <div class="bg-slate-900 rounded-xl border border-slate-800 p-12 text-center">
      <svg class="w-16 h-16 mx-auto mb-4 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <h3 class="text-lg font-semibold text-slate-300 mb-2">{{ t('industry.slots.title') }}</h3>
      <p class="text-slate-500">{{ t('industry.slots.noJobs') }}</p>
    </div>
  </div>

  <!-- Full content -->
  <div v-else-if="slotsStore.data" class="space-y-6">

    <!-- HEADER -->
    <div class="flex items-center justify-between card-enter" style="animation-delay: 0ms;">
      <div class="flex items-center gap-3">
        <svg class="w-7 h-7 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
        </svg>
        <h1 class="text-xl font-bold text-white">{{ t('industry.slots.title') }}</h1>
      </div>
      <div class="flex items-center gap-4">
        <span v-if="slotsStore.lastFetchAt" class="text-xs text-slate-500">
          {{ t('industry.slots.lastSync') }}: <span class="text-slate-400">{{ lastSyncLabel }}</span>
        </span>
        <button
          class="flex items-center gap-2 px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium transition-colors"
          :disabled="slotsStore.loading"
          @click="slotsStore.fetchSlots()"
        >
          <svg class="w-4 h-4" :class="{ 'animate-spin': slotsStore.loading }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          {{ t('industry.slots.refresh') }}
        </button>
      </div>
    </div>

    <!-- Skills stale warning -->
    <div
      v-if="slotsStore.data?.skillsMayBeStale"
      class="bg-amber-500/10 border border-amber-500/20 rounded-xl px-5 py-3 flex items-center justify-between card-enter"
      style="animation-delay: 40ms;"
    >
      <div class="flex items-center gap-3">
        <svg class="w-5 h-5 text-amber-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
        </svg>
        <span class="text-sm text-amber-300">
          {{ t('industry.slots.skillsStaleWarning') }}
        </span>
      </div>
      <button
        class="px-3 py-1.5 bg-amber-600 hover:bg-amber-500 rounded-lg text-white text-xs font-medium transition-colors"
        @click="navigateToSettings"
      >
        {{ t('industry.slots.syncSkills') }}
      </button>
    </div>

    <!-- GLOBAL SUMMARY - 3 KPI CARDS -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 card-enter" style="animation-delay: 80ms;">
      <div
        v-for="activity in SLOT_ACTIVITIES"
        :key="activity"
        class="eve-card p-5"
        :class="kpiColor(slotsStore.data.globalKpis[activity].percent).border"
      >
        <div class="flex items-center gap-5">
          <!-- Ring chart -->
          <div class="relative w-20 h-20 shrink-0">
            <svg class="w-20 h-20 -rotate-90" viewBox="0 0 80 80">
              <circle cx="40" cy="40" r="34" fill="none" stroke="rgb(30 41 59)" stroke-width="6" />
              <circle
                cx="40" cy="40" r="34" fill="none"
                :stroke="kpiColor(slotsStore.data.globalKpis[activity].percent).stroke"
                stroke-width="6"
                stroke-linecap="round"
                :stroke-dasharray="CIRCUMFERENCE"
                :style="{
                  '--ring-circumference': CIRCUMFERENCE,
                  '--ring-offset': dashOffset(slotsStore.data.globalKpis[activity].percent),
                }"
                class="ring-animated"
                :stroke-dashoffset="CIRCUMFERENCE"
              />
            </svg>
            <div class="absolute inset-0 flex items-center justify-center">
              <span
                class="font-mono text-lg font-bold"
                :class="kpiColor(slotsStore.data.globalKpis[activity].percent).text"
              >{{ slotsStore.data.globalKpis[activity].percent }}%</span>
            </div>
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm text-slate-400 mb-1">{{ t(`industry.slots.kpi.${activity}`) }}</p>
            <p class="text-2xl font-bold text-white font-mono" style="font-variant-numeric: tabular-nums">
              {{ slotsStore.data.globalKpis[activity].used }}<span class="text-slate-500 text-lg">/{{ slotsStore.data.globalKpis[activity].max }}</span>
            </p>
            <div class="mt-1.5 flex items-center gap-1.5">
              <span
                class="w-2 h-2 rounded-full"
                :class="kpiColor(slotsStore.data.globalKpis[activity].percent).dot"
              ></span>
              <span
                class="text-xs font-medium"
                :class="kpiColor(slotsStore.data.globalKpis[activity].percent).text"
              >{{ kpiColor(slotsStore.data.globalKpis[activity].percent).label }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- CHARACTER CARDS -->
    <div
      v-for="(char, charIdx) in slotsStore.data.characters"
      :key="char.characterId"
      class="eve-card overflow-hidden card-enter"
      :style="{ animationDelay: `${160 + charIdx * 80}ms` }"
    >
      <!-- Character header -->
      <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
        <div class="flex items-center gap-4">
          <img
            :src="getCharacterPortraitUrl(char.characterId, 64)"
            :alt="char.characterName"
            class="w-12 h-12 rounded-lg border border-slate-700 bg-slate-800"
            @error="onImageError"
          />
          <div>
            <h2 class="text-lg font-bold text-white">{{ char.characterName }}</h2>
            <div class="flex items-center gap-3 text-xs mt-0.5">
              <template v-for="(activity, actIdx) in SLOT_ACTIVITIES" :key="activity">
                <span class="text-slate-600" v-if="actIdx > 0">|</span>
                <span
                  class="flex items-center gap-1.5"
                  :class="isSlotActive(char, activity) ? SLOT_ACTIVITY_COLORS[activity].text : 'text-slate-400'"
                >
                  <span
                    v-if="isSlotActive(char, activity)"
                    class="w-1.5 h-1.5 rounded-full"
                    :class="SLOT_ACTIVITY_COLORS[activity].dot"
                  ></span>
                  <span :class="{ 'font-medium': isSlotActive(char, activity) }">
                    {{ char.slots[activity].used }}/{{ char.slots[activity].max }} {{ activityLabel(activity) }}
                  </span>
                </span>
              </template>
            </div>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <span
            v-if="char.isMain"
            class="text-[10px] px-2 py-0.5 rounded-full bg-cyan-500/15 text-cyan-400 border border-cyan-500/20 font-medium uppercase tracking-wider"
          >{{ t('industry.slots.main') }}</span>
          <span
            v-else
            class="text-[10px] px-2 py-0.5 rounded-full bg-slate-600/40 text-slate-400 border border-slate-600/50 font-medium uppercase tracking-wider"
          >{{ t('industry.slots.alt') }}</span>
        </div>
      </div>

      <!-- Active Jobs Table -->
      <div v-if="char.jobs.length > 0" class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase tracking-wider">
              <th class="text-left py-2.5 px-5 w-10"></th>
              <th class="text-left py-2.5 px-3">{{ t('industry.slots.table.product') }}</th>
              <th class="text-left py-2.5 px-3">{{ t('industry.slots.table.activity') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.slots.table.runs') }}</th>
              <th class="text-left py-2.5 px-3 w-48">{{ t('industry.slots.table.progress') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.slots.table.timeLeft') }}</th>
              <th class="text-left py-2.5 px-5">{{ t('industry.slots.table.facility') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800">
            <tr
              v-for="job in char.jobs"
              :key="job.jobId"
              class="hover:bg-slate-800/50"
            >
              <!-- Type icon -->
              <td class="py-2.5 px-5">
                <div class="w-8 h-8 rounded-sm bg-slate-800 border border-slate-700 overflow-hidden">
                  <img
                    :src="getTypeIconUrl(job.productTypeId, 64)"
                    :alt="job.productTypeName"
                    class="w-full h-full object-cover"
                    @error="onImageError"
                  />
                </div>
              </td>
              <!-- Product name -->
              <td class="py-2.5 px-3">
                <span class="text-slate-100 font-semibold">{{ job.productTypeName }}</span>
              </td>
              <!-- Activity badge -->
              <td class="py-2.5 px-3">
                <span
                  class="text-xs px-2 py-0.5 rounded border"
                  :class="[badgeClasses(job.activityType).bg, badgeClasses(job.activityType).text, badgeClasses(job.activityType).border]"
                >{{ activityLabel(job.activityType) }}</span>
              </td>
              <!-- Runs -->
              <td class="py-2.5 px-3 text-right font-mono text-slate-300">{{ job.runs }}</td>
              <!-- Progress bar -->
              <td class="py-2.5 px-3">
                <div class="flex items-center gap-2">
                  <div class="flex-1 h-2 bg-slate-800 rounded-full overflow-hidden">
                    <div
                      class="h-full rounded-full relative"
                      :class="job.progress >= 90 ? 'bg-emerald-500/60' : 'bg-cyan-500/60'"
                      :style="{ width: `${job.progress}%` }"
                    >
                      <div class="absolute inset-0 progress-shimmer rounded-full"></div>
                    </div>
                  </div>
                  <span
                    class="text-xs font-mono w-8 text-right"
                    :class="job.progress >= 90 ? 'text-emerald-400' : 'text-slate-500'"
                  >{{ job.progress }}%</span>
                </div>
              </td>
              <!-- Time left -->
              <td
                class="py-2.5 px-3 text-right font-mono"
                :class="job.progress >= 90 ? 'text-emerald-400' : 'text-slate-200'"
                style="font-variant-numeric: tabular-nums"
              >{{ formatDuration(job.timeLeftSeconds) }}</td>
              <!-- Facility -->
              <td class="py-2.5 px-5 text-slate-500 text-xs">{{ job.facilityName ?? '-' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- No jobs message -->
      <div v-else class="px-5 py-8 text-center">
        <p class="text-sm text-slate-500">{{ t('industry.slots.noJobs') }}</p>
      </div>

      <!-- Free slots footer -->
      <template v-for="entry in char.freeSlots" :key="entry.activityType">
        <div
          v-if="entry.count > 0"
          class="px-5 py-3 border-t border-slate-800 flex items-center gap-3"
          :class="freeSlotsStyle(entry).bg"
        >
          <div class="flex items-center gap-2">
            <svg class="w-4 h-4" :class="freeSlotsStyle(entry).icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
            </svg>
            <span class="text-sm font-medium" :class="freeSlotsStyle(entry).text">
              {{ t('industry.slots.freeSlots', { count: entry.count, type: activityLabel(entry.activityType) }) }}
            </span>
          </div>
          <template v-if="entry.suggestion != null">
            <span class="text-slate-600">|</span>
            <div class="flex items-center gap-1.5 text-sm">
              <svg class="w-3.5 h-3.5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
              </svg>
              <span class="text-slate-400">{{ freeSlotSuggestionLabel(entry) }}</span>
              <span class="text-cyan-400 font-semibold hover:underline cursor-pointer">{{ entry.suggestion.typeName }}</span>
              <span class="text-slate-500 text-xs">({{ entry.suggestion.reason }})</span>
            </div>
          </template>
        </div>
      </template>

      <!-- All occupied footer (for slot types where used >= max and max > 0) -->
      <template v-for="activity in occupiedSlotTypes(char)" :key="`occupied-${activity}`">
        <div class="px-5 py-3 bg-emerald-500/5 border-t border-slate-800 flex items-center gap-2">
          <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          <span class="text-sm text-emerald-400 font-medium">
            {{ t('industry.slots.allOccupied', { type: activityLabel(activity).toLowerCase() }) }}
          </span>
        </div>
      </template>
    </div>

    <!-- TIMELINE / GANTT VIEW -->
    <div
      v-if="slotsStore.data.timeline.length > 0"
      class="eve-card overflow-hidden card-enter"
      :style="{ animationDelay: `${160 + slotsStore.data.characters.length * 80 + 80}ms` }"
    >
      <!-- Section header -->
      <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
        <div class="flex items-center gap-2">
          <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <h3 class="text-base font-bold text-white">{{ t('industry.slots.timeline.title') }}</h3>
        </div>
        <div class="flex items-center gap-4 text-xs">
          <span class="flex items-center gap-1.5">
            <span class="w-3 h-2 rounded-sm bg-cyan-500/60"></span>
            <span class="text-slate-400">{{ t('industry.slots.activity.manufacturing') }}</span>
          </span>
          <span class="flex items-center gap-1.5">
            <span class="w-3 h-2 rounded-sm bg-purple-500/60"></span>
            <span class="text-slate-400">{{ t('industry.slots.activity.reaction') }}</span>
          </span>
          <span class="flex items-center gap-1.5">
            <span class="w-3 h-2 rounded-sm bg-amber-500/60"></span>
            <span class="text-slate-400">{{ t('industry.slots.activity.copying') }}</span>
          </span>
          <span class="flex items-center gap-1.5">
            <span class="w-3 h-2 rounded-sm bg-blue-500/60"></span>
            <span class="text-slate-400">{{ t('industry.slots.activity.invention') }}</span>
          </span>
        </div>
      </div>

      <div class="px-5 py-4">
        <!-- Time axis labels -->
        <div class="flex items-center mb-2 ml-[180px] relative">
          <div class="flex-1 flex justify-between text-xs text-slate-500 font-mono" style="font-variant-numeric: tabular-nums">
            <span v-for="label in timeAxisLabels" :key="label">{{ label }}</span>
          </div>
        </div>

        <!-- Grid + Bars area -->
        <div class="relative ml-[180px]">
          <!-- Vertical grid lines -->
          <div class="absolute inset-0 flex justify-between pointer-events-none" style="z-index: 0;">
            <div v-for="(cls, i) in gridLines" :key="i" class="w-px h-full" :class="cls"></div>
          </div>

          <!-- "Now" vertical line -->
          <div class="absolute top-0 bottom-0 left-0 w-px bg-cyan-400 now-pulse" style="z-index: 10;"></div>

          <!-- Gantt rows -->
          <div class="relative space-y-1" style="z-index: 5;">
            <template v-for="(group, groupIdx) in timelineGroups" :key="group.characterName">
              <!-- Separator between groups -->
              <div v-if="groupIdx > 0" class="h-px bg-slate-800 -ml-[180px]"></div>

              <!-- Character name label -->
              <div class="text-[10px] text-slate-500 uppercase tracking-wider font-semibold py-1 -ml-[180px] pl-1">
                {{ group.characterName }}
              </div>

              <!-- Job bars -->
              <div
                v-for="job in group.jobs"
                :key="job.jobId"
                class="flex items-center h-6 -ml-[180px]"
              >
                <span class="w-[180px] shrink-0 text-xs text-slate-400 pr-3 text-right truncate">
                  {{ job.productTypeName }}
                </span>
                <div class="flex-1 relative">
                  <div
                    class="gantt-bar absolute h-5 rounded-sm border"
                    :class="[
                      ganttClasses(job.activityType).bg,
                      ganttClasses(job.activityType).border,
                      ganttBarWidth(job.timeLeftSeconds) > 8 ? 'flex items-center px-1.5' : '',
                    ]"
                    :style="{ left: '0%', width: `${ganttBarWidth(job.timeLeftSeconds)}%` }"
                  >
                    <!-- Label on wider bars -->
                    <span
                      v-if="ganttBarWidth(job.timeLeftSeconds) > 8"
                      class="text-[10px] truncate"
                      :class="ganttClasses(job.activityType).labelText"
                    >{{ job.productTypeName }} x{{ job.runs }}</span>
                    <!-- Tooltip -->
                    <div class="gantt-tooltip bg-slate-800 border border-slate-700 rounded-lg shadow-xl px-3 py-2 text-xs">
                      <div class="text-slate-100 font-semibold">{{ job.productTypeName }} x{{ job.runs }}</div>
                      <div class="text-slate-400 mt-0.5">{{ t('industry.slots.timeline.completesIn') }} {{ formatDuration(job.timeLeftSeconds) }}</div>
                    </div>
                  </div>
                </div>
              </div>
            </template>
          </div>
        </div>

        <!-- Bottom note -->
        <div class="mt-4 ml-[180px] flex items-center gap-2 text-xs text-slate-500">
          <svg class="w-3.5 h-3.5 text-cyan-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <span>
            {{ t('industry.slots.timeline.hoverHint') }}
            <template v-if="nextSlotJob != null">
              {{ t('industry.slots.timeline.nextSlotOpens') }}
              <span class="text-cyan-400 font-mono font-semibold">{{ formatDuration(nextSlotJob.timeLeftSeconds) }}</span>
              ({{ nextSlotJob.productTypeName }}).
            </template>
          </span>
        </div>
      </div>
    </div>

    <!-- FOOTER LEGEND -->
    <div
      class="bg-slate-800/30 rounded-xl border border-slate-700/30 px-4 py-3 card-enter"
      :style="{ animationDelay: `${160 + slotsStore.data.characters.length * 80 + 160}ms` }"
    >
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-5 text-xs">
          <span class="text-slate-500 uppercase tracking-wider font-medium">{{ t('industry.slots.legend.activityTypes') }}</span>
          <div class="flex items-center gap-1.5">
            <span class="w-3 h-2 rounded-sm bg-cyan-500/60 border border-cyan-500/30"></span>
            <span class="text-slate-400">{{ t('industry.slots.activity.manufacturing') }}</span>
          </div>
          <div class="flex items-center gap-1.5">
            <span class="w-3 h-2 rounded-sm bg-purple-500/60 border border-purple-500/30"></span>
            <span class="text-slate-400">{{ t('industry.slots.activity.reaction') }}</span>
          </div>
          <div class="flex items-center gap-1.5">
            <span class="w-3 h-2 rounded-sm bg-amber-500/60 border border-amber-500/30"></span>
            <span class="text-slate-400">{{ t('industry.slots.activity.copying') }}</span>
          </div>
          <div class="flex items-center gap-1.5">
            <span class="w-3 h-2 rounded-sm bg-blue-500/60 border border-blue-500/30"></span>
            <span class="text-slate-400">{{ t('industry.slots.activity.invention') }}</span>
          </div>
        </div>
        <div class="flex items-center gap-4 text-xs text-slate-500">
          <div class="flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
            <span>{{ t('industry.slots.legend.utilization') }}</span>
          </div>
          <div class="flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-amber-400"></span>
            <span>50-80%</span>
          </div>
          <div class="flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-red-400"></span>
            <span>&lt; 50%</span>
          </div>
        </div>
      </div>
    </div>

  </div>
</template>

<style scoped>
.eve-card {
  background: rgb(15 23 42);
  border: 1px solid rgb(30 41 59);
  border-radius: 0.75rem;
}

/* SVG ring animation */
@keyframes ringFill {
  from { stroke-dashoffset: var(--ring-circumference); }
  to { stroke-dashoffset: var(--ring-offset); }
}
.ring-animated {
  animation: ringFill 1.2s cubic-bezier(0.4, 0, 0.2, 1) forwards;
}

/* Progress bar shimmer */
@keyframes progressShimmer {
  0% { background-position: -200% 0; }
  100% { background-position: 200% 0; }
}
.progress-shimmer {
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.08), transparent);
  background-size: 200% 100%;
  animation: progressShimmer 2.5s ease-in-out infinite;
}

/* Gantt "now" line pulse */
@keyframes nowPulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}
.now-pulse { animation: nowPulse 2s ease-in-out infinite; }

/* Card entrance stagger */
@keyframes cardEnter {
  from { opacity: 0; transform: translateY(12px); }
  to { opacity: 1; transform: translateY(0); }
}
.card-enter {
  animation: cardEnter 0.4s cubic-bezier(0.4, 0, 0.2, 1) backwards;
}

/* Gantt bar hover */
.gantt-bar {
  transition: filter 0.15s ease, transform 0.15s ease;
  cursor: pointer;
}
.gantt-bar:hover {
  filter: brightness(1.3);
  transform: scaleY(1.15);
}

/* Tooltip */
.gantt-bar .gantt-tooltip {
  display: none;
  position: absolute;
  z-index: 50;
  bottom: calc(100% + 8px);
  left: 50%;
  transform: translateX(-50%);
  white-space: nowrap;
}
.gantt-bar:hover .gantt-tooltip {
  display: block;
}
</style>
