<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatChunkTime, type CorporationMiningExtractions } from '@/composables/useCorporationMiningExtractions'
import { MOON_TAX_WALLET_SCOPE, useMoonTaxCorporation, type MoonTaxRefinerySetting } from '@/composables/useMoonTax'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import MoonTaxDonationsPanel from './MoonTaxDonationsPanel.vue'
import MoonTaxRefineryForm from './MoonTaxRefineryForm.vue'
import MoonTaxStatementsTable from './MoonTaxStatementsTable.vue'
import MoonTaxTotals from './MoonTaxTotals.vue'

const props = defineProps<{
  entry: CorporationMiningExtractions
  corporationLabel: string
  structureLabel: (structureId: number) => string
}>()

type AccessKind = 'ledger' | 'payments'

interface AccessAlert {
  kind: AccessKind
  state: string
  syncedAt: string | null
}

const EVE_TIME_ZONE = 'UTC'
const OK_ACCESS_STATE = 'ok'
const FILTERS = ['all', 'late']
const TOGGLE_CLASSES = 'px-3 py-1 rounded-sm border text-xs font-medium uppercase tracking-wider transition-colors'
const ACTIVE_TOGGLE_CLASSES = 'border-cyan-500/60 bg-cyan-500/10 text-cyan-300'
const IDLE_TOGGLE_CLASSES = 'border-slate-700 text-slate-400 hover:text-slate-200'

const { t, te } = useI18n()
const {
  corporation,
  statements,
  accessFailure,
  isLoading,
  isLoadingStatements,
  hasLoadError,
  hasStatementsError,
  lateOnly,
  savingStructureId,
  savedStructureId,
  saveErrors,
  busyStatementId,
  rowErrors,
  isSyncing,
  syncError,
  syncPercent,
  isExporting,
  exportError,
  loadAll,
  loadStatements,
  setLateOnly,
  saveRefinery,
  runStatementAction,
  exportCsv,
  requestSync,
} = useMoonTaxCorporation(props.entry.corporationId)

const refineryIds = computed<number[]>(() => {
  const configuredIds = corporation.value?.refineries.map(setting => setting.structureId) ?? []
  const extractionIds = props.entry.extractions.map(extraction => extraction.structureId)
  return Array.from(new Set([...extractionIds, ...configuredIds]))
})

const accessAlerts = computed<AccessAlert[]>(() => {
  if (corporation.value === null) return []
  const { access, observersSyncedAt, walletSyncedAt } = corporation.value
  const alerts: AccessAlert[] = [
    { kind: 'ledger', state: access.ledger, syncedAt: observersSyncedAt },
    { kind: 'payments', state: access.payments, syncedAt: walletSyncedAt },
  ]
  return alerts.filter(alert => alert.state !== OK_ACCESS_STATE)
})

const isLedgerRead = computed(() => corporation.value?.access.ledger === OK_ACCESS_STATE)
const syncLabel = computed(() => {
  if (!isSyncing.value) return t('ledger.mining.moonTax.sync.button')
  return syncPercent.value === null
    ? t('ledger.mining.moonTax.sync.syncing')
    : t('ledger.mining.moonTax.sync.progress', { percent: syncPercent.value })
})

function settingOf(structureId: number): MoonTaxRefinerySetting | null {
  return corporation.value?.refineries.find(setting => setting.structureId === structureId) ?? null
}

function accessStateLabel(state: string): string {
  const key = `ledger.mining.moonTax.access.states.${state}`
  return te(key) ? t(key) : state
}

function toggleClasses(active: boolean): string[] {
  return [TOGGLE_CLASSES, active ? ACTIVE_TOGGLE_CLASSES : IDLE_TOGGLE_CLASSES]
}

onMounted(() => {
  void loadAll()
})
</script>

<template>
  <section :data-moon-tax-corporation="entry.corporationId" class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h4 class="truncate text-sm font-semibold uppercase tracking-wider text-slate-300" :title="corporationLabel">{{ corporationLabel }}</h4>
      <div v-if="corporation !== null" class="flex items-center gap-3">
        <span v-if="isSyncing" data-sync-progress role="status" class="text-xs uppercase tracking-wider text-cyan-300">{{ syncLabel }}</span>
        <button
          type="button"
          data-sync
          :disabled="isSyncing"
          class="rounded-sm border border-cyan-500/60 bg-cyan-500/10 px-3 py-1 text-xs font-medium uppercase tracking-wider text-cyan-300 transition-colors hover:bg-cyan-500/20 disabled:cursor-not-allowed disabled:opacity-40"
          @click="requestSync"
        >
          {{ isSyncing ? t('ledger.mining.moonTax.sync.syncing') : t('ledger.mining.moonTax.sync.button') }}
        </button>
      </div>
    </div>

    <div
      v-if="accessFailure?.kind === 'denied'"
      data-moon-tax-denied
      role="status"
      class="rounded-sm border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-300"
    >
      <p class="font-semibold uppercase tracking-wider"><span aria-hidden="true">⚠ </span>{{ t('ledger.mining.moonTax.denied') }}</p>
    </div>
    <div
      v-else-if="accessFailure?.kind === 'unreadable'"
      data-moon-tax-unreadable
      role="alert"
      class="rounded-sm border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-300"
    >
      <p class="font-semibold uppercase tracking-wider"><span aria-hidden="true">⚠ </span>{{ t('ledger.mining.moonTax.unreadable') }}</p>
      <p class="break-words">{{ accessFailure.message }}</p>
    </div>

    <template v-else>
      <ErrorBanner v-if="hasLoadError" :message="t('ledger.mining.moonTax.loadError')" />
      <ErrorBanner v-if="syncError !== null" :message="syncError" @dismiss="syncError = null" />

      <div v-if="isLoading && corporation === null" class="flex justify-center py-8">
        <LoadingSpinner size="lg" class="text-cyan-500" />
      </div>

      <template v-if="corporation !== null">
        <p
          v-for="alert in accessAlerts"
          :key="alert.kind"
          :data-moon-tax-access="alert.kind"
          :data-state="alert.state"
          role="status"
          class="rounded-sm border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-300"
        >
          <span aria-hidden="true">⚠ </span>{{ t(`ledger.mining.moonTax.access.${alert.kind}`) }} : {{ accessStateLabel(alert.state) }}
          <template v-if="alert.kind === 'payments' && alert.state === 'reconnect'">
            · {{ t('ledger.mining.moonTax.access.reconnectScope', { scope: MOON_TAX_WALLET_SCOPE }) }}
          </template>
          <template v-if="alert.syncedAt !== null">
            · {{ t('ledger.mining.moonTax.access.lastRead') }}
            <time data-last-read :datetime="alert.syncedAt">{{ formatChunkTime(alert.syncedAt, EVE_TIME_ZONE) }}</time>
          </template>
        </p>

        <p v-if="refineryIds.length === 0" data-no-refinery class="text-sm text-slate-500">{{ t('ledger.mining.moonTax.noRefinery') }}</p>
        <div v-else class="grid gap-3 lg:grid-cols-2">
          <MoonTaxRefineryForm
            v-for="structureId in refineryIds"
            :key="structureId"
            :structure-id="structureId"
            :name="structureLabel(structureId)"
            :setting="settingOf(structureId)"
            :is-saving="savingStructureId === structureId"
            :is-saved="savedStructureId === structureId"
            :error-message="saveErrors[structureId] ?? null"
            @save="saveRefinery(structureId, $event)"
          />
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex flex-wrap items-center gap-2" role="group" :aria-label="t('ledger.mining.moonTax.filterLabel')">
            <button
              v-for="filter in FILTERS"
              :key="filter"
              type="button"
              :data-filter="filter"
              :aria-pressed="lateOnly === (filter === 'late')"
              :class="toggleClasses(lateOnly === (filter === 'late'))"
              @click="setLateOnly(filter === 'late')"
            >
              {{ t(`ledger.mining.moonTax.filters.${filter}`) }}
            </button>
          </div>
          <button
            type="button"
            data-export-csv
            :disabled="isExporting"
            class="rounded-sm border border-slate-700 px-3 py-1 text-xs font-medium uppercase tracking-wider text-slate-300 transition-colors hover:text-white disabled:cursor-not-allowed disabled:opacity-40"
            @click="exportCsv"
          >
            {{ t('ledger.mining.moonTax.exportCsv') }}
          </button>
        </div>

        <ErrorBanner v-if="exportError !== null" :message="exportError" @dismiss="exportError = null" />
        <ErrorBanner v-if="hasStatementsError" :message="t('ledger.mining.moonTax.statementsLoadError')" />

        <div v-if="isLoadingStatements && statements.length === 0" class="flex justify-center py-8">
          <LoadingSpinner size="lg" class="text-cyan-500" />
        </div>

        <template v-if="statements.length > 0">
          <MoonTaxTotals :statements="statements" />
          <MoonTaxStatementsTable
            :statements="statements"
            :structure-label="structureLabel"
            :busy-statement-id="busyStatementId"
            :row-errors="rowErrors"
            @mark-paid="runStatementAction($event, 'mark-paid')"
            @cancel="runStatementAction($event, 'cancel')"
          />
        </template>
        <p
          v-else-if="isLedgerRead && !isLoadingStatements && !hasStatementsError"
          data-empty-statements
          class="text-sm text-slate-500"
        >
          {{ t('ledger.mining.moonTax.emptyStatements') }}
        </p>

        <MoonTaxDonationsPanel :corporation-id="entry.corporationId" :statements="statements" @attributed="loadStatements" />
      </template>
    </template>
  </section>
</template>
