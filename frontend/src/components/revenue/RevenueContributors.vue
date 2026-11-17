<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { RevenueContributor, RevenueContributorUnit } from '@/types/revenueGrid'
import { hexWithAlpha, splitContributors } from '@/utils/revenueGrid'

const props = defineProps<{
  contributors: RevenueContributor[] | null
  unit: RevenueContributorUnit
  color: string
}>()

const { t } = useI18n()
const { formatIskFull } = useFormatters()

const NAMED_ALPHAS = [0.85, 0.5]
const REST_COLOR = 'rgba(100,116,139,.55)'

const split = computed(() => (props.contributors === null ? null : splitContributors(props.contributors)))
const tooltip = computed(() => (split.value === null ? '' : split.value.all.map(contributor => `${contributor.name} : ${formatIskFull(contributor.value)}`).join('\n')))
const segments = computed(() => {
  if (split.value === null) return []
  const named = split.value.named.map((contributor, index) => ({ key: String(contributor.id), share: contributor.sharePercent, fill: hexWithAlpha(props.color, NAMED_ALPHAS[index]) }))
  return split.value.restCount > 0 ? [...named, { key: 'rest', share: split.value.restSharePercent, fill: REST_COLOR }] : named
})
</script>

<template>
  <span v-if="split === null" class="font-mono text-xs text-slate-500" :title="t('revenue.grid.contributorsUnknown')">—</span>
  <div v-else-if="split.all.length > 0" class="w-full" :title="tooltip" data-testid="contributors">
    <div class="flex h-1.5 w-full gap-px">
      <span v-for="segment in segments" :key="segment.key" :style="{ width: `${segment.share}%`, background: segment.fill }"></span>
    </div>
    <div class="mt-1 space-y-0.5 text-xs leading-tight">
      <div v-for="contributor in split.named" :key="contributor.id" class="flex justify-between gap-2">
        <span class="max-w-[7.5rem] truncate text-slate-300" :title="contributor.name">{{ contributor.name }}</span>
        <span class="font-mono text-slate-400">{{ contributor.sharePercent }} %</span>
      </div>
      <div v-if="split.restCount > 0" class="flex justify-between gap-2 text-slate-500">
        <span>{{ t(`revenue.grid.more.${unit}`, { n: split.restCount }, split.restCount) }}</span>
        <span class="font-mono">{{ split.restSharePercent }} %</span>
      </div>
    </div>
  </div>
</template>
