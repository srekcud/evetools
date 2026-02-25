<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useMarketStore, type MarketSearchItem } from '@/stores/market'
import { useEveImages } from '@/composables/useEveImages'
import { apiRequest } from '@/services/api'
import MainLayout from '@/layouts/MainLayout.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import MarketSearch from '@/components/market/MarketSearch.vue'
import MarketResultsTable from '@/components/market/MarketResultsTable.vue'
import MarketTypeDetail from '@/components/market/MarketTypeDetail.vue'
import AlertsPanel from '@/components/market/AlertsPanel.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

const { t } = useI18n()
const marketStore = useMarketStore()
const { getTypeIconUrl, onImageError } = useEveImages()

// Structure name from user settings
const structureSystemName = ref<string | null>(null)

type Tab = 'browse' | 'detail' | 'alerts'
const activeTab = ref<Tab>('browse')

const route = useRoute()
const router = useRouter()

const VALID_TABS: Tab[] = ['browse', 'detail', 'alerts']

const activeAlertsCount = computed(() =>
  marketStore.alerts.filter(a => a.status === 'active').length
)

// Filtered search results: include mode takes priority over exclude mode
const filteredSearchResults = computed(() => {
  // Include mode: show only the selected category
  if (marketStore.includedGroupId !== null) {
    const group = marketStore.rootGroups.find(g => g.id === marketStore.includedGroupId)
    if (group) {
      return marketStore.searchResults.filter(item => item.categoryName === group.name)
    }
  }
  // Exclude mode: hide excluded categories
  if (marketStore.excludedGroupIds.size === 0) {
    return marketStore.searchResults
  }
  const excludedNames = new Set(
    marketStore.rootGroups
      .filter(g => marketStore.excludedGroupIds.has(g.id))
      .map(g => g.name)
  )
  return marketStore.searchResults.filter(
    item => !excludedNames.has(item.categoryName)
  )
})

// Load root groups, favorites, alerts and settings on mount
onMounted(async () => {
  const tabParam = route.query.tab as string | undefined
  if (tabParam && VALID_TABS.includes(tabParam as Tab)) {
    activeTab.value = tabParam as Tab
  }

  marketStore.fetchRootGroups()
  marketStore.fetchFavorites()
  marketStore.fetchAlerts()

  try {
    const settings = await apiRequest<{
      effectiveMarketStructureName: string
    }>('/me/settings')
    const fullName = settings.effectiveMarketStructureName
    if (fullName) {
      structureSystemName.value = fullName.split(' - ')[0] || fullName
    }
  } catch {
    // Non-blocking: badge simply won't show
  }
})

// When typeDetail is set, automatically switch to detail tab
watch(() => marketStore.typeDetail, (detail) => {
  if (detail) {
    activeTab.value = 'detail'
  }
})

// Sync URL query param when tab changes
watch(activeTab, (tab) => {
  const query = { ...route.query }
  if (tab === 'browse') {
    delete query.tab
  } else {
    query.tab = tab
  }
  router.replace({ query })
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

function handleFavoriteClick(typeId: number): void {
  selectItem(typeId)
}

function handleCloseDetail(): void {
  marketStore.clearDetail()
  activeTab.value = 'browse'
}

function selectItemFromAlerts(typeId: number): void {
  selectItem(typeId)
}

const categoryPillClasses = (groupId: number): string => {
  if (marketStore.includedGroupId === groupId) {
    return 'bg-cyan-500/20 text-cyan-300 ring-1 ring-cyan-500/40'
  }
  if (marketStore.excludedGroupIds.has(groupId)) {
    return 'bg-red-500/10 text-red-400/50 line-through'
  }
  return 'bg-slate-800/40 text-slate-500 hover:bg-slate-700/50 hover:text-slate-300'
}

const allButtonClasses = computed(() => {
  return (marketStore.includedGroupId !== null || marketStore.excludedGroupIds.size > 0)
    ? 'bg-cyan-500/20 text-cyan-300 ring-1 ring-cyan-500/40'
    : 'bg-slate-800/40 text-slate-500 hover:bg-slate-700/50 hover:text-slate-300'
})

const allButtonLabel = computed(() => {
  if (marketStore.includedGroupId !== null) {
    return t('market.categories.all')
  }
  if (marketStore.excludedGroupIds.size > 0) {
    return `${t('market.categories.all')} (${t('market.categories.hiddenCount', { count: marketStore.excludedGroupIds.size })})`
  }
  return t('market.categories.all')
})
</script>

<template>
  <MainLayout>
    <div class="space-y-0">

      <!-- Page Header -->
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-2xl font-bold text-slate-100">{{ t('market.title') }}</h1>
          <p class="text-sm text-slate-500 mt-1">{{ t('market.subtitle') }}</p>
        </div>
        <div class="flex items-center gap-3">
          <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg border bg-emerald-500/10 border-emerald-500/30 text-xs">
            <div class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></div>
            <span class="text-emerald-400">{{ t('market.syncJita') }}</span>
          </div>
          <div v-if="structureSystemName" class="flex items-center gap-2 px-3 py-1.5 rounded-lg border bg-cyan-500/10 border-cyan-500/30 text-xs">
            <div class="w-2 h-2 rounded-full bg-cyan-500 animate-pulse"></div>
            <span class="text-cyan-400">{{ structureSystemName }}</span>
          </div>
        </div>
      </div>

      <!-- Tab Navigation -->
      <div class="flex items-center gap-1 mb-6 border-b border-slate-800/50">
        <!-- Browse tab -->
        <button
          @click="activeTab = 'browse'"
          class="tab-btn px-5 py-3 text-sm font-medium transition-colors relative"
          :class="activeTab === 'browse' ? 'tab-active text-cyan-400' : 'text-slate-500 hover:text-slate-300'"
        >
          <span class="flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            {{ t('market.tabs.browse') }}
          </span>
        </button>

        <!-- Item Detail tab (dynamic, only visible when an item is selected) -->
        <button
          v-if="marketStore.typeDetail"
          @click="activeTab = 'detail'"
          class="tab-btn px-5 py-3 text-sm font-medium transition-colors relative"
          :class="activeTab === 'detail' ? 'tab-active text-cyan-400' : 'text-slate-500 hover:text-slate-300'"
        >
          <span class="flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            {{ marketStore.typeDetail.typeName }}
          </span>
        </button>

        <!-- Alerts tab -->
        <button
          @click="activeTab = 'alerts'"
          class="tab-btn px-5 py-3 text-sm font-medium transition-colors relative"
          :class="activeTab === 'alerts' ? 'tab-active text-cyan-400' : 'text-slate-500 hover:text-slate-300'"
        >
          <span class="flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
            {{ t('market.tabs.alerts') }}
            <span
              v-if="activeAlertsCount > 0"
              class="text-xs bg-amber-500/20 text-amber-400 px-1.5 py-0.5 rounded"
            >
              {{ activeAlertsCount }}
            </span>
          </span>
        </button>
      </div>

      <!-- Error banner -->
      <ErrorBanner v-if="marketStore.error" :message="marketStore.error" class="mb-6" @dismiss="marketStore.clearError()" />

      <!-- Browse Tab -->
      <div v-if="activeTab === 'browse'">
        <MarketSearch @select="handleSearchSelect" />

        <!-- Recent Searches + Favorites Row -->
        <div
          v-if="marketStore.recentSearches.length > 0 || marketStore.favorites.length > 0"
          class="flex items-start gap-6 mb-6 mt-5"
        >
          <!-- Recent Searches -->
          <div v-if="marketStore.recentSearches.length > 0" class="flex-1">
            <h3 class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-2">{{ t('market.recentSearches') }}</h3>
            <div class="flex flex-wrap gap-2">
              <span
                v-for="entry in marketStore.recentSearches"
                :key="entry.typeId"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-800/50 border border-slate-700/50 rounded-lg text-xs text-slate-300 hover:border-cyan-500/30 hover:text-cyan-400 cursor-pointer transition-colors group"
                @click="handleRecentSearchClick(entry.typeId)"
              >
                <img
                  :src="getTypeIconUrl(entry.typeId, 32)"
                  @error="onImageError"
                  class="w-4 h-4 rounded"
                  :alt="entry.typeName"
                  loading="lazy"
                />
                {{ entry.typeName }}
                <button
                  @click.stop="marketStore.removeRecentSearch(entry.typeId)"
                  class="ml-1 text-slate-600 hover:text-red-400 transition-colors"
                >&times;</button>
              </span>
            </div>
          </div>

          <!-- Favorites -->
          <div v-if="marketStore.favorites.length > 0" class="flex-1">
            <h3 class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-2">
              <span class="inline-flex items-center gap-1">
                <svg class="w-3.5 h-3.5 text-amber-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                {{ t('market.favorites') }}
              </span>
            </h3>
            <div class="flex flex-wrap gap-2">
              <span
                v-for="fav in marketStore.favorites"
                :key="fav.typeId"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-amber-500/5 border border-amber-500/20 rounded-lg text-xs text-amber-300 hover:border-amber-500/40 cursor-pointer transition-colors"
                @click="handleFavoriteClick(fav.typeId)"
              >
                <img
                  :src="getTypeIconUrl(fav.typeId, 32)"
                  @error="onImageError"
                  class="w-4 h-4 rounded"
                  :alt="fav.typeName"
                  loading="lazy"
                />
                {{ fav.typeName }}
              </span>
            </div>
          </div>
        </div>

        <!-- Category Filter Pills -->
        <div v-if="marketStore.rootGroups.length > 0" class="flex flex-wrap items-center gap-1.5 mb-5">
          <button
            class="shrink-0 px-2 py-1 rounded-md text-[11px] font-medium transition-colors"
            :class="allButtonClasses"
            @click="marketStore.resetGroupFilters()"
          >
            {{ allButtonLabel }}
          </button>
          <button
            v-for="group in marketStore.rootGroups"
            :key="group.id"
            class="shrink-0 px-2 py-1 rounded-md text-[11px] font-medium transition-colors whitespace-nowrap"
            :class="categoryPillClasses(group.id)"
            @click="marketStore.includedGroupId === group.id ? marketStore.setIncludedGroup(null) : marketStore.setIncludedGroup(group.id)"
            @contextmenu.prevent="marketStore.toggleGroupExclusion(group.id)"
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

        <!-- Empty state when no search results -->
        <div
          v-if="filteredSearchResults.length === 0 && marketStore.searchResults.length === 0"
          class="flex items-center justify-center py-16"
        >
          <div class="text-center">
            <svg class="w-16 h-16 text-slate-700 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
            </svg>
            <p class="text-slate-500 text-sm">{{ t('market.search.placeholder') }}</p>
          </div>
        </div>

        <!-- Loading overlay for search -->
        <div
          v-if="marketStore.isLoading"
          class="flex items-center justify-center py-8"
        >
          <LoadingSpinner size="md+" class="text-cyan-400" />
          <span class="ml-2 text-slate-400 text-sm">{{ t('common.actions.loading') }}</span>
        </div>
      </div>

      <!-- Detail Tab (full width) -->
      <div v-if="activeTab === 'detail' && marketStore.typeDetail">
        <MarketTypeDetail :detail="marketStore.typeDetail" @back="handleCloseDetail" />
      </div>

      <!-- Alerts Tab -->
      <div v-if="activeTab === 'alerts'">
        <AlertsPanel
          :alerts="marketStore.alerts"
          :structure-name="marketStore.typeDetail?.structureName"
          @delete="(id: string) => marketStore.deleteAlert(id)"
          @select-item="selectItemFromAlerts"
        />
      </div>

    </div>
  </MainLayout>
</template>

<style scoped>
.tab-active {
  position: relative;
}
.tab-active::after {
  content: '';
  position: absolute;
  bottom: -1px;
  left: 0;
  right: 0;
  height: 2px;
  background: linear-gradient(90deg, #22d3ee, #6366f1);
  border-radius: 1px;
}
</style>
