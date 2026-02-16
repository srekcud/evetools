<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useMarketStore, type MarketSearchItem } from '@/stores/market'
import { useFormatters } from '@/composables/useFormatters'

const emit = defineEmits<{
  select: [item: MarketSearchItem]
}>()

const { t } = useI18n()
const marketStore = useMarketStore()
const { formatIsk } = useFormatters()

const query = ref('')
const showDropdown = ref(false)
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

function handleFocus(): void {
  if (marketStore.searchResults.length > 0 && query.value.length >= 2) {
    showDropdown.value = true
  }
}
</script>

<template>
  <div class="relative">
    <div class="relative">
      <svg
        class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-500"
        fill="none"
        stroke="currentColor"
        viewBox="0 0 24 24"
      >
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
      </svg>
      <input
        v-model="query"
        type="text"
        :placeholder="t('market.search.placeholder')"
        class="w-full pl-10 pr-4 py-3 bg-slate-800 border border-slate-700 rounded-lg text-slate-100 placeholder-slate-500 focus:outline-none focus:border-cyan-500/50 focus:ring-1 focus:ring-cyan-500/30 transition-colors"
        @focus="handleFocus"
        @blur="handleBlur"
      />
      <svg
        v-if="marketStore.isSearching"
        class="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 text-cyan-400 animate-spin"
        fill="none"
        viewBox="0 0 24 24"
      >
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
      </svg>
    </div>

    <!-- Dropdown results -->
    <div
      v-if="showDropdown && marketStore.searchResults.length > 0"
      class="absolute z-40 w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg shadow-xl shadow-black/30 max-h-80 overflow-y-auto"
    >
      <button
        v-for="item in marketStore.searchResults"
        :key="item.typeId"
        class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-slate-700/50 transition-colors text-left border-b border-slate-700/50 last:border-b-0"
        @mousedown.prevent="selectItem(item)"
      >
        <img
          :src="`https://images.evetech.net/types/${item.typeId}/icon?size=32`"
          :alt="item.typeName"
          class="w-8 h-8 rounded"
          loading="lazy"
        />
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-slate-100 truncate">{{ item.typeName }}</p>
          <p class="text-xs text-slate-500 truncate">{{ item.groupName }} &middot; {{ item.categoryName }}</p>
        </div>
        <div class="text-right shrink-0">
          <p class="text-sm text-cyan-400 font-mono">{{ formatIsk(item.jitaSell) }}</p>
          <p
            v-if="item.change30d !== null"
            class="text-xs font-mono"
            :class="item.change30d >= 0 ? 'text-emerald-400' : 'text-red-400'"
          >
            {{ item.change30d >= 0 ? '+' : '' }}{{ item.change30d.toFixed(1) }}%
          </p>
        </div>
      </button>
    </div>

    <!-- No results -->
    <div
      v-if="showDropdown && query.length >= 2 && !marketStore.isSearching && marketStore.searchResults.length === 0"
      class="absolute z-40 w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg shadow-xl p-4 text-center"
    >
      <p class="text-sm text-slate-500">{{ t('market.search.noResults') }}</p>
    </div>
  </div>
</template>
