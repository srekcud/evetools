<script setup lang="ts">
import { ref, onMounted, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useIndustryStore } from '@/stores/industry'
import { useTabQuery } from '@/composables/useTabQuery'
import { useAdminStore } from '@/stores/admin'
import { DATA_FRESHNESS_SOURCES, useDataFreshnessStore } from '@/stores/dataFreshness'
import MainLayout from '@/layouts/MainLayout.vue'
import DataFreshnessList from '@/components/common/DataFreshnessList.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import BlacklistConfig from '@/components/industry/BlacklistConfig.vue'
import StructureConfig from '@/components/industry/StructureConfig.vue'
import SkillsConfig from '@/components/industry/SkillsConfig.vue'
import FeesConfig from '@/components/industry/FeesConfig.vue'
import IndustryDashboard from '@/components/industry/IndustryDashboard.vue'
import IndustrySlotsSection from '@/components/industry/IndustrySlotsSection.vue'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import BatchScanTab from '@/components/industry/BatchScanTab.vue'
import BuyVsBuildTab from '@/components/industry/BuyVsBuildTab.vue'
import PivotAdvisorTab from '@/components/industry/PivotAdvisorTab.vue'
import StockpileDashboard from '@/components/industry/StockpileDashboard.vue'

const { t } = useI18n()
const maxJobDurationFieldId = useId()
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

// Flat pill navigation (single level)
type MainTab = 'dashboard' | 'margins' | 'batch' | 'buy-vs-build' | 'pivot' | 'slots' | 'stockpile'
const MAIN_TABS: MainTab[] = ['dashboard', 'margins', 'batch', 'buy-vs-build', 'pivot', 'slots', 'stockpile']
const mainTab = useTabQuery(MAIN_TABS, 'dashboard')
const DATA_FRESHNESS_TABS: readonly MainTab[] = ['margins', 'batch', 'buy-vs-build', 'pivot']

// Props for analysis tabs that accept an initial type ID
const analysisInitialTypeId = ref<number | undefined>(typeIdFromQuery())

watch(() => route.query.typeId, () => {
  analysisInitialTypeId.value = typeIdFromQuery()
})

async function selectPill(tab: MainTab) {
  analysisInitialTypeId.value = undefined
  if (route.query.typeId !== undefined) {
    const { typeId: _removedTypeId, ...remainingQuery } = route.query
    await router.replace({ query: remainingQuery })
  }
  mainTab.value = tab
}

// Config slide-over panel
const showConfigPanel = ref(false)
const expandedConfigSection = ref<'fees' | 'skills' | 'structures' | 'blacklist' | null>('fees')

function toggleConfigSection(section: 'fees' | 'skills' | 'structures' | 'blacklist') {
  expandedConfigSection.value = expandedConfigSection.value === section ? null : section
}

function onOpenConfigSection(section: 'fees' | 'skills' | 'structures' | 'blacklist') {
  showConfigPanel.value = true
  expandedConfigSection.value = section
}

// Navigation pill groups
type NavPill = { key: MainTab; label: () => string }
type NavGroup = { label: () => string; pills: NavPill[] }

const navGroups: NavGroup[] = [
  {
    label: () => t('industry.groups.overview'),
    pills: [
      { key: 'dashboard', label: () => t('industry.tabs.dashboard') },
    ],
  },
  {
    label: () => t('industry.groups.analysis'),
    pills: [
      { key: 'margins', label: () => t('industry.tabs.margins') },
      { key: 'batch', label: () => t('industry.tabs.batch') },
      { key: 'buy-vs-build', label: () => t('industry.tabs.buyVsBuild') },
      { key: 'pivot', label: () => t('industry.tabs.pivot') },
    ],
  },
  {
    label: () => t('industry.groups.operations'),
    pills: [
      { key: 'slots', label: () => t('industry.tabs.slots') },
      { key: 'stockpile', label: () => t('industry.tabs.stockpile') },
    ],
  },
]

onMounted(async () => {
  dataFreshnessStore.fetchDataFreshness()
  isAdmin.value = await adminStore.checkAccess()
})

// NavigationIntent watcher for cross-tab navigation
watch(() => store.navigationIntent, (intent) => {
  if (!intent) return

  if (intent.target === 'projects') {
    router.push('/group-industry')
    return
  }

  if (intent.target === 'margins') {
    mainTab.value = 'margins'
    analysisInitialTypeId.value = intent.typeId
  } else if (intent.target === 'batch') {
    mainTab.value = 'batch'
  } else if (intent.target === 'buy-vs-build') {
    mainTab.value = 'buy-vs-build'
    analysisInitialTypeId.value = intent.typeId
  } else if (intent.target === 'pivot') {
    mainTab.value = 'pivot'
    analysisInitialTypeId.value = intent.typeId
  } else if (intent.target === 'slots') {
    mainTab.value = 'slots'
  } else if (intent.target === 'stockpile') {
    mainTab.value = 'stockpile'
  }

  store.navigationIntent = null
})
</script>

<template>
  <MainLayout>
      <!-- Header -->
      <div class="mb-6 flex items-center justify-between">
        <p class="text-slate-400">{{ t('industry.subtitle') }}</p>
      </div>

      <!-- Flat pill navigation bar -->
      <div class="flex items-center gap-1 bg-slate-900/50 rounded-xl border border-slate-800/60 px-3 py-2.5 mb-6">
        <template v-for="(group, groupIndex) in navGroups" :key="groupIndex">
          <!-- Divider between groups -->
          <div v-if="groupIndex > 0" class="nav-divider"></div>

          <!-- Group with label -->
          <div class="relative flex items-center gap-1" :class="groupIndex === 0 ? 'pr-1' : 'px-1'">
            <span class="group-label">{{ group.label() }}</span>
            <button
              v-for="pill in group.pills"
              :key="pill.key"
              @click="selectPill(pill.key)"
              :class="[
                'px-3.5 py-1.5 rounded-lg text-sm font-medium transition-all duration-200',
                mainTab === pill.key
                  ? 'nav-pill-active'
                  : 'nav-pill-inactive',
              ]"
            >
              {{ pill.label() }}
            </button>
          </div>
        </template>

        <!-- Spacer -->
        <div class="flex-1"></div>

        <!-- Config gear button -->
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

      <!-- Error -->
      <ErrorBanner v-if="store.error" :message="store.error" class="mb-6" @dismiss="store.clearError()" />

      <DataFreshnessList
        v-if="DATA_FRESHNESS_TABS.includes(mainTab)"
        :sources="DATA_FRESHNESS_SOURCES"
        class="mb-4"
      />

      <!-- Dashboard -->
      <div v-if="mainTab === 'dashboard'" key="dashboard">
        <IndustryDashboard />
      </div>

      <!-- Margins -->
      <div v-else-if="mainTab === 'margins'" key="margins">
        <ProfitMarginTab :initial-type-id="analysisInitialTypeId" />
      </div>

      <!-- Batch Scan -->
      <div v-else-if="mainTab === 'batch'" key="batch">
        <BatchScanTab />
      </div>

      <!-- Buy vs Build -->
      <div v-else-if="mainTab === 'buy-vs-build'" key="buy-vs-build">
        <BuyVsBuildTab :initial-type-id="analysisInitialTypeId" />
      </div>

      <!-- Pivot -->
      <div v-else-if="mainTab === 'pivot'" key="pivot">
        <PivotAdvisorTab :initial-type-id="analysisInitialTypeId" />
      </div>

      <!-- Slots -->
      <div v-else-if="mainTab === 'slots'" key="slots">
        <IndustrySlotsSection @open-config="onOpenConfigSection" />
      </div>

      <!-- Stockpile -->
      <div v-else-if="mainTab === 'stockpile'" key="stockpile">
        <StockpileDashboard />
      </div>

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

            <!-- Max job duration setting -->
            <div class="px-6 py-4 border-b border-slate-800">
              <label :for="maxJobDurationFieldId" class="block text-xs text-slate-500 mb-1.5">{{ t('industry.config.maxJobDuration') }}</label>
              <div class="flex items-center gap-3">
                <input
                  :id="maxJobDurationFieldId"
                  :value="store.defaultMaxJobDurationDays.toFixed(1)"
                  @change="(e) => store.setDefaultMaxJobDurationDays(parseFloat((e.target as HTMLInputElement).value) || 2.0)"
                  type="number"
                  min="0.5"
                  step="0.1"
                  class="w-32 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm focus:outline-hidden focus:border-cyan-500"
                />
                <p class="text-xs text-slate-500">{{ t('industry.config.maxJobDurationHint') }}</p>
              </div>
            </div>

            <!-- Accordion sections -->
            <div class="divide-y divide-slate-800">
              <!-- Fees -->
              <div>
                <button
                  @click="toggleConfigSection('fees')"
                  class="w-full px-6 py-3.5 flex items-center justify-between hover:bg-slate-800/50 transition-colors"
                >
                  <span class="text-sm font-medium text-slate-200">{{ t('industry.configTabs.fees') }}</span>
                  <svg
                    class="w-4 h-4 text-slate-400 transition-transform duration-200"
                    :class="{ 'rotate-180': expandedConfigSection === 'fees' }"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                  </svg>
                </button>
                <div v-if="expandedConfigSection === 'fees'" class="px-6 pb-4">
                  <FeesConfig />
                </div>
              </div>

              <!-- Skills -->
              <div>
                <button
                  @click="toggleConfigSection('skills')"
                  class="w-full px-6 py-3.5 flex items-center justify-between hover:bg-slate-800/50 transition-colors"
                >
                  <span class="text-sm font-medium text-slate-200">{{ t('industry.configTabs.skills') }}</span>
                  <svg
                    class="w-4 h-4 text-slate-400 transition-transform duration-200"
                    :class="{ 'rotate-180': expandedConfigSection === 'skills' }"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                  </svg>
                </button>
                <div v-if="expandedConfigSection === 'skills'" class="px-6 pb-4">
                  <SkillsConfig />
                </div>
              </div>

              <!-- Structures -->
              <div>
                <button
                  @click="toggleConfigSection('structures')"
                  class="w-full px-6 py-3.5 flex items-center justify-between hover:bg-slate-800/50 transition-colors"
                >
                  <span class="text-sm font-medium text-slate-200">{{ t('industry.configTabs.structures') }}</span>
                  <svg
                    class="w-4 h-4 text-slate-400 transition-transform duration-200"
                    :class="{ 'rotate-180': expandedConfigSection === 'structures' }"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                  </svg>
                </button>
                <div v-if="expandedConfigSection === 'structures'" class="px-6 pb-4">
                  <StructureConfig />
                </div>
              </div>

              <!-- Blacklist -->
              <div>
                <button
                  @click="toggleConfigSection('blacklist')"
                  class="w-full px-6 py-3.5 flex items-center justify-between hover:bg-slate-800/50 transition-colors"
                >
                  <span class="text-sm font-medium text-slate-200">{{ t('industry.configTabs.blacklist') }}</span>
                  <svg
                    class="w-4 h-4 text-slate-400 transition-transform duration-200"
                    :class="{ 'rotate-180': expandedConfigSection === 'blacklist' }"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                  </svg>
                </button>
                <div v-if="expandedConfigSection === 'blacklist'" class="px-6 pb-4">
                  <BlacklistConfig />
                </div>
              </div>
            </div>
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

/* Nav pill active state with glow */
.nav-pill-active {
  background: linear-gradient(135deg, var(--color-brand-strong), var(--color-brand-deep));
  color: white;
  box-shadow: 0 0 12px rgba(8, 145, 178, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.1);
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

/* Group label */
.group-label {
  font-size: 9px;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: var(--color-subtle);
  position: absolute;
  top: -14px;
  left: 4px;
  white-space: nowrap;
}

/* Divider between groups */
.nav-divider {
  width: 1px;
  height: 24px;
  background: linear-gradient(180deg, transparent, var(--color-chart-grid) 30%, var(--color-chart-grid) 70%, transparent);
  margin: 0 6px;
  align-self: center;
}

/* Config gear rotation on hover */
.gear-btn svg {
  transition: transform 0.4s ease;
}
.gear-btn:hover svg {
  transform: rotate(90deg);
}
</style>
