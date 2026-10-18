<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { ShoppingListTotals } from '@/stores/industry/types'

type MissingTotals = {
  price: number
  volume: number | null
  jita: number | null
  jitaWeighted: number | null
  structure: number
  savings: number
}

const props = defineProps<{
  shoppingTotals: ShoppingListTotals
  missingTotals: MissingTotals
  hasAnyStock: boolean
  jitaMultibuyFormat: string
  structureMultibuyFormat: string
  hasJitaItems: boolean
  hasStructureItems: boolean
}>()

const { t } = useI18n()
const { formatIsk } = useFormatters()

const displayedJitaWithImport = computed<number | null>(() =>
  props.hasAnyStock
    ? props.missingTotals.jitaWeighted ?? props.missingTotals.jita
    : props.shoppingTotals.jitaWithImport,
)

const copiedJita = ref(false)
const copiedStructure = ref(false)

function copyToClipboard(text: string, type: 'jita' | 'structure') {
  if (!text) return
  navigator.clipboard.writeText(text)
  if (type === 'jita') {
    copiedJita.value = true
    setTimeout(() => copiedJita.value = false, 2000)
  } else {
    copiedStructure.value = true
    setTimeout(() => copiedStructure.value = false, 2000)
  }
}
</script>

<template>
  <div class="eve-card p-4">
    <div class="grid grid-cols-5 gap-6 text-center">
      <div>
        <div class="text-xs text-slate-500 uppercase mb-1">{{ t('industry.shoppingTab.volumeToBuy') }}</div>
        <div class="font-mono text-slate-200">{{ missingTotals.volume !== null ? `${missingTotals.volume.toLocaleString()} m3` : t('industry.shoppingTab.unknown') }}</div>
      </div>
      <div>
        <div class="text-xs text-slate-500 uppercase mb-1">{{ t('industry.shoppingTab.jitaImport') }}</div>
        <div class="font-mono text-slate-200">{{ displayedJitaWithImport !== null ? formatIsk(displayedJitaWithImport) : t('industry.shoppingTab.unknown') }}</div>
        <div v-if="hasAnyStock && missingTotals.jitaWeighted !== null" class="text-[10px] text-slate-600 font-mono mt-0.5">
          {{ formatIsk(missingTotals.jita) }}
        </div>
      </div>
      <div>
        <div class="text-xs text-slate-500 uppercase mb-1">{{ t('industry.shoppingTab.structure') }}</div>
        <div class="font-mono text-slate-200">{{ formatIsk(hasAnyStock ? missingTotals.structure : shoppingTotals.structure) }}</div>
      </div>
      <div>
        <div class="text-xs text-slate-500 uppercase mb-1">{{ t('industry.shoppingTab.bestTotal') }}</div>
        <div class="font-mono text-emerald-400 text-lg font-bold">{{ formatIsk(hasAnyStock ? missingTotals.price : shoppingTotals.best) }}</div>
      </div>
      <div class="flex items-center justify-center gap-2">
        <button
          v-if="hasJitaItems"
          @click="copyToClipboard(jitaMultibuyFormat, 'jita')"
          class="px-3 py-2 bg-amber-500/20 border border-amber-500/50 text-amber-400 rounded-lg text-xs hover:bg-amber-500/30 transition-colors"
        >
          {{ copiedJita ? '\u2713 Copie' : `Jita Multibuy` }}
        </button>
        <button
          v-if="hasStructureItems"
          @click="copyToClipboard(structureMultibuyFormat, 'structure')"
          class="px-3 py-2 bg-cyan-500/20 border border-cyan-500/50 text-cyan-400 rounded-lg text-xs hover:bg-cyan-500/30 transition-colors"
        >
          {{ copiedStructure ? '\u2713 Copie' : `Structure Multibuy` }}
        </button>
      </div>
    </div>
  </div>
</template>
