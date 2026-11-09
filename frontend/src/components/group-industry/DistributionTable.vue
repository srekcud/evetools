<script setup lang="ts">
import DataTable from '@/components/common/DataTable.vue'
import IskAmount from '@/components/common/IskAmount.vue'
import UnknownValue from '@/components/common/UnknownValue.vue'
import UnknownContributionValue from '@/components/group-industry/UnknownContributionValue.vue'
import AwaitingSalesValue from '@/components/group-industry/AwaitingSalesValue.vue'
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import { usePilotName } from '@/composables/usePilotName'
import { CHART_SERIES } from '@/utils/chartColors'

const { t } = useI18n()

const store = useGroupDistributionStore()
const { formatIsk } = useFormatters()
const { getCharacterPortraitUrl, onImageError } = useEveImages()
const { pilotName } = usePilotName()

const expandedMemberId = ref<string | null>(null)

const dist = computed(() => store.distribution)

const isAwaitingSales = computed(() => dist.value?.awaitingSales === true)

const unsoldProducts = computed(() => dist.value?.unsoldProducts ?? [])

const isProvisional = computed(() => !isAwaitingSales.value && unsoldProducts.value.length > 0)

const isUndistributable = computed(() => !isAwaitingSales.value && dist.value?.marginPercent === null)

const showLossScenario = computed(() => {
  const margin = dist.value?.marginPercent ?? null
  return !isAwaitingSales.value && !isProvisional.value && margin !== null && margin < 0
})

const RING_RADIUS = 80
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS

function getMemberColor(index: number): string {
  return CHART_SERIES[index % CHART_SERIES.length]
}

function toggleExpand(memberId: string): void {
  expandedMemberId.value = expandedMemberId.value === memberId ? null : memberId
}

function isExpanded(memberId: string): boolean {
  return expandedMemberId.value === memberId
}

// Ring chart segment data
const ringSegments = computed(() => {
  if (!dist.value) return []
  let offset = 0
  return dist.value.members.flatMap((member, i) => {
    if (member.sharePercent === null) return []
    const dash = (member.sharePercent / 100) * RING_CIRCUMFERENCE
    const gap = RING_CIRCUMFERENCE - dash
    const segment = { dash, gap, offset: -offset, color: getMemberColor(i) }
    offset += dash
    return [segment]
  })
})

// Totals
const totalCosts = computed(() => dist.value?.totalProjectCost ?? null)

const isContributionValueUnknown = computed(() => dist.value?.totalProjectCost === null)

function sumOrUnknown(values: (number | null)[]): number | null {
  let sum = 0
  for (const value of values) {
    if (value === null) return null
    sum += value
  }
  return sum
}

const totalProfit = computed(() => {
  if (!dist.value || isUndistributable.value) return null
  return sumOrUnknown(dist.value.members.map(m => m.profitPart))
})
const totalPayout = computed(() => {
  if (!dist.value || isUndistributable.value) return null
  return sumOrUnknown(dist.value.members.map(m => m.payoutTotal))
})

function profitColorClass(value: number | null): string {
  if (value === null) return 'text-slate-400'
  return value >= 0 ? 'text-emerald-400' : 'text-red-400'
}

function formatProfit(value: number): string {
  const prefix = value >= 0 ? '+' : ''
  return prefix + formatIsk(value)
}

// Breakdown detail colors
const BREAKDOWN_COLORS: Record<string, string> = {
  material: 'bg-cyan-400',
  job_install: 'bg-amber-400',
  bpc: 'bg-violet-400',
  line_rental: 'bg-pink-400',
  courier: 'bg-sky-400',
}
</script>

<template>
  <div v-if="store.distributionError" class="bg-red-500/10 border border-red-500/20 rounded-xl p-4">
    <p class="text-sm text-red-400">{{ store.distributionError }}</p>
  </div>
  <div v-if="dist">
    <h2 class="text-lg font-semibold text-slate-100 mb-4">Profit Distribution</h2>
    <div
      v-if="isAwaitingSales"
      data-testid="awaiting-sales-banner"
      class="bg-cyan-500/5 rounded-xl border border-dashed border-cyan-400/40 p-4 mb-4 text-sm text-cyan-300"
    >
      <p class="font-semibold">{{ t('groupIndustry.distribution.awaitingSales') }}</p>
      <p class="text-xs text-cyan-300/80 mt-1">{{ t('groupIndustry.distribution.awaitingSalesBanner') }}</p>
    </div>
    <div
      v-else-if="isContributionValueUnknown"
      class="bg-violet-500/5 rounded-xl border border-dashed border-violet-400/40 p-4 mb-4 text-sm text-violet-300"
    >
      {{ t('groupIndustry.distribution.unknownContributionValue') }}
    </div>
    <div
      v-else-if="isUndistributable"
      class="bg-amber-500/5 rounded-xl border border-amber-500/20 p-4 mb-4 text-sm text-amber-300"
    >
      {{ t('groupIndustry.distribution.undistributable') }}
    </div>
    <div
      v-if="isProvisional"
      data-testid="provisional-margin-banner"
      class="bg-amber-500/5 rounded-xl border border-amber-500/20 p-4 mb-4 text-sm"
    >
      <h4 class="font-semibold text-amber-300 mb-2">{{ t('groupIndustry.distribution.provisionalMarginTitle') }}</h4>
      <p class="text-xs text-slate-400 mb-1">{{ t('groupIndustry.distribution.provisionalMarginUnsold') }}</p>
      <ul class="space-y-1 mb-3">
        <li
          v-for="product in unsoldProducts"
          :key="product.typeId"
          data-testid="unsold-product"
          class="flex items-center justify-between text-xs"
        >
          <span class="text-slate-300">{{ product.typeName }} &times; {{ product.unsoldQuantity }}</span>
          <span class="font-mono text-slate-400">
            <template v-if="product.lastUnitPrice === null">{{ t('groupIndustry.distribution.neverSold') }}</template>
            <template v-else>{{ formatIsk(product.lastUnitPrice) }}</template>
          </span>
        </li>
      </ul>
      <p data-testid="projected-margin" class="text-xs text-slate-300">
        {{ t('groupIndustry.distribution.projectedMargin') }}
        <span class="font-mono font-semibold text-amber-300">
          <UnknownValue v-if="dist.projectedMarginPercent === null" />
          <template v-else>{{ dist.projectedMarginPercent.toFixed(1) }}%</template>
        </span>
      </p>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Ring Chart -->
      <div class="bg-slate-900/80 rounded-xl border border-slate-800 p-6 flex flex-col items-center justify-center">
        <div class="relative w-48 h-48 mb-4">
          <svg viewBox="0 0 200 200" class="w-48 h-48" style="transform: rotate(-90deg)">
            <!-- Background ring -->
            <circle cx="100" cy="100" :r="RING_RADIUS" fill="none" stroke="rgb(30 41 59)" stroke-width="24" />
            <!-- Segment per member -->
            <circle
              v-for="(seg, i) in ringSegments"
              :key="i"
              cx="100"
              cy="100"
              :r="RING_RADIUS"
              fill="none"
              :stroke="seg.color"
              stroke-width="24"
              stroke-linecap="butt"
              :stroke-dasharray="`${seg.dash} ${seg.gap}`"
              :stroke-dashoffset="seg.offset"
            />
          </svg>
          <div class="absolute inset-0 flex flex-col items-center justify-center">
            <p class="text-xs text-slate-500 uppercase tracking-wider">Total Payout</p>
            <p data-testid="total-payout" class="text-xl font-mono font-bold text-slate-100" style="font-variant-numeric: tabular-nums;">
              <AwaitingSalesValue v-if="isAwaitingSales" />
              <IskAmount v-else :value="totalPayout" />
            </p>
          </div>
        </div>
        <!-- Legend -->
        <div class="space-y-2 w-full">
          <div
            v-for="(member, i) in dist.members"
            :key="member.memberId"
            class="flex items-center justify-between text-sm"
          >
            <div class="flex items-center gap-2">
              <span class="w-3 h-3 rounded-sm" :style="{ background: getMemberColor(i) }"></span>
              <span class="text-slate-300">{{ pilotName(member.characterName) }}</span>
            </div>
            <span class="font-mono text-slate-400">
              <UnknownValue v-if="member.sharePercent === null" />
              <template v-else>{{ member.sharePercent.toFixed(1) }}%</template>
            </span>
          </div>
        </div>
      </div>

      <!-- Distribution Table -->
      <div class="lg:col-span-2 bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden">
        <DataTable>
          <table class="w-full text-sm">
            <thead>
              <tr class="text-xs text-slate-500 uppercase tracking-wider border-b border-slate-800">
                <th class="text-left py-3 px-6">Character</th>
                <th class="text-right py-3 px-3">Costs Engaged</th>
                <th class="text-right py-3 px-3">Share %</th>
                <th class="text-right py-3 px-3">Profit Part</th>
                <th class="text-right py-3 px-6">Payout Total</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/30">
              <template v-for="member in dist.members" :key="member.memberId">
                <!-- Main row -->
                <tr
                  class="hover:bg-slate-800/30 cursor-pointer"
                  @click="toggleExpand(member.memberId)"
                >
                  <td class="py-3.5 px-6">
                    <div class="flex items-center gap-2.5">
                      <img
                        :src="getCharacterPortraitUrl(0, 32)"
                        :alt="pilotName(member.characterName)"
                        class="w-8 h-8 rounded-full border border-slate-700"
                        @error="onImageError"
                      />
                      <div>
                        <span class="text-slate-200 font-medium">{{ pilotName(member.characterName) }}</span>
                        <div class="flex items-center gap-1 mt-0.5">
                          <svg
                            class="w-3 h-3 text-cyan-400 transition-transform"
                            :class="{ 'rotate-180': isExpanded(member.memberId) }"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                          >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                          </svg>
                          <span class="text-xs text-cyan-400">
                            {{ isExpanded(member.memberId) ? 'Hide breakdown' : 'Show breakdown' }}
                          </span>
                        </div>
                      </div>
                    </div>
                  </td>
                  <td class="py-3.5 px-3 text-right font-mono text-slate-300" style="font-variant-numeric: tabular-nums;">
                    <UnknownContributionValue v-if="member.totalCostsEngaged === null" />
                    <template v-else>{{ formatIsk(member.totalCostsEngaged) }}</template>
                  </td>
                  <td class="py-3.5 px-3 text-right font-mono text-slate-400" style="font-variant-numeric: tabular-nums;">
                    <UnknownValue v-if="member.sharePercent === null" />
                    <template v-else>{{ member.sharePercent.toFixed(1) }}%</template>
                  </td>
                  <td
                    class="py-3.5 px-3 text-right font-mono"
                    :class="profitColorClass(member.profitPart)"
                    style="font-variant-numeric: tabular-nums;"
                  >
                    <AwaitingSalesValue v-if="isAwaitingSales" />
                    <UnknownValue v-else-if="member.profitPart === null" />
                    <template v-else>{{ formatProfit(member.profitPart) }}</template>
                  </td>
                  <td class="py-3.5 px-6 text-right">
                    <span
                      class="font-mono font-bold text-lg"
                      :class="profitColorClass(member.payoutTotal)"
                      style="font-variant-numeric: tabular-nums;"
                    ><AwaitingSalesValue v-if="isAwaitingSales" /><IskAmount v-else :value="member.payoutTotal" /></span>
                  </td>
                </tr>
                <!-- Breakdown row (expanded) -->
                <tr v-if="isExpanded(member.memberId)" class="bg-slate-800/20">
                  <td colspan="5" class="px-6 py-3">
                    <div class="ml-10 space-y-1.5 text-xs">
                      <div v-if="member.materialCosts === null || member.materialCosts > 0" class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                          <span class="w-2 h-2 rounded-full" :class="BREAKDOWN_COLORS.material"></span>
                          <span class="text-slate-400">Materials</span>
                        </div>
                        <span class="font-mono text-slate-300" style="font-variant-numeric: tabular-nums;">
                          <UnknownContributionValue v-if="member.materialCosts === null" />
                          <template v-else>{{ formatIsk(member.materialCosts) }}</template>
                        </span>
                      </div>
                      <div v-if="member.jobInstallCosts === null || member.jobInstallCosts > 0" class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                          <span class="w-2 h-2 rounded-full" :class="BREAKDOWN_COLORS.job_install"></span>
                          <span class="text-slate-400">Job Install</span>
                        </div>
                        <span class="font-mono text-slate-300" style="font-variant-numeric: tabular-nums;">
                          <UnknownContributionValue v-if="member.jobInstallCosts === null" />
                          <template v-else>{{ formatIsk(member.jobInstallCosts) }}</template>
                        </span>
                      </div>
                      <div v-if="member.bpcCosts === null || member.bpcCosts > 0" class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                          <span class="w-2 h-2 rounded-full" :class="BREAKDOWN_COLORS.bpc"></span>
                          <span class="text-slate-400">BPC</span>
                        </div>
                        <span class="font-mono text-slate-300" style="font-variant-numeric: tabular-nums;">
                          <UnknownContributionValue v-if="member.bpcCosts === null" />
                          <template v-else>{{ formatIsk(member.bpcCosts) }}</template>
                        </span>
                      </div>
                      <div v-if="member.lineRentalCosts === null || member.lineRentalCosts > 0" class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                          <span class="w-2 h-2 rounded-full" :class="BREAKDOWN_COLORS.line_rental"></span>
                          <span class="text-slate-400">Line Rental</span>
                        </div>
                        <span class="font-mono text-slate-300" style="font-variant-numeric: tabular-nums;">
                          <UnknownContributionValue v-if="member.lineRentalCosts === null" />
                          <template v-else>{{ formatIsk(member.lineRentalCosts) }}</template>
                        </span>
                      </div>
                      <div v-if="member.courierCosts === null || member.courierCosts > 0" class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                          <span class="w-2 h-2 rounded-full" :class="BREAKDOWN_COLORS.courier"></span>
                          <span class="text-slate-400">{{ t('groupIndustry.distribution.courierCosts') }}</span>
                        </div>
                        <span class="font-mono text-slate-300" style="font-variant-numeric: tabular-nums;">
                          <UnknownContributionValue v-if="member.courierCosts === null" />
                          <template v-else>{{ formatIsk(member.courierCosts) }}</template>
                        </span>
                      </div>
                    </div>
                  </td>
                </tr>
              </template>
              <!-- Total row -->
              <tr class="bg-slate-800/30 border-t-2 border-slate-700">
                <td class="py-3.5 px-6 text-slate-200 font-semibold">{{ t('common.status.total') }}</td>
                <td class="py-3.5 px-3 text-right font-mono font-semibold text-slate-200" style="font-variant-numeric: tabular-nums;">
                  <UnknownContributionValue v-if="totalCosts === null" />
                  <template v-else>{{ formatIsk(totalCosts) }}</template>
                </td>
                <td class="py-3.5 px-3 text-right font-mono font-semibold text-slate-200" style="font-variant-numeric: tabular-nums;">
                  <UnknownValue v-if="isUndistributable" />
                  <template v-else>100%</template>
                </td>
                <td
                  class="py-3.5 px-3 text-right font-mono font-bold"
                  :class="profitColorClass(totalProfit)"
                  style="font-variant-numeric: tabular-nums;"
                >
                  <AwaitingSalesValue v-if="isAwaitingSales" />
                  <UnknownValue v-else-if="totalProfit === null" />
                  <template v-else>{{ formatProfit(totalProfit) }}</template>
                </td>
                <td
                  class="py-3.5 px-6 text-right font-mono font-bold text-lg"
                  :class="profitColorClass(totalPayout)"
                  style="font-variant-numeric: tabular-nums;"
                >
                  <AwaitingSalesValue v-if="isAwaitingSales" />
                  <IskAmount v-else :value="totalPayout" />
                </td>
              </tr>
            </tbody>
          </table>
        </DataTable>
      </div>
    </div>

    <!-- Formula Footer -->
    <div class="bg-slate-900/80 rounded-xl border border-slate-800 p-5 mt-6">
      <div class="flex items-start gap-4">
        <div class="w-8 h-8 rounded-lg bg-cyan-500/10 flex items-center justify-center flex-shrink-0 mt-0.5">
          <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div>
          <h4 class="text-sm font-semibold text-slate-200 mb-2">Distribution Formula</h4>
          <div class="font-mono text-xs text-slate-400 space-y-1">
            <p>Payout = Costs Engaged x (1 + Margin%)</p>
            <p class="text-slate-500">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; = Costs Engaged x (Net Revenue / Total Project Cost)</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Loss scenario warning -->
    <div
      v-if="showLossScenario"
      data-testid="loss-scenario"
      class="bg-red-500/5 rounded-xl border border-red-500/20 p-5 mt-4"
    >
      <div class="flex items-start gap-4">
        <div class="w-8 h-8 rounded-lg bg-red-500/10 flex items-center justify-center flex-shrink-0 mt-0.5">
          <span class="text-xs font-bold text-red-400">LOSS</span>
        </div>
        <div>
          <h4 class="text-sm font-semibold text-red-300 mb-1">Loss Scenario</h4>
          <p class="text-xs text-slate-400">
            This project resulted in a loss (negative margin). Each contributor absorbs their proportional share.
            The formula remains the same, but <span class="font-mono text-red-400">(1 + margin%)</span> becomes less than 1,
            meaning each contributor receives less than their initial costs engaged.
          </p>
        </div>
      </div>
    </div>
  </div>
</template>
