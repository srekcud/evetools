<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useMarketStore, type MarketSearchItem } from '@/stores/market'
import MainLayout from '@/layouts/MainLayout.vue'
import MarketSearch from '@/components/market/MarketSearch.vue'
import MarketResultsTable from '@/components/market/MarketResultsTable.vue'
import MarketTypeDetail from '@/components/market/MarketTypeDetail.vue'
import AlertsPanel from '@/components/market/AlertsPanel.vue'

const { t } = useI18n()
const marketStore = useMarketStore()

type Tab = 'browse' | 'favorites' | 'alerts'
const activeTab = ref<Tab>('browse')

const activeAlertsCount = computed(() =>
  marketStore.alerts.filter(a => a.status === 'active').length
)

// Filtered search results based on selected category group
const filteredSearchResults = computed(() => {
  if (marketStore.selectedGroupId === null) {
    return marketStore.searchResults
  }
  const selectedGroup = marketStore.rootGroups.find(
    g => g.id === marketStore.selectedGroupId
  )
  if (!selectedGroup) return marketStore.searchResults
  return marketStore.searchResults.filter(
    item => item.categoryName === selectedGroup.name
  )
})

// Load root groups on mount
onMounted(() => {
  marketStore.fetchRootGroups()
})

// Load tab-specific data
watch(activeTab, (tab) => {
  if (tab === 'favorites') {
    marketStore.fetchFavorites()
  } else if (tab === 'alerts') {
    marketStore.fetchAlerts()
  }
})

function selectItem(typeId: number): void {
  marketStore.fetchTypeDetail(typeId)
}

function handleSearchSelect(item: MarketSearchItem): void {
  marketStore.addRecentSearch(item.typeId, item.typeName)
  selectItem(item.typeId)
}

function handleRecentSearchClick(typeId: number): void {
  selectItem(typeId)
}

function selectItemAndBrowse(typeId: number): void {
  activeTab.value = 'browse'
  selectItem(typeId)
}

const tabClasses = (tab: Tab): string => {
  return activeTab.value === tab
    ? 'bg-cyan-500/20 text-cyan-400 border-cyan-500/30'
    : 'text-slate-400 border-transparent hover:text-slate-200 hover:border-slate-600'
}

const categoryPillClasses = (groupId: number | null): string => {
  return marketStore.selectedGroupId === groupId
    ? 'bg-cyan-500/20 text-cyan-400 border-cyan-500/50'
    : 'bg-slate-800 text-slate-400 border-slate-700 hover:bg-slate-700 hover:text-slate-300'
}
</script>

<template>
  <MainLayout>
    <div class="space-y-6">

      <!-- Tabs -->
      <div class="flex items-center gap-1 border-b border-slate-800 pb-px">
        <button
          @click="activeTab = 'browse'"
          class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors"
          :class="tabClasses('browse')"
        >
          {{ t('market.tabs.browse') }}
        </button>
        <button
          @click="activeTab = 'favorites'"
          class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors"
          :class="tabClasses('favorites')"
        >
          {{ t('market.tabs.favorites') }}
          <span
            v-if="marketStore.favorites.length > 0"
            class="ml-1.5 px-1.5 py-0.5 text-xs rounded-full bg-amber-500/20 text-amber-400"
          >
            {{ marketStore.favorites.length }}
          </span>
        </button>
        <button
          @click="activeTab = 'alerts'"
          class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors"
          :class="tabClasses('alerts')"
        >
          {{ t('market.tabs.alerts') }}
          <span
            v-if="activeAlertsCount > 0"
            class="ml-1.5 px-1.5 py-0.5 text-xs rounded-full bg-cyan-500/20 text-cyan-400"
          >
            {{ activeAlertsCount }}
          </span>
        </button>
      </div>

      <!-- Error banner -->
      <div
        v-if="marketStore.error"
        class="bg-red-500/10 border border-red-500/30 rounded-xl p-4 flex items-center gap-3"
      >
        <svg class="w-5 h-5 text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <p class="text-sm text-red-400 flex-1">{{ marketStore.error }}</p>
        <button
          @click="marketStore.clearError()"
          class="p-1 hover:bg-red-500/20 rounded-lg transition-colors text-red-400"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- Browse Tab -->
      <div v-if="activeTab === 'browse'">
        <div class="flex flex-col lg:flex-row gap-6">
          <!-- Left: Search + Results -->
          <div class="lg:w-2/5 space-y-4" :class="{ 'lg:w-full': !marketStore.typeDetail }">
            <MarketSearch @select="handleSearchSelect" />

            <!-- Recent Searches -->
            <div v-if="marketStore.recentSearches.length > 0" class="space-y-2">
              <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-slate-500 uppercase tracking-wider">{{ t('market.recentSearches') }}</span>
                <button
                  @click="marketStore.clearRecentSearches()"
                  class="text-xs text-slate-500 hover:text-slate-300 transition-colors"
                >
                  {{ t('market.clearRecent') }}
                </button>
              </div>
              <div class="flex flex-wrap gap-1.5">
                <button
                  v-for="entry in marketStore.recentSearches"
                  :key="entry.typeId"
                  class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-slate-800 text-slate-300 text-xs hover:bg-slate-700 transition-colors group"
                  @click="handleRecentSearchClick(entry.typeId)"
                >
                  <span>{{ entry.typeName }}</span>
                  <svg
                    class="w-3 h-3 text-slate-500 hover:text-slate-200 shrink-0 opacity-0 group-hover:opacity-100 transition-opacity"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                    @click.stop="marketStore.removeRecentSearch(entry.typeId)"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
            </div>

            <!-- Category Filter Pills -->
            <div v-if="marketStore.rootGroups.length > 0" class="flex gap-1.5 overflow-x-auto pb-1 scrollbar-thin">
              <button
                class="shrink-0 px-3 py-1.5 rounded-full border text-xs font-medium transition-colors"
                :class="categoryPillClasses(null)"
                @click="marketStore.setSelectedGroup(null)"
              >
                {{ t('market.categories.all') }}
              </button>
              <button
                v-for="group in marketStore.rootGroups"
                :key="group.id"
                class="shrink-0 px-3 py-1.5 rounded-full border text-xs font-medium transition-colors whitespace-nowrap"
                :class="categoryPillClasses(group.id)"
                @click="marketStore.setSelectedGroup(group.id)"
              >
                {{ group.name }}
              </button>
            </div>

            <MarketResultsTable
              v-if="filteredSearchResults.length > 0"
              :items="filteredSearchResults"
              :selected-type-id="marketStore.selectedTypeId"
              :show-category="true"
              @select="selectItem"
            />
          </div>

          <!-- Right: Detail panel -->
          <div v-if="marketStore.typeDetail" class="lg:w-3/5">
            <div class="bg-slate-900 rounded-xl border border-slate-800 p-5 relative">
              <!-- Close button -->
              <button
                @click="marketStore.clearDetail()"
                class="absolute top-3 right-3 p-1.5 hover:bg-slate-800 rounded-lg transition-colors text-slate-500 hover:text-slate-300"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>

              <MarketTypeDetail :detail="marketStore.typeDetail" />
            </div>
          </div>

          <!-- Empty state when no detail selected and no search results -->
          <div
            v-if="!marketStore.typeDetail && filteredSearchResults.length === 0 && marketStore.searchResults.length === 0"
            class="lg:flex-1 flex items-center justify-center py-16"
          >
            <div class="text-center">
              <svg class="w-16 h-16 text-slate-700 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
              </svg>
              <p class="text-slate-500 text-sm">{{ t('market.search.placeholder') }}</p>
            </div>
          </div>
        </div>

        <!-- Loading overlay for detail -->
        <div
          v-if="marketStore.isLoading"
          class="flex items-center justify-center py-8"
        >
          <svg class="w-6 h-6 animate-spin text-cyan-400" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
          </svg>
          <span class="ml-2 text-slate-400 text-sm">{{ t('common.actions.loading') }}</span>
        </div>
      </div>

      <!-- Favorites Tab -->
      <div v-if="activeTab === 'favorites'">
        <MarketResultsTable
          :items="marketStore.favorites.map(f => ({
            typeId: f.typeId,
            typeName: f.typeName,
            jitaSell: f.jitaSell,
            jitaBuy: f.jitaBuy,
            change30d: f.change30d,
          }))"
          :selected-type-id="marketStore.selectedTypeId"
          @select="selectItemAndBrowse"
        />
      </div>

      <!-- Alerts Tab -->
      <div v-if="activeTab === 'alerts'">
        <AlertsPanel
          :alerts="marketStore.alerts"
          @delete="(id: string) => marketStore.deleteAlert(id)"
          @select-item="selectItemAndBrowse"
        />
      </div>

    </div>
  </MainLayout>
</template>
