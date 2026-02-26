<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'

const { t } = useI18n()

const store = useGroupDistributionStore()
const { formatIsk } = useFormatters()
const { getCharacterPortraitUrl, onImageError } = useEveImages()

const expandedMemberId = ref<string | null>(null)

const dist = computed(() => store.distribution)

const RING_RADIUS = 80
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS

// Predefined colors for the ring chart segments
const MEMBER_COLORS = ['#22d3ee', '#a78bfa', '#fbbf24', '#f472b6', '#34d399', '#fb923c']

function getMemberColor(index: number): string {
  return MEMBER_COLORS[index % MEMBER_COLORS.length]
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
  return dist.value.members.map((member, i) => {
    const dash = (member.sharePercent / 100) * RING_CIRCUMFERENCE
    const gap = RING_CIRCUMFERENCE - dash
    const segment = { dash, gap, offset: -offset, color: getMemberColor(i) }
    offset += dash
    return segment
  })
})

// Totals
const totalCosts = computed(() => dist.value?.totalProjectCost ?? 0)
const totalProfit = computed(() => {
  if (!dist.value) return 0
  return dist.value.members.reduce((s, m) => s + m.profitPart, 0)
})
const totalPayout = computed(() => {
  if (!dist.value) return 0
  return dist.value.members.reduce((s, m) => s + m.payoutTotal, 0)
})

function profitColorClass(value: number): string {
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
}
</script>

<template>
  <div v-if="dist">
    <h2 class="text-lg font-semibold text-slate-100 mb-4">Profit Distribution</h2>
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
            <p class="text-xl font-mono font-bold text-slate-100" style="font-variant-numeric: tabular-nums;">
              {{ formatIsk(totalPayout) }}
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
              <span class="text-slate-300">{{ member.characterName }}</span>
            </div>
            <span class="font-mono text-slate-400">{{ member.sharePercent.toFixed(1) }}%</span>
          </div>
        </div>
      </div>

      <!-- Distribution Table -->
      <div class="lg:col-span-2 bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden">
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
                      :alt="member.characterName"
                      class="w-8 h-8 rounded-full border border-slate-700"
                      @error="onImageError"
                    />
                    <div>
                      <span class="text-slate-200 font-medium">{{ member.characterName }}</span>
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
                  {{ formatIsk(member.totalCostsEngaged) }}
                </td>
                <td class="py-3.5 px-3 text-right font-mono text-slate-400" style="font-variant-numeric: tabular-nums;">
                  {{ member.sharePercent.toFixed(1) }}%
                </td>
                <td
                  class="py-3.5 px-3 text-right font-mono"
                  :class="profitColorClass(member.profitPart)"
                  style="font-variant-numeric: tabular-nums;"
                >
                  {{ formatProfit(member.profitPart) }}
                </td>
                <td class="py-3.5 px-6 text-right">
                  <span
                    class="font-mono font-bold text-lg"
                    :class="profitColorClass(member.payoutTotal)"
                    style="font-variant-numeric: tabular-nums;"
                  >{{ formatIsk(member.payoutTotal) }}</span>
                </td>
              </tr>
              <!-- Breakdown row (expanded) -->
              <tr v-if="isExpanded(member.memberId)" class="bg-slate-800/20">
                <td colspan="5" class="px-6 py-3">
                  <div class="ml-10 space-y-1.5 text-xs">
                    <div v-if="member.materialCosts > 0" class="flex items-center justify-between">
                      <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full" :class="BREAKDOWN_COLORS.material"></span>
                        <span class="text-slate-400">Materials</span>
                      </div>
                      <span class="font-mono text-slate-300" style="font-variant-numeric: tabular-nums;">
                        {{ formatIsk(member.materialCosts) }}
                      </span>
                    </div>
                    <div v-if="member.jobInstallCosts > 0" class="flex items-center justify-between">
                      <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full" :class="BREAKDOWN_COLORS.job_install"></span>
                        <span class="text-slate-400">Job Install</span>
                      </div>
                      <span class="font-mono text-slate-300" style="font-variant-numeric: tabular-nums;">
                        {{ formatIsk(member.jobInstallCosts) }}
                      </span>
                    </div>
                    <div v-if="member.bpcCosts > 0" class="flex items-center justify-between">
                      <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full" :class="BREAKDOWN_COLORS.bpc"></span>
                        <span class="text-slate-400">BPC</span>
                      </div>
                      <span class="font-mono text-slate-300" style="font-variant-numeric: tabular-nums;">
                        {{ formatIsk(member.bpcCosts) }}
                      </span>
                    </div>
                    <div v-if="member.lineRentalCosts > 0" class="flex items-center justify-between">
                      <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full" :class="BREAKDOWN_COLORS.line_rental"></span>
                        <span class="text-slate-400">Line Rental</span>
                      </div>
                      <span class="font-mono text-slate-300" style="font-variant-numeric: tabular-nums;">
                        {{ formatIsk(member.lineRentalCosts) }}
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
                {{ formatIsk(totalCosts) }}
              </td>
              <td class="py-3.5 px-3 text-right font-mono font-semibold text-slate-200" style="font-variant-numeric: tabular-nums;">
                100%
              </td>
              <td
                class="py-3.5 px-3 text-right font-mono font-bold"
                :class="profitColorClass(totalProfit)"
                style="font-variant-numeric: tabular-nums;"
              >
                {{ formatProfit(totalProfit) }}
              </td>
              <td
                class="py-3.5 px-6 text-right font-mono font-bold text-lg"
                :class="profitColorClass(totalPayout)"
                style="font-variant-numeric: tabular-nums;"
              >
                {{ formatIsk(totalPayout) }}
              </td>
            </tr>
          </tbody>
        </table>
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
      v-if="dist.marginPercent < 0"
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
