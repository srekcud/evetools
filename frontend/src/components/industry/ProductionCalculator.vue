<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { calculateMaxRuns, calculateDurationForRuns, formatDuration } from '@/composables/useProductionCalculator'

const { t } = useI18n()

const mode = ref<'maxRuns' | 'duration'>('maxRuns')
const durationDays = ref<number | null>(7)
const durationHours = ref<number | null>(0)
const productionLines = ref<number | null>(1)
const timePerRunSeconds = ref<number | null>(null)
const timePerRunHours = ref<number | null>(null)
const timePerRunMinutes = ref<number | null>(null)
const desiredRuns = ref<number | null>(null)
const outputPerRun = ref<number | null>(1)

const computedTimePerRun = computed(() => {
  if (timePerRunSeconds.value != null && timePerRunSeconds.value > 0) return timePerRunSeconds.value
  const h = timePerRunHours.value ?? 0
  const m = timePerRunMinutes.value ?? 0
  return h * 3600 + m * 60
})

const totalDurationSeconds = computed(() => {
  return ((durationDays.value ?? 0) * 86400) + ((durationHours.value ?? 0) * 3600)
})

const maxRuns = computed(() => {
  if (computedTimePerRun.value <= 0 || (productionLines.value ?? 0) <= 0) return 0
  return calculateMaxRuns(totalDurationSeconds.value, computedTimePerRun.value, productionLines.value ?? 1)
})

const totalOutput = computed(() => maxRuns.value * (outputPerRun.value ?? 1))

const requiredDuration = computed(() => {
  if ((desiredRuns.value ?? 0) <= 0 || computedTimePerRun.value <= 0) return 0
  return calculateDurationForRuns(desiredRuns.value ?? 0, computedTimePerRun.value, productionLines.value ?? 1)
})

const desiredTotalOutput = computed(() => (desiredRuns.value ?? 0) * (outputPerRun.value ?? 1))
</script>

<template>
  <div class="bg-slate-900/50 border border-slate-800 rounded-xl p-5 space-y-4">
    <div class="flex items-center gap-2 mb-1">
      <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <h3 class="text-sm font-bold text-slate-100">{{ t('industry.calculator.title') }}</h3>
    </div>

    <!-- Mode toggle -->
    <div class="flex gap-2">
      <button
        @click="mode = 'maxRuns'"
        :class="['px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors', mode === 'maxRuns' ? 'bg-cyan-600/20 text-cyan-400 border border-cyan-500/30' : 'bg-slate-800 text-slate-400 border border-slate-700 hover:border-slate-600']"
      >{{ t('industry.calculator.modeMaxRuns') }}</button>
      <button
        @click="mode = 'duration'"
        :class="['px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors', mode === 'duration' ? 'bg-cyan-600/20 text-cyan-400 border border-cyan-500/30' : 'bg-slate-800 text-slate-400 border border-slate-700 hover:border-slate-600']"
      >{{ t('industry.calculator.modeDuration') }}</button>
    </div>

    <!-- Common inputs -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
      <div>
        <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.calculator.timePerRun') }}</label>
        <div class="flex gap-1">
          <input v-model.number="timePerRunHours" type="number" min="0" placeholder="h" class="w-full bg-slate-800 border border-slate-700 rounded px-2 py-1.5 text-sm font-mono text-slate-200 focus:outline-none focus:border-cyan-500" />
          <input v-model.number="timePerRunMinutes" type="number" min="0" max="59" placeholder="m" class="w-full bg-slate-800 border border-slate-700 rounded px-2 py-1.5 text-sm font-mono text-slate-200 focus:outline-none focus:border-cyan-500" />
        </div>
      </div>
      <div>
        <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.calculator.lines') }}</label>
        <input v-model.number="productionLines" type="number" min="1" max="30" class="w-full bg-slate-800 border border-slate-700 rounded px-2.5 py-1.5 text-sm font-mono text-slate-200 focus:outline-none focus:border-cyan-500" />
      </div>
      <div>
        <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.calculator.outputPerRun') }}</label>
        <input v-model.number="outputPerRun" type="number" min="1" class="w-full bg-slate-800 border border-slate-700 rounded px-2.5 py-1.5 text-sm font-mono text-slate-200 focus:outline-none focus:border-cyan-500" />
      </div>
    </div>

    <!-- Mode: Max Runs -->
    <div v-if="mode === 'maxRuns'" class="space-y-3">
      <div class="flex gap-3">
        <div>
          <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.calculator.days') }}</label>
          <input v-model.number="durationDays" type="number" min="0" class="w-24 bg-slate-800 border border-slate-700 rounded px-2.5 py-1.5 text-sm font-mono text-slate-200 focus:outline-none focus:border-cyan-500" />
        </div>
        <div>
          <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.calculator.hours') }}</label>
          <input v-model.number="durationHours" type="number" min="0" max="23" class="w-24 bg-slate-800 border border-slate-700 rounded px-2.5 py-1.5 text-sm font-mono text-slate-200 focus:outline-none focus:border-cyan-500" />
        </div>
      </div>

      <div v-if="computedTimePerRun > 0" class="flex gap-6 pt-2 border-t border-slate-800">
        <div>
          <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.calculator.maxRuns') }}</p>
          <p class="text-xl font-mono font-bold text-cyan-400">{{ maxRuns }}</p>
        </div>
        <div>
          <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.calculator.totalOutput') }}</p>
          <p class="text-xl font-mono font-bold text-emerald-400">{{ totalOutput.toLocaleString() }}</p>
        </div>
      </div>
    </div>

    <!-- Mode: Duration for runs -->
    <div v-if="mode === 'duration'" class="space-y-3">
      <div>
        <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.calculator.desiredRuns') }}</label>
        <input v-model.number="desiredRuns" type="number" min="1" class="w-32 bg-slate-800 border border-slate-700 rounded px-2.5 py-1.5 text-sm font-mono text-slate-200 focus:outline-none focus:border-cyan-500" />
      </div>

      <div v-if="computedTimePerRun > 0 && (desiredRuns ?? 0) > 0" class="flex gap-6 pt-2 border-t border-slate-800">
        <div>
          <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.calculator.requiredTime') }}</p>
          <p class="text-xl font-mono font-bold text-cyan-400">{{ formatDuration(requiredDuration) }}</p>
        </div>
        <div>
          <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.calculator.totalOutput') }}</p>
          <p class="text-xl font-mono font-bold text-emerald-400">{{ desiredTotalOutput.toLocaleString() }}</p>
        </div>
      </div>
    </div>
  </div>
</template>
