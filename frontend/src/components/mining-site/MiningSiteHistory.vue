<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import { UNKNOWN_DASH, useMiningSiteFormat } from '@/composables/useMiningSiteFormat'
import { useMiningSiteStore, type MiningSite } from '@/stores/miningSite'
import { formatDuration } from '@/views/MiningOperation/miningOperationTime'
import MiningSiteCreateModal from './MiningSiteCreateModal.vue'

const COLUMNS = ['label', 'system', 'firstScan', 'lastScan', 'observed', 'volume', 'value', 'rate', 'status', 'actions'] as const

const { t } = useI18n()
const router = useRouter()
const store = useMiningSiteStore()
const { dateTimeText, wholeText, valueText } = useMiningSiteFormat()

const loading = ref(true)
const showCreation = ref(false)
const siteToClose = ref<MiningSite | null>(null)
const closing = ref(false)
const systemFilter = ref('')

interface SystemOption {
  solarSystemId: number
  name: string
}

const systemOptions = computed<SystemOption[]>(() => {
  const byId = new Map<number, SystemOption>()
  for (const site of store.sites) {
    if (site.solarSystemId === null || site.solarSystemId === undefined) continue
    byId.set(site.solarSystemId, { solarSystemId: site.solarSystemId, name: site.solarSystemName ?? `#${site.solarSystemId}` })
  }
  return [...byId.values()].sort((first, second) => first.name.localeCompare(second.name))
})

const shownSites = computed(() => (systemFilter.value === '' ? store.sites : store.sites.filter(site => String(site.solarSystemId) === systemFilter.value)))

function hasScan(site: MiningSite): boolean {
  return site.firstScanAt !== null && site.firstScanAt !== undefined
}

function scanDateText(moment: string | null | undefined): string {
  return moment === null || moment === undefined ? UNKNOWN_DASH : dateTimeText(moment)
}

function observedText(site: MiningSite): string {
  return site.observedSeconds === null || site.observedSeconds === undefined ? UNKNOWN_DASH : formatDuration(site.observedSeconds)
}

function volumeText(site: MiningSite): string {
  if (!hasScan(site)) return UNKNOWN_DASH
  return site.firstVolumeM3 === null || site.firstVolumeM3 === undefined ? t('miningSite.value.unknown') : `${wholeText(site.firstVolumeM3)} m³`
}

function firstValueText(site: MiningSite): string {
  return hasScan(site) ? valueText(site.firstValue) : UNKNOWN_DASH
}

function averageRateText(site: MiningSite): string {
  return site.averageTrendRate === null || site.averageTrendRate === undefined ? UNKNOWN_DASH : t('miningSite.unit.perMinute', { rate: wholeText(site.averageTrendRate) })
}

function isUnknownValue(site: MiningSite): boolean {
  return hasScan(site) && (site.firstValue === null || site.firstValue === undefined)
}

function openSite(site: MiningSite): void {
  router.push({ name: 'mining-site-detail', params: { id: site.id } })
}

async function confirmClose(): Promise<void> {
  const site = siteToClose.value
  if (site === null) return
  closing.value = true
  try {
    await store.close(site.id)
  } catch (cause) {
    console.error('Failed to close the mining site:', cause)
  } finally {
    closing.value = false
    siteToClose.value = null
  }
}

onMounted(async () => {
  await store.fetchSites()
  loading.value = false
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <p class="min-w-0 text-sm text-slate-400">{{ t('miningSite.history.hint') }}</p>
      <div class="flex flex-wrap items-center gap-3">
        <label v-if="systemOptions.length > 0" class="flex items-center gap-2 text-sm text-slate-400">
          <span>{{ t('miningSite.history.filter') }}</span>
          <select v-model="systemFilter" data-testid="system-filter" class="max-w-40 rounded-md border border-slate-700 bg-slate-950 px-2 py-1.5 text-sm text-slate-100">
            <option value="">{{ t('miningSite.history.allSystems') }}</option>
            <option v-for="option in systemOptions" :key="option.solarSystemId" :value="String(option.solarSystemId)">{{ option.name }}</option>
          </select>
        </label>
        <button
          type="button"
          data-testid="site-estimate"
          class="rounded-md bg-cyan-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-cyan-500"
          @click="showCreation = true"
        >{{ t('miningSite.history.estimate') }}</button>
      </div>
    </div>

    <p v-if="store.error !== null" class="eve-card mb-4 p-4 text-sm text-red-400" role="alert">{{ store.error }}</p>

    <div v-if="loading" class="flex justify-center py-16">
      <LoadingSpinner size="xl" class="text-cyan-400" />
    </div>

    <p v-else-if="store.sites.length === 0 && store.error === null" class="eve-card p-6 text-sm text-slate-300">{{ t('miningSite.history.empty') }}</p>

    <div v-else-if="store.sites.length > 0" class="eve-card overflow-x-auto p-2">
      <table class="w-full text-left text-sm">
        <thead>
          <tr class="border-b border-slate-800 font-mono text-xs uppercase tracking-wider text-slate-400">
            <th v-for="column in COLUMNS" :key="column" class="px-3 py-2 font-medium">{{ t(`miningSite.history.columns.${column}`) }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="site in shownSites"
            :key="site.id"
            :data-testid="`site-row-${site.id}`"
            class="cursor-pointer border-b border-slate-800/60 hover:bg-slate-800/40"
            @click="openSite(site)"
          >
            <td class="max-w-56 px-3 py-2.5">
              <router-link :to="{ name: 'mining-site-detail', params: { id: site.id } }" class="block text-slate-100 hover:text-cyan-200" @click.stop>
                <span class="block truncate font-display font-semibold" :title="site.label">{{ site.label }}</span>
              </router-link>
            </td>
            <td class="max-w-40 truncate px-3 py-2.5 text-slate-300" :title="site.solarSystemName ?? ''">{{ site.solarSystemName ?? t('miningSite.create.systemNone') }}</td>
            <td class="whitespace-nowrap px-3 py-2.5 font-mono text-xs text-cyan-200">{{ scanDateText(site.firstScanAt) }}</td>
            <td class="whitespace-nowrap px-3 py-2.5 font-mono text-xs text-cyan-200">{{ scanDateText(site.lastScanAt) }}</td>
            <td class="whitespace-nowrap px-3 py-2.5 font-mono text-slate-300">{{ observedText(site) }}</td>
            <td class="whitespace-nowrap px-3 py-2.5 text-right font-mono text-slate-300">{{ volumeText(site) }}</td>
            <td class="whitespace-nowrap px-3 py-2.5 text-right font-mono" :class="isUnknownValue(site) ? 'text-amber-300' : 'text-slate-100'">{{ firstValueText(site) }}</td>
            <td class="whitespace-nowrap px-3 py-2.5 text-right font-mono text-slate-300">{{ averageRateText(site) }}</td>
            <td class="px-3 py-2.5">
              <span
                class="whitespace-nowrap rounded-sm border px-2 py-0.5 font-mono text-xs"
                :class="site.status === 'active' ? 'border-cyan-500/40 text-cyan-200' : 'border-slate-600 text-slate-400'"
              >{{ t(`miningSite.status.${site.status}`) }}</span>
            </td>
            <td class="px-3 py-2.5">
              <button
                v-if="site.status === 'active'"
                type="button"
                :data-testid="`site-close-${site.id}`"
                class="whitespace-nowrap rounded-md border border-slate-600 px-3 py-1 text-xs text-slate-200 hover:bg-slate-800"
                @click.stop="siteToClose = site"
              >{{ t('miningSite.close.action') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <MiningSiteCreateModal :show="showCreation" @close="showCreation = false" />

    <ConfirmModal
      :show="siteToClose !== null"
      :title="t('miningSite.close.confirm')"
      :subtitle="siteToClose?.label"
      :is-loading="closing"
      @confirm="confirmClose"
      @cancel="siteToClose = null"
    />
  </div>
</template>
