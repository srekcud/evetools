<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import type { BomItem } from '@/stores/group-industry/types'

const { t } = useI18n()
const projectStore = useGroupProjectStore()
const { getTypeIconUrl, onImageError } = useEveImages()
const { formatIsk, formatNumber } = useFormatters()

// Collapse states for accordion groups
const expandedGroups = ref<Record<string, boolean>>({
  blueprint: true,
  component: true,
  final: true,
})

function toggleGroup(group: string): void {
  expandedGroups.value[group] = !expandedGroups.value[group]
}

const blueprintJobs = computed(() => projectStore.blueprintJobs)
const componentJobs = computed(() => projectStore.componentJobs)
const finalJobs = computed(() => projectStore.finalJobs)
const allJobs = computed(() => projectStore.jobs)

const totalJobCount = computed(() => allJobs.value.length)

type JobStatusCounts = {
  toLaunch: number
  active: number
  delivered: number
  bpcContributed: number
}

const statusCounts = computed<JobStatusCounts>(() => {
  const counts: JobStatusCounts = { toLaunch: 0, active: 0, delivered: 0, bpcContributed: 0 }
  for (const job of allJobs.value) {
    if (job.isFulfilled && job.jobGroup === 'blueprint') {
      counts.bpcContributed++
    } else if (job.isFulfilled) {
      counts.delivered++
    } else if (job.fulfilledQuantity > 0) {
      counts.active++
    } else {
      counts.toLaunch++
    }
  }
  return counts
})

const totalEstCost = computed(() =>
  allJobs.value.reduce((sum, j) => sum + (j.estimatedTotal ?? 0), 0)
)

type JobGroupConfig = {
  key: string
  label: string
  items: BomItem[]
  badgeBg: string
  badgeText: string
  badgeBorder: string
}

const jobGroups = computed<JobGroupConfig[]>(() => [
  {
    key: 'blueprint',
    label: t('groupIndustry.jobs.blueprints'),
    items: blueprintJobs.value,
    badgeBg: 'bg-violet-500/15',
    badgeText: 'text-violet-400',
    badgeBorder: 'border-violet-500/20',
  },
  {
    key: 'component',
    label: t('groupIndustry.jobs.components'),
    items: componentJobs.value,
    badgeBg: 'bg-amber-500/15',
    badgeText: 'text-amber-400',
    badgeBorder: 'border-amber-500/20',
  },
  {
    key: 'final',
    label: t('groupIndustry.jobs.finalProducts'),
    items: finalJobs.value,
    badgeBg: 'bg-cyan-500/15',
    badgeText: 'text-cyan-400',
    badgeBorder: 'border-cyan-500/20',
  },
])

type ActivityBadgeConfig = { bg: string; text: string; border: string }

function activityBadge(activityType: string | null): ActivityBadgeConfig {
  const configs: Record<string, ActivityBadgeConfig> = {
    manufacturing: { bg: 'bg-amber-500/12', text: 'text-amber-400', border: 'border-amber-500/25' },
    reaction: { bg: 'bg-amber-500/12', text: 'text-amber-400', border: 'border-amber-500/25' },
    copy: { bg: 'bg-violet-500/12', text: 'text-violet-400', border: 'border-violet-500/25' },
    invention: { bg: 'bg-pink-500/12', text: 'text-pink-400', border: 'border-pink-500/25' },
  }
  return configs[activityType ?? ''] ?? { bg: 'bg-slate-700', text: 'text-slate-400', border: 'border-slate-600' }
}

function activityLabel(activityType: string | null): string {
  if (!activityType) return '---'
  return activityType.charAt(0).toUpperCase() + activityType.slice(1)
}

type JobStatus = 'bpc_contributed' | 'delivered' | 'active' | 'to_launch'

function jobStatus(job: BomItem): JobStatus {
  if (job.isFulfilled && job.jobGroup === 'blueprint') return 'bpc_contributed'
  if (job.isFulfilled) return 'delivered'
  if (job.fulfilledQuantity > 0) return 'active'
  return 'to_launch'
}

function isJobDimmed(job: BomItem): boolean {
  return jobStatus(job) === 'bpc_contributed'
}
</script>

<template>
  <!-- Jobs to Launch -->
  <div class="mt-8">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-semibold text-slate-100">{{ t('groupIndustry.jobs.title') }}</h2>
      <div class="flex items-center gap-2 text-xs">
        <span class="text-slate-400">{{ statusCounts.toLaunch }} to launch</span>
        <span class="text-slate-700">&middot;</span>
        <span class="text-cyan-400">{{ statusCounts.active }} active</span>
        <span class="text-slate-700">&middot;</span>
        <span class="text-emerald-400">{{ statusCounts.delivered }} delivered</span>
        <template v-if="statusCounts.bpcContributed > 0">
          <span class="text-slate-700">&middot;</span>
          <span class="text-violet-400">{{ statusCounts.bpcContributed }} BPC contributed</span>
        </template>
      </div>
    </div>

    <div class="bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden">
      <!-- Loading -->
      <div v-if="projectStore.bomLoading && allJobs.length === 0" class="p-8 text-center text-slate-500">
        <svg class="w-6 h-6 animate-spin text-cyan-500 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
        {{ t('common.status.loading') }}
      </div>

      <template v-else>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-xs text-slate-500 uppercase tracking-wider border-b border-slate-800">
                <th class="text-left py-2.5 px-6 w-8"></th>
                <th class="text-left py-2.5 px-3">{{ t('groupIndustry.jobs.product') }}</th>
                <th class="text-left py-2.5 px-3">{{ t('groupIndustry.jobs.activity') }}</th>
                <th class="text-right py-2.5 px-3">{{ t('groupIndustry.jobs.runs') }}</th>
                <th class="text-center py-2.5 px-3">ME/TE</th>
                <th class="text-center py-2.5 px-3">{{ t('groupIndustry.jobs.status') }}</th>
                <th class="text-right py-2.5 px-6">{{ t('groupIndustry.bom.estCost') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/30">
              <template v-for="group in jobGroups" :key="group.key">
                <!-- Group Header -->
                <tr
                  v-if="group.items.length > 0"
                  class="bg-slate-800/40 cursor-pointer hover:bg-slate-800/60 transition-colors"
                  @click="toggleGroup(group.key)"
                >
                  <td colspan="7" class="px-6 py-2">
                    <div class="flex items-center gap-2">
                      <svg
                        class="w-3 h-3 text-slate-500 transition-transform"
                        :class="expandedGroups[group.key] ? 'rotate-0' : '-rotate-90'"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24"
                      >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                      </svg>
                      <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">{{ group.label }}</span>
                      <span
                        class="text-xs px-1.5 py-0.5 rounded border"
                        :class="[group.badgeBg, group.badgeText, group.badgeBorder]"
                      >
                        {{ group.items.length }}
                      </span>
                    </div>
                  </td>
                </tr>

                <!-- Group Jobs -->
                <template v-if="expandedGroups[group.key]">
                  <tr
                    v-for="job in group.items"
                    :key="job.id"
                    class="hover:bg-slate-800/30"
                    :class="{ 'opacity-70': isJobDimmed(job) }"
                  >
                    <!-- Icon -->
                    <td class="py-2.5 px-6">
                      <div class="w-8 h-8 rounded bg-slate-800 border border-slate-700 overflow-hidden">
                        <img
                          :src="getTypeIconUrl(job.typeId, 32)"
                          :alt="job.typeName"
                          class="w-full h-full"
                          @error="onImageError"
                        />
                      </div>
                    </td>

                    <!-- Product name -->
                    <td class="py-2.5 px-3 text-slate-200">{{ job.typeName }}</td>

                    <!-- Activity badge -->
                    <td class="py-2.5 px-3">
                      <span
                        class="text-xs px-2 py-0.5 rounded border font-medium"
                        :class="[activityBadge(job.activityType).bg, activityBadge(job.activityType).text, activityBadge(job.activityType).border]"
                      >
                        {{ activityLabel(job.activityType) }}
                      </span>
                    </td>

                    <!-- Runs -->
                    <td class="py-2.5 px-3 text-right font-mono text-slate-300" style="font-variant-numeric: tabular-nums;">
                      {{ job.runs != null ? formatNumber(job.runs, 0) : '---' }}
                    </td>

                    <!-- ME/TE -->
                    <td class="py-2.5 px-3 text-center text-xs text-slate-400">
                      <template v-if="job.meLevel != null && job.teLevel != null">
                        ME {{ job.meLevel }} / TE {{ job.teLevel }}
                      </template>
                      <template v-else>&mdash;</template>
                    </td>

                    <!-- Status -->
                    <td class="py-2.5 px-3 text-center">
                      <!-- BPC Contributed -->
                      <span v-if="jobStatus(job) === 'bpc_contributed'" class="text-xs px-2 py-0.5 rounded bg-violet-500/15 text-violet-400 border border-violet-500/20 font-medium inline-flex items-center gap-1.5">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                        BPC Contributed
                      </span>
                      <!-- Delivered -->
                      <span v-else-if="jobStatus(job) === 'delivered'" class="text-xs px-2 py-0.5 rounded border bg-emerald-500/15 text-emerald-400 border-emerald-500/20 font-medium inline-flex items-center gap-1.5">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                        Delivered
                      </span>
                      <!-- Active -->
                      <span v-else-if="jobStatus(job) === 'active'" class="text-xs px-2 py-0.5 rounded border bg-cyan-500/15 text-cyan-400 border-cyan-500/20 font-medium inline-flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-cyan-400 animate-pulse"></span>
                        Active
                      </span>
                      <!-- To Launch -->
                      <span v-else class="text-xs px-2 py-0.5 rounded border bg-slate-700 text-slate-400 border-slate-600 font-medium">
                        To Launch
                      </span>
                    </td>

                    <!-- Est. Cost -->
                    <td class="py-2.5 px-6 text-right font-mono" :class="isJobDimmed(job) ? 'text-slate-500' : 'text-slate-400'" style="font-variant-numeric: tabular-nums;">
                      {{ job.estimatedTotal != null ? formatIsk(job.estimatedTotal, 1) : '&mdash;' }}
                    </td>
                  </tr>
                </template>
              </template>
            </tbody>
          </table>
        </div>

        <!-- Jobs Footer Summary -->
        <div class="px-6 py-3.5 border-t border-slate-800 flex items-center gap-6">
          <div class="flex items-center gap-2 text-sm">
            <span class="text-slate-500">{{ t('groupIndustry.jobs.totalJobs') }}:</span>
            <span class="font-mono font-semibold text-slate-200" style="font-variant-numeric: tabular-nums;">{{ totalJobCount }}</span>
          </div>
          <div class="w-px h-5 bg-slate-800"></div>
          <div class="flex items-center gap-2 text-sm">
            <span class="text-slate-500">{{ t('groupIndustry.bom.totalEstCost') }}:</span>
            <span class="font-mono font-semibold text-slate-200" style="font-variant-numeric: tabular-nums;">{{ formatIsk(totalEstCost, 1) }}</span>
          </div>
          <template v-if="statusCounts.bpcContributed > 0">
            <div class="w-px h-5 bg-slate-800"></div>
            <div class="flex items-center gap-2 text-sm">
              <span class="text-xs px-2 py-0.5 rounded bg-violet-500/15 text-violet-400 border border-violet-500/20 font-medium">
                {{ statusCounts.bpcContributed }} fulfilled by BPC
              </span>
            </div>
          </template>
        </div>
      </template>
    </div>
  </div>
</template>
