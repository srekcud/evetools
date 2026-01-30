<script setup lang="ts">
import { ref } from 'vue'
import { useIndustryStore, type SearchResult } from '@/stores/industry'

const emit = defineEmits<{
  select: [result: SearchResult]
}>()

const store = useIndustryStore()
const query = ref('')
const showDropdown = ref(false)
let searchTimeout: ReturnType<typeof setTimeout> | null = null

function onInput() {
  if (searchTimeout) clearTimeout(searchTimeout)
  if (query.value.length < 2) {
    store.searchResults = []
    showDropdown.value = false
    return
  }
  searchTimeout = setTimeout(async () => {
    await store.searchProducts(query.value)
    showDropdown.value = store.searchResults.length > 0
  }, 300)
}

function selectResult(result: SearchResult) {
  emit('select', result)
  query.value = result.typeName
  showDropdown.value = false
  store.searchResults = []
}

function onBlur() {
  // Delay to allow click to register
  setTimeout(() => {
    showDropdown.value = false
  }, 200)
}
</script>

<template>
  <div class="relative">
    <input
      v-model="query"
      @input="onInput"
      @focus="showDropdown = store.searchResults.length > 0"
      @blur="onBlur"
      type="text"
      placeholder="Rechercher un produit (ex: Nomad, Raven...)"
      class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:border-cyan-500 pr-10"
    />
    <svg
      class="absolute right-3 top-3 w-4 h-4 text-slate-500"
      fill="none"
      stroke="currentColor"
      viewBox="0 0 24 24"
    >
      <path
        stroke-linecap="round"
        stroke-linejoin="round"
        stroke-width="2"
        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
      />
    </svg>
    <div
      v-if="showDropdown && store.searchResults.length > 0"
      class="absolute z-20 w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg shadow-xl max-h-60 overflow-y-auto"
    >
      <button
        v-for="result in store.searchResults"
        :key="result.typeId"
        @mousedown.prevent="selectResult(result)"
        class="w-full px-4 py-2.5 text-left hover:bg-slate-700 text-sm flex items-center gap-3 transition-colors"
      >
        <img
          :src="`https://images.evetech.net/types/${result.typeId}/icon?size=32`"
          class="w-6 h-6 rounded"
          @error="($event.target as HTMLImageElement).style.display = 'none'"
        />
        <span>{{ result.typeName }}</span>
      </button>
    </div>
  </div>
</template>
