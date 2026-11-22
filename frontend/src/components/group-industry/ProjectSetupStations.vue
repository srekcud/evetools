<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { ACTIVITY_STYLES } from './companionActivityStyles'
import { selectedStructureId, stationBannerOf, stationsSummary, type StationBanner, type StationPicks, type StationPolicy } from './projectSetup'
import type { ProjectWorkStations, WorkStationRow, WorkStationType } from '@/stores/group-industry/types'

const STRUCTURE_SETTINGS_LOCATION = { name: 'settings', params: { section: 'industry' } }

type PolicyChange = { acceptsOutsideFavoriteSystem: boolean } | { minimumOutsideSaving: number }

const props = defineProps<{
  workStations: ProjectWorkStations
  picks: StationPicks
  acceptsOutsideFavoriteSystem: boolean
  minimumOutsideSaving: number
  stationsOnly?: boolean
  policyBusy?: boolean
  policyError?: string | null
}>()
const emit = defineEmits<{ 'update:picks': [picks: StationPicks]; 'update:policy': [change: PolicyChange]; next: [] }>()

const { t } = useI18n()
const { formatIsk, formatNumber } = useFormatters()

const thresholdFieldId = useId()
const thresholdDraft = ref<number | string>(props.minimumOutsideSaving)

const policy = computed<StationPolicy>(() => ({
  acceptsOutsideFavoriteSystem: props.acceptsOutsideFavoriteSystem,
  minimumOutsideSaving: props.minimumOutsideSaving,
}))

const canSaveThreshold = computed(() =>
  typeof thresholdDraft.value === 'number'
  && Number.isFinite(thresholdDraft.value)
  && thresholdDraft.value >= 0
  && thresholdDraft.value !== props.minimumOutsideSaving
  && !props.policyBusy,
)

watch(() => props.minimumOutsideSaving, minimumOutsideSaving => {
  thresholdDraft.value = minimumOutsideSaving
})

watch(() => props.policyError, policyError => {
  if (policyError) thresholdDraft.value = props.minimumOutsideSaving
})

function toggleOutside(event: Event): void {
  const checkbox = event.target as HTMLInputElement
  emit('update:policy', { acceptsOutsideFavoriteSystem: checkbox.checked })
  checkbox.checked = props.acceptsOutsideFavoriteSystem
}

function saveThreshold(): void {
  if (!canSaveThreshold.value || typeof thresholdDraft.value !== 'number') return
  emit('update:policy', { minimumOutsideSaving: thresholdDraft.value })
}

function bannerOf(type: WorkStationType): StationBanner | null {
  return stationBannerOf(type, props.picks, policy.value)
}

function structureNameOf(structureId: string): string {
  return stationNames.value.get(structureId) ?? t('groupIndustry.setup.stations.unknownStation')
}

function bannerText(banner: StationBanner): string {
  return t(`groupIndustry.setup.stations.banner.${banner.kind}`, {
    structure: structureNameOf(banner.structureId),
    amount: formatIsk(banner.saving),
    threshold: formatIsk(props.minimumOutsideSaving),
  })
}

function switchPolicy(banner: StationBanner): void {
  emit('update:policy', { acceptsOutsideFavoriteSystem: banner.kind === 'onlyFavoriteSystem' })
}

function positionOf(row: WorkStationRow): string {
  if (row.inFavoriteSystem) return t('groupIndustry.setup.stations.position.favorite')
  const outside = t('groupIndustry.setup.stations.position.outside')
  const jumps = row.jumps === null ? t('groupIndustry.setup.stations.position.jumpsUnknown') : t('groupIndustry.setup.stations.position.jumps', row.jumps)
  return `${outside} · ${jumps}`
}

const summary = computed(() => stationsSummary(props.workStations, props.picks))

const stationNames = computed(() => {
  const names = new Map<string, string>()
  for (const type of props.workStations.workTypes) {
    for (const row of type.rows) names.set(row.structureId, row.name ?? t('groupIndustry.setup.stations.unknownStation'))
  }
  return names
})

const chosenStationNames = computed(() => summary.value.stationIds.map(id => stationNames.value.get(id) ?? t('groupIndustry.setup.stations.unknownStation')))

const allRecommendedPicks = computed<StationPicks>(() => {
  const picks: StationPicks = {}
  for (const type of props.workStations.workTypes) {
    if (!type.locked && type.recommendedStructureId !== null) picks[type.workType] = type.recommendedStructureId
  }
  return picks
})

const hasStaleChoice = computed(() => props.workStations.workTypes.some(type => type.choiceStale))

function styleOf(type: WorkStationType) {
  return ACTIVITY_STYLES[type.activity]
}

function choose(type: WorkStationType, row: WorkStationRow): void {
  emit('update:picks', { ...props.picks, [type.workType]: row.structureId })
}

function amountOf(amount: number | null): string {
  return amount === null ? t('groupIndustry.setup.stations.unknown') : formatIsk(amount)
}

function deltaOf(row: WorkStationRow): string {
  if (row.delta === null) return t('groupIndustry.setup.stations.unknown')
  return row.delta > 0 ? `+ ${formatIsk(row.delta)}` : t('groupIndustry.setup.stations.cheapest')
}

function legLabel(leg: WorkStationRow['transportLegs'][number]): string {
  const key = leg.cost === null ? 'legUnknown' : 'legLine'
  return t(`groupIndustry.setup.stations.${key}`, { kind: t(`groupIndustry.setup.stations.legs.${leg.kind}`), volume: formatNumber(leg.cubicMeters, 0) })
}

const gapLabel = computed(() => {
  const gap = summary.value.gapToRecommended
  if (gap === null) return t('groupIndustry.setup.stations.unknown')
  return gap > 0
    ? t('groupIndustry.setup.stations.againstRecommended', { amount: formatIsk(gap) })
    : t('groupIndustry.setup.stations.allRecommended')
})
</script>

<template>
  <section data-testid="stations-step">
    <header class="mb-4">
      <h2 class="text-lg font-bold text-slate-100">{{ t('groupIndustry.setup.stations.title') }}</h2>
      <p class="text-sm text-slate-400">{{ t('groupIndustry.setup.stations.intro') }}</p>
    </header>

    <section data-testid="stations-policy" class="mb-4 rounded-xl border border-slate-700 bg-slate-900/70 p-3">
      <h3 class="mb-2 text-xs uppercase tracking-wider text-slate-400">{{ t('groupIndustry.setup.stations.policy.title') }}</h3>
      <label class="flex items-center gap-2 text-sm text-slate-200">
        <input
          type="checkbox"
          data-testid="stations-policy-outside"
          :checked="acceptsOutsideFavoriteSystem"
          :disabled="policyBusy"
          class="accent-cyan-500"
          @change="toggleOutside"
        >
        {{ t('groupIndustry.setup.stations.policy.outsideLabel') }}
      </label>
      <form v-if="acceptsOutsideFavoriteSystem" class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="saveThreshold">
        <div>
          <label :for="thresholdFieldId" class="mb-1.5 block text-xs text-slate-500">{{ t('groupIndustry.setup.stations.policy.thresholdLabel') }}</label>
          <input
            :id="thresholdFieldId"
            v-model.number="thresholdDraft"
            data-testid="stations-policy-threshold-input"
            type="number"
            min="0"
            step="any"
            class="form-input w-44 rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 font-mono text-sm text-slate-200"
          >
        </div>
        <button
          type="button"
          data-testid="stations-policy-threshold-save"
          :disabled="!canSaveThreshold"
          class="rounded border border-cyan-500/40 px-3 py-2 text-xs text-cyan-300 hover:bg-cyan-500/10 disabled:cursor-not-allowed disabled:opacity-50"
          @click="saveThreshold"
        >{{ t('groupIndustry.setup.stations.policy.save') }}</button>
        <p class="min-w-0 flex-1 basis-64 text-xs text-slate-500">{{ t('groupIndustry.setup.stations.policy.hint') }}</p>
      </form>
      <p v-if="policyError" data-testid="stations-policy-error" role="alert" class="mt-3 rounded border border-red-500/30 bg-red-500/10 p-2 text-sm text-red-300">{{ policyError }}</p>
    </section>

    <p v-if="hasStaleChoice" class="mb-3 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-300">
      {{ t('groupIndustry.setup.stations.stale') }}
    </p>
    <div
      v-if="workStations.structuresToSpecify.length > 0"
      data-testid="hull-to-specify-notice"
      class="mb-3 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-300"
    >
      <p class="font-medium">{{ t('groupIndustry.setup.stations.hullToSpecify.title') }}</p>
      <ul class="mt-1 list-disc pl-5">
        <li v-for="structure in workStations.structuresToSpecify" :key="structure.id">{{ t('groupIndustry.setup.stations.hullToSpecify.item', { name: structure.name }) }}</li>
      </ul>
      <router-link :to="STRUCTURE_SETTINGS_LOCATION" class="mt-1 inline-block underline hover:text-amber-200">{{ t('groupIndustry.setup.stations.taxNotSetLink') }}</router-link>
    </div>
    <p v-if="workStations.workTypes.length === 0" class="mb-4 text-sm text-slate-400">
      {{ t('groupIndustry.setup.stations.empty') }}
    </p>

    <div class="space-y-3">
      <article
        v-for="type in workStations.workTypes"
        :key="type.workType"
        :data-testid="`station-row-${type.workType}`"
        class="grid gap-3 rounded-xl border border-l-4 border-slate-700 bg-slate-900/70 p-3 lg:grid-cols-[14rem_1fr]"
        :class="styleOf(type).stripe"
      >
        <div class="min-w-0">
          <h3 class="font-semibold text-slate-100">{{ t(`groupIndustry.setup.stations.workTypes.${type.workType}`) }}</h3>
          <p class="text-xs text-slate-400">{{ t('groupIndustry.setup.stations.need', { jobs: type.jobs, runs: type.runs }) }}</p>
          <p class="mt-1 text-xs" :class="styleOf(type).text">{{ t(`groupIndustry.setup.stations.activities.${type.activity}`) }}</p>
          <p v-if="type.locked && type.lockReason !== null" :data-testid="`station-lock-${type.workType}`" class="mt-2 text-xs text-amber-300">
            {{ t(`groupIndustry.setup.stations.locks.${type.lockReason}`) }}
          </p>
        </div>

        <div class="min-w-0">
          <template v-for="banner in [bannerOf(type)]" :key="banner?.kind ?? 'none'">
            <div
              v-if="banner !== null"
              :data-testid="`station-banner-${type.workType}`"
              role="status"
              class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-300"
            >
              <span class="min-w-0 break-words">{{ bannerText(banner) }}</span>
              <button
                type="button"
                :data-testid="`station-banner-${type.workType}-switch`"
                :disabled="policyBusy"
                class="rounded border border-current px-2 py-1 text-xs hover:bg-slate-100/10 disabled:cursor-not-allowed disabled:opacity-50"
                @click="switchPolicy(banner)"
              >{{ t(banner.kind === 'onlyFavoriteSystem' ? 'groupIndustry.setup.stations.banner.allow' : 'groupIndustry.setup.stations.banner.revert') }}</button>
            </div>
          </template>
          <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <label
              v-for="row in type.rows"
              :key="row.structureId"
              :data-testid="`station-card-${type.workType}-${row.structureId}`"
              class="block rounded-lg border p-3 text-sm transition-colors"
              :class="[
                selectedStructureId(type, picks) === row.structureId ? 'border-cyan-500/70 bg-cyan-500/10' : 'border-slate-700 bg-slate-950/60',
                type.locked ? 'cursor-not-allowed opacity-70' : 'cursor-pointer hover:border-cyan-500/40',
              ]"
            >
              <span class="flex items-center gap-2">
                <input
                  type="radio"
                  :name="`station-${type.workType}`"
                  :data-testid="`station-radio-${type.workType}-${row.structureId}`"
                  :checked="selectedStructureId(type, picks) === row.structureId"
                  :disabled="type.locked"
                  class="accent-cyan-500"
                  @click="choose(type, row)"
                >
                <b class="min-w-0 truncate text-slate-100" :title="row.name ?? undefined">{{ row.name ?? t('groupIndustry.setup.stations.unknownStation') }}</b>
                <span
                  v-if="type.recommendedStructureId === row.structureId"
                  :data-testid="`station-recommended-${type.workType}-${row.structureId}`"
                  class="rounded bg-emerald-500/15 px-1.5 py-0.5 text-xs text-emerald-300"
                >{{ t('groupIndustry.setup.stations.recommended') }}</span>
              </span>
              <span class="mt-1 block text-xs text-slate-400">{{ [row.kind, row.solarSystemName].filter(part => part !== null).join(' · ') }}</span>
              <span :data-testid="`station-position-${type.workType}-${row.structureId}`" class="mt-0.5 block text-xs text-slate-400">{{ positionOf(row) }}</span>
              <span
                v-if="row.facilityTaxPercent === null"
                :data-testid="`station-tax-not-set-${type.workType}-${row.structureId}`"
                class="mt-0.5 block text-xs text-amber-300"
              >
                {{ t('groupIndustry.setup.stations.taxNotSet') }}
                <router-link :to="STRUCTURE_SETTINGS_LOCATION" class="underline hover:text-amber-200" @click.stop>{{ t('groupIndustry.setup.stations.taxNotSetLink') }}</router-link>
              </span>
              <span v-else :data-testid="`station-tax-${type.workType}-${row.structureId}`" class="mt-0.5 block text-xs text-slate-400">
                {{ t('groupIndustry.setup.stations.taxRate', { percent: `${formatNumber(row.facilityTaxPercent)} %` }) }}
              </span>

              <dl v-if="type.costsCompared" class="mt-2 space-y-0.5 text-xs">
                <div class="flex justify-between gap-2">
                  <dt class="text-slate-400">{{ t('groupIndustry.setup.stations.materialBonus') }}</dt>
                  <dd class="text-slate-200">{{ formatNumber(row.materialBonusPercent) }} %</dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt class="text-slate-400">{{ t('groupIndustry.setup.stations.materials') }}</dt>
                  <dd class="text-slate-200">{{ amountOf(row.materialsCost) }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt class="text-slate-400">{{ t('groupIndustry.setup.stations.installation') }}</dt>
                  <dd class="text-slate-200">{{ amountOf(row.installationCost) }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt class="text-slate-400">{{ t('groupIndustry.setup.stations.transport') }}</dt>
                  <dd class="text-slate-200">{{ amountOf(row.transportCost) }}</dd>
                </div>
                <div v-for="(leg, index) in row.transportLegs" :key="index" class="flex justify-between gap-2 pl-2 text-slate-500">
                  <dt>{{ legLabel(leg) }}</dt>
                  <dd>{{ amountOf(leg.cost) }}</dd>
                </div>
                <div class="flex justify-between gap-2 border-t border-slate-700 pt-0.5 font-semibold">
                  <dt class="text-slate-300">{{ t('groupIndustry.setup.stations.total') }}</dt>
                  <dd class="text-slate-100">{{ amountOf(row.totalCost) }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt class="text-slate-400">{{ t('groupIndustry.setup.stations.delta') }}</dt>
                  <dd :class="row.delta !== null && row.delta > 0 ? 'text-amber-300' : 'text-emerald-300'">{{ deltaOf(row) }}</dd>
                </div>
              </dl>
              <p v-else class="mt-2 text-xs text-slate-500">{{ t('groupIndustry.setup.stations.notCompared') }}</p>
            </label>
          </div>
        </div>
      </article>
    </div>

    <section class="mt-4 grid gap-3 rounded-xl border border-slate-700 bg-slate-900/70 p-4 md:grid-cols-4">
      <div>
        <p class="text-xs uppercase tracking-wider text-slate-400">{{ t('groupIndustry.setup.stations.summaryCost') }}</p>
        <p data-testid="stations-total" class="text-xl font-bold text-slate-100">{{ amountOf(summary.totalCost) }}</p>
        <p data-testid="stations-gap" class="text-xs" :class="summary.gapToRecommended !== null && summary.gapToRecommended > 0 ? 'text-amber-300' : 'text-emerald-300'">{{ gapLabel }}</p>
      </div>
      <div>
        <p class="text-xs uppercase tracking-wider text-slate-400">{{ t('groupIndustry.setup.stations.summaryTransport') }}</p>
        <p data-testid="stations-transport" class="text-xl font-bold text-slate-100">{{ amountOf(summary.transportCost) }}</p>
      </div>
      <div>
        <p class="text-xs uppercase tracking-wider text-slate-400">{{ t('groupIndustry.setup.stations.summaryStations') }}</p>
        <p data-testid="stations-count" class="text-sm text-slate-200">{{ chosenStationNames.join(', ') }}</p>
      </div>
      <div class="flex flex-col justify-center gap-2">
        <button
          type="button"
          data-testid="stations-all-recommended"
          class="rounded-lg border border-slate-600 px-3 py-2 text-sm text-slate-200 hover:border-cyan-500/50"
          @click="emit('update:picks', allRecommendedPicks)"
        >
          {{ t('groupIndustry.setup.stations.allToRecommended') }}
        </button>
        <button
          type="button"
          data-testid="stations-next"
          class="rounded-lg bg-cyan-600 px-3 py-2 text-sm font-medium text-white hover:bg-cyan-500"
          @click="emit('next')"
        >
          {{ stationsOnly ? t('groupIndustry.setup.saveStations') : t('groupIndustry.setup.stations.next') }}
        </button>
      </div>
    </section>

    <p v-if="workStations.courierIskPerCubicMeter !== null && workStations.jumpBridgeIskPerCubicMeter !== null" class="mt-3 text-xs text-slate-500">
      {{ t('groupIndustry.setup.stations.footnote', { courier: formatNumber(workStations.courierIskPerCubicMeter), bridge: formatNumber(workStations.jumpBridgeIskPerCubicMeter) }) }}
    </p>
    <p v-else class="mt-3 text-xs text-amber-300">{{ t('groupIndustry.setup.stations.footnoteUnknownRates') }}</p>
  </section>
</template>
