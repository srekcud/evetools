<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{ step: 2 | 3; busy?: boolean; stationsOnly?: boolean }>()
defineEmits<{ next: []; validate: []; goto: [step: 2] }>()

const { t } = useI18n()

const STEP_BASE = 'flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm'
const STEP_ON = 'border-cyan-500/60 bg-cyan-500/10 text-cyan-300'
const STEP_DONE = 'border-slate-700 bg-slate-900/60 text-slate-300'
const STEP_TODO = 'border-slate-800 bg-slate-900/40 text-slate-500'
</script>

<template>
  <nav
    data-testid="setup-step-bar"
    class="sticky top-0 z-10 mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-slate-700 bg-slate-950/90 p-2 backdrop-blur"
  >
    <span :class="[STEP_BASE, STEP_DONE]">
      <b class="text-cyan-400">1</b>
      {{ t('groupIndustry.setup.stepProducts') }}
    </span>
    <button
      v-if="step === 3"
      type="button"
      data-testid="setup-goto-stations"
      :class="[STEP_BASE, STEP_DONE, 'hover:border-cyan-500/40']"
      @click="$emit('goto', 2)"
    >
      <b class="text-cyan-400">2</b>
      {{ t('groupIndustry.setup.stepStations') }}
    </button>
    <span v-else :class="[STEP_BASE, STEP_ON]">
      <b>2</b>
      {{ t('groupIndustry.setup.stepStations') }}
    </span>
    <span v-if="!stationsOnly" :class="[STEP_BASE, step === 3 ? STEP_ON : STEP_TODO]">
      <b>3</b>
      {{ t('groupIndustry.setup.stepSourcing') }}
    </span>
    <span class="flex-1" />
    <button
      v-if="step === 2 && !stationsOnly"
      type="button"
      data-testid="setup-next"
      class="rounded-lg bg-cyan-600 px-4 py-2 text-sm font-medium text-white transition-all hover:bg-cyan-500 disabled:opacity-50"
      :disabled="busy"
      @click="$emit('next')"
    >
      {{ t('groupIndustry.setup.next') }}
    </button>
    <button
      v-else
      type="button"
      data-testid="setup-validate"
      class="rounded-lg bg-cyan-600 px-4 py-2 text-sm font-medium text-white transition-all hover:bg-cyan-500 disabled:opacity-50"
      :disabled="busy"
      @click="$emit('validate')"
    >
      {{ busy ? t('groupIndustry.setup.saving') : stationsOnly ? t('groupIndustry.setup.saveStations') : t('groupIndustry.setup.validate') }}
    </button>
  </nav>
</template>
