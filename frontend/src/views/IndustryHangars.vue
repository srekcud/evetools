<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import CorpVisibilityPanel from '@/components/assets/CorpVisibilityPanel.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import SellMarketUnknownValue from '@/components/industry/SellMarketUnknownValue.vue'
import { useCorporationAssetVisibility } from '@/composables/useCorporationAssetVisibility'
import { usePilotName } from '@/composables/usePilotName'
import MainLayout from '@/layouts/MainLayout.vue'
import { useAuthStore } from '@/stores/auth'

type DivisionMode = 'all' | 'list' | 'hidden'

const MODE_LABEL_KEYS: Record<DivisionMode, string> = {
  all: 'assets.visibility.modeAll',
  list: 'assets.visibility.modeList',
  hidden: 'assets.visibility.modeHidden',
}

const MODE_CHIP_CLASSES: Record<DivisionMode, string> = {
  all: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400',
  list: 'border-cyan-500/30 bg-cyan-500/10 text-cyan-400',
  hidden: 'border-slate-600 bg-slate-800 text-slate-400',
}

const { t } = useI18n()
const router = useRouter()
const { pilotName } = usePilotName()
const authStore = useAuthStore()
const { visibility, visibilityPanel, isDirector, loadFailed, fetchVisibility, saveVisibility } = useCorporationAssetVisibility()

const divisions = computed(() => {
  const current = visibility.value
  if (current === null) return []
  return Object.entries(current.allDivisions)
    .map(([key, name]) => {
      const number = Number(key)
      const mode: DivisionMode = current.visibleDivisions.includes(number)
        ? 'all'
        : current.restrictedDivisions[number] !== undefined ? 'list' : 'hidden'
      return { number, name, mode, players: current.restrictedDivisions[number] ?? [] }
    })
    .sort((a, b) => a.number - b.number)
})

onMounted(fetchVisibility)

function backToWorkshop() {
  router.push({ name: 'industry' })
}
</script>

<template>
  <MainLayout>
    <div class="space-y-6">
      <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p class="text-xs font-mono uppercase tracking-[0.14em] text-cyan-400">
            {{ t('industry.hangars.kicker', { name: authStore.user?.corporationName ?? '' }) }}
          </p>
          <h1 class="font-display text-2xl font-bold uppercase tracking-[0.14em] text-slate-100">{{ t('industry.hangars.title') }}</h1>
          <p class="text-sm text-slate-400">{{ t('industry.hangars.subtitle') }}</p>
        </div>
        <button
          data-testid="hangars-back"
          class="px-3.5 py-1.5 rounded-lg text-sm font-medium text-slate-300 border border-slate-700 hover:border-cyan-500/50"
          @click="backToWorkshop"
        >{{ t('industry.hangars.back') }}</button>
      </div>

      <div v-if="loadFailed" data-testid="hangars-error">
        <ErrorBanner :message="t('industry.hangars.loadFailed')" />
      </div>

      <div v-else-if="visibility === null" class="flex justify-center py-12">
        <LoadingSpinner size="lg" class="text-cyan-500" />
      </div>

      <template v-else>
        <div
          v-if="!visibility.directorConnected"
          data-testid="hangars-no-director"
          class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-300"
        >{{ t('industry.hangars.noDirector') }}</div>
        <div
          v-else
          data-testid="hangars-director-info"
          class="rounded-xl border border-cyan-500/30 bg-cyan-500/10 px-4 py-3 text-sm text-cyan-300"
        >
          <template v-if="visibility.updatedAt !== null">{{ t('assets.visibility.configuredBy', { name: pilotName(visibility.configuredByName) }) }}. </template>
          {{ isDirector ? t('industry.hangars.directorHint') : t('industry.hangars.readOnlyHint') }}
        </div>

        <section class="bg-slate-900 rounded-xl border border-slate-800">
          <header class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
            <h2 class="font-display text-base font-bold uppercase tracking-[0.14em] text-slate-100">{{ t('industry.hangars.cardTitle') }}</h2>
            <span class="text-xs font-mono px-2 py-0.5 rounded border border-slate-700 text-slate-400">
              {{ visibility.directorConnected ? t('industry.hangars.divisionsCount', { count: divisions.length }) : t('industry.hangars.notReadable') }}
            </span>
          </header>

          <p
            v-if="divisions.length === 0"
            data-testid="hangars-empty"
            class="px-4 py-8 text-center text-sm text-slate-500"
          >{{ t('industry.hangars.empty') }}</p>
          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-xs uppercase tracking-wider text-slate-500 border-y border-slate-800">
                  <th class="px-4 py-2 font-medium">{{ t('industry.hangars.division') }}</th>
                  <th class="px-4 py-2 font-medium">{{ t('industry.hangars.visibleBy') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="division in divisions"
                  :key="division.number"
                  :data-testid="`hangars-division-${division.number}`"
                  class="border-b border-slate-800 last:border-b-0"
                >
                  <td class="px-4 py-2.5">
                    <span class="font-medium text-slate-200">{{ division.name }}</span>
                    <span class="ml-2 text-xs text-slate-500">{{ t('industry.hangars.divisionNumber', { number: division.number }) }}</span>
                  </td>
                  <td class="px-4 py-2.5">
                    <SellMarketUnknownValue v-if="!visibility.directorConnected" :cause="t('industry.hangars.unknown')" />
                    <span v-else class="flex flex-wrap items-center gap-2">
                      <span :class="['inline-flex px-2 py-0.5 rounded border text-xs font-medium', MODE_CHIP_CLASSES[division.mode]]">{{ t(MODE_LABEL_KEYS[division.mode]) }}</span>
                      <span v-for="player in division.players" :key="player.id" class="text-xs text-slate-400">{{ player.name }}</span>
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <CorpVisibilityPanel
          v-if="isDirector"
          ref="visibilityPanel"
          :visibility="visibility"
          @save="saveVisibility"
        />
      </template>
    </div>
  </MainLayout>
</template>
