<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { TIER_CONFIG } from '@/composables/usePlanetaryHelpers'
import { useFactoryGapDisplay } from '@/composables/useFactoryGapDisplay'
import { useFormatters } from '@/composables/useFormatters'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'

const UNKNOWN_FIGURE = '—'

const emit = defineEmits<{ open: [] }>()

const { t } = useI18n()
const store = usePlanetaryPlannerStore()
const { formatNumber } = useFormatters()
const { gapText, gapClass } = useFactoryGapDisplay()

const template = computed(() => store.followedTemplate)
const loading = computed(() => store.catalog === null)
const recipeMissing = computed(() => store.catalog !== null && store.followedTargetsOfPlan === null)

const tiers = computed(() =>
  store.followedFactoryComparison.filter(gap => !(gap.planned === 0 && (gap.real === 0 || gap.real === null))),
)

function onOpen(): void {
  const current = template.value
  if (current === null || loading.value) return
  store.loadTemplateIntoPlan(current.id)
  emit('open')
}
</script>

<template>
  <section
    v-if="template !== null"
    class="rounded-xl border border-emerald-500/30 bg-slate-900 p-4"
    data-testid="followed-plan-banner"
    :data-state="loading ? 'loading' : 'ready'"
  >
    <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
      <div class="min-w-0">
        <span class="block text-xs uppercase tracking-wider text-emerald-400">{{ t('pi.planner.follow.banner.title') }}</span>
        <span class="mt-0.5 block truncate text-base font-semibold text-white" data-testid="followed-plan-name" :title="template.name">{{ template.name }}</span>
      </div>

      <p v-if="loading" class="text-sm text-slate-400" data-testid="followed-plan-loading">{{ t('pi.planner.follow.banner.loading') }}</p>

      <p v-else-if="recipeMissing" class="text-sm text-amber-400" data-testid="followed-plan-missing">{{ t('pi.planner.follow.banner.missingRecipe') }}</p>

      <ul v-else class="flex flex-wrap gap-3">
        <li
          v-for="gap in tiers"
          :key="gap.tier"
          class="flex items-center gap-3 rounded-lg border border-slate-800 bg-slate-950/40 px-3 py-2 text-sm"
          data-testid="followed-plan-tier"
          :data-tier="gap.tier"
          :data-state="gap.state"
        >
          <span :class="['rounded-sm border px-1.5 py-0.5 text-xs', TIER_CONFIG[gap.tier].badgeBg, TIER_CONFIG[gap.tier].badgeText, TIER_CONFIG[gap.tier].badgeBorder]">{{ gap.tier }}</span>
          <span class="font-mono-tech text-slate-400">
            <span class="text-slate-200" data-testid="followed-plan-real">{{ gap.real === null ? UNKNOWN_FIGURE : formatNumber(gap.real, 0) }}</span>
            /
            <span data-testid="followed-plan-planned">{{ formatNumber(gap.planned, 0) }}</span>
          </span>
          <span class="font-mono-tech" :class="gapClass(gap.state)" data-testid="followed-plan-gap">{{ gapText(gap) }}</span>
        </li>
      </ul>

      <button
        type="button"
        data-testid="followed-plan-open"
        :disabled="recipeMissing || loading"
        class="rounded-md border border-emerald-500/40 px-4 py-1.5 text-sm text-emerald-300 transition-colors hover:bg-emerald-500/10 disabled:cursor-not-allowed disabled:border-slate-700 disabled:text-slate-600 disabled:hover:bg-transparent"
        @click="onOpen"
      >{{ t('pi.planner.follow.banner.open') }}</button>
    </div>
  </section>
</template>
