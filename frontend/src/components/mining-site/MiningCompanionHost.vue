<script setup lang="ts">
import { useEventListener } from '@vueuse/core'
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import MiningCompanionFree from '@/components/mining-site/MiningCompanionFree.vue'
import MiningCompanionNextSite from '@/components/mining-site/MiningCompanionNextSite.vue'
import MiningCompanionPanel from '@/components/mining-site/MiningCompanionPanel.vue'
import MiningCompanionSettings from '@/components/mining-site/MiningCompanionSettings.vue'
import { useCorporationMiningExtractions } from '@/composables/useCorporationMiningExtractions'
import { useMiningSiteFormat } from '@/composables/useMiningSiteFormat'
import { useMiningSiteOreTypes } from '@/composables/useMiningSiteOreTypes'
import { useToast } from '@/composables/useToast'
import { useMiningCompanionStore, type CompanionTarget } from '@/stores/miningCompanion'
import { useMiningOperationStore, type MiningOperation } from '@/stores/miningOperation'
import { useMiningSiteStore, type MiningSite, type MiningSiteScan, type MiningSiteScanLine } from '@/stores/miningSite'
import { useSyncStore } from '@/stores/sync'
import {
  COMPANION_TEXT_SCALES,
  companionShortcutOf,
  companionSizeOfShortcut,
  fingerprintOf,
  isTextEntryTarget,
  latestActiveSiteOf,
  nextChunkOf,
} from '@/views/MiningSite/miningCompanion'
import {
  RESCAN_MINUTES_STORAGE_KEY,
  clampRescanMinutes,
  isEverythingDepleted,
  readRescanMinutes,
} from '@/views/MiningSite/miningSiteEstimate'
import { parseSurveyPaste, type SurveyPasteIssue } from '@/views/MiningSite/miningSurveyPaste'

interface CompanionOperation {
  id: string
  status: string
  myDepartedAt: string | null
}

type PasteOutcome =
  | { kind: 'lines'; lines: MiningSiteScanLine[] }
  | { kind: 'not-a-scan'; message: string }
  | { kind: 'unavailable'; message: string }

interface DepletionObservation {
  site: MiningSite
  scanNumber: number
  depleted: boolean
}

const CLOCK_TICK_MS = 1000
const SITE_SCAN_RECORDED_ACTION = 'site_scan_recorded'
const SITE_NEXT_STARTED_ACTION = 'site_next_started'
const NOTIFICATION_GRANTED = 'granted'
const NOTIFICATION_DENIED = 'denied'
const PERCENT_DIVISOR = 100
const BACKGROUND_RGB = '2 6 23'

const { t } = useI18n()
const router = useRouter()
const companion = useMiningCompanionStore()
const siteStore = useMiningSiteStore()
const operationStore = useMiningOperationStore()
const syncStore = useSyncStore()
const oreTypes = useMiningSiteOreTypes()
const { etaText } = useMiningSiteFormat()
const { showToast } = useToast()
const extractions = useCorporationMiningExtractions()

const now = ref(new Date())
const rescanMinutes = ref(readRescanMinutes(localStorage))
const settingsOpen = ref(false)
const loaded = ref(false)
const message = ref<string | null>(null)
const failure = ref<string | null>(null)
const resolvedSiteId = ref<string | null>(null)
const operation = ref<CompanionOperation | null>(null)
const nextSiteOpen = ref(false)
const nextSiteSubmitting = ref(false)
const nextSiteFailure = ref<string | null>(null)
const estimating = ref(false)
const estimateFailure = ref<string | null>(null)
const settingsRevision = ref(0)
const mountPoint = shallowRef<HTMLElement | null>(null)
const observedDepletion = new Map<string, boolean>()
const notifiedDepletions = new Set<string>()
let clockTimer: ReturnType<typeof setInterval> | null = null
let loadCounter = 0
let lastRecordedFingerprint: number | null = null
let clipboardDeniedShown = false
let readingClipboardOnFocus = false

const companionDocument = computed(() => companion.companionWindow?.document ?? null)
const companionWindowTarget = computed(() => companion.companionWindow)
const targetKey = computed(() => (companion.target === null ? null : `${companion.target.siteId}|${companion.target.operationId}`))
const eventOperationId = computed(() => companion.target?.operationId ?? siteStore.companionSite?.operationId ?? null)
const operationEvent = computed(() => (eventOperationId.value === null ? null : syncStore.getMiningOperationEvent(eventOperationId.value)))
const noticeText = computed(() => {
  if (companion.notice === null) return null
  return companion.noticeCause === null ? t(companion.notice) : `${t(companion.notice)} (${companion.noticeCause})`
})
const shownNotice = computed(() => message.value ?? failure.value ?? noticeText.value)
const isFreeTarget = computed(() => companion.target !== null && companion.target.siteId === null && companion.target.operationId === null)
const nextChunk = computed(() => nextChunkOf(extractions.corporations.value, now.value))
const extractionsFailure = computed(() => (extractions.hasError.value ? t('ledger.mining.moonChunks.loadError') : null))
const typeNames = computed<Record<number, string>>(() => {
  const typeIds = siteStore.companionScans.flatMap(scan => [...scan.types.map(type => type.typeId), ...scan.missingPriceTypeIds])
  return Object.fromEntries(typeIds.map(typeId => [typeId, oreTypes.nameOf(typeId)]))
})

const depletionObservation = computed<DepletionObservation | null>(() => {
  const site = siteStore.companionSite
  const scans = siteStore.companionScans
  if (site === null || site.status !== 'active' || scans.length === 0) return null
  const lastScan = scans.reduce((latest, scan) => (scan.number > latest.number ? scan : latest))
  return { site, scanNumber: lastScan.number, depleted: isEverythingDepleted(lastScan) }
})

function failureText(cause: unknown): string {
  return cause instanceof Error ? cause.message : String(cause)
}

function operationOf(source: MiningOperation | null, operationId: string): CompanionOperation | null {
  return source !== null && source.id === operationId ? { id: source.id, status: source.status, myDepartedAt: source.myDepartedAt } : null
}

async function loadOperation(operationId: string): Promise<void> {
  try {
    operation.value = operationOf(await operationStore.readOperation(operationId), operationId)
  } catch (cause) {
    operation.value = null
    failure.value = failureText(cause)
  }
}

async function resolveActiveSiteId(operationId: string): Promise<string | null> {
  try {
    return latestActiveSiteOf(await siteStore.readOperationSites(operationId))?.id ?? null
  } catch (cause) {
    failure.value = failureText(cause)
    return null
  }
}

async function reloadSite(siteId: string): Promise<void> {
  await siteStore.fetchCompanion(siteId)
  failure.value = siteStore.companionError
}

function isFreeTargetOf(requested: CompanionTarget): boolean {
  return requested.siteId === null && requested.operationId === null
}

async function loadTarget(requested: CompanionTarget): Promise<void> {
  const token = ++loadCounter
  failure.value = null
  if (isFreeTargetOf(requested)) void extractions.load()
  if (requested.operationId !== null) await loadOperation(requested.operationId)
  const siteId = requested.siteId ?? (requested.operationId === null ? null : await resolveActiveSiteId(requested.operationId))
  if (token !== loadCounter) return
  resolvedSiteId.value = siteId
  if (siteId === null) siteStore.clearCompanion()
  else await reloadSite(siteId)
  if (token !== loadCounter) return
  loaded.value = true
}

function resetData(): void {
  loadCounter++
  loaded.value = false
  resolvedSiteId.value = null
  operation.value = null
  message.value = null
  failure.value = null
  settingsOpen.value = false
  nextSiteOpen.value = false
  nextSiteSubmitting.value = false
  nextSiteFailure.value = null
  estimating.value = false
  estimateFailure.value = null
  observedDepletion.clear()
  notifiedDepletions.clear()
  lastRecordedFingerprint = null
  clipboardDeniedShown = false
  siteStore.clearCompanion()
}

function issueText(issue: SurveyPasteIssue): string {
  return t(`miningSite.scan.paste.issues.${issue.kind}`, { line: issue.line, name: issue.name ?? '' })
}

function trendEtaAfter(scan: MiningSiteScan): string {
  return isEverythingDepleted(scan) ? t('miningSite.eta.depleted') : etaText(scan.trendEta)
}

async function interpretPastedText(text: string): Promise<PasteOutcome> {
  if (text.trim() === '') return { kind: 'not-a-scan', message: t('miningSite.scan.errors.noLines') }
  if (oreTypes.rows.value.length === 0) await oreTypes.load()
  if (oreTypes.rows.value.length === 0) return { kind: 'unavailable', message: oreTypes.error.value ?? t('miningCompanion.errors.oreTypesUnavailable') }
  const result = parseSurveyPaste(text, oreTypes.rows.value.map(row => ({ typeId: row.typeId, typeName: row.typeName, volume: row.volume })))
  if (result.issues.length > 0 || result.lines.length === 0) {
    return { kind: 'not-a-scan', message: result.issues.length === 0 ? t('miningSite.scan.errors.noLines') : result.issues.map(issueText).join(' ') }
  }
  return { kind: 'lines', lines: result.lines }
}

async function recordLines(siteId: string, site: MiningSite, lines: MiningSiteScanLine[], text: string): Promise<void> {
  const trendEtaBefore = etaText(site.trendEta)
  try {
    const recorded = await siteStore.recordScan(siteId, lines)
    lastRecordedFingerprint = fingerprintOf(text)
    message.value = t('miningSite.scan.added', { number: recorded.number, from: trendEtaBefore, to: trendEtaAfter(recorded) })
  } catch (cause) {
    message.value = failureText(cause)
    return
  }
  await reloadSite(siteId)
}

async function recordPastedText(text: string): Promise<void> {
  message.value = null
  const siteId = resolvedSiteId.value
  const site = siteStore.companionSite
  if (siteId === null || site === null || site.status !== 'active') {
    message.value = t('miningCompanion.errors.noActiveSite')
    return
  }
  const outcome = await interpretPastedText(text)
  if (outcome.kind !== 'lines') {
    message.value = outcome.message
    return
  }
  await recordLines(siteId, site, outcome.lines, text)
}

async function readClipboardOf(opened: Window): Promise<string | null> {
  try {
    return await opened.navigator.clipboard.readText()
  } catch {
    return null
  }
}

async function pasteFromClipboard(): Promise<void> {
  const opened = companion.companionWindow
  if (opened === null) return
  const text = await readClipboardOf(opened)
  if (text === null) {
    message.value = t('miningCompanion.notices.clipboardDenied')
    return
  }
  await recordPastedText(text)
}

async function recordClipboardOnFocus(): Promise<void> {
  const opened = companion.companionWindow
  const siteId = resolvedSiteId.value
  const site = siteStore.companionSite
  if (!companion.settings.clipboardOnFocus || readingClipboardOnFocus || !loaded.value) return
  if (opened === null || siteId === null || site === null || site.status !== 'active') return
  readingClipboardOnFocus = true
  try {
    const text = await readClipboardOf(opened)
    if (text === null) {
      if (!clipboardDeniedShown) message.value = t('miningCompanion.notices.clipboardDenied')
      clipboardDeniedShown = true
      return
    }
    if (fingerprintOf(text) === lastRecordedFingerprint) return
    const outcome = await interpretPastedText(text)
    if (outcome.kind === 'unavailable') message.value = outcome.message
    if (outcome.kind !== 'lines' || resolvedSiteId.value !== siteId) return
    message.value = null
    await recordLines(siteId, site, outcome.lines, text)
  } finally {
    readingClipboardOnFocus = false
  }
}

function isNotificationGranted(): boolean {
  return typeof Notification !== 'undefined' && Notification.permission === NOTIFICATION_GRANTED
}

function notifyDepleted(site: MiningSite): void {
  new Notification(t('miningCompanion.notification.depletedTitle'), {
    body: site.label,
    tag: `companion-depleted-${site.id}`,
    silent: true,
  })
}

function observeDepletion(observation: DepletionObservation | null): void {
  if (observation === null) return
  const siteId = observation.site.id
  const wasObservedNotEmpty = observedDepletion.get(siteId) === false
  observedDepletion.set(siteId, observation.depleted)
  const notificationKey = `${siteId}|${observation.scanNumber}`
  if (!wasObservedNotEmpty || !observation.depleted || notifiedDepletions.has(notificationKey)) return
  if (!companion.settings.notifications || !isNotificationGranted()) return
  notifiedDepletions.add(notificationKey)
  notifyDepleted(observation.site)
}

async function changeNotifications(enabled: boolean): Promise<void> {
  if (!enabled) {
    companion.setNotifications(false)
    return
  }
  const permission = typeof Notification === 'undefined' ? NOTIFICATION_DENIED : await Notification.requestPermission()
  if (permission === NOTIFICATION_GRANTED) {
    companion.setNotifications(true)
    return
  }
  companion.setNotifications(false)
  message.value = t('miningCompanion.notices.notificationsDenied')
  settingsRevision.value++
}

function toggleNextSite(): void {
  nextSiteOpen.value = !nextSiteOpen.value
  nextSiteFailure.value = null
}

async function startNextSite(input: { label: string; solarSystemId: number | null }): Promise<void> {
  const requested = companion.target
  if (requested === null || requested.operationId === null) return
  nextSiteSubmitting.value = true
  nextSiteFailure.value = null
  try {
    const started = await siteStore.startNextSite(requested.operationId, input)
    nextSiteOpen.value = false
    message.value = t('miningCompanion.nextSite.started', { label: started.label })
  } catch (cause) {
    nextSiteFailure.value = failureText(cause)
    return
  } finally {
    nextSiteSubmitting.value = false
  }
  await loadTarget(requested)
}

async function estimateSingleSite(input: { label: string; solarSystemId: number | null }): Promise<void> {
  estimating.value = true
  estimateFailure.value = null
  try {
    const created = await siteStore.create({ ...input, operationId: undefined })
    companion.retarget({ siteId: created.id, operationId: null })
  } catch (cause) {
    estimateFailure.value = failureText(cause)
  } finally {
    estimating.value = false
  }
}

function createOperationFromChunk(chunkId: string): void {
  window.focus()
  router.push({ name: 'mining-moons', query: { chunk: chunkId } })
}

function followNextSite(requested: CompanionTarget, data: Record<string, unknown> | null): void {
  if (requested.siteId === null) {
    loadTarget(requested)
    return
  }
  const startedSiteId = data?.siteId
  const closedSiteIds = data?.closedSiteIds
  if (typeof startedSiteId !== 'string' || !Array.isArray(closedSiteIds) || !closedSiteIds.includes(requested.siteId)) return
  companion.retarget({ siteId: startedSiteId, operationId: requested.operationId })
}

function onKeydown(event: KeyboardEvent): void {
  const shortcut = companionShortcutOf(event)
  if (shortcut === null) return
  const requestedSize = companionSizeOfShortcut(shortcut)
  if (requestedSize !== null) companion.setSize(requestedSize)
  else if (shortcut === 'paste') pasteFromClipboard()
}

function onPaste(event: ClipboardEvent): Promise<void> | void {
  if (isTextEntryTarget(event.target)) return
  event.preventDefault()
  return recordPastedText(event.clipboardData === null ? '' : event.clipboardData.getData('text'))
}

async function leave(): Promise<void> {
  const operationId = companion.target?.operationId ?? null
  if (operationId === null) return
  try {
    await operationStore.leaveOperation(operationId)
  } catch (cause) {
    message.value = failureText(cause)
    return
  }
  await loadOperation(operationId)
}

function openDesk(): void {
  const requested = companion.target
  if (requested === null) return
  window.focus()
  if (requested.siteId !== null) router.push({ name: 'mining-site-detail', params: { id: requested.siteId } })
  else if (requested.operationId !== null) router.push({ name: 'mining-operation-detail', params: { id: requested.operationId } })
  else router.push({ name: 'mining-operations' })
}

function changeRescanMinutes(minutes: number): void {
  rescanMinutes.value = clampRescanMinutes(minutes)
  localStorage.setItem(RESCAN_MINUTES_STORAGE_KEY, String(rescanMinutes.value))
}

function copyStyleSheets(into: Document): void {
  for (const node of document.querySelectorAll<HTMLLinkElement | HTMLStyleElement>('link[rel="stylesheet"], style')) {
    if (node instanceof HTMLLinkElement) {
      const link = into.createElement('link')
      link.rel = 'stylesheet'
      link.href = node.href
      into.head.appendChild(link)
    } else {
      into.head.appendChild(into.importNode(node, true))
    }
  }
}

function stopClock(): void {
  if (clockTimer !== null) clearInterval(clockTimer)
  clockTimer = null
}

function startClock(): void {
  stopClock()
  clockTimer = setInterval(() => {
    now.value = new Date()
    rescanMinutes.value = readRescanMinutes(localStorage)
  }, CLOCK_TICK_MS)
}

function openWindowContent(opened: Window): void {
  const windowDocument = opened.document
  copyStyleSheets(windowDocument)
  windowDocument.title = t('miningCompanion.windowTitle')
  windowDocument.body.style.margin = '0'
  const container = windowDocument.createElement('div')
  container.style.minHeight = '100vh'
  windowDocument.body.appendChild(container)
  mountPoint.value = container
  now.value = new Date()
  rescanMinutes.value = readRescanMinutes(localStorage)
  startClock()
  oreTypes.load()
}

function closeWindowContent(): void {
  mountPoint.value?.remove()
  mountPoint.value = null
  stopClock()
}

watch(
  () => companion.companionWindow,
  (opened) => {
    closeWindowContent()
    if (opened !== null) openWindowContent(opened)
  },
  { immediate: true },
)

watch(
  [() => companion.companionWindow, () => companion.settings.opacity, () => companion.settings.textScale],
  ([opened, opacity, textScale]) => {
    if (opened === null) return
    opened.document.documentElement.style.fontSize = `${COMPANION_TEXT_SCALES[textScale] * PERCENT_DIVISOR}%`
    opened.document.body.style.backgroundColor = `rgb(${BACKGROUND_RGB} / ${opacity}%)`
  },
  { immediate: true },
)

watch(
  targetKey,
  (key) => {
    if (key === null) {
      resetData()
      return
    }
    loaded.value = false
    const requested = companion.target
    if (requested !== null) loadTarget(requested)
  },
  { immediate: true },
)

watch(operationEvent, (event) => {
  const requested = companion.target
  if (event === null || requested === null) return
  if (event.action === SITE_NEXT_STARTED_ACTION) {
    followNextSite(requested, event.data)
    return
  }
  if (event.action !== SITE_SCAN_RECORDED_ACTION) return
  if (requested.siteId === null) loadTarget(requested)
  else if (event.data?.siteId === requested.siteId) reloadSite(requested.siteId)
})

watch(depletionObservation, observeDepletion)

watch(
  () => companion.notice,
  (notice) => {
    if (notice !== null && companion.companionWindow === null) showToast(t(notice), 'warning')
  },
)

useEventListener(companionDocument, 'keydown', onKeydown)
useEventListener(companionDocument, 'paste', onPaste)
useEventListener(companionWindowTarget, 'focus', recordClipboardOnFocus)

onBeforeUnmount(closeWindowContent)
</script>

<template>
  <Teleport v-if="mountPoint !== null" :to="mountPoint">
    <p v-if="!loaded" class="p-3 font-mono text-sm text-slate-300" role="status" data-testid="companion-loading">{{ t('miningCompanion.loading') }}</p>
    <MiningCompanionPanel
      v-else
      :site="siteStore.companionSite"
      :scans="siteStore.companionScans"
      :size="companion.size"
      :now="now"
      :rescan-minutes="rescanMinutes"
      :type-names="typeNames"
      :operation="operation"
      :notice="shownNotice"
      @paste="pasteFromClipboard"
      @leave="leave"
      @next-site="toggleNextSite"
      @open-desk="openDesk"
      @size="companion.setSize"
    >
      <template #tools>
        <button
          type="button"
          data-testid="companion-settings-toggle"
          :aria-label="t('miningCompanion.settings.open')"
          :aria-pressed="settingsOpen"
          :title="t('miningCompanion.settings.open')"
          class="rounded-sm border border-slate-700 px-1.5 py-0.5 text-xs text-slate-300 hover:bg-slate-800"
          @click="settingsOpen = !settingsOpen"
        >⚙</button>
      </template>
      <template v-if="isFreeTarget" #free>
        <MiningCompanionFree
          :chunk="nextChunk"
          :now="now"
          :loading="extractions.isLoading.value"
          :failure="extractionsFailure"
          :estimating="estimating"
          :estimate-failure="estimateFailure"
          :size="companion.size"
          @create-operation="createOperationFromChunk"
          @estimate="estimateSingleSite"
        />
      </template>
      <template #next-site>
        <MiningCompanionNextSite
          v-if="nextSiteOpen"
          :failure="nextSiteFailure"
          :submitting="nextSiteSubmitting"
          @submit="startNextSite"
          @cancel="toggleNextSite"
        />
      </template>
      <template #settings>
        <MiningCompanionSettings
          v-if="settingsOpen"
          :key="settingsRevision"
          :opacity="companion.settings.opacity"
          :text-scale="companion.settings.textScale"
          :rescan-minutes="rescanMinutes"
          :notifications="companion.settings.notifications"
          :clipboard-on-focus="companion.settings.clipboardOnFocus"
          @notifications="changeNotifications"
          @clipboard="companion.setClipboardOnFocus"
          @opacity="companion.setOpacity"
          @text-scale="companion.setTextScale"
          @rescan="changeRescanMinutes"
        />
      </template>
    </MiningCompanionPanel>
  </Teleport>
</template>
