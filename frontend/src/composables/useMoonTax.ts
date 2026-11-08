import { computed, ref, watch, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ApiError, apiRequest, authFetch } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore } from '@/stores/sync'
import { chunkDay, type CorporationMiningExtractions } from '@/composables/useCorporationMiningExtractions'

export const RATE_FIELDS = ['rateR4', 'rateR8', 'rateR16', 'rateR32', 'rateR64'] as const

export type MoonTaxRateField = typeof RATE_FIELDS[number]

export interface MoonTaxRefineryPayload extends Record<MoonTaxRateField, number> {
  openToAlliance: boolean
  period: string
  walletDivision: number
}

export interface MoonTaxRefinerySetting extends MoonTaxRefineryPayload {
  structureId: number
}

export interface MoonTaxAccess {
  ledger: string
  payments: string
}

export interface MoonTaxCorporation {
  corporationId: number
  refineries: MoonTaxRefinerySetting[]
  access: MoonTaxAccess
  observersSyncedAt: string | null
  walletSyncedAt: string | null
}

export interface MoonTaxStatement {
  id: string | null
  corporationId: number
  structureId: number
  characterId: number
  characterName: string | null
  periodStart: string
  periodEnd: string
  reference: string | null
  tax: number | null
  paid: number
  remaining: number | null
  status: string
  outsideAlliance: boolean | null
  unknownTypeIds: number[]
}

export interface MoonTaxMineStatement {
  corporationId: number
  structureId: number
  characterId: number
  periodStart: string
  periodEnd: string
  reference: string | null
  tax: number | null
  paid: number
  remaining: number | null
  status: string
  outsideAlliance: boolean | null
  unknownTypeIds: number[]
}

export interface MoonTaxSum {
  knownTotal: number
  isComplete: boolean
}

export interface MoonTaxTotals {
  due: MoonTaxSum
  paid: MoonTaxSum
  remaining: MoonTaxSum
  outsideAllianceCount: number
}

export interface MoonTaxAccessFailure {
  kind: 'denied' | 'unreadable'
  message: string
}

export interface MoonTaxCorporationGroup {
  corporationId: number
  statements: MoonTaxMineStatement[]
}

export type StatementAction = 'mark-paid' | 'cancel'

export const MOON_TAX_WALLET_SCOPE = 'esi-wallet.read_corporation_wallets.v1'
export const WALLET_DIVISIONS = [1, 2, 3, 4, 5, 6, 7] as const
export const MIN_TAX_RATE = 0
export const MAX_TAX_RATE = 100
export const TAX_RATE_STEP = 0.01
export const PAYABLE_STATUSES: readonly string[] = ['due', 'late', 'partial']
export const NON_CANCELLABLE_STATUSES: readonly string[] = ['cancelled', 'provisional']

const MOON_TAX_ENDPOINT = '/mining/moon-tax'
const MINE_ENDPOINT = `${MOON_TAX_ENDPOINT}/mine`
const HTTP_FORBIDDEN = 403
const HTTP_SERVICE_UNAVAILABLE = 503
const CANCELLED_STATUS = 'cancelled'
const EVE_TIME_ZONE = 'UTC'

function sumAmounts(amounts: Array<number | null>): MoonTaxSum {
  const known = amounts.filter((amount): amount is number => amount !== null)
  return { knownTotal: known.reduce((total, amount) => total + amount, 0), isComplete: known.length === amounts.length }
}

export function summarizeStatements(statements: MoonTaxStatement[]): MoonTaxTotals {
  const active = statements.filter(statement => statement.status !== CANCELLED_STATUS)
  return {
    due: sumAmounts(active.map(statement => statement.tax)),
    paid: sumAmounts(active.map(statement => statement.paid)),
    remaining: sumAmounts(active.map(statement => statement.remaining)),
    outsideAllianceCount: active.filter(statement => statement.outsideAlliance === true).length,
  }
}

export function statementKey(statement: MoonTaxMineStatement): string {
  return `${statement.structureId}-${statement.characterId}-${statement.periodStart}`
}

export function formatStatementPeriod(statement: MoonTaxMineStatement): string {
  return `${chunkDay(statement.periodStart, EVE_TIME_ZONE)} → ${chunkDay(statement.periodEnd, EVE_TIME_ZONE)}`
}

export function groupByCorporation(statements: MoonTaxMineStatement[]): MoonTaxCorporationGroup[] {
  const groups = new Map<number, MoonTaxMineStatement[]>()
  for (const statement of statements) {
    groups.set(statement.corporationId, [...(groups.get(statement.corporationId) ?? []), statement])
  }
  return Array.from(groups, ([corporationId, grouped]) => ({ corporationId, statements: grouped }))
}

function isCorporationPayload(payload: unknown): payload is MoonTaxCorporation {
  if (typeof payload !== 'object' || payload === null) return false
  const candidate = payload as Partial<MoonTaxCorporation>
  return Array.isArray(candidate.refineries) && typeof candidate.access === 'object' && candidate.access !== null
}

function withoutKey<Key extends string | number, Value>(record: Record<Key, Value>, key: Key): Record<Key, Value> {
  const copy = { ...record }
  delete copy[key]
  return copy
}

function downloadBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  document.body.append(anchor)
  anchor.click()
  anchor.remove()
  URL.revokeObjectURL(url)
}

export function useMoonTaxLabels(corporations: Ref<CorporationMiningExtractions[]>) {
  const { t } = useI18n()
  const authStore = useAuthStore()

  const structureNames = computed(() => {
    const names = new Map<number, string>()
    for (const extraction of corporations.value.flatMap(entry => entry.extractions)) {
      if (extraction.structureName !== null) names.set(extraction.structureId, extraction.structureName)
    }
    return names
  })

  function structureLabel(structureId: number): string {
    return structureNames.value.get(structureId) ?? t('ledger.mining.moonTax.structure', { id: structureId })
  }

  function corporationLabel(corporationId: number): string {
    const character = authStore.user?.characters.find(candidate => candidate.corporationId === corporationId)
    return character?.corporationName ?? t('ledger.mining.moonTax.corporation', { id: corporationId })
  }

  return { structureLabel, corporationLabel }
}

export function useMoonTaxCorporation(corporationId: number) {
  const { t } = useI18n()
  const syncStore = useSyncStore()
  const corporationEndpoint = `${MOON_TAX_ENDPOINT}/${corporationId}`

  const corporation = ref<MoonTaxCorporation | null>(null)
  const statements = ref<MoonTaxStatement[]>([])
  const accessFailure = ref<MoonTaxAccessFailure | null>(null)
  const isLoading = ref(false)
  const isLoadingStatements = ref(false)
  const hasLoadError = ref(false)
  const hasStatementsError = ref(false)
  const lateOnly = ref(false)
  const savingStructureId = ref<number | null>(null)
  const savedStructureId = ref<number | null>(null)
  const saveErrors = ref<Record<number, string>>({})
  const busyStatementId = ref<string | null>(null)
  const rowErrors = ref<Record<string, string>>({})
  const isSyncing = ref(false)
  const syncError = ref<string | null>(null)
  const isExporting = ref(false)
  const exportError = ref<string | null>(null)
  let latestStatementsRequest = 0

  const syncPercent = computed<number | null>(() => {
    const progress = syncStore.moonTaxProgress
    return progress?.status === 'started' || progress?.status === 'in_progress' ? progress.progress : null
  })

  function statementsEndpoint(): string {
    return `${corporationEndpoint}/statements${lateOnly.value ? '?late=true' : ''}`
  }

  function failureMessage(error: unknown): string {
    return error instanceof Error ? error.message : t('ledger.mining.moonTax.requestFailed')
  }

  async function loadCorporation(): Promise<boolean> {
    isLoading.value = true
    try {
      const payload = await apiRequest<unknown>(corporationEndpoint)
      if (!isCorporationPayload(payload)) throw new Error('Unexpected moon tax corporation payload')
      corporation.value = payload
      accessFailure.value = null
      hasLoadError.value = false
      return true
    } catch (error) {
      if (error instanceof ApiError && error.status === HTTP_FORBIDDEN) {
        corporation.value = null
        accessFailure.value = { kind: 'denied', message: error.message }
      } else if (error instanceof ApiError && error.status === HTTP_SERVICE_UNAVAILABLE) {
        corporation.value = null
        accessFailure.value = { kind: 'unreadable', message: error.message }
      } else {
        hasLoadError.value = true
      }
      return false
    } finally {
      isLoading.value = false
    }
  }

  async function loadStatements(): Promise<void> {
    const request = ++latestStatementsRequest
    isLoadingStatements.value = true
    try {
      const payload = await apiRequest<unknown>(statementsEndpoint())
      if (request !== latestStatementsRequest) return
      if (!Array.isArray(payload)) throw new Error('Unexpected moon tax statements payload')
      statements.value = payload as MoonTaxStatement[]
      hasStatementsError.value = false
    } catch {
      if (request === latestStatementsRequest) hasStatementsError.value = true
    } finally {
      if (request === latestStatementsRequest) isLoadingStatements.value = false
    }
  }

  async function loadAll(): Promise<void> {
    if (await loadCorporation()) await loadStatements()
  }

  async function setLateOnly(value: boolean): Promise<void> {
    if (lateOnly.value === value) return
    lateOnly.value = value
    await loadStatements()
  }

  async function saveRefinery(structureId: number, payload: MoonTaxRefineryPayload): Promise<void> {
    savingStructureId.value = structureId
    savedStructureId.value = null
    saveErrors.value = withoutKey(saveErrors.value, structureId)
    try {
      await apiRequest(`${corporationEndpoint}/refineries/${structureId}`, { method: 'PUT', body: JSON.stringify(payload) })
    } catch (error) {
      saveErrors.value = { ...saveErrors.value, [structureId]: failureMessage(error) }
      return
    } finally {
      savingStructureId.value = null
    }
    savedStructureId.value = structureId
    await loadCorporation()
  }

  async function runStatementAction(statementId: string, action: StatementAction): Promise<void> {
    busyStatementId.value = statementId
    rowErrors.value = withoutKey(rowErrors.value, statementId)
    try {
      await apiRequest(`${corporationEndpoint}/statements/${statementId}/${action}`, { method: 'POST', body: JSON.stringify({}) })
    } catch (error) {
      rowErrors.value = { ...rowErrors.value, [statementId]: failureMessage(error) }
      return
    } finally {
      busyStatementId.value = null
    }
    await loadStatements()
  }

  async function exportCsv(): Promise<void> {
    isExporting.value = true
    exportError.value = null
    try {
      const response = await authFetch(`/api${statementsEndpoint()}`, { headers: { Accept: 'text/csv' } })
      if (!response.ok) throw new Error(`Moon tax export failed with status ${response.status}`)
      downloadBlob(await response.clone().blob(), `moon-tax-${corporationId}.csv`)
    } catch {
      exportError.value = t('ledger.mining.moonTax.exportError')
    } finally {
      isExporting.value = false
    }
  }

  async function requestSync(): Promise<void> {
    isSyncing.value = true
    syncError.value = null
    try {
      await apiRequest(`${corporationEndpoint}/sync`, { method: 'POST', body: JSON.stringify({}) })
    } catch (error) {
      isSyncing.value = false
      syncError.value = failureMessage(error)
    }
  }

  watch(() => syncStore.moonTaxProgress, progress => {
    if (progress?.status === 'completed') {
      isSyncing.value = false
      void loadAll()
    } else if (progress?.status === 'error' && isSyncing.value) {
      isSyncing.value = false
      syncError.value = progress.message ?? t('ledger.mining.moonTax.sync.failed')
    }
  })

  return {
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
    setLateOnly,
    saveRefinery,
    runStatementAction,
    exportCsv,
    requestSync,
  }
}

export function useMoonTaxMine() {
  const statements = ref<MoonTaxMineStatement[]>([])
  const isLoading = ref(false)
  const hasError = ref(false)

  async function load(): Promise<void> {
    isLoading.value = true
    try {
      const payload = await apiRequest<unknown>(MINE_ENDPOINT)
      if (!Array.isArray(payload)) throw new Error('Unexpected moon tax mine payload')
      statements.value = payload as MoonTaxMineStatement[]
      hasError.value = false
    } catch {
      hasError.value = true
    } finally {
      isLoading.value = false
    }
  }

  return { statements, isLoading, hasError, load }
}
