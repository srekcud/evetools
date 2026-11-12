<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import PartialPriceMarker from '@/components/industry/PartialPriceMarker.vue'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupMaterialSourcingStore } from '@/stores/group-industry/materialSourcing'
import type { MaterialSourcingRow, MaterialSourcingSide } from '@/stores/group-industry/types'
import { useSyncStore } from '@/stores/sync'
import { multibuyText } from '@/utils/multibuy'
import { useRefetchOnReconnect } from '@/composables/useRefetchOnReconnect'
import UnknownContributionValue from './UnknownContributionValue.vue'

const SIDE_CHIP_CLASSES: Record<MaterialSourcingSide, string> = {
  jita: 'border-amber-500/40 bg-amber-500/10 text-amber-300',
  structure: 'border-cyan-500/40 bg-cyan-500/10 text-cyan-300',
  unknown: 'border-dashed border-violet-400 text-violet-300',
}

const COLUMNS = ['material', 'quantity', 'jitaWithImport', 'structure', 'best'] as const
const COPIED_FEEDBACK_MS = 2000
const STRUCTURE_NAME_SEPARATOR = ' - '

const props = defineProps<{
  projectId: string
}>()

const { t } = useI18n()
const { formatIsk, formatNumber } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()
const syncStore = useSyncStore()
const sourcingStore = useGroupMaterialSourcingStore()

const copiedList = ref<'jita' | 'structure' | null>(null)

const sourcing = computed(() => sourcingStore.sourcing)
const rows = computed(() => sourcing.value?.rows ?? [])
const jitaRows = computed(() => rows.value.filter(row => row.side !== 'structure'))
const structureRows = computed(() => rows.value.filter(row => row.side === 'structure'))
const shortStructureName = computed(() => sourcing.value?.structureName?.split(STRUCTURE_NAME_SEPARATOR)[0] ?? '')

function multibuyOf(listed: MaterialSourcingRow[]): string {
  return multibuyText(listed)
}

function copyList(list: 'jita' | 'structure'): void {
  navigator.clipboard.writeText(multibuyOf(list === 'jita' ? jitaRows.value : structureRows.value))
  copiedList.value = list
  setTimeout(() => {
    copiedList.value = null
  }, COPIED_FEEDBACK_MS)
}

function jitaCoveragePercent(row: MaterialSourcingRow): number | null {
  return row.warnings.find(warning => warning.typeId === row.typeId)?.coveragePercent ?? null
}

function columnAlignment(column: (typeof COLUMNS)[number]): string {
  return column === 'material' ? 'text-left' : 'text-right'
}

watch(() => props.projectId, projectId => sourcingStore.fetchMaterialSourcing(projectId), { immediate: true })

watch(
  () => syncStore.getGroupProjectEvent(props.projectId),
  (event) => {
    if (!event) return
    sourcingStore.fetchMaterialSourcing(props.projectId)
  },
)

useRefetchOnReconnect(() => sourcingStore.fetchMaterialSourcing(props.projectId))

watch(
  () => syncStore.getSyncProgress('industry-project'),
  (progress) => {
    if (progress?.data?.projectId !== props.projectId) return
    sourcingStore.fetchMaterialSourcing(props.projectId)
  },
)

watch(
  () => syncStore.getSyncProgress('market-structure'),
  (progress) => {
    if (progress?.status !== 'completed') return
    sourcingStore.fetchMaterialSourcing(props.projectId)
  },
)
</script>

<template>
  <section data-testid="material-sourcing" class="bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden mb-6">
    <header class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-b border-slate-800">
      <h2 class="font-display text-sm font-semibold uppercase tracking-widest text-cyan-400">
        {{ t('groupIndustry.materialSourcing.title') }}
      </h2>
      <div v-if="sourcing !== null" class="flex flex-wrap items-center gap-2 text-xs text-slate-300">
        <span
          v-if="sourcing.structureName !== null"
          data-testid="material-sourcing-structure"
          class="px-2 py-1 rounded border border-slate-700"
        >{{ sourcing.structureName }}</span>
        <span
          data-testid="material-sourcing-rate"
          class="px-2 py-1 rounded border border-slate-700"
        >{{ t('groupIndustry.materialSourcing.importRate', { rate: formatNumber(sourcing.importIskPerCubicMeterJump) }) }}</span>
      </div>
    </header>

    <p
      v-if="sourcingStore.loading && sourcing === null"
      data-testid="material-sourcing-loading"
      class="p-8 text-center text-slate-500"
    >{{ t('groupIndustry.materialSourcing.loading') }}</p>

    <div
      v-else-if="sourcingStore.error !== null"
      data-testid="material-sourcing-error"
      role="alert"
      class="m-4 p-3 rounded border border-red-500/30 bg-red-500/10 text-sm text-red-300"
    >
      <p class="font-semibold">{{ t('groupIndustry.materialSourcing.loadError') }}</p>
      <p class="mt-1 break-words">{{ sourcingStore.error }}</p>
    </div>

    <p
      v-else-if="sourcing !== null && rows.length === 0"
      data-testid="material-sourcing-empty"
      class="p-8 text-center text-slate-500"
    >{{ t('groupIndustry.materialSourcing.empty') }}</p>

    <template v-else-if="sourcing !== null">
      <p
        v-if="sourcing.structureId === null || !sourcing.structureSynced"
        data-testid="material-sourcing-structure-hint"
        class="px-4 py-3 border-b border-slate-800 text-sm text-amber-300"
      >{{ t(sourcing.structureId === null ? 'groupIndustry.materialSourcing.noStructure' : 'groupIndustry.materialSourcing.structureNotSynced') }}</p>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[48rem] text-sm">
          <thead>
            <tr class="border-b border-slate-800 text-xs uppercase tracking-wider text-slate-500">
              <th
                v-for="column in COLUMNS"
                :key="column"
                scope="col"
                class="px-4 py-2 font-medium"
                :class="columnAlignment(column)"
              >{{ t(`groupIndustry.materialSourcing.columns.${column}`) }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800/60">
            <tr
              v-for="row in rows"
              :key="row.typeId"
              :data-testid="`material-sourcing-row-${row.typeId}`"
              class="align-top hover:bg-slate-800/30"
            >
              <td class="px-4 py-3 max-w-[16rem]">
                <div class="flex items-center gap-2 min-w-0">
                  <img
                    :src="getTypeIconUrl(row.typeId, 32)"
                    alt=""
                    class="w-8 h-8 rounded border border-slate-700 bg-slate-800 flex-shrink-0"
                    @error="onImageError"
                  />
                  <span class="truncate font-medium text-slate-100" :title="row.typeName">{{ row.typeName }}</span>
                </div>
              </td>

              <td :data-testid="`material-sourcing-row-${row.typeId}-quantity`" class="px-4 py-3 text-right font-mono tabular-nums text-slate-300">
                {{ formatNumber(row.quantity) }}
              </td>

              <td :data-testid="`material-sourcing-row-${row.typeId}-jita`" class="px-4 py-3 text-right font-mono tabular-nums text-slate-300">
                <UnknownContributionValue v-if="row.jitaWithImportTotal === null" />
                <template v-else>{{ formatIsk(row.jitaWithImportTotal) }}<PartialPriceMarker :percent="jitaCoveragePercent(row)" /></template>
              </td>

              <td :data-testid="`material-sourcing-row-${row.typeId}-structure`" class="px-4 py-3 text-right font-mono tabular-nums text-slate-300">
                <UnknownContributionValue v-if="row.structureTotal === null" />
                <template v-else>{{ formatIsk(row.structureTotal) }}</template>
              </td>

              <td class="px-4 py-3 text-right">
                <span
                  :data-testid="`material-sourcing-row-${row.typeId}-side`"
                  class="inline-block px-2 py-0.5 rounded border text-xs uppercase tracking-wider"
                  :class="SIDE_CHIP_CLASSES[row.side]"
                >{{ t(`groupIndustry.materialSourcing.side.${row.side}`) }}</span>
                <div
                  v-if="row.saving !== null"
                  :data-testid="`material-sourcing-row-${row.typeId}-saving`"
                  class="mt-1 text-xs text-slate-400"
                >{{ t('groupIndustry.materialSourcing.saving', { amount: formatIsk(row.saving) }) }}</div>
                <div
                  v-if="row.structureFallback"
                  :data-testid="`material-sourcing-row-${row.typeId}-fallback`"
                  class="mt-1 text-xs text-amber-400"
                >{{ t('groupIndustry.materialSourcing.structureFallback') }}</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="flex flex-wrap items-center justify-between gap-4 px-4 py-3 border-t border-slate-800">
        <dl class="flex flex-wrap gap-6">
          <div>
            <dt class="text-xs uppercase tracking-wider text-slate-500">{{ t('groupIndustry.materialSourcing.totals.jitaWithImport') }}</dt>
            <dd data-testid="material-sourcing-total-jita" class="font-mono tabular-nums text-slate-200">
              <UnknownContributionValue v-if="sourcing.totals.jitaWithImport === null" />
              <template v-else>{{ formatIsk(sourcing.totals.jitaWithImport) }}</template>
            </dd>
          </div>
          <div>
            <dt class="text-xs uppercase tracking-wider text-slate-500">{{ t('groupIndustry.materialSourcing.totals.structure') }}</dt>
            <dd data-testid="material-sourcing-total-structure" class="font-mono tabular-nums text-slate-200">
              <UnknownContributionValue v-if="sourcing.totals.structure === null" />
              <template v-else>{{ formatIsk(sourcing.totals.structure) }}</template>
            </dd>
          </div>
          <div>
            <dt class="text-xs uppercase tracking-wider text-slate-500">{{ t('groupIndustry.materialSourcing.totals.best') }}</dt>
            <dd data-testid="material-sourcing-total-best" class="font-mono tabular-nums font-semibold text-emerald-400">
              <UnknownContributionValue v-if="sourcing.totals.best === null" />
              <template v-else>{{ formatIsk(sourcing.totals.best) }}</template>
            </dd>
          </div>
        </dl>

        <div class="flex flex-wrap gap-3">
          <button
            v-if="jitaRows.length > 0"
            type="button"
            data-testid="material-sourcing-copy-jita"
            class="px-4 py-2 rounded-lg border border-amber-500/50 bg-amber-500/20 text-amber-300 text-sm hover:bg-amber-500/30 transition-colors"
            @click="copyList('jita')"
          >{{ copiedList === 'jita' ? t('shopping.copied') : t('shopping.copyJita', { count: jitaRows.length }) }}</button>
          <button
            v-if="structureRows.length > 0"
            type="button"
            data-testid="material-sourcing-copy-structure"
            class="px-4 py-2 rounded-lg border border-cyan-500/50 bg-cyan-500/20 text-cyan-300 text-sm hover:bg-cyan-500/30 transition-colors"
            @click="copyList('structure')"
          >{{ copiedList === 'structure' ? t('shopping.copied') : t('shopping.copyStructure', { name: shortStructureName, count: structureRows.length }) }}</button>
        </div>
      </div>

      <p class="px-4 py-3 border-t border-slate-800 text-xs text-slate-500">{{ t('groupIndustry.materialSourcing.hint') }}</p>
    </template>
  </section>
</template>
