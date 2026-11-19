<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { BomItem, JobLocation } from '@/stores/group-industry/types'
import type { CompanionAlert, CompanionTasks } from './companionTasks'
import OpenInGameButton from '@/components/common/OpenInGameButton.vue'
import BlueprintNameCopyButton from './BlueprintNameCopyButton.vue'
import { recallCollapsedSections, rememberCollapsedSections, type CompanionSectionKey } from './companionCollapsedSections'

type LineKind = 'buy' | 'build'

const FREE_LINES_SHOWN = 6
const ALERT_PRIORITY: CompanionAlert['kind'][] = ['job_ready', 'move_products', 'price_below_break_even', 'undercut_sell_order', 'join_request', 'bom_complete']
const VALUE_DECIMALS = 1
const BOM_PERCENT_DECIMALS = 0
const LINE_KINDS: LineKind[] = ['buy', 'build']

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

const collapsedSections = ref<CompanionSectionKey[]>(recallCollapsedSections())

function isOpen(key: CompanionSectionKey): boolean {
  return !collapsedSections.value.includes(key)
}

function toggleSection(key: CompanionSectionKey): void {
  collapsedSections.value = isOpen(key)
    ? [...collapsedSections.value, key]
    : collapsedSections.value.filter(collapsed => collapsed !== key)
  rememberCollapsedSections(collapsedSections.value)
}

function isOfKind(item: BomItem, kind: LineKind): boolean {
  return item.isJob === (kind === 'build')
}

function countOfKind(items: BomItem[], kind: LineKind): number {
  return items.filter(item => isOfKind(item, kind)).length
}

const freeGroups = computed(() => LINE_KINDS.map((kind) => {
  const lines = props.tasks.free.filter(item => isOfKind(item, kind))
  return { kind, total: lines.length, shown: lines.slice(0, FREE_LINES_SHOWN), hidden: Math.max(0, lines.length - FREE_LINES_SHOWN) }
}).filter(group => group.total > 0))

const mineGroups = computed(() => LINE_KINDS.map((kind) => {
  const lines = props.tasks.mine.filter(entry => isOfKind(entry.item, kind))
  return { kind, total: lines.length, lines }
}).filter(group => group.total > 0))

const freeCounts = computed(() => ({
  buy: countOfKind(props.tasks.free, 'buy'),
  build: countOfKind(props.tasks.free, 'build'),
}))
const mineCounts = computed(() => ({
  buy: props.tasks.mine.filter(entry => isOfKind(entry.item, 'buy')).length,
  build: props.tasks.mine.filter(entry => isOfKind(entry.item, 'build')).length,
}))
const hasTodo = computed(() => props.tasks.toReview !== null || props.tasks.joinRequests !== null)
const isBar = computed(() => props.size === 'bar')

const sortedAlerts = computed(() => [...props.tasks.alerts].sort(
  (left, right) => ALERT_PRIORITY.indexOf(left.kind) - ALERT_PRIORITY.indexOf(right.kind),
))

function jobAlertText(alert: CompanionAlert): string {
  const job = props.tasks.jobs.find(candidate => `job_ready:${candidate.match.id}` === alert.key)
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
      quantity: formatNumber(props.tasks.moveProducts.quantity, 0),
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

const alertViews = computed(() => sortedAlerts.value.map(alert => ({ ...alert, text: alertText(alert) })))
const topAlert = computed(() => alertViews.value[0] ?? null)

function alertClasses(kind: CompanionAlert['kind']): string {
  return kind === 'bom_complete'
    ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300'
    : 'border-amber-500/40 bg-amber-500/10 text-amber-300'
}

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

function activityLabel(activityType: string | null): string | null {
  return activityType === null ? null : activityType.charAt(0).toUpperCase() + activityType.slice(1)
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
    class="flex h-full w-full flex-col border border-cyan-500/30 bg-slate-950 text-slate-200"
    :class="[isBar ? 'overflow-hidden' : 'overflow-y-auto', pulseClass]"
    @animationend.self="pulseClass = null"
  >
    <header
      class="flex items-center gap-2 px-3 py-2"
      :class="isBar ? 'flex-1' : 'border-b border-slate-800'"
    >
      <span
        data-testid="companion-live"
        class="inline-flex flex-shrink-0 items-center gap-1 text-xs uppercase tracking-wider text-emerald-400"
        :title="t('groupIndustry.companion.liveTitle')"
      >
        <span class="h-2 w-2 rounded-full bg-emerald-400" />
        {{ t('groupIndustry.companion.live') }}
      </span>
      <h1 class="min-w-0 flex-1 truncate text-sm font-bold text-slate-100" :title="projectName">{{ projectName }}</h1>
      <span class="flex-shrink-0 font-mono text-sm text-cyan-300" data-testid="companion-bom-percent" style="font-variant-numeric: tabular-nums;">
        {{ t('groupIndustry.companion.bomPercent', { percent: formatNumber(tasks.bomPercent, BOM_PERCENT_DECIMALS) }) }}
      </span>
      <template v-if="isBar">
        <span class="flex-shrink-0 rounded border border-slate-700 px-1.5 py-0.5 text-xs text-slate-300" data-testid="companion-count-free" :title="t('groupIndustry.companion.sections.free')">
          <template v-if="tasks.free.length === 0">{{ t('groupIndustry.companion.counters.free', { count: 0 }) }}</template>
          <template v-else>
            {{ t('groupIndustry.companion.counters.freeLabel') }}
            <span v-if="freeCounts.buy > 0" data-testid="companion-count-free-buy">{{ t('groupIndustry.companion.counters.toBuy', { count: freeCounts.buy }) }}</span>
            <template v-if="freeCounts.buy > 0 && freeCounts.build > 0"> &middot; </template>
            <span v-if="freeCounts.build > 0" data-testid="companion-count-free-build">{{ t('groupIndustry.companion.counters.toBuild', { count: freeCounts.build }) }}</span>
          </template>
        </span>
        <span class="flex-shrink-0 rounded border border-slate-700 px-1.5 py-0.5 text-xs text-slate-300" data-testid="companion-count-mine" :title="t('groupIndustry.companion.sections.mine')">
          <template v-if="tasks.mine.length === 0">{{ t('groupIndustry.companion.counters.mine', { count: 0 }) }}</template>
          <template v-else>
            {{ t('groupIndustry.companion.counters.mineLabel') }}
            <span v-if="mineCounts.buy > 0" data-testid="companion-count-mine-buy">{{ t('groupIndustry.companion.counters.toBuy', { count: mineCounts.buy }) }}</span>
            <template v-if="mineCounts.buy > 0 && mineCounts.build > 0"> &middot; </template>
            <span v-if="mineCounts.build > 0" data-testid="companion-count-mine-build">{{ t('groupIndustry.companion.counters.toBuild', { count: mineCounts.build }) }}</span>
          </template>
        </span>
        <span
          v-if="tasks.toReview !== null"
          class="flex-shrink-0 rounded border border-slate-700 px-1.5 py-0.5 text-xs text-slate-300"
          data-testid="companion-count-review"
          :title="t('groupIndustry.companion.todo.contributions', { count: tasks.toReview })"
        >{{ t('groupIndustry.companion.counters.review', { count: tasks.toReview }) }}</span>
        <span
          v-if="topAlert !== null"
          data-testid="companion-alert"
          :data-kind="topAlert.kind"
          class="max-w-40 flex-shrink-0 truncate rounded border px-1.5 py-0.5 text-xs"
          :class="alertClasses(topAlert.kind)"
          :title="topAlert.text"
        >{{ topAlert.text }}</span>
      </template>
      <button
        type="button"
        data-testid="companion-toggle-size"
        class="flex-shrink-0 rounded border border-slate-700 px-2 py-0.5 text-xs text-slate-300 hover:bg-slate-800"
        :aria-label="isBar ? t('groupIndustry.companion.toColumn') : t('groupIndustry.companion.toBar')"
        :title="isBar ? t('groupIndustry.companion.toColumn') : t('groupIndustry.companion.toBar')"
        @click="emit('toggle-size')"
      >{{ isBar ? t('groupIndustry.companion.expand') : t('groupIndustry.companion.collapse') }}</button>
    </header>

    <p
      v-if="errorMessage !== null"
      data-testid="companion-error"
      role="alert"
      class="mx-3 my-2 rounded border border-red-500/40 bg-red-500/10 px-2 py-1 text-xs text-red-300"
    >{{ errorMessage }}</p>

    <div v-if="!isBar" class="flex flex-col gap-4 p-3">
      <section v-if="alertViews.length > 0" class="flex flex-col gap-1.5">
        <div
          v-for="alert in alertViews"
          :key="alert.key"
          data-testid="companion-alert"
          :data-kind="alert.kind"
          class="min-w-0 truncate rounded border px-2 py-1 text-xs"
          :class="alertClasses(alert.kind)"
          :title="alert.text"
        >{{ alert.text }}</div>
      </section>

      <p
        v-if="tasks.moveProducts.unknownJobCount > 0"
        data-testid="companion-move-unknown"
        data-unknown="true"
        class="text-xs text-slate-500"
      >{{ t('groupIndustry.companion.moveProductsUnknown', { count: tasks.moveProducts.unknownJobCount }) }}</p>

      <section data-testid="companion-free" class="flex flex-col gap-1.5">
        <button
          type="button"
          data-testid="companion-toggle-free"
          class="flex w-full items-center gap-1.5 text-left text-xs uppercase tracking-wider text-cyan-400"
          :aria-expanded="isOpen('free')"
          @click="toggleSection('free')"
        >
          <svg class="h-2.5 w-2.5 flex-shrink-0 text-slate-500 transition-transform" :class="isOpen('free') ? 'rotate-90' : ''" viewBox="0 0 10 10" fill="currentColor" aria-hidden="true"><path d="M3 1l5 4-5 4z" /></svg>
          <span>{{ t('groupIndustry.companion.sections.free') }}</span>
          <span data-testid="companion-free-total" class="ml-auto font-mono text-xs normal-case tracking-normal" :class="isOpen('free') ? 'text-slate-500' : 'text-slate-300'">{{ tasks.free.length }}</span>
        </button>
        <template v-if="isOpen('free')">
          <p v-if="tasks.free.length === 0" class="text-xs text-slate-500">{{ t('groupIndustry.companion.emptyFree') }}</p>
          <div v-for="group in freeGroups" :key="group.kind" :data-testid="`companion-free-${group.kind}`" class="flex flex-col gap-1">
            <button
              type="button"
              :data-testid="`companion-toggle-free-${group.kind}`"
              class="flex w-full items-center gap-1.5 text-left text-xs uppercase tracking-wider text-slate-400"
              :aria-expanded="isOpen(`free:${group.kind}`)"
              @click="toggleSection(`free:${group.kind}`)"
            >
              <svg class="h-2.5 w-2.5 flex-shrink-0 text-slate-500 transition-transform" :class="isOpen(`free:${group.kind}`) ? 'rotate-90' : ''" viewBox="0 0 10 10" fill="currentColor" aria-hidden="true"><path d="M3 1l5 4-5 4z" /></svg>
              <template v-if="group.kind === 'buy'"><svg class="h-3 w-3 flex-shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M2 3h2l1.5 7h7L14 5H5" /><circle cx="6.5" cy="13" r="1" /><circle cx="12" cy="13" r="1" /></svg></template><template v-else><svg class="h-3 w-3 flex-shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M9.5 2.5l4 4-2 2-4-4zM7.5 4.5l-5 5 4 4 5-5" /></svg></template>
              <span>{{ t(`groupIndustry.companion.groups.${group.kind}`) }}</span>
              <span :data-testid="`companion-free-${group.kind}-total`" class="font-mono normal-case tracking-normal text-slate-300">{{ group.total }}</span>
            </button>
            <template v-if="isOpen(`free:${group.kind}`)">
              <ul class="flex flex-col gap-1">
                <li
                  v-for="item in group.shown"
                  :key="item.id"
                  data-testid="companion-free-line"
                  class="flex items-center gap-2 rounded border border-slate-800 bg-slate-900/60 px-2 py-1"
                >
                  <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-1">
                      <span class="min-w-0 truncate text-sm text-slate-100" :title="item.typeName">{{ item.typeName }}</span>
                      <BlueprintNameCopyButton :blueprint-name="item.blueprintName ?? null" />
                    </p>
                    <p v-if="item.isJob" class="truncate text-xs text-slate-400">
                      <template v-if="activityLabel(item.activityType) !== null">{{ activityLabel(item.activityType) }}</template>
                      <template v-else><span data-unknown="true">{{ t('common.unknownValue') }}</span></template>
                      &middot;
                      <template v-if="item.runs !== null">{{ t('groupIndustry.companion.runs', { runs: item.runs }, item.runs) }}</template>
                      <template v-else><span data-unknown="true">{{ t('common.unknownValue') }}</span></template>
                    </p>
                    <p v-else class="truncate text-xs text-slate-400" style="font-variant-numeric: tabular-nums;">
                      <span class="font-mono">&times;{{ formatNumber(item.freeQuantity, 0) }}</span>
                      &middot;
                      <span v-if="freeValueUnknown(item)" data-unknown="true" class="text-slate-500">{{ t('common.unknownValue') }}</span>
                      <span v-else class="font-mono">{{ freeValue(item) }}</span>
                    </p>
                  </div>
                  <button
                    type="button"
                    data-testid="companion-claim"
                    class="flex-shrink-0 rounded border border-cyan-500/40 px-2 py-0.5 text-xs text-cyan-300 hover:bg-cyan-500/10"
                    @click="emit('claim', item.id)"
                  >{{ t('groupIndustry.companion.claim') }}</button>
                </li>
              </ul>
              <button
                v-if="group.hidden > 0"
                type="button"
                :data-testid="`companion-more-free-${group.kind}`"
                :data-count="group.hidden"
                class="self-start text-xs text-cyan-400 hover:underline"
                @click="emit('open-bom')"
              >{{ t(`groupIndustry.companion.more.${group.kind}`, { count: group.hidden }) }}</button>
            </template>
          </div>
        </template>
      </section>

      <section data-testid="companion-mine" class="flex flex-col gap-1.5">
        <button
          type="button"
          data-testid="companion-toggle-mine"
          class="flex w-full items-center gap-1.5 text-left text-xs uppercase tracking-wider text-cyan-400"
          :aria-expanded="isOpen('mine')"
          @click="toggleSection('mine')"
        >
          <svg class="h-2.5 w-2.5 flex-shrink-0 text-slate-500 transition-transform" :class="isOpen('mine') ? 'rotate-90' : ''" viewBox="0 0 10 10" fill="currentColor" aria-hidden="true"><path d="M3 1l5 4-5 4z" /></svg>
          <span>{{ t('groupIndustry.companion.sections.mine') }}</span>
          <span data-testid="companion-mine-total" class="ml-auto font-mono text-xs normal-case tracking-normal" :class="isOpen('mine') ? 'text-slate-500' : 'text-slate-300'">{{ tasks.mine.length }}</span>
        </button>
        <template v-if="isOpen('mine')">
          <p v-if="tasks.mine.length === 0" class="text-xs text-slate-500">{{ t('groupIndustry.companion.emptyMine') }}</p>
          <div v-for="group in mineGroups" :key="group.kind" :data-testid="`companion-mine-${group.kind}`" class="flex flex-col gap-1">
            <button
              type="button"
              :data-testid="`companion-toggle-mine-${group.kind}`"
              class="flex w-full items-center gap-1.5 text-left text-xs uppercase tracking-wider text-slate-400"
              :aria-expanded="isOpen(`mine:${group.kind}`)"
              @click="toggleSection(`mine:${group.kind}`)"
            >
              <svg class="h-2.5 w-2.5 flex-shrink-0 text-slate-500 transition-transform" :class="isOpen(`mine:${group.kind}`) ? 'rotate-90' : ''" viewBox="0 0 10 10" fill="currentColor" aria-hidden="true"><path d="M3 1l5 4-5 4z" /></svg>
              <template v-if="group.kind === 'buy'"><svg class="h-3 w-3 flex-shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M2 3h2l1.5 7h7L14 5H5" /><circle cx="6.5" cy="13" r="1" /><circle cx="12" cy="13" r="1" /></svg></template><template v-else><svg class="h-3 w-3 flex-shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M9.5 2.5l4 4-2 2-4-4zM7.5 4.5l-5 5 4 4 5-5" /></svg></template>
              <span>{{ t(`groupIndustry.companion.groups.${group.kind}`) }}</span>
              <span :data-testid="`companion-mine-${group.kind}-total`" class="font-mono normal-case tracking-normal text-slate-300">{{ group.total }}</span>
            </button>
            <ul v-if="isOpen(`mine:${group.kind}`)" class="flex flex-col gap-1">
              <li
                v-for="entry in group.lines"
                :key="entry.item.id"
                data-testid="companion-mine-line"
                class="flex items-center gap-2 rounded border border-slate-800 bg-slate-900/60 px-2 py-1"
              >
                <div class="min-w-0 flex-1">
                  <p class="flex items-center gap-1">
                    <span class="min-w-0 truncate text-sm text-slate-100" :title="entry.item.typeName">{{ entry.item.typeName }}</span>
                    <BlueprintNameCopyButton :blueprint-name="entry.item.blueprintName ?? null" />
                    <OpenInGameButton v-if="!entry.item.isJob" type="market" :target-id="entry.item.typeId" />
                  </p>
                  <p class="truncate font-mono text-xs text-slate-400" style="font-variant-numeric: tabular-nums;">&times;{{ formatNumber(entry.quantity, 0) }}</p>
                </div>
                <button
                  type="button"
                  data-testid="companion-deliver"
                  class="flex-shrink-0 rounded border border-emerald-500/40 px-2 py-0.5 text-xs text-emerald-300 hover:bg-emerald-500/10"
                  @click="emit('deliver', entry.item.id)"
                >{{ t('groupIndustry.companion.deliver') }}</button>
                <button
                  type="button"
                  data-testid="companion-release"
                  class="flex-shrink-0 rounded border border-slate-600 px-2 py-0.5 text-xs text-slate-300 hover:bg-slate-800"
                  @click="emit('release', entry.item.id)"
                >{{ t('groupIndustry.companion.release') }}</button>
              </li>
            </ul>
          </div>
        </template>
      </section>

      <section v-if="tasks.awaitingApproval.length > 0" data-testid="companion-awaiting" class="flex flex-col gap-1.5">
        <h2 class="text-xs uppercase tracking-wider text-cyan-400">{{ t('groupIndustry.companion.sections.awaiting') }}</h2>
        <ul class="flex flex-col gap-1">
          <li
            v-for="entry in tasks.awaitingApproval"
            :key="entry.item.id"
            data-testid="companion-awaiting-line"
            class="flex items-center gap-2 rounded border border-slate-800 bg-slate-900/60 px-2 py-1"
          >
            <div class="min-w-0 flex-1">
              <p class="flex items-center gap-1">
                <span class="min-w-0 truncate text-sm text-slate-100" :title="entry.item.typeName">{{ entry.item.typeName }}</span>
                <BlueprintNameCopyButton :blueprint-name="entry.item.blueprintName ?? null" />
              </p>
              <p class="truncate font-mono text-xs text-slate-400" style="font-variant-numeric: tabular-nums;">&times;{{ formatNumber(entry.quantity, 0) }}</p>
            </div>
            <span class="flex-shrink-0 rounded border border-amber-500/40 px-1.5 py-0.5 text-xs text-amber-300">{{ t('groupIndustry.companion.sections.awaiting') }}</span>
          </li>
        </ul>
      </section>

      <section v-if="hasTodo" data-testid="companion-todo" class="flex flex-col gap-1.5">
        <h2 class="text-xs uppercase tracking-wider text-cyan-400">{{ t('groupIndustry.companion.sections.todo') }}</h2>
        <p v-if="tasks.toReview !== null" class="text-sm text-slate-300">{{ t('groupIndustry.companion.todo.contributions', { count: tasks.toReview }) }}</p>
        <p v-if="tasks.joinRequests !== null" class="text-sm text-slate-300">{{ t('groupIndustry.companion.todo.joinRequests', { count: tasks.joinRequests }) }}</p>
      </section>

      <section data-testid="companion-jobs" class="flex flex-col gap-1.5">
        <h2 class="text-xs uppercase tracking-wider text-cyan-400">{{ t('groupIndustry.companion.sections.jobs') }}</h2>
        <p v-if="tasks.jobs.length === 0" class="text-xs text-slate-500">{{ t('groupIndustry.companion.emptyJobs') }}</p>
        <ul v-else class="flex flex-col gap-1">
          <li
            v-for="job in tasks.jobs"
            :key="job.match.id"
            data-testid="companion-job"
            class="flex flex-col gap-0.5 rounded border border-slate-800 bg-slate-900/60 px-2 py-1"
          >
            <p class="flex items-center gap-2">
              <span class="min-w-0 truncate text-sm text-slate-100" :title="job.item.typeName">{{ job.item.typeName }}</span>
              <BlueprintNameCopyButton :blueprint-name="job.item.blueprintName ?? null" />
              <span class="ml-auto flex-shrink-0 text-xs text-slate-400">{{ t(`groupIndustry.companion.jobStatus.${job.match.status}`) }}</span>
              <span class="flex-shrink-0 font-mono text-xs text-slate-400" style="font-variant-numeric: tabular-nums;">{{ t('groupIndustry.companion.runs', { runs: job.match.runs }, job.match.runs) }}</span>
            </p>
            <p v-if="job.match.locations" data-testid="companion-job-location" class="truncate text-xs text-slate-400">
              <template v-if="outputPlace(job.match.locations.output) !== null">
                <span :title="outputPlace(job.match.locations.output) ?? undefined">{{ t('groupIndustry.companion.output', { place: outputPlace(job.match.locations.output) }) }}</span>
              </template>
              <template v-else>
                <span data-unknown="true" :title="unknownReasonText(job.match.locations.output) ?? undefined">{{ t('groupIndustry.companion.outputUnknown') }}</span>
                <span v-if="unknownReasonText(job.match.locations.output) !== null" class="text-slate-500"> &middot; {{ unknownReasonText(job.match.locations.output) }}</span>
              </template>
            </p>
          </li>
        </ul>
      </section>

      <section data-testid="companion-project" class="flex flex-col gap-1.5">
        <h2 class="text-xs uppercase tracking-wider text-cyan-400">{{ t('groupIndustry.companion.sections.project') }}</h2>
        <p class="text-sm text-slate-300" style="font-variant-numeric: tabular-nums;">{{ t('groupIndustry.companion.bomFulfillment', { percent: formatNumber(tasks.bomPercent, BOM_PERCENT_DECIMALS) }) }}</p>
        <p class="text-sm text-slate-300" style="font-variant-numeric: tabular-nums;">{{ t('groupIndustry.companion.finalJobsRunning', { count: tasks.finalJobsRunning }) }}</p>
      </section>
    </div>
  </div>
</template>
