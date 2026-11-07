<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupCourierStore } from '@/stores/group-industry/courier'
import type { CourierCandidate, CourierLine, CourierQuoteStatus, CourierTransportState } from '@/stores/group-industry/types'
import { useSyncStore } from '@/stores/sync'
import UnknownContributionValue from './UnknownContributionValue.vue'

const DASH = '—'
const COPIED_FEEDBACK_MS = 2000

const STATE_CHIP_CLASSES: Record<CourierTransportState, string> = {
  not_shipped: 'border-slate-600 bg-slate-800/60 text-slate-400',
  waiting: 'border-amber-500/40 bg-amber-500/10 text-amber-300',
  in_transit: 'border-cyan-500/40 bg-cyan-500/10 text-cyan-300',
  delivered: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300',
  failed: 'border-red-500/40 bg-red-500/10 text-red-300',
  unknown: 'border-dashed border-violet-400 text-violet-300',
}

const UNKNOWN_QUOTE_STATUSES: readonly CourierQuoteStatus[] = ['volume_unknown', 'workshop_unknown', 'route_unknown']

const props = defineProps<{
  projectId: string
}>()

const { t } = useI18n()
const { formatIskFull, formatNumber, formatDateTime } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()
const syncStore = useSyncStore()
const courierStore = useGroupCourierStore()

const copied = ref(false)
const copyError = ref<string | null>(null)
let copiedTimer: ReturnType<typeof setTimeout> | null = null

const courier = computed(() => courierStore.courier)

const hasQuote = computed(() => courier.value !== null && courier.value.quoteStatus !== 'no_purchases')

const unknownQuoteStatus = computed<CourierQuoteStatus | null>(() => {
  const status = courier.value?.quoteStatus
  return status !== undefined && UNKNOWN_QUOTE_STATUSES.includes(status) ? status : null
})

function textOrDash(value: string | null): string {
  return value === null ? DASH : value
}

function volumeText(volume: number | null): string {
  return volume === null ? DASH : `${formatNumber(volume)} m³`
}

function iskText(amount: number | null): string {
  return amount === null ? DASH : formatIskFull(amount)
}

function locationText(locationId: number | null): string {
  return locationId === null ? DASH : `#${locationId}`
}

const contractText = computed(() => {
  const read = courier.value
  if (read === null) return ''
  return [
    `${t('groupIndustry.courier.contract.from')}: ${textOrDash(read.originSolarSystemName)}`,
    `${t('groupIndustry.courier.contract.to')}: ${textOrDash(read.workshopSolarSystemName)}`,
    `${t('groupIndustry.courier.contract.volume')}: ${volumeText(read.volumeCubicMeters)}`,
    `${t('groupIndustry.courier.contract.collateral')}: ${iskText(read.collateral)}`,
    `${t('groupIndustry.courier.contract.reward')}: ${iskText(read.reward)}`,
    `${t('groupIndustry.courier.contract.daysToComplete')}: ${t('groupIndustry.courier.days', { count: read.daysToComplete })}`,
    `${t('groupIndustry.courier.contract.expiration')}: ${t('groupIndustry.courier.days', { count: read.expirationDays })}`,
  ].join('\n')
})

const unknownCause = computed(() => {
  const read = courier.value
  const status = unknownQuoteStatus.value
  if (read === null || status === null) return ''
  const cause = t(`groupIndustry.courier.cause.${status}`)
  if (status !== 'volume_unknown' || read.missingVolumeTypeIds.length === 0) return cause
  return `${cause} ${t('groupIndustry.courier.cause.missingTypes', { types: read.missingVolumeTypeIds.join(', ') })}`
})

function stateLabel(state: CourierTransportState): string {
  return t(`groupIndustry.courier.state.${state}`)
}

function lineLabel(line: CourierLine): string {
  return line.typeName ?? `#${line.typeId}`
}

function isLinked(candidate: CourierCandidate): boolean {
  return courier.value?.contract?.contractId === candidate.contractId
}

async function copyContract(): Promise<void> {
  copyError.value = null
  try {
    await navigator.clipboard.writeText(contractText.value)
  } catch (e) {
    copyError.value = e instanceof Error ? e.message : t('groupIndustry.courier.copyFailed')
    return
  }
  copied.value = true
  if (copiedTimer !== null) clearTimeout(copiedTimer)
  copiedTimer = setTimeout(() => { copied.value = false }, COPIED_FEEDBACK_MS)
}

watch(() => props.projectId, projectId => courierStore.fetchCourier(projectId), { immediate: true })

watch(
  () => syncStore.getGroupProjectEvent(props.projectId),
  (event) => {
    if (!event) return
    courierStore.fetchCourier(props.projectId)
  },
)

watch(
  () => syncStore.getSyncProgress('industry-project'),
  (progress) => {
    if (progress?.data?.projectId !== props.projectId) return
    courierStore.fetchCourier(props.projectId)
  },
)

onBeforeUnmount(() => {
  if (copiedTimer !== null) clearTimeout(copiedTimer)
})
</script>

<template>
  <section data-testid="courier-card" class="mb-6 bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden">
    <header class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-b border-slate-800">
      <h2 class="font-display text-sm font-semibold uppercase tracking-widest text-cyan-400">
        {{ t('groupIndustry.courier.title') }}
      </h2>
      <span
        v-if="courier !== null && courier.contract !== null"
        data-testid="courier-state"
        :data-state="courier.contract.state"
        class="inline-block px-2 py-0.5 rounded border text-xs uppercase tracking-wider"
        :class="STATE_CHIP_CLASSES[courier.contract.state]"
      >{{ stateLabel(courier.contract.state) }}</span>
    </header>

    <div
      v-if="courierStore.error !== null"
      data-testid="courier-error"
      role="alert"
      class="m-4 p-3 rounded border border-red-500/30 bg-red-500/10 text-sm text-red-300"
    >
      <p class="font-semibold">{{ t('groupIndustry.courier.error') }}</p>
      <p class="mt-1 break-words">{{ courierStore.error }}</p>
    </div>

    <p
      v-if="courier === null && courierStore.loading"
      data-testid="courier-loading"
      class="p-8 text-center text-slate-500"
    >{{ t('groupIndustry.courier.loading') }}</p>

    <template v-else-if="courier !== null">
      <p
        v-if="!hasQuote"
        data-testid="courier-empty"
        class="p-8 text-center text-slate-500"
      >{{ t('groupIndustry.courier.empty') }}</p>

      <template v-else>
        <div class="p-4 grid gap-4 lg:grid-cols-2">
          <div class="min-w-0">
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
              <dt class="text-xs uppercase tracking-wider text-slate-500">{{ t('groupIndustry.courier.contract.route') }}</dt>
              <dd data-testid="courier-route" class="min-w-0 text-right text-slate-100 break-words">
                {{ textOrDash(courier.originSolarSystemName) }} → {{ textOrDash(courier.workshopSolarSystemName) }}
              </dd>

              <dt class="text-xs uppercase tracking-wider text-slate-500">{{ t('groupIndustry.courier.contract.volume') }}</dt>
              <dd data-testid="courier-volume" class="text-right font-mono tabular-nums text-slate-300">
                <UnknownContributionValue v-if="courier.volumeCubicMeters === null" />
                <template v-else>{{ volumeText(courier.volumeCubicMeters) }}</template>
              </dd>

              <dt class="text-xs uppercase tracking-wider text-slate-500">{{ t('groupIndustry.courier.contract.collateral') }}</dt>
              <dd data-testid="courier-collateral" class="text-right font-mono tabular-nums text-slate-300">
                <UnknownContributionValue v-if="courier.collateral === null" />
                <template v-else>{{ iskText(courier.collateral) }}</template>
              </dd>

              <dt class="text-xs uppercase tracking-wider text-slate-500">{{ t('groupIndustry.courier.contract.reward') }}</dt>
              <dd data-testid="courier-reward" class="text-right font-mono tabular-nums font-semibold text-slate-100">
                <UnknownContributionValue v-if="courier.reward === null" />
                <template v-else>{{ iskText(courier.reward) }}</template>
              </dd>

              <dt class="text-xs uppercase tracking-wider text-slate-500">{{ t('groupIndustry.courier.contract.jumps') }}</dt>
              <dd data-testid="courier-jumps" class="text-right font-mono tabular-nums text-slate-300">
                <UnknownContributionValue v-if="courier.jumps === null" />
                <template v-else>{{ courier.jumps }}</template>
              </dd>

              <dt class="text-xs uppercase tracking-wider text-slate-500">{{ t('groupIndustry.courier.contract.daysToComplete') }}</dt>
              <dd data-testid="courier-days-to-complete" class="text-right font-mono tabular-nums text-slate-300">
                {{ t('groupIndustry.courier.days', { count: courier.daysToComplete }) }}
              </dd>

              <dt class="text-xs uppercase tracking-wider text-slate-500">{{ t('groupIndustry.courier.contract.expiration') }}</dt>
              <dd data-testid="courier-expiration" class="text-right font-mono tabular-nums text-slate-300">
                {{ t('groupIndustry.courier.days', { count: courier.expirationDays }) }}
              </dd>
            </dl>

            <p
              v-if="unknownQuoteStatus !== null"
              data-testid="courier-unknown-cause"
              :data-cause="unknownQuoteStatus"
              class="mt-3 p-2 rounded border border-dashed border-violet-400 text-xs text-violet-300 break-words"
            >{{ unknownCause }}</p>
          </div>

          <div class="min-w-0">
            <pre
              data-testid="courier-contract-text"
              class="p-3 rounded border border-slate-700 bg-slate-950/60 font-mono text-xs text-slate-200 whitespace-pre-wrap break-words"
            >{{ contractText }}</pre>
            <div class="mt-2 flex flex-wrap items-center gap-3">
              <button
                type="button"
                data-testid="courier-copy"
                class="px-3 py-1.5 rounded border border-cyan-500/40 bg-cyan-500/10 text-xs uppercase tracking-wider text-cyan-300 hover:bg-cyan-500/20"
                @click="copyContract"
              >{{ t('groupIndustry.courier.copy') }}</button>
              <span
                v-if="copied"
                data-testid="courier-copied"
                role="status"
                class="text-xs text-emerald-300"
              >{{ t('groupIndustry.courier.copied') }}</span>
              <span
                v-if="copyError !== null"
                data-testid="courier-copy-error"
                role="alert"
                class="text-xs text-red-300 break-words"
              >{{ t('groupIndustry.courier.copyFailed') }} {{ copyError }}</span>
            </div>
          </div>
        </div>

        <div
          v-if="courier.contract !== null"
          data-testid="courier-linked-contract"
          class="mx-4 mb-4 p-3 rounded border border-slate-700 bg-slate-800/30"
        >
          <div class="flex flex-wrap items-center justify-between gap-3">
            <span class="font-mono text-sm text-slate-100">{{ t('groupIndustry.courier.linked.title', { id: courier.contract.contractId }) }}</span>
            <button
              v-if="courier.canLink"
              type="button"
              data-testid="courier-unlink"
              :disabled="courierStore.saving"
              class="px-3 py-1.5 rounded border border-slate-600 text-xs uppercase tracking-wider text-slate-300 hover:bg-slate-700/50 disabled:opacity-50"
              @click="courierStore.unlinkContract(projectId)"
            >{{ t('groupIndustry.courier.unlink') }}</button>
          </div>
          <dl class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-xs text-slate-400">
            <div class="flex gap-1">
              <dt>{{ t('groupIndustry.courier.contract.volume') }}</dt>
              <dd class="font-mono tabular-nums text-slate-300"><UnknownContributionValue v-if="courier.contract.volume === null" /><template v-else>{{ volumeText(courier.contract.volume) }}</template></dd>
            </div>
            <div class="flex gap-1">
              <dt>{{ t('groupIndustry.courier.contract.collateral') }}</dt>
              <dd class="font-mono tabular-nums text-slate-300"><UnknownContributionValue v-if="courier.contract.collateral === null" /><template v-else>{{ iskText(courier.contract.collateral) }}</template></dd>
            </div>
            <div class="flex gap-1">
              <dt>{{ t('groupIndustry.courier.contract.reward') }}</dt>
              <dd class="font-mono tabular-nums text-slate-300"><UnknownContributionValue v-if="courier.contract.reward === null" /><template v-else>{{ iskText(courier.contract.reward) }}</template></dd>
            </div>
            <div class="flex gap-1">
              <dt>{{ t('groupIndustry.courier.linked.issuedAt') }}</dt>
              <dd class="text-slate-300"><UnknownContributionValue v-if="courier.contract.issuedAt === null" /><template v-else>{{ formatDateTime(courier.contract.issuedAt) }}</template></dd>
            </div>
          </dl>
          <p
            v-if="courier.contract.state === 'unknown' && courier.contract.unknownReason !== null"
            data-testid="courier-contract-unknown-reason"
            class="mt-2 text-xs text-violet-300 break-words"
          >{{ courier.contract.unknownReason }}</p>
        </div>

        <div v-if="courier.lines.length > 0" class="border-t border-slate-800">
          <h3 class="px-4 pt-3 text-xs uppercase tracking-wider text-slate-500">{{ t('groupIndustry.courier.lines') }}</h3>
          <ul class="p-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <li
              v-for="line in courier.lines"
              :key="line.bomLineId"
              :data-testid="`courier-line-${line.typeId}`"
              :data-state="line.transportState"
              class="flex items-center gap-2 min-w-0 p-2 rounded border border-slate-800 bg-slate-800/30"
            >
              <img
                :src="getTypeIconUrl(line.typeId, 32)"
                alt=""
                class="w-8 h-8 rounded border border-slate-700 bg-slate-800 flex-shrink-0"
                @error="onImageError"
              />
              <span class="truncate font-medium text-slate-100" :title="lineLabel(line)">{{ lineLabel(line) }}</span>
              <span class="ml-auto font-mono tabular-nums text-sm text-slate-300">{{ formatNumber(line.purchasedQuantity) }}</span>
              <span
                class="inline-block flex-shrink-0 px-2 py-0.5 rounded border text-xs uppercase tracking-wider"
                :class="STATE_CHIP_CLASSES[line.transportState]"
              >{{ stateLabel(line.transportState) }}</span>
            </li>
          </ul>
        </div>
      </template>

      <div v-if="courier.canLink" class="border-t border-slate-800">
        <h3 class="px-4 pt-3 text-xs uppercase tracking-wider text-slate-500">{{ t('groupIndustry.courier.candidates.title') }}</h3>
        <p
          v-if="courier.candidates.length === 0"
          data-testid="courier-no-candidate"
          class="px-4 py-3 text-sm text-slate-500"
        >{{ t('groupIndustry.courier.candidates.empty') }}</p>
        <ul v-else class="p-4 space-y-2">
          <li
            v-for="candidate in courier.candidates"
            :key="candidate.contractId"
            :data-testid="`courier-candidate-${candidate.contractId}`"
            :data-matches="candidate.matchesQuote ? 'true' : 'false'"
            :data-state="candidate.state"
            class="flex flex-wrap items-center gap-x-4 gap-y-2 p-2 rounded border bg-slate-800/30"
            :class="candidate.matchesQuote ? 'border-emerald-500/40' : 'border-slate-800'"
          >
            <span class="font-mono text-sm text-slate-100">#{{ candidate.contractId }}</span>
            <span
              class="inline-block px-2 py-0.5 rounded border text-xs uppercase tracking-wider"
              :class="STATE_CHIP_CLASSES[candidate.state]"
            >{{ stateLabel(candidate.state) }}</span>
            <span
              v-if="candidate.matchesQuote"
              class="inline-block px-2 py-0.5 rounded border border-emerald-500/40 bg-emerald-500/10 text-xs uppercase tracking-wider text-emerald-300"
            >{{ t('groupIndustry.courier.candidates.matches') }}</span>
            <span class="min-w-0 text-xs text-slate-400 break-words">
              {{ locationText(candidate.startLocationId) }} → {{ locationText(candidate.endLocationId) }}
              &middot; {{ volumeText(candidate.volume) }}
              &middot; {{ iskText(candidate.reward) }}
              &middot; {{ iskText(candidate.collateral) }}
              &middot; {{ formatDateTime(candidate.issuedAt) }}
            </span>
            <button
              v-if="!isLinked(candidate)"
              type="button"
              :data-testid="`courier-link-${candidate.contractId}`"
              :disabled="courierStore.saving"
              class="ml-auto px-3 py-1.5 rounded border border-cyan-500/40 bg-cyan-500/10 text-xs uppercase tracking-wider text-cyan-300 hover:bg-cyan-500/20 disabled:opacity-50"
              @click="courierStore.linkContract(projectId, candidate.contractId)"
            >{{ t('groupIndustry.courier.link') }}</button>
          </li>
        </ul>
      </div>
    </template>
  </section>
</template>
