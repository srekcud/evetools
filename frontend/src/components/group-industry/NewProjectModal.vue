<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { useProjectsStore } from '@/stores/industry/projects'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { SearchResult } from '@/stores/industry/types'
import type { CreateGroupProjectInput } from '@/stores/group-industry/types'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

const { t } = useI18n()
const { getTypeIconUrl, onImageError } = useEveImages()
const projectsStore = useProjectsStore()
const groupProjectStore = useGroupProjectStore()

const props = defineProps<{
  show: boolean
}>()

const emit = defineEmits<{
  close: []
  created: []
}>()

// --- Form state ---

type AddMode = 'add-item' | 'bulk-paste'
const addMode = ref<AddMode>('add-item')

// Single item add fields
const searchQuery = ref('')
const selectedItem = ref<SearchResult | null>(null)
const showDropdown = ref(false)
let searchTimeout: ReturnType<typeof setTimeout> | null = null
const itemMe = ref(2)
const itemTe = ref(4)
const itemRuns = ref(100)

// --- Search logic ---

function onSearchInput() {
  // Clear previous selection when user types
  selectedItem.value = null
  if (searchTimeout) clearTimeout(searchTimeout)
  if (searchQuery.value.length < 2) {
    projectsStore.searchResults = []
    showDropdown.value = false
    return
  }
  searchTimeout = setTimeout(async () => {
    await projectsStore.searchProducts(searchQuery.value)
    showDropdown.value = projectsStore.searchResults.length > 0
  }, 300)
}

function selectSearchResult(result: SearchResult) {
  selectedItem.value = result
  searchQuery.value = result.typeName
  showDropdown.value = false
  projectsStore.searchResults = []
}

function onSearchBlur() {
  // Delay to allow mousedown on dropdown items to register first
  setTimeout(() => {
    showDropdown.value = false
  }, 200)
}

function onSearchFocus() {
  if (projectsStore.searchResults.length > 0 && !selectedItem.value) {
    showDropdown.value = true
  }
}

function addSelectedItem() {
  if (!selectedItem.value) return
  // Prevent duplicates
  const alreadyExists = items.value.some(item => item.typeId === selectedItem.value!.typeId)
  if (alreadyExists) {
    // Clear and ignore silently
    searchQuery.value = ''
    selectedItem.value = null
    return
  }
  items.value.push({
    typeId: selectedItem.value.typeId,
    typeName: selectedItem.value.typeName,
    meLevel: itemMe.value,
    teLevel: itemTe.value,
    runs: itemRuns.value,
  })
  // Reset search state for next item
  searchQuery.value = ''
  selectedItem.value = null
  projectsStore.searchResults = []
}

// Bulk paste
const bulkText = ref('')

// Items list
type ProjectItem = {
  typeId: number
  typeName: string
  meLevel: number
  teLevel: number
  runs: number
}
const items = ref<ProjectItem[]>([])

// Project metadata
const projectName = ref('')
const containerName = ref('')

// Blacklist categories
type BlacklistCategory = {
  id: string
  label: string
  checked: boolean
}
const blacklistCategories = ref<BlacklistCategory[]>([
  { id: 'advanced_components', label: 'Advanced Components', checked: false },
  { id: 'capital_components', label: 'Capital Components', checked: false },
  { id: 'advanced_capital_components', label: 'Advanced Capital Components', checked: false },
  { id: 'hybrid_components', label: 'Hybrid Components', checked: false },
  { id: 'fuel_blocks', label: 'Fuel Blocks', checked: false },
  { id: 'tools', label: 'Tools (R.A.M.)', checked: false },
  { id: 'simple_reactions', label: 'Simple Reactions', checked: false },
  { id: 'complex_reactions', label: 'Complex Reactions', checked: false },
  { id: 'hybrid_reactions', label: 'Hybrid Reactions', checked: false },
  { id: 'biochem_reactions', label: 'Biochemical Reactions', checked: false },
])

// Individual blacklist items
const blacklistItemSearch = ref('')
const blacklistItems = ref<{ typeId: number; typeName: string }[]>([])

// Line rental override
const showLineRentalOverride = ref(false)
const lineRentalOverrideEnabled = ref(false)

type LineRentalRate = {
  key: string
  label: string
  value: number
  unit: string
}
const lineRentalRates = ref<LineRentalRate[]>([
  { key: 'component_t1', label: 'Component T1', value: 250, unit: 'ISK/run' },
  { key: 'module_t1', label: 'Module T1', value: 10_000, unit: 'ISK/run' },
  { key: 'ship_t1', label: 'Ship T1', value: 100_000, unit: 'ISK/run' },
  { key: 'module_t2', label: 'Module T2', value: 100_000, unit: 'ISK/run' },
  { key: 'ship_t2', label: 'Ship T2', value: 2_500_000, unit: 'ISK/run' },
  { key: 'capital_t1', label: 'Capital T1', value: 25_000, unit: 'ISK/run' },
  { key: 'capital_t2', label: 'Capital T2', value: 125_000, unit: 'ISK/run' },
  { key: 'location_factor', label: 'Location Factor', value: 1_000_000, unit: 'ISK/day' },
])

function toggleLineRentalOverride() {
  showLineRentalOverride.value = !showLineRentalOverride.value
  if (showLineRentalOverride.value && !lineRentalOverrideEnabled.value) {
    lineRentalOverrideEnabled.value = true
  }
}

// Submission
const isCreating = ref(false)
const errorMsg = ref<string | null>(null)

// Computed
const totalRuns = computed(() =>
  items.value.reduce((sum, item) => sum + item.runs, 0)
)

// Reset form when modal opens
watch(() => props.show, (newVal) => {
  if (newVal) resetForm()
})

function resetForm() {
  addMode.value = 'add-item'
  searchQuery.value = ''
  selectedItem.value = null
  showDropdown.value = false
  projectsStore.searchResults = []
  if (searchTimeout) clearTimeout(searchTimeout)
  itemMe.value = 2
  itemTe.value = 4
  itemRuns.value = 100
  bulkText.value = ''
  items.value = []
  projectName.value = ''
  containerName.value = ''
  blacklistItemSearch.value = ''
  blacklistItems.value = []
  showLineRentalOverride.value = false
  lineRentalOverrideEnabled.value = false
  lineRentalRates.value.forEach(r => {
    if (r.key === 'component_t1') r.value = 250
    else if (r.key === 'module_t1') r.value = 10_000
    else if (r.key === 'ship_t1') r.value = 100_000
    else if (r.key === 'module_t2') r.value = 100_000
    else if (r.key === 'ship_t2') r.value = 2_500_000
    else if (r.key === 'capital_t1') r.value = 25_000
    else if (r.key === 'capital_t2') r.value = 125_000
    else if (r.key === 'location_factor') r.value = 1_000_000
  })
  isCreating.value = false
  errorMsg.value = null
  blacklistCategories.value.forEach(cat => {
    cat.checked = false
  })
}

function removeItem(index: number) {
  items.value.splice(index, 1)
}

function removeBlacklistItem(index: number) {
  blacklistItems.value.splice(index, 1)
}

function parseBulkText() {
  const lines = bulkText.value.trim().split('\n').filter(line => line.trim())
  const parsed: ProjectItem[] = []

  for (const line of lines) {
    // Format: ItemName ME TE xRuns  or  ItemName ME TE Runs
    const match = line.match(/^(.+?)\s+(\d+)\s+(\d+)\s+x?(\d+)$/i)
    if (match) {
      parsed.push({
        typeId: 0, // Will be resolved by the store/API
        typeName: match[1].trim(),
        meLevel: parseInt(match[2], 10),
        teLevel: parseInt(match[3], 10),
        runs: parseInt(match[4], 10),
      })
    }
  }

  if (parsed.length > 0) {
    items.value.push(...parsed)
    bulkText.value = ''
  }
}

async function handleCreate() {
  if (items.value.length === 0) return
  isCreating.value = true
  errorMsg.value = null

  const input: CreateGroupProjectInput = {
    name: projectName.value.trim() || undefined,
    items: items.value.map(i => ({
      typeId: i.typeId,
      typeName: i.typeName,
      meLevel: i.meLevel,
      teLevel: i.teLevel,
      runs: i.runs,
    })),
    containerName: containerName.value.trim() || undefined,
    blacklistCategoryKeys: blacklistCategories.value
      .filter(cat => cat.checked)
      .map(cat => cat.id),
    blacklistTypeIds: blacklistItems.value.map(i => i.typeId),
    lineRentalRatesOverride: lineRentalOverrideEnabled.value
      ? Object.fromEntries(lineRentalRates.value.map(r => [r.key, r.value]))
      : undefined,
  }

  try {
    await groupProjectStore.createProject(input)
    emit('created')
  } catch (e) {
    errorMsg.value = e instanceof Error ? e.message : 'Failed to create project'
    isCreating.value = false
  }
}

function handleClose() {
  emit('close')
}

function handleOverlayClick() {
  emit('close')
}
</script>

<template>
  <Teleport to="body">
    <div v-if="show" class="fixed inset-0 z-50">
      <div class="modal-overlay absolute inset-0 bg-black/70 backdrop-blur-sm" @click="handleOverlayClick"></div>
      <div class="relative flex items-center justify-center min-h-screen p-4 pointer-events-none">
        <div class="modal-content bg-slate-900 border border-slate-700 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto pointer-events-auto">

          <!-- Modal Header -->
          <div class="px-6 py-5 border-b border-slate-800 flex items-center justify-between">
            <div>
              <h2 class="text-lg font-bold text-slate-100">{{ t('groupIndustry.modals.newProject.title') }}</h2>
              <p class="text-sm text-slate-500 mt-0.5">{{ t('groupIndustry.modals.newProject.subtitle') }}</p>
            </div>
            <button
              class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 flex items-center justify-center text-slate-400 hover:text-slate-200 transition-colors"
              @click="handleClose"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <!-- Modal Body -->
          <div class="px-6 py-5 space-y-5">

            <!-- Add Items Section -->
            <div>
              <label class="text-sm font-medium text-slate-300 mb-3 block">{{ t('groupIndustry.modals.newProject.itemsLabel') }}</label>

              <!-- Mode Toggle Tabs -->
              <div class="flex gap-1 mb-3 bg-slate-800/50 rounded-lg p-0.5 w-fit">
                <button
                  class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors"
                  :class="addMode === 'add-item' ? 'bg-cyan-500/15 text-cyan-400' : 'text-slate-500 hover:text-slate-300'"
                  @click="addMode = 'add-item'"
                >
                  {{ t('groupIndustry.modals.newProject.addItem') }}
                </button>
                <button
                  class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors"
                  :class="addMode === 'bulk-paste' ? 'bg-cyan-500/15 text-cyan-400' : 'text-slate-500 hover:text-slate-300'"
                  @click="addMode = 'bulk-paste'"
                >
                  {{ t('groupIndustry.modals.newProject.bulkPaste') }}
                </button>
              </div>

              <!-- Mode A: Add single item -->
              <div v-if="addMode === 'add-item'">
                <div class="flex gap-2 items-start">
                  <div class="flex-1 relative">
                    <input
                      v-model="searchQuery"
                      type="text"
                      :placeholder="t('groupIndustry.modals.newProject.searchPlaceholder')"
                      class="form-input w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2.5 text-sm text-slate-200 placeholder-slate-600"
                      @input="onSearchInput"
                      @focus="onSearchFocus"
                      @blur="onSearchBlur"
                    />
                    <svg class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <!-- Search results dropdown -->
                    <div
                      v-if="showDropdown && projectsStore.searchResults.length > 0"
                      class="absolute z-20 w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg shadow-xl max-h-60 overflow-y-auto"
                    >
                      <button
                        v-for="result in projectsStore.searchResults"
                        :key="result.typeId"
                        class="w-full px-4 py-2.5 text-left hover:bg-slate-700 text-sm flex items-center gap-3 transition-colors"
                        @mousedown.prevent="selectSearchResult(result)"
                      >
                        <img
                          :src="getTypeIconUrl(result.typeId, 32)"
                          class="w-6 h-6 rounded-sm"
                          @error="onImageError"
                        />
                        <span class="text-slate-200">{{ result.typeName }}</span>
                        <span
                          v-if="result.isT2"
                          class="text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 bg-blue-500/20 text-blue-400 rounded-sm"
                        >T2</span>
                      </button>
                    </div>
                  </div>
                  <input
                    v-model.number="itemMe"
                    type="number"
                    min="0"
                    max="10"
                    placeholder="ME"
                    title="ME"
                    class="form-input w-16 bg-slate-800 border border-slate-700 rounded-lg px-2.5 py-2.5 text-sm text-slate-200 font-mono text-center"
                  />
                  <input
                    v-model.number="itemTe"
                    type="number"
                    min="0"
                    max="20"
                    placeholder="TE"
                    title="TE"
                    class="form-input w-16 bg-slate-800 border border-slate-700 rounded-lg px-2.5 py-2.5 text-sm text-slate-200 font-mono text-center"
                  />
                  <input
                    v-model.number="itemRuns"
                    type="number"
                    min="1"
                    placeholder="Runs"
                    title="Runs"
                    class="form-input w-20 bg-slate-800 border border-slate-700 rounded-lg px-2.5 py-2.5 text-sm text-slate-200 font-mono text-center"
                  />
                  <button
                    class="btn-action px-3 py-2.5 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex-shrink-0 whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed"
                    :disabled="!selectedItem"
                    @click="addSelectedItem"
                  >
                    {{ t('groupIndustry.modals.newProject.addToList') }}
                  </button>
                </div>
                <div class="flex gap-10 mt-1.5 ml-0 text-[10px] text-slate-600 uppercase tracking-wider">
                  <span class="flex-1 pl-4">{{ t('groupIndustry.modals.newProject.columnItem') }}</span>
                  <span class="w-16 text-center">ME</span>
                  <span class="w-16 text-center">TE</span>
                  <span class="w-20 text-center">{{ t('groupIndustry.modals.newProject.columnRuns') }}</span>
                  <span class="w-[88px]"></span>
                </div>
              </div>

              <!-- Mode B: Bulk Paste -->
              <div v-else>
                <textarea
                  v-model="bulkText"
                  rows="5"
                  :placeholder="t('groupIndustry.modals.newProject.bulkPlaceholder')"
                  class="form-input w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-3 text-sm text-slate-200 font-mono placeholder-slate-600 resize-none"
                  style="font-variant-numeric: tabular-nums;"
                ></textarea>
                <p class="text-xs text-slate-600 mt-1.5">
                  {{ t('groupIndustry.modals.newProject.bulkFormat') }}
                  <span class="font-mono text-slate-500">ItemName ME TE xRuns</span>
                </p>
                <button
                  class="btn-action mt-2 px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2"
                  :disabled="!bulkText.trim()"
                  @click="parseBulkText"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                  </svg>
                  {{ t('groupIndustry.modals.newProject.parseAndAdd') }}
                </button>
              </div>
            </div>

            <!-- Items Table -->
            <div>
              <!-- Empty state -->
              <div v-if="items.length === 0" class="bg-slate-800/30 rounded-xl border border-dashed border-slate-700 p-8 text-center">
                <svg class="w-8 h-8 mx-auto text-slate-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                </svg>
                <p class="text-sm text-slate-500">{{ t('groupIndustry.modals.newProject.noItems') }}</p>
                <p class="text-xs text-slate-600 mt-1">{{ t('groupIndustry.modals.newProject.noItemsHint') }}</p>
              </div>

              <!-- Filled table -->
              <div v-else class="bg-slate-800/30 rounded-xl border border-slate-700 overflow-hidden">
                <table class="w-full text-sm">
                  <thead>
                    <tr class="text-xs text-slate-500 uppercase tracking-wider border-b border-slate-700">
                      <th class="text-left py-2 px-3 w-8"></th>
                      <th class="text-left py-2 px-3">{{ t('groupIndustry.modals.newProject.columnItem') }}</th>
                      <th class="text-center py-2 px-2 w-16">ME</th>
                      <th class="text-center py-2 px-2 w-16">TE</th>
                      <th class="text-center py-2 px-2 w-20">{{ t('groupIndustry.modals.newProject.columnRuns') }}</th>
                      <th class="text-center py-2 px-3 w-10"></th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-700/50">
                    <tr
                      v-for="(item, index) in items"
                      :key="`${item.typeName}-${index}`"
                      class="hover:bg-slate-800/30"
                    >
                      <td class="py-2 px-3">
                        <div class="w-8 h-8 rounded bg-slate-800 border border-slate-700 overflow-hidden">
                          <img
                            v-if="item.typeId > 0"
                            :src="getTypeIconUrl(item.typeId, 32)"
                            :alt="item.typeName"
                            class="w-full h-full"
                            @error="onImageError"
                          />
                        </div>
                      </td>
                      <td class="py-2 px-3 text-slate-200">{{ item.typeName }}</td>
                      <td class="py-2 px-2 text-center">
                        <input
                          v-model.number="item.meLevel"
                          type="number"
                          min="0"
                          max="10"
                          class="w-12 bg-slate-900 border border-slate-700 rounded px-1.5 py-1 text-xs text-slate-200 font-mono text-center focus:border-cyan-500 focus:outline-none"
                        />
                      </td>
                      <td class="py-2 px-2 text-center">
                        <input
                          v-model.number="item.teLevel"
                          type="number"
                          min="0"
                          max="20"
                          class="w-12 bg-slate-900 border border-slate-700 rounded px-1.5 py-1 text-xs text-slate-200 font-mono text-center focus:border-cyan-500 focus:outline-none"
                        />
                      </td>
                      <td class="py-2 px-2 text-center">
                        <input
                          v-model.number="item.runs"
                          type="number"
                          min="1"
                          class="w-16 bg-slate-900 border border-slate-700 rounded px-1.5 py-1 text-xs text-slate-200 font-mono text-center focus:border-cyan-500 focus:outline-none"
                          style="font-variant-numeric: tabular-nums;"
                        />
                      </td>
                      <td class="py-2 px-3 text-center">
                        <button
                          class="text-slate-600 hover:text-red-400 transition-colors"
                          :title="t('common.actions.delete')"
                          @click="removeItem(index)"
                        >
                          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                          </svg>
                        </button>
                      </td>
                    </tr>
                  </tbody>
                </table>
                <!-- Table Footer -->
                <div class="px-3 py-2 border-t border-slate-700 flex items-center justify-between">
                  <span class="text-xs text-slate-400 font-medium">
                    {{ items.length }} {{ items.length === 1 ? 'item' : 'items' }} &middot; {{ totalRuns }} {{ t('groupIndustry.modals.newProject.totalRuns') }}
                  </span>
                </div>
              </div>
            </div>

            <!-- Name (optional) -->
            <div>
              <label class="text-sm font-medium text-slate-300 mb-1.5 block">
                {{ t('groupIndustry.modals.newProject.nameLabel') }}
                <span class="text-slate-600 font-normal">({{ t('groupIndustry.modals.newProject.optional') }})</span>
              </label>
              <input
                v-model="projectName"
                type="text"
                :placeholder="t('groupIndustry.modals.newProject.namePlaceholder')"
                class="form-input w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2.5 text-sm text-slate-200 placeholder-slate-600"
              />
            </div>

            <!-- Production Blacklist -->
            <div>
              <label class="text-sm font-medium text-slate-300 mb-1 block">{{ t('groupIndustry.modals.newProject.blacklistLabel') }}</label>
              <p class="text-xs text-slate-500 mb-3">{{ t('groupIndustry.modals.newProject.blacklistDesc') }}</p>

              <!-- Predefined categories -->
              <div class="grid grid-cols-2 gap-x-6 gap-y-2 mb-4">
                <label
                  v-for="cat in blacklistCategories"
                  :key="cat.id"
                  class="flex items-center gap-2.5 cursor-pointer group"
                >
                  <input
                    v-model="cat.checked"
                    type="checkbox"
                    class="w-3.5 h-3.5 rounded border-slate-600 bg-slate-800 accent-cyan-500 cursor-pointer"
                  />
                  <span
                    class="text-sm group-hover:text-slate-100"
                    :class="cat.checked ? 'text-slate-200' : 'text-slate-400 group-hover:text-slate-300'"
                  >
                    {{ cat.label }}
                  </span>
                </label>
              </div>

              <!-- Individual items search -->
              <div class="relative mb-2">
                <input
                  v-model="blacklistItemSearch"
                  type="text"
                  :placeholder="t('groupIndustry.modals.newProject.blacklistItemPlaceholder')"
                  class="form-input w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2 text-sm text-slate-200 placeholder-slate-600"
                />
                <svg class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
              </div>
              <div v-if="blacklistItems.length > 0" class="flex flex-wrap gap-2 mb-2">
                <span
                  v-for="(item, index) in blacklistItems"
                  :key="item.typeId"
                  class="inline-flex items-center gap-1.5 bg-slate-800 border border-slate-700 rounded-lg px-2 py-1 text-xs text-slate-300"
                >
                  {{ item.typeName }}
                  <button
                    class="text-slate-500 hover:text-red-400 transition-colors"
                    @click="removeBlacklistItem(index)"
                  >
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </span>
              </div>
              <p class="text-xs text-slate-600">{{ t('groupIndustry.modals.newProject.blacklistHint') }}</p>
            </div>

            <!-- Corp Container (Optional) -->
            <div>
              <label class="text-sm font-medium text-slate-300 mb-1.5 block">
                {{ t('groupIndustry.modals.newProject.containerLabel') }}
                <span class="text-slate-600 font-normal">({{ t('groupIndustry.modals.newProject.optional') }})</span>
              </label>
              <input
                v-model="containerName"
                type="text"
                :placeholder="t('groupIndustry.modals.newProject.containerPlaceholder')"
                class="form-input w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2.5 text-sm text-slate-200 placeholder-slate-600"
              />
              <p class="text-xs text-slate-500 mt-1.5">{{ t('groupIndustry.modals.newProject.containerHint') }}</p>
            </div>

            <!-- Line Rental Rates -->
            <div>
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                  <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <span class="text-sm text-slate-400">
                    {{ t('groupIndustry.modals.newProject.lineRentalInfo') }}
                    <span v-if="!lineRentalOverrideEnabled" class="text-cyan-400">{{ t('groupIndustry.modals.newProject.lineRentalDefault') }}</span>
                    <span v-else class="text-amber-400">{{ t('groupIndustry.modals.newProject.lineRentalCustom') }}</span>
                  </span>
                </div>
                <button
                  type="button"
                  class="text-xs text-cyan-400 hover:text-cyan-300 transition-colors flex items-center gap-1"
                  @click="toggleLineRentalOverride"
                >
                  <svg
                    class="w-3.5 h-3.5 transition-transform duration-200"
                    :class="{ 'rotate-180': showLineRentalOverride }"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                  </svg>
                  {{ showLineRentalOverride ? t('groupIndustry.modals.newProject.collapse') : t('groupIndustry.modals.newProject.overrideForProject') }}
                </button>
              </div>

              <!-- Collapsible override table -->
              <div
                v-if="showLineRentalOverride"
                class="mt-3 bg-slate-800/50 rounded-xl border border-slate-700/50 overflow-hidden"
              >
                <table class="w-full text-sm">
                  <thead>
                    <tr class="text-xs text-slate-500 uppercase tracking-wider border-b border-slate-700">
                      <th class="text-left py-2.5 px-4">{{ t('groupIndustry.modals.lineRental.category') }}</th>
                      <th class="text-right py-2.5 px-4">{{ t('groupIndustry.modals.lineRental.rate') }}</th>
                      <th class="text-left py-2.5 px-4">{{ t('groupIndustry.modals.lineRental.unit') }}</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-700/50">
                    <tr
                      v-for="rate in lineRentalRates"
                      :key="rate.key"
                      class="hover:bg-slate-800/30"
                      :class="{ 'border-t-2 border-slate-600': rate.key === 'location_factor' }"
                    >
                      <td class="py-2 px-4 text-slate-300">{{ rate.label }}</td>
                      <td class="py-2 px-4 text-right">
                        <input
                          v-model.number="rate.value"
                          type="text"
                          class="w-24 bg-slate-900 border border-slate-700 rounded px-2.5 py-1 text-sm text-slate-200 font-mono text-right focus:border-cyan-500 focus:outline-none"
                          style="font-variant-numeric: tabular-nums;"
                        />
                      </td>
                      <td class="py-2 px-4 text-xs text-slate-500">{{ rate.unit }}</td>
                    </tr>
                  </tbody>
                </table>
                <div class="px-4 py-2 border-t border-slate-700/50">
                  <p class="text-xs text-slate-600">{{ t('groupIndustry.modals.newProject.lineRentalOverrideHint') }}</p>
                </div>
              </div>
            </div>

          </div>

          <!-- Modal Footer -->
          <div class="px-6 py-4 border-t border-slate-800">
            <p v-if="errorMsg" class="text-sm text-red-400 mb-3">{{ errorMsg }}</p>
            <p class="text-xs text-slate-500 mb-3">{{ t('groupIndustry.modals.newProject.bomNote') }}</p>
            <div class="flex items-center justify-end gap-3">
              <button
                class="px-4 py-2.5 bg-slate-800 hover:bg-slate-700 rounded-lg text-slate-300 text-sm font-medium transition-colors"
                @click="handleClose"
              >
                {{ t('common.actions.cancel') }}
              </button>
              <button
                class="btn-action px-6 py-2.5 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
                :disabled="items.length === 0 || isCreating"
                @click="handleCreate"
              >
                <LoadingSpinner v-if="isCreating" size="sm" />
                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                {{ t('groupIndustry.modals.newProject.createButton') }}
              </button>
            </div>
          </div>

        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
@keyframes modalFadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
@keyframes modalSlideUp {
  from { opacity: 0; transform: translateY(24px) scale(0.97); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}
.modal-overlay {
  animation: modalFadeIn 0.2s ease-out;
}
.modal-content {
  animation: modalSlideUp 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
}

.form-input {
  transition: border-color 0.15s ease;
}
.form-input:focus {
  border-color: #0ea5e9;
  outline: none;
  box-shadow: 0 0 0 2px rgba(14, 165, 233, 0.1);
}

.btn-action {
  transition: all 0.15s ease;
}
.btn-action:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}
.btn-action:active:not(:disabled) {
  transform: translateY(0);
}
</style>
