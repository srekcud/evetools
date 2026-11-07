<script setup lang="ts">
import { watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupStructureHealthStore } from '@/stores/group-industry/structureHealth'
import type { StructureHealthRow, StructureHealthState } from '@/stores/group-industry/types'

const STATE_LABEL_KEYS: Record<StructureHealthState, string> = {
  ok: 'ok',
  'low-fuel': 'lowFuel',
  'offline-service': 'offlineService',
  unknown: 'unknown',
}
const STATE_CLASSES: Record<StructureHealthState, string> = {
  ok: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300',
  'low-fuel': 'border-amber-500/40 bg-amber-500/10 text-amber-300',
  'offline-service': 'border-red-500/40 bg-red-500/10 text-red-300',
  unknown: 'border-slate-600 bg-slate-800 text-slate-300',
}

const props = defineProps<{
  projectId: string
}>()

const { t } = useI18n()
const healthStore = useGroupStructureHealthStore()

function stateLabel(row: StructureHealthRow): string {
  return t(`groupIndustry.structureHealth.state.${STATE_LABEL_KEYS[row.state]}`)
}

function detailOf(row: StructureHealthRow): string {
  if (row.state === 'unknown') return t('groupIndustry.structureHealth.unknownReason')
  const parts: string[] = []
  if (row.fuelDays !== null) parts.push(t('groupIndustry.structureHealth.fuel', { days: row.fuelDays }))
  if (row.offlineServices.length > 0) {
    parts.push(t('groupIndustry.structureHealth.offlineServices', { services: row.offlineServices.join(', ') }))
  }
  return parts.join(' · ')
}

watch(() => props.projectId, projectId => healthStore.fetchStructureHealth(projectId), { immediate: true })
</script>

<template>
  <section data-testid="structure-health" class="bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden">
    <header class="px-4 py-3 border-b border-slate-800">
      <h2 class="font-display text-sm font-semibold uppercase tracking-widest text-cyan-400">
        {{ t('groupIndustry.structureHealth.title') }}
      </h2>
    </header>

    <p
      v-if="healthStore.loading && healthStore.rows.length === 0"
      data-testid="structure-health-loading"
      class="p-6 text-center text-slate-500"
    >{{ t('groupIndustry.structureHealth.loading') }}</p>

    <div
      v-else-if="healthStore.error !== null"
      data-testid="structure-health-error"
      role="alert"
      class="m-4 p-3 rounded border border-red-500/30 bg-red-500/10 text-sm text-red-300"
    >
      <p class="font-semibold">{{ t('groupIndustry.structureHealth.loadError') }}</p>
      <p class="mt-1 break-words">{{ healthStore.error }}</p>
    </div>

    <p
      v-else-if="healthStore.rows.length === 0"
      data-testid="structure-health-empty"
      class="p-6 text-center text-slate-500"
    >{{ t('groupIndustry.structureHealth.empty') }}</p>

    <ul v-else class="divide-y divide-slate-800/60">
      <li
        v-for="row in healthStore.rows"
        :key="row.structureId"
        :data-testid="`structure-health-row-${row.structureId}`"
        class="flex flex-wrap items-center gap-3 px-4 py-3 min-w-0"
      >
        <span
          :data-testid="`structure-health-state-${row.structureId}`"
          class="px-2 py-0.5 rounded border text-xs font-medium"
          :class="STATE_CLASSES[row.state]"
        >{{ stateLabel(row) }}</span>
        <span class="truncate font-medium text-slate-100 max-w-[18rem]" :title="row.name">{{ row.name }}</span>
        <span class="text-sm text-slate-400 break-words">{{ detailOf(row) }}</span>
      </li>
    </ul>
  </section>
</template>
