<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useIndustryStore } from '@/stores/industry'
import { useEveImages } from '@/composables/useEveImages'
import type { SearchResult } from '@/stores/industry'

const store = useIndustryStore()
const { getTypeIconUrl, onImageError } = useEveImages()

const itemSearchQuery = ref('')
const itemSearchResults = ref<SearchResult[]>([])
const showDropdown = ref(false)
let searchTimeout: ReturnType<typeof setTimeout> | null = null

onMounted(() => {
  store.fetchBlacklist()
})

const activeGroupIds = computed(() => {
  if (!store.blacklist) return new Set<number>()
  const ids = new Set<number>()
  for (const cat of store.blacklist.categories) {
    if (cat.blacklisted) {
      for (const gid of cat.groupIds) ids.add(gid)
    }
  }
  return ids
})

const activeTypeIds = computed(() => {
  if (!store.blacklist) return new Set<number>()
  return new Set(store.blacklist.items.map((i) => i.typeId))
})

async function toggleCategory(key: string) {
  if (!store.blacklist) return
  const cat = store.blacklist.categories.find((c) => c.key === key)
  if (!cat) return

  const currentGroupIds = [...activeGroupIds.value]
  let newGroupIds: number[]

  if (cat.blacklisted) {
    // Remove this category's group IDs
    newGroupIds = currentGroupIds.filter((id) => !cat.groupIds.includes(id))
  } else {
    // Add this category's group IDs
    newGroupIds = [...new Set([...currentGroupIds, ...cat.groupIds])]
  }

  await store.updateBlacklist(newGroupIds, [...activeTypeIds.value])
}

async function removeItem(typeId: number) {
  const newTypeIds = [...activeTypeIds.value].filter((id) => id !== typeId)
  await store.updateBlacklist([...activeGroupIds.value], newTypeIds)
}

async function addItem(typeId: number) {
  if (activeTypeIds.value.has(typeId)) return
  const newTypeIds = [...activeTypeIds.value, typeId]
  await store.updateBlacklist([...activeGroupIds.value], newTypeIds)
  itemSearchQuery.value = ''
  itemSearchResults.value = []
  showDropdown.value = false
}

watch(itemSearchQuery, (val) => {
  if (searchTimeout) clearTimeout(searchTimeout)
  if (val.length < 2) {
    itemSearchResults.value = []
    showDropdown.value = false
    return
  }
  searchTimeout = setTimeout(async () => {
    itemSearchResults.value = await store.searchBlacklistItems(val)
    showDropdown.value = itemSearchResults.value.length > 0
  }, 300)
})

function hideDropdownDelayed() {
  window.setTimeout(() => {
    showDropdown.value = false
  }, 200)
}
</script>

<template>
  <div class="bg-slate-900 rounded-xl border border-slate-800">
    <div class="px-6 py-4 border-b border-slate-800">
      <h3 class="text-lg font-semibold text-slate-100 mb-1">Construction Blacklist</h3>
      <p class="text-sm text-slate-400">
        Sélectionnez les catégories et items que vous ne fabriquez pas. Ils seront traités comme des achats dans tous vos projets.
      </p>
    </div>

    <div class="p-6 space-y-6">
      <div v-if="!store.blacklist" class="text-center py-8 text-slate-500">
        Chargement...
      </div>

      <template v-else>
        <!-- Categories -->
        <div>
          <h4 class="text-sm font-medium text-slate-400 uppercase tracking-wider mb-3">Catégories</h4>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
            <label
              v-for="cat in store.blacklist.categories"
              :key="cat.key"
              class="flex items-center gap-3 p-3 rounded-lg bg-slate-800/50 border border-slate-700 cursor-pointer hover:bg-slate-800"
            >
              <input
                type="checkbox"
                :checked="cat.blacklisted"
                @change="toggleCategory(cat.key)"
                class="w-4 h-4 rounded border-slate-600 bg-slate-800 text-cyan-500 focus:ring-cyan-500 focus:ring-offset-slate-900"
              />
              <span class="text-sm text-slate-200">{{ cat.label }}</span>
            </label>
          </div>
        </div>

        <!-- Individual items -->
        <div>
          <h4 class="text-sm font-medium text-slate-400 uppercase tracking-wider mb-3">Items individuels</h4>

          <!-- Search to add -->
          <div class="relative mb-3">
            <input
              v-model="itemSearchQuery"
              type="text"
              placeholder="Rechercher un item à blacklister..."
              class="w-full px-4 py-2 rounded-lg bg-slate-800 border border-slate-700 text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-cyan-500 focus:border-cyan-500"
              @focus="showDropdown = itemSearchResults.length > 0"
              @blur="hideDropdownDelayed"
            />
            <div
              v-if="showDropdown"
              class="absolute z-10 mt-1 w-full bg-slate-800 border border-slate-700 rounded-lg shadow-lg max-h-60 overflow-y-auto"
            >
              <button
                v-for="result in itemSearchResults"
                :key="result.typeId"
                @mousedown.prevent="addItem(result.typeId)"
                class="w-full flex items-center gap-3 px-4 py-2 hover:bg-slate-700 text-left"
                :class="activeTypeIds.has(result.typeId) ? 'opacity-50' : ''"
              >
                <img
                  :src="getTypeIconUrl(result.typeId, 32)"
                  class="w-6 h-6 rounded"
                  @error="onImageError"
                />
                <span class="text-sm text-slate-200">{{ result.typeName }}</span>
                <span v-if="activeTypeIds.has(result.typeId)" class="text-xs text-slate-500 ml-auto">
                  déjà ajouté
                </span>
              </button>
            </div>
          </div>

          <!-- List of blacklisted items -->
          <div v-if="store.blacklist.items.length > 0" class="flex flex-wrap gap-2 max-h-64 overflow-y-auto">
            <div
              v-for="item in store.blacklist.items"
              :key="item.typeId"
              class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-slate-800/50 border border-slate-700"
            >
              <img
                :src="getTypeIconUrl(item.typeId, 32)"
                class="w-5 h-5 rounded"
                @error="onImageError"
              />
              <span class="text-sm text-slate-200">{{ item.typeName }}</span>
              <button
                @click="removeItem(item.typeId)"
                class="p-0.5 hover:bg-slate-700 rounded text-slate-500 hover:text-red-400"
              >
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
          </div>
          <p v-else class="text-sm text-slate-500">
            Aucun item individuel blacklisté.
          </p>
        </div>
      </template>
    </div>
  </div>
</template>
