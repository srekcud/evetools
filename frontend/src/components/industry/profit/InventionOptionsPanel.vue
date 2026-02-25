<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { ProfitMarginInventionOption } from '@/stores/industry/types'

const props = defineProps<{
  baseProbability: number
  datacores: string[]
  options: ProfitMarginInventionOption[]
  bestDecryptorIndex: number
  selectedOption: ProfitMarginInventionOption | null
  selectedDecryptorTypeId: number | null | undefined
  effectiveInventionCost: number
  effectiveTotalCost: number
  bestMargin: number
}>()

const emit = defineEmits<{
  'select-decryptor': [option: ProfitMarginInventionOption]
}>()

const { t } = useI18n()
const { formatIsk } = useFormatters()

function isInventionBest(index: number): boolean {
  return index === props.bestDecryptorIndex
}

function isInventionSelected(option: ProfitMarginInventionOption): boolean {
  if (props.selectedOption == null && props.selectedDecryptorTypeId === undefined) {
    // Nothing explicitly selected yet: highlight the "best" one
    return isInventionBest(props.options.indexOf(option))
  }
  if (props.selectedOption != null) {
    return option.decryptorTypeId === props.selectedOption.decryptorTypeId
  }
  return option.decryptorTypeId === props.selectedDecryptorTypeId
}

function meTeColor(value: number): string {
  if (value < 0) return 'text-emerald-400'
  if (value > 0) return 'text-red-400'
  return 'text-slate-400'
}

function formatMeTe(value: number): string {
  if (value > 0) return `+${value}`
  if (value === 0) return '+0'
  return String(value)
}

function selectedDecryptorName(): string {
  if (props.selectedOption != null) return props.selectedOption.decryptorName
  if (props.bestDecryptorIndex >= 0 && props.bestDecryptorIndex < props.options.length) {
    return props.options[props.bestDecryptorIndex].decryptorName
  }
  return t('industry.bpcKitTab.none')
}
</script>

<template>
  <div class="eve-card overflow-hidden">
    <div class="px-4 py-3 border-b border-slate-800">
      <div class="flex items-center justify-between flex-wrap gap-2">
        <div class="flex items-center gap-2">
          <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
          </svg>
          <h4 class="text-sm font-semibold text-slate-200">{{ t('industry.margins.inventionTitle') }}</h4>
        </div>
        <div class="flex items-center gap-4 text-xs text-slate-500">
          <span>{{ t('industry.bpcKitTab.baseProbability') }}: <span class="text-cyan-400 font-mono">{{ (props.baseProbability * 100).toFixed(1) }}%</span></span>
          <span class="text-slate-700">|</span>
          <span>{{ t('industry.bpcKitTab.datacoresLabel') }}: <span class="text-slate-400">{{ props.datacores.join(' + ') }}</span></span>
        </div>
      </div>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase tracking-wider">
            <th class="text-center py-2.5 px-2 w-8"></th>
            <th class="text-left py-2.5 px-3">{{ t('industry.bpcKitTab.decryptor') }}</th>
            <th class="text-center py-2.5 px-2">{{ t('industry.bpcKitTab.me') }}</th>
            <th class="text-center py-2.5 px-2">{{ t('industry.bpcKitTab.te') }}</th>
            <th class="text-center py-2.5 px-2">{{ t('industry.bpcKitTab.runsBpc') }}</th>
            <th class="text-right py-2.5 px-3">{{ t('industry.margins.probability') }}</th>
            <th class="text-right py-2.5 px-3">{{ t('industry.margins.inventionCostCol') }}</th>
            <th class="text-right py-2.5 px-3">{{ t('industry.margins.totalProdCost') }}</th>
            <th class="text-right py-2.5 px-3">{{ t('industry.margins.bestMarginCol') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800">
          <tr
            v-for="(option, index) in props.options"
            :key="option.decryptorTypeId ?? 'none'"
            :class="[
              'relative group cursor-pointer transition-colors',
              isInventionBest(index) ? 'bg-emerald-500/5 hover:bg-emerald-500/10 border-l-2 border-l-emerald-500' : '',
              !isInventionBest(index) && isInventionSelected(option) ? 'bg-cyan-500/10 hover:bg-cyan-500/15' : '',
              !isInventionBest(index) && !isInventionSelected(option) ? 'hover:bg-slate-800/50' : '',
            ]"
            @click="emit('select-decryptor', option)"
          >
            <!-- Radio -->
            <td class="py-2.5 px-2 text-center">
              <div
                v-if="isInventionSelected(option)"
                class="w-3.5 h-3.5 rounded-full border-2 border-cyan-500 mx-auto flex items-center justify-center"
                style="box-shadow: 0 0 0 2px rgba(6, 182, 212, 0.3)"
              >
                <div class="w-1.5 h-1.5 rounded-full bg-cyan-400"></div>
              </div>
              <div
                v-else
                class="w-3.5 h-3.5 rounded-full border border-slate-600 mx-auto"
              ></div>
            </td>

            <!-- Decryptor name -->
            <td class="py-2.5 px-3">
              <div v-if="option.decryptorTypeId == null" class="text-slate-400 italic">
                {{ t('industry.bpcKitTab.none') }}
              </div>
              <div v-else class="flex items-center gap-2">
                <span :class="isInventionBest(index) ? 'text-slate-100 font-semibold' : 'text-slate-200'">
                  {{ option.decryptorName }}
                </span>
                <span
                  v-if="isInventionBest(index)"
                  class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 bg-emerald-500/20 text-emerald-400 rounded-sm font-semibold"
                >{{ t('industry.margins.best') }}</span>
              </div>
            </td>

            <!-- ME -->
            <td class="py-2.5 px-2 text-center font-mono" :class="[meTeColor(-option.me), isInventionBest(index) ? 'font-semibold' : '']">
              {{ formatMeTe(-option.me) }}
            </td>

            <!-- TE -->
            <td class="py-2.5 px-2 text-center font-mono" :class="[meTeColor(-option.te), isInventionBest(index) ? 'font-semibold' : '']">
              {{ formatMeTe(-option.te) }}
            </td>

            <!-- Runs/BPC -->
            <td class="py-2.5 px-2 text-center font-mono" :class="isInventionBest(index) ? 'text-slate-100 font-semibold' : 'text-slate-300'">
              {{ option.runs }}
            </td>

            <!-- Probability -->
            <td class="py-2.5 px-3 text-right font-mono" :class="isInventionBest(index) ? 'text-slate-100 font-semibold' : 'text-slate-300'">
              {{ (option.probability * 100).toFixed(1) }}%
            </td>

            <!-- Invention Cost -->
            <td class="py-2.5 px-3 text-right font-mono" :class="isInventionBest(index) ? 'text-slate-100 font-semibold' : 'text-slate-300'">
              {{ formatIsk(option.inventionCost) }}
            </td>

            <!-- Total Production Cost -->
            <td class="py-2.5 px-3 text-right font-mono" :class="[
              isInventionBest(index) ? 'text-cyan-400 font-bold' : '',
              isInventionSelected(option) && !isInventionBest(index) ? 'text-cyan-400 font-semibold' : '',
              !isInventionSelected(option) && !isInventionBest(index) ? 'text-slate-300' : '',
            ]">
              {{ formatIsk(option.totalProductionCost) }}
            </td>

            <!-- Best Margin -->
            <td class="py-2.5 px-3 text-right font-mono" :class="[
              option.bestMargin >= 0 ? 'text-emerald-400' : 'text-red-400',
              isInventionBest(index) ? 'font-bold' : '',
            ]">
              {{ option.bestMargin >= 0 ? '+' : '' }}{{ option.bestMargin.toFixed(1) }}%
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Footer -->
    <div class="px-4 py-3 border-t border-slate-800">
      <div class="text-xs text-slate-500">
        {{ t('industry.margins.inventionFooterNote') }}
      </div>
    </div>

    <!-- Selected decryptor summary (below table) -->
    <div class="px-4 py-3 border-t border-slate-700 bg-slate-800/40">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div class="flex items-center gap-3">
          <svg class="w-4 h-4 text-cyan-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <div>
            <span class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.margins.selectedDecryptor') }}</span>
            <p class="text-sm text-slate-200 font-medium">
              {{ selectedDecryptorName() }}
            </p>
          </div>
        </div>
        <div class="flex items-center gap-6">
          <div class="text-right">
            <span class="text-xs text-slate-500 uppercase tracking-wider block">{{ t('industry.margins.inventionCostCol') }}</span>
            <span class="font-mono text-slate-200 font-semibold">{{ formatIsk(props.effectiveInventionCost) }}</span>
          </div>
          <div class="text-right">
            <span class="text-xs text-slate-500 uppercase tracking-wider block">{{ t('industry.margins.totalProdCost') }}</span>
            <span class="font-mono text-cyan-400 font-bold">{{ formatIsk(props.effectiveTotalCost) }}</span>
          </div>
          <div class="text-right">
            <span class="text-xs text-slate-500 uppercase tracking-wider block">{{ t('industry.margins.bestMarginCol') }}</span>
            <span
              class="font-mono font-bold"
              :class="props.bestMargin >= 0 ? 'text-emerald-400' : 'text-red-400'"
            >{{ props.bestMargin >= 0 ? '+' : '' }}{{ props.bestMargin.toFixed(1) }}%</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
