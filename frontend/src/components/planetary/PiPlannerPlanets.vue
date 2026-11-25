<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { TIER_CONFIG } from '@/composables/usePlanetaryHelpers'
import { useEveImages } from '@/composables/useEveImages'
import { usePlannerDisplay } from '@/composables/usePlannerDisplay'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import { parseDecimalInput } from '@/utils/planetaryPlanner'
import { PLANETS_PER_CHARACTER, type ExtractionPlanetRow, type FactoryPlanetRow } from '@/utils/planetaryPlanets'

const ICON_SIZE = 32
const YIELD_DECIMALS = 2
const PERCENT = 100
const UNKNOWN = 'unknown'
const SEGMENT_COLORS = ['bg-cyan-500', 'bg-violet-500', 'bg-amber-500', 'bg-fuchsia-500', 'bg-sky-500', 'bg-lime-500'] as const
const GRID_COLUMNS = 'grid min-w-[56rem] grid-cols-[minmax(13rem,1.2fr)_minmax(8rem,0.8fr)_7rem_minmax(18rem,1.6fr)_5rem] items-start gap-3'

const { t } = useI18n()
const store = usePlanetaryPlannerStore()
const { getTypeIconUrl, onImageError } = useEveImages()
const { typeOf, formatNumber } = usePlannerDisplay()

const yieldDrafts = ref<Record<number, string>>({})
const invalidYields = ref<Record<number, boolean>>({})

const plan = computed(() => store.planetPlan)

const rows = computed(() =>
  (plan.value?.rows ?? []).map(row => ({
    row,
    key: row.role === 'factory' ? row.tier : String(row.typeId),
    name: row.role === 'extraction' ? typeOf(row.typeId).name : null,
  })),
)

const missingPlanets = computed(() => {
  const current = plan.value
  if (current === null || current.accountMaxPlanets === null) return null
  return current.totalPlanets > current.accountMaxPlanets ? current.totalPlanets - current.accountMaxPlanets : null
})

const slotScale = computed(() => {
  const current = plan.value
  if (current === null) return 0
  return Math.max(current.totalPlanets, current.accountMaxPlanets === null ? 0 : current.accountMaxPlanets)
})

const slotSegments = computed(() =>
  store.productions.map((production, index) => ({
    index,
    name: typeOf(production.typeId).name,
    planets: plan.value === null ? 0 : plan.value.addedByProduction[index],
  })).filter(segment => segment.planets > 0),
)

function segmentWidth(planets: number): string {
  return `${(planets / slotScale.value) * PERCENT}%`
}

function yieldText(row: ExtractionPlanetRow): string {
  return row.yieldPerHour === null ? '' : String(Number(row.yieldPerHour.toFixed(YIELD_DECIMALS)))
}

function yieldValue(row: ExtractionPlanetRow): string {
  const draft = yieldDrafts.value[row.typeId]
  return draft === undefined ? yieldText(row) : draft
}

function onYieldInput(typeId: number, event: Event): void {
  const raw = (event.target as HTMLInputElement).value
  yieldDrafts.value = { ...yieldDrafts.value, [typeId]: raw }
  invalidYields.value = { ...invalidYields.value, [typeId]: !store.setExtractionYield(typeId, parseDecimalInput(raw)) }
}

function isYieldInvalid(typeId: number): boolean {
  return invalidYields.value[typeId] === true
}

function planetsState(row: FactoryPlanetRow | ExtractionPlanetRow): 'known' | 'unknown' {
  return row.planets === null ? 'unknown' : 'known'
}

function rowStatus(row: FactoryPlanetRow | ExtractionPlanetRow): string {
  if (row.role === 'factory') return row.status
  return row.yieldPerHour === null ? 'unknownYield' : 'ok'
}
</script>

<template>
  <section v-if="plan !== null && store.productions.length > 0" class="rounded-xl border border-slate-800 bg-slate-900 p-5" data-testid="planner-planets">
    <div class="mb-4">
      <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.planner.planets.title') }}</h3>
      <p class="mt-1 max-w-3xl text-xs text-slate-500">{{ t('pi.planner.planets.subtitle') }}</p>
    </div>

    <div
      v-if="missingPlanets !== null"
      role="alert"
      class="mb-4 rounded-lg border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-300"
      data-testid="planets-shortfall"
      :data-missing="missingPlanets"
    >
      <span class="font-semibold">{{ t('pi.planner.planets.shortfall.title') }}</span>
      <span class="ml-2">{{ t('pi.planner.planets.shortfall.text', { missing: formatNumber(missingPlanets, 0), max: formatNumber(plan.accountMaxPlanets, 0), total: formatNumber(plan.totalPlanets, 0) }) }}</span>
    </div>

    <div class="overflow-x-auto">
      <div :class="[GRID_COLUMNS, 'border-b border-slate-700 px-2 pb-2 text-xs uppercase tracking-wider text-slate-500']">
        <span>{{ t('pi.planner.planets.columns.role') }}</span>
        <span>{{ t('pi.planner.planets.columns.types') }}</span>
        <span class="text-right">{{ t('pi.planner.planets.columns.need') }}</span>
        <span>{{ t('pi.planner.planets.columns.layout') }}</span>
        <span class="text-right">{{ t('pi.planner.planets.columns.planets') }}</span>
      </div>

      <div
        v-for="entry in rows"
        :key="entry.key"
        :class="[GRID_COLUMNS, 'border-b border-slate-800/70 px-2 py-2 text-sm']"
        data-testid="planets-row"
        :data-key="entry.key"
        :data-role="entry.row.role"
        :data-status="rowStatus(entry.row)"
      >
        <div class="min-w-0">
          <div v-if="entry.row.role === 'factory'" class="flex items-center gap-2">
            <span :class="['shrink-0 rounded-sm border px-1.5 py-0.5 text-xs', TIER_CONFIG[entry.row.tier].badgeBg, TIER_CONFIG[entry.row.tier].badgeText, TIER_CONFIG[entry.row.tier].badgeBorder]">{{ entry.row.tier }}</span>
            <span class="truncate text-slate-100">{{ t('pi.planner.planets.roles.factory') }}</span>
          </div>
          <template v-else>
            <div class="flex items-center gap-2">
              <img :src="getTypeIconUrl(entry.row.typeId, ICON_SIZE)" alt="" class="h-5 w-5 shrink-0 rounded-sm" @error="onImageError" />
              <span class="min-w-0 truncate text-slate-100" data-testid="planets-resource-name" :title="entry.name ?? undefined">{{ entry.name }}</span>
            </div>
            <p class="mt-1 text-xs text-slate-500" data-testid="planets-p1-factories">{{ t('pi.planner.planets.p1Factories', { count: entry.row.p1Factories }, entry.row.p1Factories) }}</p>
          </template>
        </div>

        <span class="min-w-0 text-slate-300" data-testid="planets-types">
          <template v-if="entry.row.planetTypes.length > 0">{{ entry.row.planetTypes.join(', ') }}</template>
          <span v-else class="text-slate-500">{{ t('pi.planner.planets.typesUnknown') }}</span>
        </span>

        <span class="text-right font-mono-tech text-slate-300" data-testid="planets-need">
          <template v-if="entry.row.role === 'factory'">{{ t('pi.planner.planets.need.factories', { count: formatNumber(entry.row.factoriesNeeded, 0) }, entry.row.factoriesNeeded) }}</template>
          <template v-else>{{ t('pi.planner.planets.need.perHour', { count: formatNumber(entry.row.perHour, 0) }) }}</template>
        </span>

        <div class="min-w-0 text-slate-300">
          <template v-if="entry.row.role === 'factory'">
            <span
              v-if="entry.row.layout !== null && entry.row.status === 'ok'"
              data-testid="planets-layout"
              :data-launchpads="entry.row.layout.launchpads"
              :data-factories="entry.row.layout.factories"
            >
              <span class="font-mono-tech text-slate-100">{{ t('pi.planner.planets.layout.launchpads', { count: entry.row.layout.launchpads }, entry.row.layout.launchpads) }} + {{ t('pi.planner.planets.layout.installations', { count: entry.row.layout.factories }, entry.row.layout.factories) }}</span>
              <span class="block text-xs text-slate-500">{{ t('pi.planner.planets.layout.volume', { volume: formatNumber(entry.row.layout.dailyVolume * entry.row.layout.days, 0), capacity: formatNumber(entry.row.layout.capacity, 0), days: formatNumber(entry.row.layout.days, 1) }) }}</span>
            </span>
            <span v-else-if="entry.row.layout !== null" class="text-amber-400" data-testid="planets-layout">{{ t('pi.planner.planets.layout.doesNotFit', { days: formatNumber(entry.row.layout.days, 1) }) }}</span>
            <span v-else class="text-amber-400" data-testid="planets-layout">{{ t('pi.planner.planets.layout.unknown') }}</span>
          </template>
          <template v-else>
            <label class="flex items-center gap-2">
              <input
                type="text"
                inputmode="decimal"
                data-testid="planets-yield-input"
                :data-source="entry.row.yieldSource === null ? 'none' : entry.row.yieldSource"
                :value="yieldValue(entry.row)"
                :aria-label="t('pi.planner.planets.yield.label')"
                :aria-invalid="isYieldInvalid(entry.row.typeId)"
                :class="['w-28 rounded-md border bg-slate-800 px-2 py-1 font-mono-tech text-sm text-white focus:outline-hidden', isYieldInvalid(entry.row.typeId) ? 'border-red-500/60' : 'border-slate-700 focus:border-cyan-500']"
                @input="onYieldInput(entry.row.typeId, $event)"
              />
              <span class="text-xs text-slate-500">{{ t('pi.planner.planets.yield.unit') }}</span>
            </label>
            <p v-if="isYieldInvalid(entry.row.typeId)" class="mt-1 text-xs text-red-400">{{ t('pi.planner.planets.yield.invalid') }}</p>
            <p v-else-if="entry.row.yieldSource === null" class="mt-1 text-xs text-amber-400">{{ t('pi.planner.planets.yield.unknown') }}</p>
            <p v-else class="mt-1 text-xs text-slate-500">{{ t(`pi.planner.planets.yield.${entry.row.yieldSource}`) }}</p>
          </template>
        </div>

        <span
          class="text-right font-mono-tech"
          :class="entry.row.planets === null ? 'text-slate-500' : 'text-white'"
          data-testid="planets-count"
          :data-state="planetsState(entry.row)"
          :data-planets="entry.row.planets === null ? undefined : entry.row.planets"
        >{{ entry.row.planets === null ? (entry.row.role === 'extraction' ? t('pi.planner.unknown') : '-') : formatNumber(entry.row.planets, 0) }}</span>
      </div>
    </div>

    <div class="mt-4 grid gap-4 md:grid-cols-2">
      <div class="rounded-lg border border-slate-800 bg-slate-950/40 p-4" data-testid="planets-total" :data-planets="plan.totalPlanets" :data-incomplete="plan.incomplete" :data-characters="plan.charactersNeeded">
        <span class="block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.planets.total.title') }}</span>
        <span class="mt-1 block font-mono-tech text-2xl text-white">{{ t('pi.planner.planets.total.planets', { count: formatNumber(plan.totalPlanets, 0) }, plan.totalPlanets) }}</span>
        <span class="block text-sm text-slate-300">{{ t('pi.planner.planets.total.characters', { count: formatNumber(plan.charactersNeeded, 0), perCharacter: PLANETS_PER_CHARACTER }, plan.charactersNeeded) }}</span>
        <span v-if="plan.incomplete" class="mt-1 block text-xs text-amber-400">{{ t('pi.planner.planets.total.incomplete') }}</span>
      </div>

      <div class="rounded-lg border border-slate-800 bg-slate-950/40 p-4" data-testid="planets-account" :data-used="plan.usedPlanets === null ? UNKNOWN : plan.usedPlanets" :data-max="plan.accountMaxPlanets === null ? UNKNOWN : plan.accountMaxPlanets">
        <span class="block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.planets.total.account') }}</span>
        <span class="mt-1 block text-sm text-slate-300">
          {{ t('pi.planner.planets.total.used') }}
          <span class="font-mono-tech text-slate-100">{{ plan.usedPlanets === null ? t('pi.planner.unknown') : formatNumber(plan.usedPlanets, 0) }}</span>
        </span>
        <span class="block text-sm text-slate-300">
          {{ t('pi.planner.planets.total.max') }}
          <span class="font-mono-tech" :class="plan.accountMaxPlanets === null ? 'text-slate-500' : 'text-slate-100'">{{ plan.accountMaxPlanets === null ? t('pi.planner.planets.total.maxUnknown') : formatNumber(plan.accountMaxPlanets, 0) }}</span>
        </span>
      </div>
    </div>

    <div class="mt-4 rounded-lg border border-slate-800 bg-slate-950/40 p-4" data-testid="planets-slots" :data-free="plan.freePlanets === null ? UNKNOWN : plan.freePlanets">
      <span class="block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.planets.slots.title') }}</span>
      <div v-if="slotScale > 0" class="mt-2 flex h-3 overflow-hidden rounded-full bg-slate-800">
        <span
          v-for="segment in slotSegments"
          :key="segment.index"
          :class="SEGMENT_COLORS[segment.index % SEGMENT_COLORS.length]"
          :style="{ width: segmentWidth(segment.planets) }"
          :title="`${segment.name}: ${segment.planets}`"
        />
        <span v-if="plan.freePlanets !== null" class="bg-emerald-500/60" :style="{ width: segmentWidth(plan.freePlanets) }" />
      </div>
      <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-400">
        <li v-for="segment in slotSegments" :key="segment.index" class="flex min-w-0 items-center gap-1.5">
          <span :class="['h-2 w-2 shrink-0 rounded-full', SEGMENT_COLORS[segment.index % SEGMENT_COLORS.length]]" />
          <span class="max-w-48 truncate" :title="segment.name">{{ segment.name }}</span>
          <span class="font-mono-tech text-slate-200">{{ t('pi.planner.planets.slots.occupied', { count: formatNumber(segment.planets, 0) }, segment.planets) }}</span>
        </li>
        <li v-if="plan.freePlanets !== null" class="flex items-center gap-1.5" data-testid="planets-slots-free">
          <span class="h-2 w-2 shrink-0 rounded-full bg-emerald-500/60" />
          <span>{{ t('pi.planner.planets.slots.free') }}</span>
          <span class="font-mono-tech text-slate-200">{{ formatNumber(plan.freePlanets, 0) }}</span>
        </li>
        <li v-else class="text-slate-500">{{ t('pi.planner.planets.slots.freeUnknown') }}</li>
      </ul>
    </div>
  </section>
</template>
