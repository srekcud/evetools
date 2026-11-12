<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { DEFAULT_PI_EXPIRY_THRESHOLD_MINUTES, usePlanetaryTimers } from '@/composables/usePlanetaryTimers'
import { useTypeNames } from '@/composables/useTypeNames'
import type { FactoryProgress } from '@/stores/planetary'

const props = withDefaults(defineProps<{
  progress: FactoryProgress
  thresholdMinutes?: number
}>(), {
  thresholdMinutes: DEFAULT_PI_EXPIRY_THRESHOLD_MINUTES,
})

const { t } = useI18n()
const { formatNumber } = useFormatters()
const { typeNameLabel } = useTypeNames()
const { getTimerInfo, getTimerColorClasses } = usePlanetaryTimers(computed(() => props.thresholdMinutes))

const timer = computed(() => getTimerInfo(props.progress.endsAt))
const colors = computed(() => getTimerColorClasses(timer.value.status))
</script>

<template>
  <div data-testid="pi-factory-progress" class="flex items-center gap-3 flex-wrap">
    <div class="flex items-center gap-1.5">
      <div :class="['w-2 h-2 rounded-full', colors.dot, colors.pulse]"></div>
      <span
        data-testid="pi-factory-timer"
        :class="['font-mono-tech text-sm', colors.text, timer.status === 'expired' ? 'font-bold' : '']"
      >
        {{ timer.formatted }}
      </span>
    </div>
    <span class="text-slate-400 text-xs">
      {{ t('pi.colony.factoryProgress', {
        product: typeNameLabel(progress.finalProductName, progress.finalProductTypeId),
        stocked: formatNumber(progress.stockedFinalProduct, 0),
        max: formatNumber(progress.maxProducts, 0),
      }) }}
    </span>
  </div>
</template>
