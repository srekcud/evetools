<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { MiningInvitationValuationSettings, MiningSplitMode } from '@/stores/miningOperation'

const props = defineProps<{
  settings: MiningInvitationValuationSettings
  splitMode: MiningSplitMode
  fleetStorageRecipientName: string | null
}>()

const { t } = useI18n()

const marketText = computed(() =>
  props.settings.valuationMarket === 'structure'
    ? (props.settings.structureName ?? t('miningOperation.valuation.market.structure'))
    : t('miningOperation.valuation.market.jita'),
)
const basisText = computed(() =>
  t('miningOperation.valuation.basis', {
    form: t(`miningOperation.valuation.form.${props.settings.valuationForm}`),
    market: marketText.value,
    side: t(`miningOperation.valuation.side.${props.settings.valuationSide}`),
  }),
)
const boosterText = computed(() => {
  const cut = `${props.settings.boosterCutPercent} %`
  return props.settings.boosterCharacterName === null ? cut : `${cut} (${props.settings.boosterCharacterName})`
})
const fleetStorageText = computed(() => {
  if (props.splitMode === 'each_for_themselves') return t('miningOperation.fleetStorage.eachForThemselves')
  return props.fleetStorageRecipientName ?? t('miningOperation.fleetStorage.none')
})
</script>

<template>
  <dl class="space-y-1 text-sm">
    <div class="flex flex-wrap gap-x-2">
      <dt class="text-slate-400">{{ t('miningOperation.valuation.form.label') }} :</dt>
      <dd class="min-w-0 truncate text-slate-200" :title="basisText">{{ basisText }}</dd>
    </div>
    <template v-if="splitMode === 'value_prorata'">
      <div class="flex flex-wrap gap-x-2">
        <dt class="text-slate-400">{{ t('miningOperation.valuation.boosterCut') }} :</dt>
        <dd class="min-w-0 truncate text-slate-200" :title="boosterText">{{ boosterText }}</dd>
      </div>
      <div class="flex flex-wrap gap-x-2">
        <dt class="text-slate-400">{{ t('miningOperation.valuation.corporationCut') }} :</dt>
        <dd class="text-slate-200">{{ settings.corporationCutPercent }} %</dd>
      </div>
    </template>
    <div class="flex flex-wrap gap-x-2">
      <dt class="text-slate-400">{{ t('miningOperation.rules.fleetStorage') }} :</dt>
      <dd class="min-w-0 truncate text-slate-200" :title="fleetStorageText">{{ fleetStorageText }}</dd>
    </div>
  </dl>
</template>
