<script setup lang="ts">
import { computed, ref } from 'vue'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'

export interface ShoppingItem {
  typeId: number
  typeName: string
  quantity: number
  volume?: number
  totalVolume: number
  // Support both naming conventions
  jitaPrice?: number | null
  jitaUnitPrice?: number | null
  jitaTotal: number | null
  importCost: number
  jitaWithImport: number | null
  structurePrice?: number | null
  structureUnitPrice?: number | null
  structureTotal: number | null
  bestLocation: 'jita' | 'structure' | null
  bestTotal?: number | null
  bestPrice?: number | null
  savings?: number | null
}

export interface ShoppingTotals {
  jita?: number
  import?: number
  jitaWithImport: number
  structure: number
  best: number
  volume: number
  savingsVsJitaWithImport?: number
  savingsVsStructure?: number
}

const props = defineProps<{
  items: ShoppingItem[]
  totals: ShoppingTotals
  structureName: string
  notFound?: string[]
  priceError?: string | null
  structureAccessible?: boolean
  structureFromCache?: boolean
  structureLastSync?: string | null
  isSyncing?: boolean
  isSharing?: boolean
  readonly?: boolean
  shareUrl?: string | null
}>()

const emit = defineEmits<{
  syncStructure: []
  share: []
}>()

const { formatIsk, formatNumber, formatDateTime } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

const copiedJita = ref(false)
const copiedStructure = ref(false)
const copiedShareUrl = ref(false)

function copyShareUrl() {
  if (!props.shareUrl) return
  navigator.clipboard.writeText(props.shareUrl)
  copiedShareUrl.value = true
  setTimeout(() => copiedShareUrl.value = false, 2000)
}

// Items to buy at Jita (where jita is best or no data for structure)
const jitaItems = computed(() => {
  return props.items.filter(item => item.bestLocation === 'jita' || item.bestLocation === null)
})

// Items to buy at structure (where structure is best)
const structureItems = computed(() => {
  return props.items.filter(item => item.bestLocation === 'structure')
})

// Generate EVE multibuy format for Jita items
const jitaMultibuyFormat = computed(() => {
  return jitaItems.value
    .map(item => `${item.typeName}\t${item.quantity}`)
    .join('\n')
})

// Generate EVE multibuy format for structure items
const structureMultibuyFormat = computed(() => {
  return structureItems.value
    .map(item => `${item.typeName}\t${item.quantity}`)
    .join('\n')
})

// Short structure name (first part before " - ")
const shortStructureName = computed(() => {
  return props.structureName?.split(' - ')[0] || 'Structure'
})

function formatRelativeTime(isoDate: string | null): string {
  if (!isoDate) return ''
  const date = new Date(isoDate)
  const now = new Date()
  const diffMs = now.getTime() - date.getTime()
  const diffMins = Math.floor(diffMs / 60000)
  const diffHours = Math.floor(diffMins / 60)

  if (diffMins < 1) return "il y a moins d'une minute"
  if (diffMins < 60) return `il y a ${diffMins} min`
  if (diffHours < 24) return `il y a ${diffHours}h`
  return formatDateTime(isoDate)
}

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
  <div class="space-y-4">
    <!-- Price info banner -->
    <div class="bg-cyan-900/30 border border-cyan-500/30 rounded-lg p-3 flex items-center gap-3">
      <svg class="w-5 h-5 text-cyan-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <p class="text-cyan-300 text-sm">
        Les prix affiches sont les <span class="font-medium">ordres de vente les plus bas</span> (Jita Sell et {{ shortStructureName }} Sell).
      </p>
    </div>

    <!-- Not Found Items -->
    <div v-if="notFound && notFound.length > 0" class="bg-yellow-900/30 border border-yellow-500/30 rounded-xl p-4">
      <h3 class="text-yellow-400 font-medium mb-2">Items non trouves ({{ notFound.length }})</h3>
      <p class="text-yellow-300/70 text-sm">
        {{ notFound.join(', ') }}
      </p>
    </div>

    <!-- Price Error -->
    <div v-if="priceError" class="bg-yellow-900/30 border border-yellow-500/30 rounded-xl p-4 text-yellow-400">
      {{ priceError }}
    </div>

    <!-- Structure not accessible warning (only show when not readonly) -->
    <div
      v-if="structureAccessible === false && !priceError && !readonly"
      class="bg-amber-900/30 border border-amber-500/50 rounded-lg p-3"
    >
      <div class="flex items-center justify-between gap-3">
        <div class="flex items-center gap-3">
          <svg class="w-5 h-5 text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
          <div class="text-sm">
            <p class="text-amber-300">Prix de la structure non disponibles</p>
            <p class="text-amber-400/70 text-xs mt-0.5">
              Les donnees de marche pour {{ structureName }} n'ont pas ete synchronisees.
            </p>
          </div>
        </div>
        <button
          v-if="!readonly"
          @click="emit('syncStructure')"
          :disabled="isSyncing"
          class="px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg text-white text-sm font-medium transition-colors flex items-center gap-2"
        >
          <svg v-if="isSyncing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          {{ isSyncing ? 'Synchronisation...' : 'Synchroniser' }}
        </button>
      </div>
    </div>

    <!-- Cache info -->
    <div
      v-if="structureFromCache && structureLastSync"
      class="text-xs text-slate-500 flex items-center gap-2"
    >
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      Prix {{ shortStructureName }} mis a jour {{ formatRelativeTime(structureLastSync) }}
    </div>

    <!-- Summary -->
    <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700">
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
        <div>
          <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">Volume total</p>
          <p class="text-lg font-mono text-slate-200">{{ formatNumber(totals.volume) }} m³</p>
        </div>
        <div>
          <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">Jita + Transport</p>
          <p class="text-lg font-mono text-cyan-400">{{ formatIsk(totals.jitaWithImport) }}</p>
        </div>
        <div>
          <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ shortStructureName }}</p>
          <p class="text-lg font-mono text-purple-400">{{ formatIsk(totals.structure) }}</p>
        </div>
        <div>
          <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">Meilleur prix</p>
          <p class="text-lg font-mono text-green-400 font-bold">{{ formatIsk(totals.best) }}</p>
        </div>
      </div>

      <!-- Copy buttons -->
      <div class="flex flex-wrap gap-3">
        <button
          v-if="jitaItems.length > 0"
          @click="copyToClipboard(jitaMultibuyFormat, 'jita')"
          class="flex items-center gap-2 px-4 py-2 bg-cyan-700 hover:bg-cyan-600 rounded-lg text-white text-sm font-medium transition-colors"
        >
          <svg v-if="!copiedJita" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
          </svg>
          <svg v-else class="w-4 h-4 text-green-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          {{ copiedJita ? 'Copie !' : `Copier Jita (${jitaItems.length})` }}
        </button>

        <button
          v-if="structureItems.length > 0"
          @click="copyToClipboard(structureMultibuyFormat, 'structure')"
          class="flex items-center gap-2 px-4 py-2 bg-purple-700 hover:bg-purple-600 rounded-lg text-white text-sm font-medium transition-colors"
        >
          <svg v-if="!copiedStructure" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
          </svg>
          <svg v-else class="w-4 h-4 text-green-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          {{ copiedStructure ? 'Copie !' : `Copier ${shortStructureName} (${structureItems.length})` }}
        </button>

        <div class="flex-1"></div>

        <!-- Share button -->
        <button
          v-if="!readonly && !shareUrl"
          @click="emit('share')"
          :disabled="isSharing"
          class="flex items-center gap-2 px-4 py-2 bg-emerald-700 hover:bg-emerald-600 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg text-white text-sm font-medium transition-colors"
        >
          <svg v-if="isSharing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
          </svg>
          {{ isSharing ? 'Creation...' : 'Partager' }}
        </button>

        <!-- Copy share URL button (shown after sharing) -->
        <button
          v-if="shareUrl"
          @click="copyShareUrl"
          class="flex items-center gap-2 px-4 py-2 bg-emerald-700 hover:bg-emerald-600 rounded-lg text-white text-sm font-medium transition-colors"
        >
          <svg v-if="!copiedShareUrl" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
          </svg>
          <svg v-else class="w-4 h-4 text-green-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          {{ copiedShareUrl ? 'Lien copie !' : 'Copier le lien' }}
        </button>
      </div>
    </div>

    <!-- Slot for additional content between summary and items table -->
    <slot name="after-summary"></slot>

    <!-- Items Table -->
    <div class="bg-slate-900/50 backdrop-blur border border-slate-700 rounded-xl overflow-hidden">
      <div class="p-4 border-b border-slate-700/50 flex items-center justify-between">
        <h3 class="text-lg font-semibold text-slate-200">
          Items ({{ items.length }})
        </h3>
        <div class="flex items-center gap-4 text-sm">
          <span class="flex items-center gap-2">
            <span class="w-3 h-3 rounded-full bg-cyan-500"></span>
            <span class="text-slate-400">Jita ({{ jitaItems.length }})</span>
          </span>
          <span class="flex items-center gap-2">
            <span class="w-3 h-3 rounded-full bg-purple-500"></span>
            <span class="text-slate-400">{{ shortStructureName }} ({{ structureItems.length }})</span>
          </span>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-800/50 text-slate-400 text-xs uppercase tracking-wider">
            <tr>
              <th class="px-4 py-3 text-left">Item</th>
              <th class="px-4 py-3 text-right">Quantite</th>
              <th class="px-4 py-3 text-right">Volume</th>
              <th class="px-4 py-3 text-right">Jita + Import</th>
              <th class="px-4 py-3 text-right">{{ shortStructureName }}</th>
              <th class="px-4 py-3 text-center">Acheter a</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-700/30">
            <tr
              v-for="item in items"
              :key="item.typeId"
              class="hover:bg-slate-800/30 transition-colors"
              :class="{
                'bg-cyan-900/10': item.bestLocation === 'jita',
                'bg-purple-900/10': item.bestLocation === 'structure',
              }"
            >
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <img
                    :src="getTypeIconUrl(item.typeId)"
                    :alt="item.typeName"
                    @error="onImageError"
                    class="w-8 h-8 rounded"
                  />
                  <span class="text-slate-200">{{ item.typeName }}</span>
                </div>
              </td>
              <td class="px-4 py-3 text-right text-slate-300 font-mono">
                {{ formatNumber(item.quantity) }}
              </td>
              <td class="px-4 py-3 text-right text-slate-400 font-mono text-xs">
                {{ formatNumber(item.totalVolume) }} m³
              </td>
              <td class="px-4 py-3 text-right font-mono" :class="item.bestLocation === 'jita' || item.bestLocation === null ? 'text-cyan-400 font-medium' : 'text-slate-400'">
                {{ item.jitaWithImport !== null ? formatIsk(item.jitaWithImport) : '-' }}
              </td>
              <td class="px-4 py-3 text-right font-mono" :class="item.bestLocation === 'structure' ? 'text-purple-400 font-medium' : 'text-slate-400'">
                {{ item.structureTotal !== null ? formatIsk(item.structureTotal) : '-' }}
              </td>
              <td class="px-4 py-3 text-center">
                <span
                  v-if="item.bestLocation"
                  class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium"
                  :class="item.bestLocation === 'jita' ? 'bg-cyan-900/50 text-cyan-300' : 'bg-purple-900/50 text-purple-300'"
                >
                  {{ item.bestLocation === 'jita' ? 'Jita' : shortStructureName }}
                </span>
                <span v-else class="text-slate-500">-</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
