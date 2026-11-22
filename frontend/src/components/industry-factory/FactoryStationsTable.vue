<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import UnknownValue from '@/components/common/UnknownValue.vue'
import { useFormatters } from '@/composables/useFormatters'
import { useFactoryStore } from '@/stores/industry/factory'
import type { FactoryStationAccess, StructureAccessState } from '@/stores/industry/types'

const PERCENT = 100
const INDEX_DECIMALS = 1
const ACCESS_MARKS: Record<StructureAccessState, string> = { granted: '✓', denied: '✕', unknown: '?' }
const ACCESS_CLASSES: Record<StructureAccessState, string> = {
  granted: 'text-emerald-400',
  denied: 'text-red-400',
  unknown: 'text-slate-400',
}

const { t } = useI18n()
const { formatNumber } = useFormatters()
const store = useFactoryStore()

const stations = computed(() => store.stations ?? [])

function indexLabel(index: number): string {
  return `${formatNumber(index * PERCENT, INDEX_DECIMALS)} %`
}

function accessTitle(access: FactoryStationAccess): string {
  return `${access.characterName} · ${t(`industryFactory.stations.access.${access.status}`)}`
}

onMounted(() => store.fetchStations())
</script>

<template>
  <section data-testid="factory-stations" class="mb-8">
    <div class="flex items-baseline gap-3 mb-3">
      <h2 class="text-lg font-semibold text-slate-100">{{ t('industryFactory.stations.title') }}</h2>
      <span class="text-sm text-slate-400">{{ t('industryFactory.stations.subtitle') }}</span>
    </div>

    <p v-if="store.stations === null && store.error" data-testid="factory-stations-error" class="text-sm text-red-400">{{ store.error }}</p>
    <p v-else-if="store.stations === null" class="text-sm text-slate-400">{{ t('common.actions.loading') }}</p>
    <p v-else-if="stations.length === 0" data-testid="factory-stations-empty" class="text-sm text-slate-400">{{ t('industryFactory.stations.empty') }}</p>

    <div v-else class="overflow-x-auto bg-slate-900 rounded-xl border border-slate-800">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
            <th class="px-3 py-2">{{ t('industryFactory.stations.columns.station') }}</th>
            <th class="px-3 py-2">{{ t('industryFactory.stations.columns.specialisation') }}</th>
            <th class="px-3 py-2 text-right">{{ t('industryFactory.stations.columns.index') }}</th>
            <th class="px-3 py-2">{{ t('industryFactory.stations.columns.access') }}</th>
            <th class="px-3 py-2 text-right">{{ t('industryFactory.stations.columns.lines') }}</th>
            <th class="px-3 py-2">{{ t('industryFactory.stations.columns.projects') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="station in stations" :key="station.id" data-testid="factory-station-row" class="border-t border-slate-800 align-top">
            <td class="px-3 py-2">
              <div class="font-medium text-cyan-300">{{ station.name }}</div>
              <div class="text-xs text-slate-400">{{ station.solarSystemName ?? t('industryFactory.stations.systemUnknown') }} · {{ station.structureType }}</div>
            </td>
            <td class="px-3 py-2 text-slate-300">
              <template v-if="station.specialisations.length > 0">
                {{ station.specialisations.map(specialisation => t(`industryFactory.stations.specialisation.${specialisation}`)).join(', ') }}
              </template>
              <span v-else class="text-slate-500">{{ t('industryFactory.stations.noSpecialisation') }}</span>
            </td>
            <td data-testid="factory-station-index" class="px-3 py-2 text-right">
              <div v-if="station.manufacturingCostIndex !== null || station.reactionCostIndex !== null" class="space-y-0.5">
                <div v-if="station.manufacturingCostIndex !== null" class="text-slate-200">{{ t('industryFactory.stations.specialisation.manufacturing') }} {{ indexLabel(station.manufacturingCostIndex) }}</div>
                <div v-if="station.reactionCostIndex !== null" class="text-slate-200">{{ t('industryFactory.stations.specialisation.reaction') }} {{ indexLabel(station.reactionCostIndex) }}</div>
              </div>
              <UnknownValue v-else data-unknown="true" />
            </td>
            <td data-testid="factory-station-access" class="px-3 py-2">
              <span
                v-for="access in station.access"
                :key="access.characterId"
                :data-status="access.status"
                :data-character-id="access.characterId"
                :title="accessTitle(access)"
                class="mr-2 whitespace-nowrap"
                :class="ACCESS_CLASSES[access.status]"
              >{{ ACCESS_MARKS[access.status] }} {{ access.characterName }}</span>
            </td>
            <td data-testid="factory-station-lines" class="px-3 py-2 text-right text-slate-200">
              <template v-if="station.linesInUse !== null">{{ station.linesInUse }}</template>
              <UnknownValue v-else data-unknown="true" />
            </td>
            <td class="px-3 py-2">
              <RouterLink
                v-for="project in station.projects"
                :key="project.id"
                :to="{ name: 'industry-project', params: { id: project.id } }"
                class="mr-3 text-cyan-400 hover:text-cyan-300"
              >{{ project.name ?? t('industryFactory.unnamedProject') }}</RouterLink>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
