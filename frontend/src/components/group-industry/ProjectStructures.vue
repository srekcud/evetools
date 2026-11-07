<script setup lang="ts">
import { watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupStructuresStore } from '@/stores/group-industry/structures'
import type { StructureComparisonGroup, StructureComparisonRow } from '@/stores/group-industry/types'
import { useSyncStore } from '@/stores/sync'
import UnknownContributionValue from './UnknownContributionValue.vue'

type BannerKind = 'onlyFavoriteSystem' | 'belowThreshold' | 'notRetained' | 'byTotalCost'

const COLUMNS = ['structure', 'materialBonus', 'materials', 'installation', 'transport', 'total', 'delta'] as const
const RIGHT_ALIGNED_COLUMNS: readonly (typeof COLUMNS)[number][] = ['materials', 'installation', 'transport', 'total', 'delta']
const BANNER_CLASSES: Record<BannerKind, string> = {
  onlyFavoriteSystem: 'border-amber-500/40 bg-amber-500/10 text-amber-300',
  belowThreshold: 'border-amber-500/40 bg-amber-500/10 text-amber-300',
  notRetained: 'border-amber-500/40 bg-amber-500/10 text-amber-300',
  byTotalCost: 'border-cyan-500/40 bg-cyan-500/10 text-cyan-300',
}

const props = defineProps<{
  projectId: string
}>()

const { t } = useI18n()
const { formatIsk, formatNumber } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()
const syncStore = useSyncStore()
const structuresStore = useGroupStructuresStore()

function productLabel(group: StructureComparisonGroup): string {
  return group.typeName ?? `#${group.typeId}`
}

function structureLabel(row: StructureComparisonRow): string {
  return row.name ?? row.structureId
}

function columnAlignment(column: (typeof COLUMNS)[number]): string {
  return RIGHT_ALIGNED_COLUMNS.includes(column) ? 'text-right' : 'text-left'
}

function rowTestId(group: StructureComparisonGroup, row: StructureComparisonRow, part: string): string {
  return `structures-row-${group.typeId}-${row.structureId}-${part}`
}

function signedIsk(amount: number): string {
  return amount > 0 ? `+${formatIsk(amount)}` : formatIsk(amount)
}

function cheapestRowOf(group: StructureComparisonGroup): StructureComparisonRow | null {
  return group.rows.find(row => row.structureId === group.cheapestStructureId) ?? null
}

function chosenRowOf(group: StructureComparisonGroup): StructureComparisonRow | null {
  return group.rows.find(row => row.structureId === group.chosenStructureId) ?? null
}

function bannerKindOf(group: StructureComparisonGroup): BannerKind | null {
  if (group.savingIfCheapest !== null && cheapestRowOf(group) !== null) {
    if (!structuresStore.acceptsOutsideFavoriteSystem) return 'onlyFavoriteSystem'
    return group.savingIfCheapest < structuresStore.minimumOutsideSaving ? 'belowThreshold' : 'notRetained'
  }
  return group.choiceStatus === 'best_by_total_cost' && chosenRowOf(group) !== null ? 'byTotalCost' : null
}

function bannerTextOf(group: StructureComparisonGroup, kind: BannerKind): string {
  const subject = kind === 'byTotalCost' ? chosenRowOf(group) : cheapestRowOf(group)
  return t(`groupIndustry.structures.banner.${kind}`, {
    structure: subject === null ? '' : structureLabel(subject),
    amount: formatIsk(group.savingIfCheapest),
    threshold: formatIsk(structuresStore.minimumOutsideSaving),
  })
}

watch(() => props.projectId, projectId => structuresStore.fetchStructures(projectId), { immediate: true })

watch(
  () => syncStore.getGroupProjectEvent(props.projectId),
  (event) => {
    if (!event) return
    structuresStore.fetchStructures(props.projectId)
  },
)

watch(
  () => syncStore.getSyncProgress('industry-project'),
  (progress) => {
    if (progress?.data?.projectId !== props.projectId) return
    structuresStore.fetchStructures(props.projectId)
  },
)
</script>

<template>
  <section data-testid="structures" class="bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden">
    <header class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-b border-slate-800">
      <h2 class="font-display text-sm font-semibold uppercase tracking-widest text-cyan-400">
        {{ t('groupIndustry.structures.title') }}
      </h2>
      <span
        v-if="structuresStore.transportIskPerCubicMeterJump !== null"
        data-testid="structures-rate"
        class="px-2 py-1 rounded border border-slate-700 text-xs text-slate-300"
      >{{ t('groupIndustry.structures.transportRate', { rate: formatNumber(structuresStore.transportIskPerCubicMeterJump) }) }}</span>
    </header>

    <p
      v-if="structuresStore.loading && structuresStore.groups.length === 0"
      data-testid="structures-loading"
      class="p-8 text-center text-slate-500"
    >{{ t('groupIndustry.structures.loading') }}</p>

    <div
      v-else-if="structuresStore.error !== null"
      data-testid="structures-error"
      role="alert"
      class="m-4 p-3 rounded border border-red-500/30 bg-red-500/10 text-sm text-red-300"
    >
      <p class="font-semibold">{{ t('groupIndustry.structures.loadError') }}</p>
      <p class="mt-1 break-words">{{ structuresStore.error }}</p>
    </div>

    <p
      v-else-if="structuresStore.groups.length === 0"
      data-testid="structures-empty"
      class="p-8 text-center text-slate-500"
    >{{ t('groupIndustry.structures.empty') }}</p>

    <template v-else>
      <div
        v-for="group in structuresStore.groups"
        :key="group.typeId"
        :data-testid="`structures-group-${group.typeId}`"
        class="border-b border-slate-800 last:border-b-0"
      >
        <div class="flex items-center gap-2 px-4 py-3 min-w-0">
          <img
            :src="getTypeIconUrl(group.typeId, 32)"
            alt=""
            class="w-8 h-8 rounded border border-slate-700 bg-slate-800 flex-shrink-0"
            @error="onImageError"
          />
          <h3 class="truncate font-medium text-slate-100" :title="productLabel(group)">
            {{ t('groupIndustry.structures.groupTitle', {
              product: productLabel(group),
              activity: t(`groupIndustry.structures.activity.${group.activity}`),
              jobs: group.jobs,
              runs: group.runs,
            }) }}
          </h3>
        </div>

        <template v-for="kind in [bannerKindOf(group)]" :key="kind">
          <div
            v-if="kind !== null"
            :data-testid="`structures-banner-${group.typeId}`"
            role="status"
            class="mx-4 mb-3 p-3 rounded border text-sm"
            :class="BANNER_CLASSES[kind]"
          >{{ bannerTextOf(group, kind) }}</div>
        </template>

        <p
          v-if="group.rows.length === 0"
          class="px-4 pb-4 text-sm text-slate-500"
        >{{ t('groupIndustry.structures.noStructure') }}</p>

        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[56rem] text-sm">
            <thead>
              <tr class="border-b border-slate-800 text-xs uppercase tracking-wider text-slate-500">
                <th
                  v-for="column in COLUMNS"
                  :key="column"
                  scope="col"
                  class="px-4 py-2 font-medium"
                  :class="columnAlignment(column)"
                >{{ t(`groupIndustry.structures.columns.${column}`) }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/60">
              <tr
                v-for="row in group.rows"
                :key="row.structureId"
                :data-testid="`structures-row-${group.typeId}-${row.structureId}`"
                class="align-top hover:bg-slate-800/30"
                :class="{ 'bg-cyan-500/5': row.structureId === group.chosenStructureId }"
              >
                <td class="px-4 py-3 max-w-[18rem]">
                  <div class="flex items-center gap-1 min-w-0">
                    <span
                      :data-testid="rowTestId(group, row, 'name')"
                      class="truncate font-medium text-slate-100"
                      :title="structureLabel(row)"
                    >{{ structureLabel(row) }}</span>
                    <span
                      v-if="row.structureId === group.chosenStructureId"
                      :data-testid="rowTestId(group, row, 'retained')"
                      :title="t('groupIndustry.structures.retained')"
                      class="text-cyan-400"
                    >★</span>
                  </div>
                  <div :data-testid="rowTestId(group, row, 'location')" class="mt-0.5 text-xs text-slate-500 truncate">
                    <span v-if="row.kind !== null" class="capitalize">{{ row.kind }}</span>
                    <span v-if="row.solarSystemName !== null"> · {{ row.solarSystemName }}</span>
                    <span v-if="row.inFavoriteSystem"> · {{ t('groupIndustry.structures.preferred') }}</span>
                    <span v-else-if="row.jumps !== null"> · {{ t('groupIndustry.structures.jumps', row.jumps) }}</span>
                  </div>
                </td>

                <td :data-testid="rowTestId(group, row, 'bonus')" class="px-4 py-3 text-slate-300">
                  −{{ formatNumber(row.materialBonusPercent) }} %
                </td>

                <td :data-testid="rowTestId(group, row, 'materials')" class="px-4 py-3 text-right font-mono tabular-nums text-slate-300">
                  <UnknownContributionValue v-if="row.materialsCost === null" />
                  <template v-else>{{ formatIsk(row.materialsCost) }}</template>
                </td>

                <td :data-testid="rowTestId(group, row, 'installation')" class="px-4 py-3 text-right font-mono tabular-nums text-slate-300">
                  <UnknownContributionValue v-if="row.installationCost === null" />
                  <template v-else>{{ formatIsk(row.installationCost) }}</template>
                  <div class="mt-0.5 text-xs font-sans text-slate-500">{{ t('groupIndustry.structures.taxDetail', { percent: `${formatNumber(row.facilityTaxPercent)} %` }) }}</div>
                </td>

                <td :data-testid="rowTestId(group, row, 'transport')" class="px-4 py-3 text-right font-mono tabular-nums text-slate-300">
                  <UnknownContributionValue v-if="row.transportCost === null" />
                  <template v-else>{{ formatIsk(row.transportCost) }}</template>
                  <div
                    v-if="row.transportedCubicMeters !== null && row.jumps !== null && row.jumps > 0"
                    class="mt-0.5 text-xs font-sans text-slate-500"
                  >{{ t('groupIndustry.structures.transportDetail', { volume: formatNumber(row.transportedCubicMeters), jumps: row.jumps }) }}</div>
                </td>

                <td :data-testid="rowTestId(group, row, 'total')" class="px-4 py-3 text-right font-mono tabular-nums font-semibold text-slate-100">
                  <UnknownContributionValue v-if="row.totalCost === null" />
                  <template v-else>{{ formatIsk(row.totalCost) }}</template>
                </td>

                <td
                  :data-testid="rowTestId(group, row, 'delta')"
                  class="px-4 py-3 text-right font-mono tabular-nums"
                  :class="row.delta !== null && row.delta < 0 ? 'text-emerald-300' : 'text-slate-300'"
                >
                  <UnknownContributionValue v-if="row.delta === null" />
                  <template v-else-if="row.delta === 0">—</template>
                  <template v-else>{{ signedIsk(row.delta) }}</template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <p class="px-4 py-3 border-t border-slate-800 text-xs text-slate-500">{{ t('groupIndustry.structures.hint') }}</p>
    </template>
  </section>
</template>
