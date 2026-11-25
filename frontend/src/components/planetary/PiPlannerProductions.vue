<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { TIER_CONFIG } from '@/composables/usePlanetaryHelpers'
import { useEveImages } from '@/composables/useEveImages'
import { usePlannerDisplay } from '@/composables/usePlannerDisplay'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import { indexCatalog, parseDecimalInput, type PlanetaryTier, type PlannerType } from '@/utils/planetaryPlanner'

const SELECTABLE_TIERS: readonly PlanetaryTier[] = ['P4', 'P3', 'P2', 'P1']
const ICON_SIZE = 32

const { t } = useI18n()
const store = usePlanetaryPlannerStore()
const { getTypeIconUrl, onImageError } = useEveImages()
const { typeOf, formatPlannerIsk } = usePlannerDisplay()

const invalidTargets = ref<Record<number, boolean>>({})

const selectableGroups = computed(() => {
  const catalog = store.catalog
  if (!catalog) return []
  const { recipeByOutput } = indexCatalog(catalog)
  return SELECTABLE_TIERS.map(tier => ({
    tier,
    types: catalog.types.filter((type: PlannerType) => type.tier === tier && recipeByOutput.has(type.typeId)),
  })).filter(group => group.types.length > 0)
})

const rows = computed(() =>
  store.productions.map((production, index) => ({
    index,
    production,
    type: typeOf(production.typeId),
    profit: store.plan ? store.plan.summary.profitPerDay[index] : null,
  })),
)

function onAdd(event: Event): void {
  const select = event.target as HTMLSelectElement
  const selected = select.value
  select.value = ''
  if (selected !== '') store.addProduction(Number(selected))
}

function onTargetInput(index: number, event: Event): void {
  const accepted = store.setTarget(index, parseDecimalInput((event.target as HTMLInputElement).value))
  invalidTargets.value = { ...invalidTargets.value, [index]: !accepted }
}

function onRemove(index: number): void {
  store.removeProduction(index)
  invalidTargets.value = {}
}

function rowClass(index: number): string[] {
  return [
    'rounded-lg border p-3 transition-colors',
    store.selectedIndex === index ? 'border-cyan-500/50 bg-cyan-500/5' : 'border-slate-800 bg-slate-950/40 hover:border-slate-700',
  ]
}
</script>

<template>
  <section class="rounded-xl border border-slate-800 bg-slate-900 p-5" data-testid="planner-productions">
    <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.planner.productions.title') }}</h3>

    <label class="mb-4 block">
      <span class="mb-1.5 block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.productions.addLabel') }}</span>
      <select
        data-testid="planner-add-select"
        class="w-full rounded-md border border-slate-700 bg-slate-800 px-3 py-1.5 text-sm text-white focus:border-cyan-500 focus:outline-hidden"
        @change="onAdd"
      >
        <option value="">{{ t('pi.planner.productions.addPlaceholder') }}</option>
        <optgroup v-for="group in selectableGroups" :key="group.tier" :label="t(`pi.planner.tiers.${group.tier}`)">
          <option v-for="type in group.types" :key="type.typeId" :value="type.typeId">{{ type.name }}</option>
        </optgroup>
      </select>
    </label>

    <p v-if="store.productions.length === 0" class="rounded-lg border border-dashed border-slate-700 p-4 text-sm text-slate-400" data-testid="planner-empty">
      {{ t('pi.planner.empty') }}
    </p>

    <ul v-else class="space-y-2">
      <li v-for="row in rows" :key="row.index" :class="rowClass(row.index)" data-testid="planner-production-row" :data-selected="store.selectedIndex === row.index">
        <div class="flex items-center gap-2">
          <button
            type="button"
            class="flex min-w-0 flex-1 items-center gap-2 text-left"
            :title="row.type.name"
            :aria-label="t('pi.planner.productions.select', { name: row.type.name })"
            :aria-pressed="store.selectedIndex === row.index"
            @click="store.selectProduction(row.index)"
          >
            <img :src="getTypeIconUrl(row.type.typeId, ICON_SIZE)" alt="" class="h-6 w-6 shrink-0 rounded-sm" @error="onImageError" />
            <span :class="['shrink-0 rounded-sm border px-1.5 py-0.5 text-xs', TIER_CONFIG[row.type.tier].badgeBg, TIER_CONFIG[row.type.tier].badgeText, TIER_CONFIG[row.type.tier].badgeBorder]">{{ row.type.tier }}</span>
            <span class="min-w-0 truncate text-sm font-medium text-slate-100">{{ row.type.name }}</span>
          </button>
          <button
            type="button"
            class="shrink-0 rounded-md p-1 text-slate-500 transition-colors hover:bg-red-500/15 hover:text-red-400"
            :aria-label="t('pi.planner.productions.remove', { name: row.type.name })"
            @click="onRemove(row.index)"
          >
            <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div class="mt-2 flex flex-wrap items-end gap-3">
          <label class="min-w-0 flex-1">
            <span class="mb-1 block text-xs text-slate-500">{{ t('pi.planner.productions.targetLabel') }}</span>
            <input
              type="number"
              inputmode="decimal"
              min="0"
              step="any"
              data-testid="planner-target-input"
              :value="row.production.targetPerDay"
              :aria-invalid="invalidTargets[row.index] === true"
              :class="['w-full rounded-md border bg-slate-800 px-2 py-1 font-mono-tech text-sm text-white focus:outline-hidden', invalidTargets[row.index] === true ? 'border-red-500/60' : 'border-slate-700 focus:border-cyan-500']"
              @input="onTargetInput(row.index, $event)"
            />
          </label>
          <div class="text-right" data-testid="planner-production-profit">
            <span class="block text-xs text-slate-500">{{ t('pi.planner.productions.profitPerDay') }}</span>
            <span v-if="row.profit !== null" :class="['font-mono-tech text-sm', row.profit >= 0 ? 'text-emerald-400' : 'text-red-400']">{{ formatPlannerIsk(row.profit) }}</span>
            <span v-else class="font-mono-tech text-sm text-slate-500">{{ t('pi.planner.unknown') }}</span>
          </div>
        </div>
        <p v-if="invalidTargets[row.index] === true" class="mt-1 text-xs text-red-400">{{ t('pi.planner.productions.targetInvalid') }}</p>
      </li>
    </ul>
  </section>
</template>
