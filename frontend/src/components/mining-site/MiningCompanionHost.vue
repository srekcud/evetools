<script setup lang="ts">
import { useEventListener } from '@vueuse/core'
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import MiningCompanionPanel from '@/components/mining-site/MiningCompanionPanel.vue'
import MiningCompanionSettings from '@/components/mining-site/MiningCompanionSettings.vue'
import { useMiningSiteFormat } from '@/composables/useMiningSiteFormat'
import { useMiningSiteOreTypes } from '@/composables/useMiningSiteOreTypes'
import { useToast } from '@/composables/useToast'
import { useMiningCompanionStore, type CompanionTarget } from '@/stores/miningCompanion'
import { useMiningOperationStore, type MiningOperation } from '@/stores/miningOperation'
import { useMiningSiteStore, type MiningSiteScan } from '@/stores/miningSite'
import { useSyncStore } from '@/stores/sync'
import {
  COMPANION_TEXT_SCALES,
  companionShortcutOf,
  companionSizeOfShortcut,
  isTextEntryTarget,
  latestActiveSiteOf,
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

const CLOCK_TICK_MS = 1000
const SITE_SCAN_RECORDED_ACTION = 'site_scan_recorded'
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

const now = ref(new Date())
const rescanMinutes = ref(readRescanMinutes(localStorage))
const settingsOpen = ref(false)
const loaded = ref(false)
const message = ref<string | null>(null)
const failure = ref<string | null>(null)
const resolvedSiteId = ref<string | null>(null)
const operation = ref<CompanionOperation | null>(null)
const mountPoint = shallowRef<HTMLElement | null>(null)
let clockTimer: ReturnType<typeof setInterval> | null = null
let loadCounter = 0

const companionDocument = computed(() => companion.companionWindow?.document ?? null)
const targetKey = computed(() => (companion.target === null ? null : `${companion.target.siteId}|${companion.target.operationId}`))
const eventOperationId = computed(() => companion.target?.operationId ?? siteStore.companionSite?.operationId ?? null)
const operationEvent = computed(() => (eventOperationId.value === null ? null : syncStore.getMiningOperationEvent(eventOperationId.value)))
const noticeText = computed(() => {
  if (companion.notice === null) return null
  return companion.noticeCause === null ? t(companion.notice) : `${t(companion.notice)} (${companion.noticeCause})`
})
const shownNotice = computed(() => message.value ?? failure.value ?? noticeText.value)
const typeNames = computed<Record<number, string>>(() => {
  const typeIds = siteStore.companionScans.flatMap(scan => [...scan.types.map(type => type.typeId), ...scan.missingPriceTypeIds])
  return Object.fromEntries(typeIds.map(typeId => [typeId, oreTypes.nameOf(typeId)]))
})

function operationOf(source: MiningOperation | null, operationId: string): CompanionOperation | null {
  return source !== null && source.id === operationId ? { id: source.id, status: source.status, myDepartedAt: source.myDepartedAt } : null
}

async function loadOperation(operationId: string): Promise<void> {
  await operationStore.fetchDetail(operationId)
  operation.value = operationOf(operationStore.currentOperation, operationId)
  if (operation.value === null) failure.value = operationStore.error
}

async function resolveActiveSiteId(operationId: string): Promise<string | null> {
  await siteStore.fetchOperationSites(operationId)
  if (siteStore.error !== null) {
    failure.value = siteStore.error
    return null
  }
  return latestActiveSiteOf(siteStore.operationSites)?.id ?? null
}

async function reloadSite(siteId: string): Promise<void> {
  await siteStore.fetchCompanion(siteId)
  failure.value = siteStore.companionError
}

async function loadTarget(requested: CompanionTarget): Promise<void> {
  const token = ++loadCounter
  failure.value = null
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
  siteStore.clearCompanion()
}

function issueText(issue: SurveyPasteIssue): string {
  return t(`miningSite.scan.paste.issues.${issue.kind}`, { line: issue.line, name: issue.name ?? '' })
}

function trendEtaAfter(scan: MiningSiteScan): string {
  return isEverythingDepleted(scan) ? t('miningSite.eta.depleted') : etaText(scan.trendEta)
}

async function recordPastedText(text: string): Promise<void> {
  message.value = null
  const siteId = resolvedSiteId.value
  const site = siteStore.companionSite
  if (siteId === null || site === null || site.status !== 'active') {
    message.value = t('miningCompanion.errors.noActiveSite')
    return
  }
  if (text.trim() === '') {
    message.value = t('miningSite.scan.errors.noLines')
    return
  }
  if (oreTypes.rows.value.length === 0) await oreTypes.load()
  if (oreTypes.rows.value.length === 0) {
    message.value = oreTypes.error.value ?? t('miningCompanion.errors.oreTypesUnavailable')
    return
  }
  const result = parseSurveyPaste(text, oreTypes.rows.value.map(row => ({ typeId: row.typeId, typeName: row.typeName, volume: row.volume })))
  if (result.issues.length > 0 || result.lines.length === 0) {
    message.value = result.issues.length === 0 ? t('miningSite.scan.errors.noLines') : result.issues.map(issueText).join(' ')
    return
  }
  const trendEtaBefore = etaText(site.trendEta)
  try {
    const recorded = await siteStore.recordScan(siteId, result.lines)
    message.value = t('miningSite.scan.added', { number: recorded.number, from: trendEtaBefore, to: trendEtaAfter(recorded) })
  } catch (cause) {
    message.value = cause instanceof Error ? cause.message : String(cause)
    return
  }
  await reloadSite(siteId)
}

async function pasteFromClipboard(): Promise<void> {
  const opened = companion.companionWindow
  if (opened === null) return
  let text: string
  try {
    text = await opened.navigator.clipboard.readText()
  } catch {
    message.value = t('miningCompanion.notices.clipboardDenied')
    return
  }
  await recordPastedText(text)
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
    await operationStore.depart(operationId)
  } catch (cause) {
    message.value = operationStore.error ?? (cause instanceof Error ? cause.message : String(cause))
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
  if (event === null || requested === null || event.action !== SITE_SCAN_RECORDED_ACTION) return
  if (requested.siteId === null) loadTarget(requested)
  else if (event.data?.siteId === requested.siteId) reloadSite(requested.siteId)
})

watch(
  () => companion.notice,
  (notice) => {
    if (notice !== null && companion.companionWindow === null) showToast(t(notice), 'warning')
  },
)

useEventListener(companionDocument, 'keydown', onKeydown)
useEventListener(companionDocument, 'paste', onPaste)

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
      <template #settings>
        <MiningCompanionSettings
          v-if="settingsOpen"
          :opacity="companion.settings.opacity"
          :text-scale="companion.settings.textScale"
          :rescan-minutes="rescanMinutes"
          @opacity="companion.setOpacity"
          @text-scale="companion.setTextScale"
          @rescan="changeRescanMinutes"
        />
      </template>
    </MiningCompanionPanel>
  </Teleport>
</template>
