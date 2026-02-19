<script setup lang="ts">
import { ref, watch, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useMarketStore, type MarketSearchItem } from '@/stores/market'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

const emit = defineEmits<{
  select: [item: MarketSearchItem]
}>()

const { t } = useI18n()
const marketStore = useMarketStore()
const { formatIsk } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

const query = ref('')
const showDropdown = ref(false)
const inputRef = ref<HTMLInputElement | null>(null)
let debounceTimer: ReturnType<typeof setTimeout> | null = null

watch(query, (val) => {
  if (debounceTimer) clearTimeout(debounceTimer)

  if (!val || val.length < 2) {
    marketStore.searchResults = []
    showDropdown.value = false
    return
  }

  debounceTimer = setTimeout(() => {
    marketStore.searchItems(val)
    showDropdown.value = true
  }, 300)
})

watch(() => marketStore.searchResults, (results) => {
  showDropdown.value = results.length > 0 && query.value.length >= 2
})

function selectItem(item: MarketSearchItem): void {
  emit('select', item)
  query.value = item.typeName
  showDropdown.value = false
}

function handleBlur(): void {
  // Delay to allow click events on dropdown items
  setTimeout(() => {
    showDropdown.value = false
  }, 200)
}

function handleSubmit(): void {
  showDropdown.value = false
}

function handleFocus(): void {
  if (marketStore.searchResults.length > 0 && query.value.length >= 2) {
    showDropdown.value = true
  }
}

// Keyboard shortcut "/" to focus search
function handleKeydown(e: KeyboardEvent): void {
  if (e.key === '/' && document.activeElement?.tagName !== 'INPUT' && document.activeElement?.tagName !== 'TEXTAREA') {
    e.preventDefault()
    inputRef.value?.focus()
  }
}

onMounted(() => {
  window.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeydown)
})
</script>

<template>
  <div class="relative">
    <div class="relative rounded-xl overflow-hidden">
      <div class="flex items-center bg-slate-900/80 border border-slate-700/50 rounded-xl focus-within:border-cyan-500/50 transition-colors">
        <svg
          class="w-5 h-5 text-slate-500 ml-4"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        <input
          ref="inputRef"
          v-model="query"
          type="text"
          :placeholder="t('market.search.placeholder')"
          class="w-full bg-transparent px-4 py-3.5 text-sm text-slate-200 placeholder-slate-600 outline-none"
          @focus="handleFocus"
          @blur="handleBlur"
          @keydown.enter="handleSubmit"
        />
        <LoadingSpinner
          v-if="marketStore.isSearching"
          size="md"
          class="text-cyan-400 mr-4"
        />
        <kbd
          v-if="!marketStore.isSearching"
          class="hidden sm:inline-block mr-4 px-2 py-1 bg-slate-800 border border-slate-700 rounded text-xs text-slate-500 font-mono"
        >/</kbd>
      </div>
    </div>

    <!-- Dropdown results -->
    <div
      v-if="showDropdown && marketStore.searchResults.length > 0"
      class="absolute z-40 w-full mt-1 bg-slate-900 border border-slate-700/50 rounded-xl shadow-2xl shadow-black/50 max-h-80 overflow-y-auto"
    >
      <button
        v-for="item in marketStore.searchResults"
        :key="item.typeId"
        class="autocomplete-item w-full flex items-center gap-3 px-4 py-3 hover:bg-cyan-500/10 cursor-pointer transition-colors text-left border-b border-slate-800/50 last:border-b-0"
        @mousedown.prevent="selectItem(item)"
      >
        <img
          :src="getTypeIconUrl(item.typeId, 32)"
          @error="onImageError"
          :alt="item.typeName"
          class="w-8 h-8 rounded item-icon transition-transform"
          loading="lazy"
        />
        <div class="flex-1 min-w-0">
          <div class="text-sm font-medium text-slate-200">{{ item.typeName }}</div>
          <div class="text-xs text-slate-500">{{ item.groupName }} &gt; {{ item.categoryName }}</div>
        </div>
        <div class="text-right shrink-0">
          <div class="text-sm font-mono text-cyan-400">{{ formatIsk(item.jitaSell) }}</div>
          <div class="text-xs text-slate-500">{{ t('market.jitaSell') }}</div>
        </div>
      </button>
    </div>

    <!-- No results -->
    <div
      v-if="showDropdown && query.length >= 2 && !marketStore.isSearching && marketStore.searchResults.length === 0"
      class="absolute z-40 w-full mt-1 bg-slate-900 border border-slate-700/50 rounded-xl shadow-2xl shadow-black/50 p-4 text-center"
    >
      <p class="text-sm text-slate-500">{{ t('market.search.noResults') }}</p>
    </div>
  </div>
</template>

<style scoped>
.autocomplete-item:hover .item-icon {
  transform: scale(1.1);
  filter: drop-shadow(0 0 4px rgba(6,182,212,0.5));
}
</style>
