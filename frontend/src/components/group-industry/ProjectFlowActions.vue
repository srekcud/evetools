<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import type { PlacedAction, PlacedActionCode } from '@/stores/group-industry/types'
import { groupActionsByPlace, reservedHoldersText, type PlaceGroup } from '@/utils/todoPlaces'
import { useFlowReasonLabel } from './useFlowReasonLabel'

const TYPED_ACTION_CODES: PlacedActionCode[] = ['launch_job', 'source_material']

const props = defineProps<{
  actions: PlacedAction[]
  loading: boolean
  error: string | null
}>()

const { t } = useI18n()
const { formatNumber } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()
const reasonLabel = useFlowReasonLabel()

const groups = computed(() => groupActionsByPlace(props.actions))
const isLoadingFirstRead = computed(() => props.loading && props.error === null && props.actions.length === 0)
const isEmpty = computed(() => !props.loading && props.error === null && props.actions.length === 0)

function placeTitle({ place }: PlaceGroup): string {
  if (place.kind === 'station') return place.name ?? t('groupIndustry.flow.unnamedStation')
  if (place.kind === 'anywhere') return t('groupIndustry.flow.actions.anywhere')
  return place.unknownReason === null ? t('groupIndustry.flow.actions.unknownPlace') : reasonLabel(place.unknownReason)
}

function reservedByOthersText(action: PlacedAction): string {
  return t('groupIndustry.flow.actions.reservedByOthers', {
    holders: reservedHoldersText(action.reservedByOthers, t('groupIndustry.flow.actions.unknownMember'), (quantity) => formatNumber(quantity, 0)),
  })
}

function hasTypeName(action: PlacedAction): boolean {
  return TYPED_ACTION_CODES.includes(action.code)
}
</script>

<template>
  <aside data-testid="project-flow-actions" class="border border-slate-800 bg-slate-950">
    <h2 class="border-b border-slate-800 px-3 py-2.5 font-display text-base font-semibold tracking-wide text-slate-100">{{ t('groupIndustry.flow.actions.title') }}</h2>
    <p v-if="error !== null" data-testid="flow-actions-error" role="alert" class="px-3 py-4 text-sm text-red-300">
      {{ t('groupIndustry.flow.actions.error') }} {{ error }}
    </p>
    <p v-else-if="isLoadingFirstRead" data-testid="flow-actions-loading" class="px-3 py-4 text-sm text-slate-400">{{ t('groupIndustry.flow.actions.loading') }}</p>
    <p v-else-if="isEmpty" data-testid="flow-actions-empty" class="px-3 py-4 text-sm text-slate-400">{{ t('groupIndustry.flow.actions.empty') }}</p>
    <section v-for="group in groups" :key="group.key" data-testid="flow-actions-place" class="border-b border-slate-800 last:border-b-0">
      <header class="flex items-center gap-2 px-3 pb-1 pt-2">
        <span data-testid="flow-actions-place-title" class="min-w-0 truncate font-display text-sm font-semibold tracking-wide text-cyan-300">{{ placeTitle(group) }}</span>
        <span
          v-if="group.here === true"
          data-testid="flow-actions-here"
          class="shrink-0 whitespace-nowrap rounded-sm border border-emerald-400/30 px-1.5 text-[10.5px] text-emerald-400"
        >{{ t('groupIndustry.flow.here') }}</span>
      </header>
      <div v-for="action in group.actions" :key="action.id" class="px-3 py-1.5">
      <div data-testid="flow-action-row" class="flex items-center gap-2">
        <span class="w-24 shrink-0 font-display text-xs font-semibold uppercase tracking-wider text-amber-400">{{ t(`groupIndustry.flow.actions.verbs.${action.code}`) }}</span>
        <img
          v-if="action.typeId !== null && hasTypeName(action)"
          :src="getTypeIconUrl(action.typeId, 32)"
          alt=""
          class="h-6 w-6 shrink-0 border border-slate-700"
          @error="onImageError"
        >
        <span v-if="hasTypeName(action)" class="min-w-0 flex-1 truncate text-sm text-slate-200">{{ action.typeName ?? t('groupIndustry.flow.unnamedType') }}</span>
        <span v-else class="flex-1" />
        <span v-if="action.quantity !== null" class="whitespace-nowrap text-xs tabular-nums text-slate-400">&times; {{ formatNumber(action.quantity, 0) }}</span>
      </div>
      <p v-if="action.reservedByOthers.length > 0" data-testid="flow-action-reserved-by-others" class="mt-0.5 text-xs text-cyan-400/80">{{ reservedByOthersText(action) }}</p>
      </div>
    </section>
  </aside>
</template>
