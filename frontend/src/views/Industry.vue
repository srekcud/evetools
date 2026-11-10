<script setup lang="ts">
import { computed, ref, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useTabQuery } from '@/composables/useTabQuery'
import { useIndustryStore } from '@/stores/industry'
import { useAdminStore } from '@/stores/admin'
import { DATA_FRESHNESS_SOURCES, useDataFreshnessStore } from '@/stores/dataFreshness'
import MainLayout from '@/layouts/MainLayout.vue'
import IndustryModuleTabs from '@/components/industry-module/IndustryModuleTabs.vue'
import MiningSubTabs from '@/components/mining-module/MiningSubTabs.vue'
import DataFreshnessList from '@/components/common/DataFreshnessList.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import IndustrySettings, { type IndustryConfigSection } from '@/components/industry/IndustrySettings.vue'
import IndustryDashboard from '@/components/industry/IndustryDashboard.vue'
import IndustryWorkshop from '@/components/industry/IndustryWorkshop.vue'
import IndustrySlotsSection from '@/components/industry/IndustrySlotsSection.vue'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import BatchScanTab from '@/components/industry/BatchScanTab.vue'
import BuyVsBuildTab from '@/components/industry/BuyVsBuildTab.vue'
import PivotAdvisorTab from '@/components/industry/PivotAdvisorTab.vue'
import StockpileDashboard from '@/components/industry/StockpileDashboard.vue'
import BlueprintLibraryTab from '@/components/industry/BlueprintLibraryTab.vue'

const props = defineProps<{
  page: 'workshop' | 'analysis' | 'production'
}>()

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useIndustryStore()
const adminStore = useAdminStore()
const dataFreshnessStore = useDataFreshnessStore()
const isAdmin = ref(false)

function typeIdFromQuery(): number | undefined {
  const queryTypeId = route.query.typeId
  return typeof queryTypeId === 'string' && /^\d+$/.test(queryTypeId) ? Number(queryTypeId) : undefined
}

const ANALYSIS_TABS = ['margins', 'batch', 'buy-vs-build', 'pivot']
const PRODUCTION_TABS = ['slots', 'stockpile', 'blueprints']
const analysisTab = useTabQuery(ANALYSIS_TABS, 'margins')
const productionTab = useTabQuery(PRODUCTION_TABS, 'slots')
const subTabIds = computed(() => props.page === 'analysis' ? ANALYSIS_TABS : PRODUCTION_TABS)

const activeSubTab = computed<string>({
  get: () => props.page === 'analysis' ? analysisTab.value : productionTab.value,
  set: (tab) => {
    if (props.page === 'analysis') {
      analysisTab.value = tab
    } else {
      productionTab.value = tab
    }
  },
})

const SUB_TAB_LABEL_KEYS: Record<string, string> = {
  margins: 'industry.tabs.margins',
  batch: 'industry.tabs.batch',
  'buy-vs-build': 'industry.tabs.buyVsBuild',
  pivot: 'industry.tabs.pivot',
  slots: 'industry.tabs.slots',
  stockpile: 'industry.tabs.stockpile',
  blueprints: 'industry.tabs.blueprints',
}

const subTabs = computed(() => subTabIds.value.map(id => ({ id, label: t(SUB_TAB_LABEL_KEYS[id]) })))

const analysisInitialTypeId = ref<number | undefined>(typeIdFromQuery())

watch(() => route.query.typeId, () => {
  analysisInitialTypeId.value = typeIdFromQuery()
})

// Config slide-over panel
const showConfigPanel = ref(false)
const expandedConfigSection = ref<IndustryConfigSection | null>('fees')

function onOpenConfigSection(section: IndustryConfigSection) {
  showConfigPanel.value = true
  expandedConfigSection.value = section
}

onMounted(async () => {
  dataFreshnessStore.fetchDataFreshness()
  isAdmin.value = await adminStore.checkAccess()
})

const ANALYSIS_PATH = '/industry/analysis'
const PRODUCTION_PATH = '/industry/production'

function pageLocationOf(path: string, tab: string, typeId?: number) {
  return { path, query: typeId === undefined ? { tab } : { tab, typeId: String(typeId) } }
}

// NavigationIntent watcher for cross-tab navigation
watch(() => store.navigationIntent, (intent) => {
  if (!intent) return

  switch (intent.target) {
    case 'projects':
      router.push({ name: 'industry-projects' })
      return
    case 'margins':
    case 'buy-vs-build':
    case 'pivot':
      router.push(pageLocationOf(ANALYSIS_PATH, intent.target, intent.typeId))
      break
    case 'batch':
      router.push(pageLocationOf(ANALYSIS_PATH, intent.target))
      break
    case 'slots':
    case 'stockpile':
      router.push(pageLocationOf(PRODUCTION_PATH, intent.target))
      break
  }

  store.navigationIntent = null
})
</script>

<template>
  <MainLayout>
      <IndustryModuleTabs :active="page" />

      <div class="mb-6 flex items-center justify-between">
        <p class="text-slate-400">{{ t('industry.subtitle') }}</p>

        <button
          class="gear-btn nav-pill-inactive p-2 rounded-lg"
          :title="t('industry.tabs.config')"
          @click="showConfigPanel = !showConfigPanel"
          :aria-label="t('industry.tabs.config')"
        >
          <svg aria-hidden="true" class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 010-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
        </button>
      </div>

      <ErrorBanner v-if="store.error" :message="store.error" class="mb-6" @dismiss="store.clearError()" />

      <template v-if="page === 'workshop'">
        <IndustryDashboard />
        <IndustryWorkshop />
      </template>

      <template v-else>
        <MiningSubTabs v-model="activeSubTab" :tabs="subTabs" />

        <DataFreshnessList
          v-if="page === 'analysis'"
          :sources="DATA_FRESHNESS_SOURCES"
          class="mb-4"
        />

        <ProfitMarginTab v-if="activeSubTab === 'margins'" :initial-type-id="analysisInitialTypeId" />
        <BatchScanTab v-else-if="activeSubTab === 'batch'" />
        <BuyVsBuildTab v-else-if="activeSubTab === 'buy-vs-build'" :initial-type-id="analysisInitialTypeId" />
        <PivotAdvisorTab v-else-if="activeSubTab === 'pivot'" :initial-type-id="analysisInitialTypeId" />
        <IndustrySlotsSection v-else-if="activeSubTab === 'slots'" @open-config="onOpenConfigSection" />
        <StockpileDashboard v-else-if="activeSubTab === 'stockpile'" />
        <BlueprintLibraryTab v-else-if="activeSubTab === 'blueprints'" />
      </template>

      <!-- Config Slide-Over Panel -->
      <Teleport to="body">
        <div
          v-if="showConfigPanel"
          class="fixed inset-0 z-50"
          role="presentation"
          @keydown.escape="showConfigPanel = false"
        >
          <!-- Backdrop -->
          <div class="absolute inset-0 bg-slate-950/60 backdrop-blur-sm" role="presentation" @click="showConfigPanel = false"></div>

          <!-- Panel -->
          <div
            class="absolute top-0 right-0 bottom-0 w-full max-w-md bg-slate-900 border-l border-slate-700 shadow-2xl animate-slide-in-right overflow-y-auto"
            role="dialog"
            aria-modal="true"
            :aria-label="t('industry.tabs.config')"
          >
            <!-- Header -->
            <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between sticky top-0 bg-slate-900 z-10">
              <h3 class="text-lg font-semibold text-white">{{ t('industry.tabs.config') }}</h3>
              <button
                @click="showConfigPanel = false"
                class="p-1.5 hover:bg-slate-800 rounded-lg text-slate-400 hover:text-slate-200 transition-colors"
                :aria-label="t('common.actions.close')"
              >
                <svg aria-hidden="true" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            <IndustrySettings v-model:expanded-section="expandedConfigSection" />
          </div>
        </div>
      </Teleport>
  </MainLayout>
</template>

<style scoped>
@keyframes slide-in-right {
  from { transform: translateX(100%); }
  to { transform: translateX(0); }
}
.animate-slide-in-right {
  animation: slide-in-right 0.25s cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards;
}

.nav-pill-inactive {
  background: rgba(30, 41, 59, 0.6);
  color: var(--color-muted);
  border: 1px solid rgba(51, 65, 85, 0.5);
  transition: all 0.2s ease;
}
.nav-pill-inactive:hover {
  background: rgba(30, 41, 59, 0.9);
  color: var(--color-chart-text);
  border-color: rgba(71, 85, 105, 0.8);
  transform: translateY(-1px);
}

/* Config gear rotation on hover */
.gear-btn svg {
  transition: transform 0.4s ease;
}
.gear-btn:hover svg {
  transform: rotate(90deg);
}
</style>
