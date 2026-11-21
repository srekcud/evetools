<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import type { BomItem, JobLocation } from '@/stores/group-industry/types'
import OpenInGameButton from '@/components/common/OpenInGameButton.vue'
import BlueprintNameCopyButton from './BlueprintNameCopyButton.vue'
import CompanionGauge from './CompanionGauge.vue'
import CompanionGroupHeader from './CompanionGroupHeader.vue'
import { ACTIVITY_STYLES } from './companionActivityStyles'
import {
  COMPANION_PANES,
  recallCollapsedSections,
  rememberCollapsedSections,
  type CompanionPane,
  type CompanionSectionKey,
} from './companionCollapsedSections'
import { activityGroupOf, countdownPartsOf, groupByActivity, jobTimingOf, type ActivityGroup, type CompanionAlert, type CompanionTasks } from './companionTasks'

type CopyState = 'idle' | 'copied' | 'failed'

const FREE_LINES_SHOWN = 6
const ALERT_PRIORITY: CompanionAlert['kind'][] = ['job_ready', 'move_products', 'price_below_break_even', 'undercut_sell_order', 'join_request', 'bom_complete']
const GREEN_ALERT_KINDS: CompanionAlert['kind'][] = ['job_ready', 'bom_complete']
const VALUE_DECIMALS = 1
const NUMBER_DECIMALS = 0
const COPY_FEEDBACK_MS = 2000
const COLUMN_GAUGE_DIAMETER = 54
const BAR_GAUGE_DIAMETER = 46
const PRODUCT_IMAGE_SIZE = 64
const TIME_UNIT_DIGITS = 2

const props = defineProps<{
  tasks: CompanionTasks
  projectName: string
  size: 'bar' | 'column'
  errorMessage: string | null
}>()

const emit = defineEmits<{
  claim: [lineId: string]
  release: [lineId: string]
  deliver: [lineId: string]
  'open-bom': []
  'toggle-size': []
}>()

const { t } = useI18n()
const { formatNumber, formatIsk } = useFormatters()
const { getTypeIconUrl, getTypeRenderUrl, onImageError } = useEveImages()

const collapsedSections = ref<CompanionSectionKey[]>(recallCollapsedSections())
const activePane = ref<CompanionPane>('free')
const copyState = ref<CopyState>('idle')
let copyResetTimer: ReturnType<typeof setTimeout> | null = null

function sectionKeyOf(pane: CompanionPane, group: ActivityGroup): CompanionSectionKey {
  return `${pane}:${group}`
}

function isOpen(pane: CompanionPane, group: ActivityGroup): boolean {
  return !collapsedSections.value.includes(sectionKeyOf(pane, group))
}

function toggleSection(pane: CompanionPane, group: ActivityGroup): void {
  const key = sectionKeyOf(pane, group)
  collapsedSections.value = isOpen(pane, group)
    ? [...collapsedSections.value, key]
    : collapsedSections.value.filter(collapsed => collapsed !== key)
  rememberCollapsedSections(collapsedSections.value)
}

const isBar = computed(() => props.size === 'bar')

const freeView = computed(() => {
  let remaining = FREE_LINES_SHOWN
  const groups = groupByActivity(props.tasks.free, item => item)
    .map(({ group, entries }) => {
      const shown = entries.slice(0, remaining)
      remaining -= shown.length
      return { group, total: entries.length, shown }
    })
    .filter(group => group.shown.length > 0)
  return { groups, hidden: Math.max(0, props.tasks.free.length - FREE_LINES_SHOWN) }
})

const mineGroups = computed(() => groupByActivity(props.tasks.mine, entry => entry.item))
const jobGroups = computed(() => groupByActivity(
  props.tasks.jobs.map(job => ({ ...job, timing: jobTimingOf(job.match, props.tasks.now) })),
  job => job.item,
))
const miniBarSegments = computed(() => groupByActivity(props.tasks.free, item => item).map(({ group, entries }) => ({ group, count: entries.length })))

const tabs = computed(() => COMPANION_PANES.map(pane => ({
  pane,
  count: pane === 'free' ? props.tasks.free.length : pane === 'mine' ? props.tasks.mine.length : props.tasks.jobs.length,
  hot: pane === 'mine' ? props.tasks.mine.length > 0 : pane === 'jobs' ? props.tasks.readyJobCount > 0 : false,
})))

const hasTodo = computed(() => props.tasks.toReview !== null || props.tasks.joinRequests !== null)
const chainSegments = computed(() => props.tasks.activityProgress.map(progress => ({
  ...progress,
  label: t(`groupIndustry.companion.activityGroups.${progress.group}`),
})))

const sortedAlerts = computed(() => [...props.tasks.alerts].sort(
  (left, right) => ALERT_PRIORITY.indexOf(left.kind) - ALERT_PRIORITY.indexOf(right.kind),
))

function jobOfAlert(alert: CompanionAlert) {
  return props.tasks.jobs.find(candidate => `job_ready:${candidate.match.id}` === alert.key)
}

function jobAlertText(alert: CompanionAlert): string {
  const job = jobOfAlert(alert)
  if (job === undefined) return t('groupIndustry.companion.alerts.jobReadyGeneric')
  return t('groupIndustry.companion.alerts.jobReady', {
    name: job.item.typeName,
    runs: job.match.runs,
    character: job.match.characterName ?? t('common.unknownName'),
  })
}

function alertText(alert: CompanionAlert): string {
  if (alert.kind === 'job_ready') return jobAlertText(alert)
  if (alert.kind === 'move_products') {
    return t('groupIndustry.companion.alerts.moveProducts', {
      quantity: formatNumber(props.tasks.moveProducts.quantity, NUMBER_DECIMALS),
      count: props.tasks.moveProducts.jobCount,
    })
  }
  if (alert.kind === 'price_below_break_even' && props.tasks.priceAlert !== null) {
    return t('groupIndustry.companion.alerts.priceBelowBreakEven', {
      jitaPrice: formatIsk(props.tasks.priceAlert.jitaUnitPrice),
      breakEven: formatIsk(props.tasks.priceAlert.breakEvenPerUnit),
    })
  }
  if (alert.kind === 'undercut_sell_order' && props.tasks.undercutOrders.length > 0) {
    const order = props.tasks.undercutOrders[0]
    return t('groupIndustry.companion.alerts.undercutSellOrder', {
      typeName: order.typeName,
      quantity: order.volumeRemain,
      market: order.marketName ?? `#${order.locationId}`,
      best: formatIsk(order.bestPrice),
      mine: formatIsk(order.price),
    })
  }
  if (alert.kind === 'join_request') return t('groupIndustry.companion.alerts.joinRequest', { count: props.tasks.joinRequests })
  return t('groupIndustry.companion.alerts.bomComplete')
}

function actionOf(alert: CompanionAlert): 'deliver' | 'copy' | null {
  if (alert.kind === 'job_ready') return jobOfAlert(alert) === undefined ? null : 'deliver'
  if (alert.kind !== 'undercut_sell_order') return null
  const order = props.tasks.undercutOrders.at(0)
  return order !== undefined && order.suggestedPrice !== null ? 'copy' : null
}

const alertViews = computed(() => sortedAlerts.value.map(alert => ({ ...alert, text: alertText(alert), action: actionOf(alert) })))
const topAlert = computed(() => alertViews.value.at(0) ?? null)

function alertClasses(kind: CompanionAlert['kind']): string {
  return GREEN_ALERT_KINDS.includes(kind)
    ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300'
    : 'border-amber-500/40 bg-amber-500/10 text-amber-300'
}

function actionLabel(action: 'deliver' | 'copy'): string {
  if (action === 'deliver') return t('groupIndustry.companion.deliver')
  if (copyState.value === 'copied') return t('groupIndustry.companion.alerts.priceCopied')
  if (copyState.value === 'failed') return t('groupIndustry.companion.alerts.priceCopyFailed')
  return t('groupIndustry.companion.alerts.actionCopyPrice')
}

function clipboardOf(button: HTMLElement): Clipboard {
  const renderingWindow = button.ownerDocument.defaultView
  if (renderingWindow === null) throw new Error('The button is not attached to a window')
  return renderingWindow.navigator.clipboard
}

async function copySuggestedPrice(event: MouseEvent): Promise<void> {
  const order = props.tasks.undercutOrders.at(0)
  if (order === undefined || order.suggestedPrice === null) return
  try {
    await clipboardOf(event.currentTarget as HTMLElement).writeText(String(order.suggestedPrice))
    copyState.value = 'copied'
  } catch {
    copyState.value = 'failed'
  }
  if (copyResetTimer !== null) clearTimeout(copyResetTimer)
  copyResetTimer = setTimeout(() => {
    copyState.value = 'idle'
  }, COPY_FEEDBACK_MS)
}

function runAlertAction(alert: { action: 'deliver' | 'copy' | null } & CompanionAlert, event: MouseEvent): void {
  if (alert.action === 'deliver') {
    const job = jobOfAlert(alert)
    if (job !== undefined) emit('deliver', job.item.id)
    return
  }
  if (alert.action === 'copy') void copySuggestedPrice(event)
}

onBeforeUnmount(() => {
  if (copyResetTimer !== null) clearTimeout(copyResetTimer)
})

const pulseClass = ref<string | null>(null)

watch(
  () => props.tasks.alerts.map(alert => alert.key),
  async (_keys, previousKeys) => {
    const appeared = props.tasks.alerts.filter(alert => !previousKeys.includes(alert.key))
    if (appeared.length === 0) return
    pulseClass.value = null
    await nextTick()
    pulseClass.value = appeared.some(alert => alert.kind !== 'bom_complete') ? 'companion-pulse-amber' : 'companion-pulse-green'
  },
)

function freeValueUnknown(item: BomItem): boolean {
  return item.estimatedPrice === null
}

function freeValue(item: BomItem): string {
  return item.estimatedPrice === null ? '' : formatIsk(item.freeQuantity * item.estimatedPrice, VALUE_DECIMALS)
}

function activityText(item: BomItem): string | null {
  const group = activityGroupOf(item)
  return group === 'unknown' || group === 'material' ? null : t(`groupIndustry.companion.activities.${group}`)
}

function lineStyle(group: ActivityGroup): string {
  const style = ACTIVITY_STYLES[group]
  return `border-l-2 ${style.stripe} bg-gradient-to-r ${style.tint} to-white/[0.02]`
}

function padTimeUnit(value: number): string {
  return String(value).padStart(TIME_UNIT_DIGITS, '0')
}

function countdownText(remainingMs: number): string {
  const { days, hours, minutes } = countdownPartsOf(remainingMs)
  return days > 0
    ? t('groupIndustry.companion.countdown.days', { days, hours: padTimeUnit(hours) })
    : t('groupIndustry.companion.countdown.clock', { hours: padTimeUnit(hours), minutes: padTimeUnit(minutes) })
}

function jobStateClasses(state: string): string {
  if (state === 'ready') return 'border-l-emerald-400 bg-gradient-to-r from-emerald-400/15 to-white/[0.02]'
  return ''
}

function etaClasses(state: string): string {
  if (state === 'ready') return 'text-emerald-300'
  return state === 'paused' ? 'text-amber-300' : 'text-slate-200'
}

function outputPlace(output: JobLocation): string | null {
  return output.name
}

function unknownReasonText(output: JobLocation): string | null {
  return output.unknownReason === null ? null : t(`groupIndustry.jobLocations.reasons.${output.unknownReason}`)
}
</script>

<template>
  <div
    :data-testid="isBar ? 'companion-bar' : 'companion-column'"
    class="flex h-full w-full overflow-hidden border border-cyan-500/30 bg-slate-950 text-slate-200"
    :class="[isBar ? 'items-center gap-3 px-3' : 'flex-col', pulseClass]"
    @animationend.self="pulseClass = null"
  >
    <header
      class="flex items-center gap-2.5"
      :class="isBar ? 'min-w-0 flex-shrink-0' : 'flex-shrink-0 border-b border-slate-800 bg-gradient-to-b from-slate-900 to-slate-950 px-3 py-2.5'"
    >
      <div v-if="tasks.product !== null" class="relative flex-shrink-0" :class="isBar ? 'h-11 w-11' : 'h-[52px] w-[52px]'">
        <img
          data-testid="companion-product-image"
          :src="getTypeRenderUrl(tasks.product.typeId, PRODUCT_IMAGE_SIZE)"
          :title="tasks.product.typeName"
          alt=""
          class="h-full w-full"
          @error="onImageError"
        >
        <span
          data-testid="companion-product-runs"
          class="absolute -bottom-1 -right-1 border border-cyan-700 bg-black px-1 font-mono text-xs leading-tight text-cyan-300"
        >&times;{{ tasks.product.runs }}</span>
      </div>
      <div class="min-w-0" :class="isBar ? 'w-40' : 'flex-1'">
        <h1
          data-testid="companion-project-name"
          class="truncate font-bold leading-tight text-slate-100"
          :class="isBar ? 'text-sm' : 'text-base'"
          :title="projectName"
        >{{ projectName }}</h1>
        <p class="mt-1 flex items-center gap-2 text-xs text-slate-400">
          <span
            data-testid="companion-live"
            class="inline-flex flex-shrink-0 items-center gap-1 text-xs font-semibold uppercase tracking-wider text-emerald-400"
            :title="t('groupIndustry.companion.liveTitle')"
          >
            <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 shadow-[0_0_6px_rgb(52_211_153)]" />
            {{ t('groupIndustry.companion.live') }}
          </span>
          <span
            v-if="!isBar"
            data-testid="companion-final-jobs"
            class="min-w-0 flex-1 truncate"
            :title="t('groupIndustry.companion.finalJobsRunning', { count: tasks.finalJobsRunning })"
          >{{ t('groupIndustry.companion.finalJobsRunning', { count: tasks.finalJobsRunning }) }}</span>
          <button
            v-if="!isBar"
            type="button"
            data-testid="companion-toggle-size"
            class="flex-shrink-0 border border-slate-700 px-1.5 py-px text-xs uppercase tracking-wider text-slate-300 hover:bg-slate-800"
            :aria-label="t('groupIndustry.companion.toBar')"
            :title="t('groupIndustry.companion.toBar')"
            @click="emit('toggle-size')"
          >{{ t('groupIndustry.companion.collapse') }}</button>
        </p>
      </div>
      <CompanionGauge :percent="tasks.bomPercent" :diameter="isBar ? BAR_GAUGE_DIAMETER : COLUMN_GAUGE_DIAMETER" />
    </header>

    <template v-if="isBar">
      <div
        data-testid="companion-count-free"
        class="flex flex-shrink-0 flex-col gap-0.5 border-l border-slate-800 px-2.5"
        :title="t('groupIndustry.companion.tabs.free')"
      >
        <span class="text-xs uppercase tracking-[0.14em] text-slate-500">{{ t('groupIndustry.companion.tabs.free') }}</span>
        <span class="flex items-baseline gap-1.5 font-mono text-lg text-slate-100">
          {{ tasks.free.length }}
          <span v-if="miniBarSegments.length > 0" class="flex h-1 w-24 gap-0.5 self-center">
            <i
              v-for="segment in miniBarSegments"
              :key="segment.group"
              data-testid="companion-mini-segment"
              :data-group="segment.group"
              :data-count="segment.count"
              class="block h-1"
              :class="ACTIVITY_STYLES[segment.group].bar"
              :style="{ flex: segment.count }"
            />
          </span>
        </span>
      </div>
      <div
        data-testid="companion-count-mine"
        class="flex flex-shrink-0 flex-col gap-0.5 border-l border-slate-800 px-2.5"
        :title="t('groupIndustry.companion.tabs.mine')"
      >
        <span class="text-xs uppercase tracking-[0.14em] text-slate-500">{{ t('groupIndustry.companion.tabs.mine') }}</span>
        <span class="font-mono text-lg" :class="tasks.mine.length > 0 ? 'text-emerald-300' : 'text-slate-100'">{{ tasks.mine.length }}</span>
      </div>
      <div
        data-testid="companion-count-jobs"
        class="flex flex-shrink-0 flex-col gap-0.5 border-l border-slate-800 px-2.5"
        :title="t('groupIndustry.companion.tabs.jobs')"
      >
        <span class="text-xs uppercase tracking-[0.14em] text-slate-500">{{ t('groupIndustry.companion.tabs.jobs') }}</span>
        <span class="flex items-baseline gap-1.5 font-mono text-lg text-slate-100">
          {{ tasks.jobs.length }}
          <small v-if="tasks.readyJobCount > 0" data-testid="companion-count-jobs-ready" class="text-xs text-emerald-300">{{ tasks.readyJobCount }} &#10003;</small>
        </span>
      </div>
      <div
        v-if="tasks.toReview !== null"
        data-testid="companion-count-review"
        class="flex flex-shrink-0 flex-col gap-0.5 border-l border-slate-800 px-2.5"
        :title="t('groupIndustry.companion.todo.contributions', { count: tasks.toReview })"
      >
        <span class="text-xs uppercase tracking-[0.14em] text-slate-500">{{ t('groupIndustry.companion.bar.review') }}</span>
        <span class="font-mono text-lg text-amber-300">{{ tasks.toReview }}</span>
      </div>
      <div
        v-if="topAlert !== null"
        data-testid="companion-alert-row"
        :data-kind="topAlert.kind"
        class="flex min-w-0 flex-1 items-center gap-1.5"
      >
        <span
          data-testid="companion-alert"
          :data-kind="topAlert.kind"
          class="min-w-0 flex-1 truncate rounded border px-1.5 py-0.5 text-xs"
          :class="alertClasses(topAlert.kind)"
          :title="topAlert.text"
        >{{ topAlert.text }}</span>
        <button
          v-if="topAlert.action !== null"
          type="button"
          data-testid="companion-alert-action"
          :data-state="topAlert.action === 'copy' ? copyState : undefined"
          class="flex-shrink-0 border px-2 py-0.5 text-xs font-bold uppercase tracking-wider"
          :class="topAlert.action === 'deliver' ? 'border-emerald-400 bg-emerald-400 text-emerald-950' : 'border-amber-500/50 text-amber-300 hover:bg-amber-500/10'"
          @click="runAlertAction(topAlert, $event)"
        >{{ actionLabel(topAlert.action) }}</button>
      </div>
      <div v-else class="flex-1" />
      <button
        type="button"
        data-testid="companion-toggle-size"
        class="ml-auto flex-shrink-0 border border-slate-700 px-2 py-0.5 text-xs uppercase tracking-wider text-slate-300 hover:bg-slate-800"
        :aria-label="t('groupIndustry.companion.toColumn')"
        :title="t('groupIndustry.companion.toColumn')"
        @click="emit('toggle-size')"
      >{{ t('groupIndustry.companion.expand') }}</button>
    </template>

    <p
      v-if="errorMessage !== null"
      data-testid="companion-error"
      role="alert"
      class="mx-3 my-2 flex-shrink-0 rounded border border-red-500/40 bg-red-500/10 px-2 py-1 text-xs text-red-300"
    >{{ errorMessage }}</p>

    <template v-if="!isBar">
      <div data-testid="companion-chain" class="flex flex-shrink-0 gap-0.5 px-3 pt-2">
        <div
          v-for="segment in chainSegments"
          :key="segment.group"
          data-testid="companion-chain-segment"
          :data-group="segment.group"
          :data-percent="segment.percent"
          class="min-w-0 flex-1"
          :title="`${segment.label} · ${segment.delivered} / ${segment.total}`"
        >
          <div class="relative h-1 bg-slate-800">
            <div class="absolute inset-y-0 left-0" :class="ACTIVITY_STYLES[segment.group].bar" :style="{ width: `${segment.percent}%` }" />
          </div>
        </div>
      </div>

      <div v-if="alertViews.length > 0" class="mx-3 mt-2 flex max-h-24 flex-shrink-0 flex-col gap-1.5 overflow-y-auto">
        <div
          v-for="alert in alertViews"
          :key="alert.key"
          data-testid="companion-alert-row"
          :data-kind="alert.kind"
          class="flex items-center gap-1.5"
        >
          <div
            data-testid="companion-alert"
            :data-kind="alert.kind"
            class="min-w-0 flex-1 truncate rounded border px-2 py-1 text-xs"
            :class="alertClasses(alert.kind)"
            :title="alert.text"
          >{{ alert.text }}</div>
          <button
            v-if="alert.action !== null"
            type="button"
            data-testid="companion-alert-action"
            :data-state="alert.action === 'copy' ? copyState : undefined"
            class="flex-shrink-0 border px-2 py-1 text-xs font-bold uppercase tracking-wider"
            :class="alert.action === 'deliver' ? 'border-emerald-400 bg-emerald-400 text-emerald-950' : 'border-amber-500/50 text-amber-300 hover:bg-amber-500/10'"
            @click="runAlertAction(alert, $event)"
          >{{ actionLabel(alert.action) }}</button>
        </div>
      </div>

      <p
        v-if="tasks.moveProducts.unknownJobCount > 0"
        data-testid="companion-move-unknown"
        data-unknown="true"
        class="mx-3 mt-2 flex-shrink-0 text-xs text-slate-500"
      >{{ t('groupIndustry.companion.moveProductsUnknown', { count: tasks.moveProducts.unknownJobCount }) }}</p>

      <div role="tablist" class="mx-3 mt-3 grid flex-shrink-0 grid-cols-3 border-b border-slate-800">
        <button
          v-for="tab in tabs"
          :key="tab.pane"
          type="button"
          role="tab"
          :data-testid="`companion-tab-${tab.pane}`"
          :data-hot="tab.hot"
          :aria-selected="activePane === tab.pane"
          class="relative flex items-center justify-center gap-1.5 pb-1.5 pt-1 text-xs font-semibold uppercase tracking-wider"
          :class="activePane === tab.pane ? 'text-slate-100' : 'text-slate-400 hover:text-slate-200'"
          @click="activePane = tab.pane"
        >
          <span class="min-w-0 truncate">{{ t(`groupIndustry.companion.tabs.${tab.pane}`) }}</span>
          {{ ' ' }}
          <span
            class="border px-1 font-mono text-xs font-normal"
            :class="tab.hot ? 'border-emerald-400 text-emerald-300' : 'border-slate-700 text-slate-200'"
          >{{ tab.count }}</span>
          <span v-if="activePane === tab.pane" class="absolute inset-x-[12%] -bottom-px h-0.5 bg-cyan-400 shadow-[0_0_8px_rgb(34_211_238)]" aria-hidden="true" />
        </button>
      </div>

      <div data-testid="companion-body" class="min-h-0 flex-1 overflow-y-auto px-3 pb-3 pt-2">
        <div v-show="activePane === 'free'" data-testid="companion-pane-free" class="flex flex-col gap-2">
          <p v-if="tasks.free.length === 0" class="text-xs text-slate-500">{{ t('groupIndustry.companion.emptyFree') }}</p>
          <div v-for="group in freeView.groups" :key="group.group" :data-testid="`companion-group-free-${group.group}`" class="flex flex-col gap-1">
            <CompanionGroupHeader pane="free" :group="group.group" :count="group.total" :open="isOpen('free', group.group)" @toggle="toggleSection('free', group.group)" />
            <ul v-if="isOpen('free', group.group)" class="flex flex-col gap-1">
              <li
                v-for="item in group.shown"
                :key="item.id"
                data-testid="companion-free-line"
                :data-group="group.group"
                class="flex items-center gap-2 py-1 pr-1.5"
                :class="lineStyle(group.group)"
              >
                <div class="relative ml-1 h-[30px] w-[30px] flex-shrink-0 bg-black">
                  <img data-testid="companion-line-icon" :src="getTypeIconUrl(item.typeId, PRODUCT_IMAGE_SIZE)" alt="" class="h-full w-full" @error="onImageError">
                  <div v-if="group.group === 'copy'" data-testid="companion-copy-frame" class="absolute inset-0 border border-violet-400" />
                </div>
                <div class="min-w-0 flex-1">
                  <p class="flex items-center gap-1">
                    <span class="min-w-0 truncate text-sm font-semibold text-slate-100" :title="item.typeName">{{ item.typeName }}</span>
                    <BlueprintNameCopyButton :blueprint-name="item.blueprintName ?? null" />
                  </p>
                  <span
                    v-if="item.isJob && tasks.waitingForComponents.includes(item.id)"
                    data-testid="companion-waiting"
                    class="inline-block rounded border border-amber-500/40 px-1 text-xs text-amber-300"
                  >{{ t('groupIndustry.companion.waitingForComponents') }}</span>
                  <p v-if="item.isJob" class="truncate font-mono text-xs text-slate-400">
                    <template v-if="activityText(item) !== null">{{ activityText(item) }}</template>
                    <template v-else><span data-unknown="true" class="font-sans italic text-slate-500">{{ t('common.unknownValue') }}</span></template>
                    &middot;
                    <template v-if="item.runs !== null">{{ t('groupIndustry.companion.runs', { runs: item.runs }, item.runs) }}</template>
                    <template v-else><span data-unknown="true" class="font-sans italic text-slate-500">{{ t('common.unknownValue') }}</span></template>
                  </p>
                  <p v-else class="truncate font-mono text-xs text-slate-400" style="font-variant-numeric: tabular-nums;">
                    <span class="font-mono">&times;{{ formatNumber(item.freeQuantity, NUMBER_DECIMALS) }}</span>
                    &middot;
                    <span v-if="freeValueUnknown(item)" data-unknown="true" class="font-sans italic text-slate-500">{{ t('common.unknownValue') }}</span>
                    <span v-else class="font-mono">{{ freeValue(item) }}</span>
                  </p>
                </div>
                <button
                  type="button"
                  data-testid="companion-claim"
                  class="flex-shrink-0 border border-cyan-500/40 px-1.5 py-0.5 text-xs font-bold uppercase tracking-wide text-cyan-300 hover:bg-cyan-500/10"
                  @click="emit('claim', item.id)"
                >{{ t('groupIndustry.companion.claim') }}</button>
              </li>
            </ul>
          </div>
          <button
            v-if="freeView.hidden > 0"
            type="button"
            data-testid="companion-more-free"
            :data-count="freeView.hidden"
            class="self-start text-xs text-cyan-400 hover:underline"
            @click="emit('open-bom')"
          >{{ t('groupIndustry.companion.more.free', { count: freeView.hidden }) }}</button>
        </div>

        <div v-show="activePane === 'mine'" data-testid="companion-pane-mine" class="flex flex-col gap-2">
          <p v-if="tasks.mine.length === 0" class="text-xs text-slate-500">{{ t('groupIndustry.companion.emptyMine') }}</p>
          <div v-for="group in mineGroups" :key="group.group" :data-testid="`companion-group-mine-${group.group}`" class="flex flex-col gap-1">
            <CompanionGroupHeader pane="mine" :group="group.group" :count="group.entries.length" :open="isOpen('mine', group.group)" @toggle="toggleSection('mine', group.group)" />
            <ul v-if="isOpen('mine', group.group)" class="flex flex-col gap-1">
              <li
                v-for="entry in group.entries"
                :key="entry.item.id"
                data-testid="companion-mine-line"
                :data-group="group.group"
                class="flex items-center gap-2 py-1 pr-1.5"
                :class="lineStyle(group.group)"
              >
                <div class="relative ml-1 h-[30px] w-[30px] flex-shrink-0 bg-black">
                  <img data-testid="companion-line-icon" :src="getTypeIconUrl(entry.item.typeId, PRODUCT_IMAGE_SIZE)" alt="" class="h-full w-full" @error="onImageError">
                  <div v-if="group.group === 'copy'" data-testid="companion-copy-frame" class="absolute inset-0 border border-violet-400" />
                </div>
                <div class="min-w-0 flex-1">
                  <p class="flex items-center gap-1">
                    <span class="min-w-0 truncate text-sm font-semibold text-slate-100" :title="entry.item.typeName">{{ entry.item.typeName }}</span>
                    <BlueprintNameCopyButton :blueprint-name="entry.item.blueprintName ?? null" />
                    <OpenInGameButton v-if="!entry.item.isJob" type="market" :target-id="entry.item.typeId" />
                  </p>
                  <span
                    v-if="entry.item.isJob && tasks.waitingForComponents.includes(entry.item.id)"
                    data-testid="companion-waiting"
                    class="inline-block rounded border border-amber-500/40 px-1 text-xs text-amber-300"
                  >{{ t('groupIndustry.companion.waitingForComponents') }}</span>
                  <p class="truncate font-mono text-xs text-slate-400" style="font-variant-numeric: tabular-nums;">&times;{{ formatNumber(entry.quantity, NUMBER_DECIMALS) }}</p>
                </div>
                <button
                  type="button"
                  data-testid="companion-deliver"
                  class="flex-shrink-0 border border-emerald-400 bg-emerald-400 px-1.5 py-0.5 text-xs font-bold uppercase tracking-wide text-emerald-950"
                  @click="emit('deliver', entry.item.id)"
                >{{ t('groupIndustry.companion.deliver') }}</button>
                <button
                  type="button"
                  data-testid="companion-release"
                  class="flex-shrink-0 border border-slate-700 px-1.5 py-0.5 text-xs text-slate-400 hover:bg-slate-800"
                  :title="t('groupIndustry.companion.release')"
                  :aria-label="t('groupIndustry.companion.release')"
                  @click="emit('release', entry.item.id)"
                >{{ t('groupIndustry.companion.release') }}</button>
              </li>
            </ul>
          </div>

          <section v-if="tasks.awaitingApproval.length > 0" data-testid="companion-awaiting" class="flex flex-col gap-1">
            <ul class="flex flex-col gap-1">
              <li
                v-for="entry in tasks.awaitingApproval"
                :key="entry.item.id"
                data-testid="companion-awaiting-line"
                class="flex items-center gap-2 py-1 pr-1.5"
                :class="lineStyle(activityGroupOf(entry.item))"
              >
                <div class="relative ml-1 h-[30px] w-[30px] flex-shrink-0 bg-black">
                  <img data-testid="companion-line-icon" :src="getTypeIconUrl(entry.item.typeId, PRODUCT_IMAGE_SIZE)" alt="" class="h-full w-full" @error="onImageError">
                </div>
                <div class="min-w-0 flex-1">
                  <p class="flex items-center gap-1">
                    <span class="min-w-0 truncate text-sm font-semibold text-slate-100" :title="entry.item.typeName">{{ entry.item.typeName }}</span>
                    <BlueprintNameCopyButton :blueprint-name="entry.item.blueprintName ?? null" />
                  </p>
                  <p class="truncate font-mono text-xs text-slate-400" style="font-variant-numeric: tabular-nums;">&times;{{ formatNumber(entry.quantity, NUMBER_DECIMALS) }}</p>
                </div>
                <span class="flex-shrink-0 border border-amber-500/40 px-1.5 py-0.5 text-xs uppercase tracking-wider text-amber-300">{{ t('groupIndustry.companion.sections.awaiting') }}</span>
              </li>
            </ul>
          </section>
        </div>

        <div v-show="activePane === 'jobs'" data-testid="companion-pane-jobs" class="flex flex-col gap-2">
          <p v-if="tasks.jobs.length === 0" class="text-xs text-slate-500">{{ t('groupIndustry.companion.emptyJobs') }}</p>
          <div v-for="group in jobGroups" :key="group.group" :data-testid="`companion-group-jobs-${group.group}`" class="flex flex-col gap-1">
            <CompanionGroupHeader pane="jobs" :group="group.group" :count="group.entries.length" :open="isOpen('jobs', group.group)" @toggle="toggleSection('jobs', group.group)" />
            <ul v-if="isOpen('jobs', group.group)" class="flex flex-col gap-1">
              <li
                v-for="job in group.entries"
                :key="job.match.id"
                data-testid="companion-job"
                :data-group="group.group"
                :data-state="job.timing.state"
                class="flex flex-col gap-1 py-1 pr-1.5"
                :class="[lineStyle(group.group), jobStateClasses(job.timing.state)]"
              >
                <div class="flex items-center gap-2">
                  <div class="relative ml-1 h-[30px] w-[30px] flex-shrink-0 bg-black">
                    <img data-testid="companion-line-icon" :src="getTypeIconUrl(job.item.typeId, PRODUCT_IMAGE_SIZE)" alt="" class="h-full w-full" @error="onImageError">
                    <div v-if="group.group === 'copy'" data-testid="companion-copy-frame" class="absolute inset-0 border border-violet-400" />
                  </div>
                  <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-1">
                      <span class="min-w-0 truncate text-sm font-semibold text-slate-100" :title="job.item.typeName">{{ job.item.typeName }}</span>
                      <BlueprintNameCopyButton :blueprint-name="job.item.blueprintName ?? null" />
                    </p>
                    {{ ' ' }}
                    <p class="flex min-w-0 items-center gap-1 text-xs text-slate-400">
                      <span class="flex-shrink-0 font-mono" style="font-variant-numeric: tabular-nums;">{{ t('groupIndustry.companion.runs', { runs: job.match.runs }, job.match.runs) }}</span>
                      <span v-if="job.match.locations" data-testid="companion-job-location" class="min-w-0 flex-1 truncate">
                        <template v-if="outputPlace(job.match.locations.output) !== null">
                          &middot; <span :title="outputPlace(job.match.locations.output) ?? undefined">{{ t('groupIndustry.companion.output', { place: outputPlace(job.match.locations.output) }) }}</span>
                        </template>
                        <template v-else>
                          &middot; <span data-unknown="true" :title="unknownReasonText(job.match.locations.output) ?? undefined">{{ t('groupIndustry.companion.outputUnknown') }}</span>
                          <span v-if="unknownReasonText(job.match.locations.output) !== null" class="text-slate-500"> &middot; {{ unknownReasonText(job.match.locations.output) }}</span>
                        </template>
                      </span>
                    </p>
                  </div>
                  <div class="flex-shrink-0 text-right leading-tight">
                    <p
                      v-if="job.timing.state === 'ready'"
                      data-testid="companion-job-eta"
                      class="font-mono text-xs"
                      :class="etaClasses('ready')"
                    >&#10003;</p>
                    <p
                      v-else-if="job.timing.remainingMs !== null"
                      data-testid="companion-job-eta"
                      class="font-mono text-xs"
                      :class="etaClasses(job.timing.state)"
                      style="font-variant-numeric: tabular-nums;"
                    >{{ countdownText(job.timing.remainingMs) }}</p>
                    <p v-else data-testid="companion-job-eta" data-unknown="true" class="text-xs italic text-slate-500">{{ t('common.unknownValue') }}</p>
                    <p data-testid="companion-job-status" class="text-xs uppercase tracking-wider text-slate-400">{{ t(`groupIndustry.companion.jobStatus.${job.match.status}`) }}</p>
                  </div>
                </div>
                <div v-if="job.timing.state === 'ready'" data-testid="companion-job-bar" class="ml-[42px] h-[3px] bg-white/10">
                  <div class="h-full w-full bg-emerald-400 shadow-[0_0_6px_rgb(52_211_153)]" />
                </div>
              </li>
            </ul>
          </div>
        </div>
      </div>

      <footer data-testid="companion-footer" class="flex flex-shrink-0 flex-wrap items-center gap-x-3 gap-y-0.5 border-t border-slate-800 bg-slate-950 px-3 py-1.5 text-xs text-slate-400">
        <span>
          {{ t('groupIndustry.companion.footer.freeValue') }}
          <b
            v-if="tasks.freeValue.total !== null"
            data-testid="companion-footer-free-value"
            class="font-mono font-normal text-slate-200"
          >{{ formatIsk(tasks.freeValue.total, VALUE_DECIMALS) }}</b>
          <b
            v-else
            data-testid="companion-footer-free-value"
            data-unknown="true"
            class="font-normal italic text-slate-500"
          >{{ t('common.unknownValue') }}</b>
          <span
            v-if="tasks.freeValue.total !== null && tasks.freeValue.unknownLines > 0"
            data-testid="companion-footer-free-unknown"
            data-unknown="true"
            class="ml-1 italic text-slate-500"
          >{{ t('groupIndustry.companion.footer.withoutPrice', { count: tasks.freeValue.unknownLines }) }}</span>
        </span>
        <span data-testid="companion-footer-lines">
          {{ t('groupIndustry.companion.footer.lines') }}
          <b class="font-mono font-normal text-slate-200">{{ tasks.deliveredLines }} / {{ tasks.totalLines }}</b>
        </span>
        <span v-if="hasTodo" data-testid="companion-todo" class="flex flex-wrap gap-x-3 text-amber-300">
          <span v-if="tasks.toReview !== null">{{ t('groupIndustry.companion.todo.contributions', { count: tasks.toReview }) }}</span>
          <span v-if="tasks.joinRequests !== null">{{ t('groupIndustry.companion.todo.joinRequests', { count: tasks.joinRequests }) }}</span>
        </span>
      </footer>
    </template>
  </div>
</template>
