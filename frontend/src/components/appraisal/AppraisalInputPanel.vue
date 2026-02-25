<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { authFetch, safeJsonParse } from '@/services/api'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

type FormatType = 'auto' | 'multibuy' | 'cargo_scan' | 'eft' | 'dscan' | 'contract' | 'killmail' | 'inventory'

type FormatOption = {
  key: FormatType
  labelKey: string
}

type StructureSearchResult = {
  id: number
  name: string
  typeId: number | null
  solarSystemId: number | null
}

const FORMAT_OPTIONS: FormatOption[] = [
  { key: 'auto', labelKey: 'appraisal.formatAutoDetect' },
  { key: 'multibuy', labelKey: 'appraisal.formatMultibuy' },
  { key: 'cargo_scan', labelKey: 'appraisal.formatCargoScan' },
  { key: 'eft', labelKey: 'appraisal.formatEft' },
  { key: 'dscan', labelKey: 'appraisal.formatDscan' },
  { key: 'contract', labelKey: 'appraisal.formatContract' },
  { key: 'killmail', labelKey: 'appraisal.formatKillmail' },
  { key: 'inventory', labelKey: 'appraisal.formatInventory' },
]

const props = defineProps<{
  inputText: string
  selectedFormat: FormatType
  detectedFormat: FormatType | null
  selectedStructure: { id: number | null; name: string }
  isLoading: boolean
  hasInput: boolean
  hasResults: boolean
}>()

const emit = defineEmits<{
  'update:inputText': [value: string]
  'update:selectedFormat': [value: FormatType]
  'update:detectedFormat': [value: FormatType | null]
  'update:selectedStructure': [value: { id: number | null; name: string }]
  'analyze': []
  'clear': []
}>()

const { t } = useI18n()

// Structure search state (local to this component)
const structureSearchQuery = ref('')
const structureSearchResults = ref<StructureSearchResult[]>([])
const isSearchingStructures = ref(false)
const showStructureDropdown = ref(false)

function selectFormat(format: FormatType): void {
  emit('update:selectedFormat', format)
  if (format !== 'auto') {
    emit('update:detectedFormat', null)
  }
}

function formatLabelForDetection(format: FormatType): string {
  const option = FORMAT_OPTIONS.find(o => o.key === format)
  return option ? t(option.labelKey) : format
}

// Debounced structure search
let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(structureSearchQuery, (query) => {
  if (searchTimeout) clearTimeout(searchTimeout)
  if (query.length < 3) {
    structureSearchResults.value = []
    return
  }
  searchTimeout = setTimeout(() => {
    searchStructures(query)
  }, 300)
})

async function searchStructures(query: string): Promise<void> {
  isSearchingStructures.value = true
  try {
    const response = await authFetch(`/api/shopping-list/search-structures?q=${encodeURIComponent(query)}`)
    if (response.ok) {
      const data = await safeJsonParse<{ results: StructureSearchResult[] }>(response)
      structureSearchResults.value = data.results
      showStructureDropdown.value = true
    }
  } catch (e) {
    console.error('Structure search failed:', e)
  } finally {
    isSearchingStructures.value = false
  }
}

function selectStructure(structure: StructureSearchResult): void {
  emit('update:selectedStructure', { id: structure.id, name: structure.name })
  structureSearchQuery.value = ''
  structureSearchResults.value = []
  showStructureDropdown.value = false
}

function clearStructure(): void {
  emit('update:selectedStructure', { id: null, name: '' })
  structureSearchQuery.value = ''
  structureSearchResults.value = []
}

function onStructureInputBlur(): void {
  setTimeout(() => {
    showStructureDropdown.value = false
  }, 200)
}
</script>

<template>
  <div class="bg-slate-900 rounded-xl p-6 border border-cyan-500/15">
    <div class="flex flex-col gap-4">

      <!-- Label -->
      <label class="text-sm font-medium text-slate-300">
        {{ t('shopping.pasteLabel') }}
      </label>

      <!-- Textarea -->
      <textarea
        :value="props.inputText"
        @input="emit('update:inputText', ($event.target as HTMLTextAreaElement).value)"
        rows="6"
        class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-3 text-slate-200 placeholder-slate-500 focus:outline-hidden font-mono text-[13px] resize-y leading-relaxed"
        :class="{ 'ring-1 ring-cyan-500/40 border-cyan-500/40 shadow-[0_0_20px_-6px_rgba(6,182,212,0.15)]': props.inputText.trim().length > 0 }"
        placeholder="Tritanium&#9;10000&#10;Pyerite&#9;5000&#10;Megacyte 200&#10;200x Nocxium&#10;..."
      ></textarea>

      <!-- Format detection chips -->
      <div class="flex items-center gap-2 flex-wrap -mt-2">
        <span class="text-[11px] text-slate-500 mr-1">{{ t('appraisal.formatLabel') }}</span>
        <button
          v-for="fmt in FORMAT_OPTIONS"
          :key="fmt.key"
          @click="selectFormat(fmt.key)"
          class="px-3 py-1 rounded-md text-[11px] font-medium transition-all border cursor-pointer tracking-wide"
          :class="[
            props.selectedFormat === fmt.key && fmt.key === 'auto' && !props.detectedFormat
              ? 'bg-cyan-500/12 text-cyan-400 border-cyan-500/25'
              : props.detectedFormat === fmt.key && props.selectedFormat === 'auto'
                ? 'bg-green-500/12 text-green-400 border-green-500/25'
                : props.selectedFormat === fmt.key && fmt.key !== 'auto'
                  ? 'bg-cyan-500/12 text-cyan-400 border-cyan-500/25'
                  : 'bg-slate-800/60 text-slate-500 border-transparent hover:text-slate-400 hover:bg-slate-700/60'
          ]"
        >
          {{ t(fmt.labelKey) }}
        </button>
        <div class="flex-1"></div>
        <span
          v-if="props.detectedFormat && props.selectedFormat === 'auto'"
          class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] font-medium bg-green-500/10 text-green-400 border border-green-500/20"
        >
          <span class="w-1.5 h-1.5 rounded-full bg-green-400 animate-pulse"></span>
          {{ t('appraisal.formatDetected', { format: formatLabelForDetection(props.detectedFormat) }) }}
        </span>
      </div>

      <!-- Action row -->
      <div class="flex items-center gap-3 flex-wrap">

        <!-- Structure selector -->
        <div class="relative min-w-[300px] max-w-[420px]">
          <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-500 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
          <input
            v-model="structureSearchQuery"
            type="text"
            :placeholder="props.selectedStructure.id ? props.selectedStructure.name : t('shopping.searchStructure')"
            @focus="showStructureDropdown = true"
            @blur="onStructureInputBlur"
            class="w-full bg-slate-800 border border-slate-700 rounded-lg pl-8 pr-8 py-2 text-slate-200 text-[13px] focus:outline-hidden focus:border-cyan-500/50 placeholder-slate-400"
            :class="{ 'border-cyan-500/50 text-cyan-400 placeholder-cyan-400': props.selectedStructure.id }"
          />
          <button
            v-if="props.selectedStructure.id || structureSearchQuery"
            @mousedown.prevent="clearStructure"
            class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-200 p-0.5"
            :title="t('shopping.clearStructure')"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
          <LoadingSpinner
            v-else-if="isSearchingStructures"
            size="sm"
            class="absolute right-2.5 top-1/2 -translate-y-1/2 text-cyan-400"
          />

          <!-- Dropdown -->
          <div
            v-if="showStructureDropdown && (structureSearchResults.length > 0 || (structureSearchQuery.length >= 3 && !isSearchingStructures))"
            class="absolute z-50 w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg shadow-xl max-h-60 overflow-y-auto"
          >
            <button
              v-for="struct in structureSearchResults"
              :key="struct.id"
              @mousedown.prevent="selectStructure(struct)"
              class="w-full px-3 py-2 text-left text-slate-200 hover:bg-slate-700/50 transition-colors border-b border-slate-800 last:border-0"
            >
              <div class="font-medium truncate">{{ struct.name }}</div>
            </button>
            <div
              v-if="structureSearchQuery.length >= 3 && structureSearchResults.length === 0 && !isSearchingStructures"
              class="px-3 py-2 text-slate-400 text-sm"
            >
              {{ t('shopping.noStructureFound') }}
            </div>
          </div>
        </div>

        <div class="flex-1"></div>

        <!-- Clear -->
        <button
          @click="emit('clear')"
          :disabled="!props.hasInput && !props.hasResults"
          class="px-4 py-2 bg-slate-700 hover:bg-slate-600 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg text-slate-200 text-[13px] font-medium transition-colors"
        >
          {{ t('common.actions.clear') }}
        </button>

        <!-- Analyze button -->
        <button
          @click="emit('analyze')"
          :disabled="!props.hasInput || props.isLoading"
          class="px-6 py-2 rounded-lg text-white text-[13px] font-semibold transition-colors flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
          :class="props.isLoading ? 'bg-cyan-700' : 'btn-analyze'"
        >
          <LoadingSpinner v-if="props.isLoading" size="sm" />
          <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
          </svg>
          {{ props.isLoading ? t('appraisal.appraising') : t('appraisal.analyze') }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.btn-analyze {
  background: linear-gradient(135deg, #0e7490, #0891b2, #06b6d4);
  background-size: 200% 200%;
  animation: gradient-shift 3s ease infinite;
}

@keyframes gradient-shift {
  0%, 100% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
}
</style>
