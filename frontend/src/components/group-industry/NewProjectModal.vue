<script setup lang="ts">
import DataTable from '@/components/common/DataTable.vue'
import { ref, computed, watch, useId, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { DEFAULT_MINIMUM_OUTSIDE_SAVING, useProjectsStore } from '@/stores/industry/projects'
import { useBlacklistStore } from '@/stores/industry/blacklist'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useGroupProjectReferencesStore } from '@/stores/group-industry/projectReferences'
import { useFormatters } from '@/composables/useFormatters'
import type { ProjectPrefill, ProjectPrefillItem, SearchResult } from '@/stores/industry/types'
import type { CreateGroupProjectInput, GroupProject, ProjectReferenceRow, ProjectTemplate } from '@/stores/group-industry/types'
import BaseModal from '@/components/common/BaseModal.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import ProjectTemplatesPanel from '@/components/group-industry/ProjectTemplatesPanel.vue'
import SellStructureSelect from '@/components/group-industry/SellStructureSelect.vue'
import { containerNamesFromText } from '@/utils/containerNames'
import {
  MAXIMUM_MATERIAL_EFFICIENCY,
  MAXIMUM_BLUEPRINT_COPIES,
  MAXIMUM_RUNS,
  MAXIMUM_TIME_EFFICIENCY,
  MINIMUM_BLUEPRINT_COPIES,
  MINIMUM_RUNS,
  parseBulkLine,
  splitBulkLines,
  type BulkLineRejection,
  type BulkLineRequest,
} from '@/components/group-industry/bulkPasteLines'

const DEFAULT_PREFILL_LEVEL = 0
const DEFAULT_PREFILL_RUNS = 1
const INVENTED_BLUEPRINT_TIME_EFFICIENCY = 4

const { t } = useI18n()
const projectNameFieldId = useId()
const containerNameFieldId = useId()
const minimumOutsideSavingFieldId = useId()
const excludeInventionFieldId = useId()
const { getTypeIconUrl, onImageError } = useEveImages()
const projectsStore = useProjectsStore()
const blacklistStore = useBlacklistStore()
const groupProjectStore = useGroupProjectStore()
const referencesStore = useGroupProjectReferencesStore()
const { formatIskFull, formatNumber, formatDate } = useFormatters()

const props = defineProps<{
  show: boolean
  prefill?: ProjectPrefill | null
  prefillItems?: ProjectPrefillItem[] | null
}>()

const emit = defineEmits<{
  close: []
  created: [project: GroupProject]
}>()

// --- Form state ---

type AddMode = 'add-item' | 'bulk-paste' | 'from-template'
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
const isParsingBulk = ref(false)

type BulkLineResult =
  | { line: string; status: 'recognized'; items: ProjectItem[] }
  | { line: string; status: 'unknown'; request: BulkLineRequest; suggestion: SearchResult | null }
  | { line: string; status: 'invalid'; rejection: BulkLineRejection }
const bulkResults = ref<BulkLineResult[]>([])

// Items list
type ProjectItem = {
  typeId: number
  typeName: string
  meLevel: number
  teLevel: number
  runs: number
}
const items = ref<ProjectItem[]>([])
const blacklistTypeIds = ref<number[]>([])
const blacklistGroupIds = ref<number[]>([])

// Project metadata
const projectName = ref('')
const containerNames = ref('')
const containerNamesInForm = computed(() => containerNamesFromText(containerNames.value))
const isPrivate = ref(true)
const isPersonalUse = ref(false)
const usedTemplateId = ref<string | null>(null)
const sellStructureId = ref<number | null>(null)
const selectableSellStructureIds = ref<number[]>([])
const acceptsOutsideFavoriteSystem = ref(false)
const excludesInvention = ref(false)
const excludesInventionChosen = ref(false)
const minimumOutsideSaving = ref<number | string>(DEFAULT_MINIMUM_OUTSIDE_SAVING)
const hasValidMinimumOutsideSaving = computed(() =>
  typeof minimumOutsideSaving.value === 'number' && minimumOutsideSaving.value >= 0,
)

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
  { id: 'complex_reactions', label: 'Composite Reactions', checked: false },
  { id: 'hybrid_reactions', label: 'Hybrid Reactions', checked: false },
  { id: 'biochem_reactions', label: 'Biochemical Reactions', checked: false },
])
const blacklistEdited = ref(false)
const blacklistDefaultsUnavailable = ref(false)
const settingsLockedCategoryIds = ref<string[]>([])
const settingsExcludedItemCount = ref(0)

async function applyBlacklistDefaults() {
  blacklistDefaultsUnavailable.value = false
  try {
    await blacklistStore.fetchBlacklist()
  } catch {
    blacklistDefaultsUnavailable.value = true
    return
  }
  const settingsCategories = blacklistStore.blacklist?.categories
  if (settingsCategories === undefined) {
    blacklistDefaultsUnavailable.value = true
    return
  }
  if (!excludesInventionChosen.value) {
    excludesInvention.value = blacklistStore.blacklist?.excludeInvention === true
  }
  if (blacklistEdited.value) return
  const blacklistedKeys = new Set(settingsCategories.filter(category => category.blacklisted).map(category => category.key))
  blacklistCategories.value.forEach(cat => {
    cat.checked = blacklistedKeys.has(cat.id)
  })
  settingsLockedCategoryIds.value = [...blacklistedKeys]
  settingsExcludedItemCount.value = blacklistStore.blacklist?.items.length ?? 0
}

function isLockedBySettings(categoryId: string): boolean {
  return settingsLockedCategoryIds.value.includes(categoryId)
}

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
  if (newVal) {
    resetForm()
    applyPrefill()
    void referencesStore.fetchReferences()
    void applyBlacklistDefaults()
  }
})

onMounted(() => {
  if (props.show) void applyBlacklistDefaults()
})

function referencesOf(productTypeId: number): ProjectReferenceRow[] {
  const reference = referencesStore.referenceOf(productTypeId)
  return reference === undefined ? [] : [reference]
}

function referenceCostLabel(unitCost: number | null): string {
  return t('groupIndustry.modals.newProject.reference.unitCost', {
    value: unitCost === null ? t('groupIndustry.modals.newProject.reference.unknown') : formatIskFull(unitCost),
  })
}

function referenceMarginLabel(marginPercent: number | null): string {
  return t('groupIndustry.modals.newProject.reference.margin', {
    value: marginPercent === null ? t('groupIndustry.modals.newProject.reference.unknown') : `${formatNumber(marginPercent, 1)} %`,
  })
}

function applyPrefill() {
  if (props.prefillItems && props.prefillItems.length > 0) {
    items.value = props.prefillItems.map(item => ({ ...item }))
    return
  }
  if (!props.prefill) return
  items.value = [{
    typeId: props.prefill.typeId,
    typeName: props.prefill.typeName,
    meLevel: props.prefill.me ?? DEFAULT_PREFILL_LEVEL,
    teLevel: props.prefill.te ?? (props.prefill.isT2 ? INVENTED_BLUEPRINT_TIME_EFFICIENCY : DEFAULT_PREFILL_LEVEL),
    runs: props.prefill.runs ?? DEFAULT_PREFILL_RUNS,
  }]
  blacklistTypeIds.value = props.prefill.excludedTypeIds ?? []
}

function resetForm() {
  addMode.value = 'add-item'
  usedTemplateId.value = null
  sellStructureId.value = null
  searchQuery.value = ''
  selectedItem.value = null
  showDropdown.value = false
  projectsStore.searchResults = []
  if (searchTimeout) clearTimeout(searchTimeout)
  itemMe.value = 2
  itemTe.value = 4
  itemRuns.value = 100
  bulkText.value = ''
  bulkResults.value = []
  isParsingBulk.value = false
  blacklistEdited.value = false
  blacklistDefaultsUnavailable.value = false
  settingsLockedCategoryIds.value = []
  settingsExcludedItemCount.value = 0
  items.value = []
  blacklistTypeIds.value = []
  blacklistGroupIds.value = []
  projectName.value = ''
  containerNames.value = ''
  isPrivate.value = true
  isPersonalUse.value = false
  acceptsOutsideFavoriteSystem.value = false
  excludesInvention.value = false
  excludesInventionChosen.value = false
  minimumOutsideSaving.value = DEFAULT_MINIMUM_OUTSIDE_SAVING
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

function applyTemplate(template: ProjectTemplate) {
  usedTemplateId.value = template.id
  items.value = template.items.map(item => ({ ...item }))
  blacklistTypeIds.value = [...template.blacklistTypeIds]
  blacklistGroupIds.value = [...template.blacklistGroupIds]
  acceptsOutsideFavoriteSystem.value = template.acceptsOutsideFavoriteSystem
  excludesInvention.value = template.excludesInvention
  excludesInventionChosen.value = true
  minimumOutsideSaving.value = template.minimumOutsideSaving
  if (template.sellStructureId !== null && selectableSellStructureIds.value.includes(template.sellStructureId)) {
    sellStructureId.value = template.sellStructureId
  }
  addMode.value = 'add-item'
}

function removeItem(index: number) {
  items.value.splice(index, 1)
}

function lookupKey(typeName: string): string {
  return typeName.toLowerCase()
}

function bulkItemsOf(request: BulkLineRequest, product: SearchResult): ProjectItem[] {
  return Array.from({ length: request.copies }, () => ({
    typeId: product.typeId,
    typeName: product.typeName,
    meLevel: request.meLevel,
    teLevel: request.teLevel,
    runs: request.runs,
  }))
}

function resolveBulkLine(line: string, catalogs: Map<string, SearchResult[]>): BulkLineResult {
  const parsed = parseBulkLine(line)
  if ('rejection' in parsed) return { line, status: 'invalid', rejection: parsed.rejection }

  const candidates = catalogs.get(lookupKey(parsed.request.typeName)) ?? []
  const exactMatch = candidates.find(candidate => lookupKey(candidate.typeName) === lookupKey(parsed.request.typeName))
  if (exactMatch !== undefined) {
    return { line, status: 'recognized', items: bulkItemsOf(parsed.request, exactMatch) }
  }
  return { line, status: 'unknown', request: parsed.request, suggestion: candidates[0] ?? null }
}

async function parseBulkText() {
  const lines = splitBulkLines(bulkText.value)
  if (lines.length === 0) return
  isParsingBulk.value = true
  errorMsg.value = null

  const typeNames = new Set<string>()
  for (const line of lines) {
    const parsed = parseBulkLine(line)
    if ('request' in parsed) typeNames.add(parsed.request.typeName)
  }

  const catalogs = new Map<string, SearchResult[]>()
  try {
    await Promise.all([...typeNames].map(async typeName => {
      catalogs.set(lookupKey(typeName), await projectsStore.findManufacturableProducts(typeName))
    }))
  } catch {
    errorMsg.value = t('groupIndustry.modals.newProject.bulkLookupFailed')
    isParsingBulk.value = false
    return
  }

  const results = lines.map(line => resolveBulkLine(line, catalogs))
  for (const result of results) {
    if (result.status === 'recognized') items.value.push(...result.items)
  }
  bulkResults.value = results
  bulkText.value = results.filter(result => result.status !== 'recognized').map(result => result.line).join('\n')
  isParsingBulk.value = false
}

function acceptSuggestion(index: number) {
  const result = bulkResults.value[index]
  if (result.status !== 'unknown' || result.suggestion === null) return
  const acceptedItems = bulkItemsOf(result.request, result.suggestion)
  items.value.push(...acceptedItems)
  bulkResults.value[index] = { line: result.line, status: 'recognized', items: acceptedItems }
  const remainingLines = splitBulkLines(bulkText.value)
  const lineIndex = remainingLines.indexOf(result.line)
  if (lineIndex >= 0) remainingLines.splice(lineIndex, 1)
  bulkText.value = remainingLines.join('\n')
}

function bulkRejectionMessage(rejection: BulkLineRejection): string {
  switch (rejection) {
    case 'format':
      return t('groupIndustry.modals.newProject.bulkInvalidFormat')
    case 'materialEfficiency':
      return t('groupIndustry.modals.newProject.bulkInvalidMaterialEfficiency', { max: MAXIMUM_MATERIAL_EFFICIENCY })
    case 'timeEfficiency':
      return t('groupIndustry.modals.newProject.bulkInvalidTimeEfficiency', { max: MAXIMUM_TIME_EFFICIENCY })
    case 'runs':
      return t('groupIndustry.modals.newProject.bulkInvalidRuns', { min: MINIMUM_RUNS, max: MAXIMUM_RUNS })
    case 'copies':
      return t('groupIndustry.modals.newProject.bulkInvalidCopies', { min: MINIMUM_BLUEPRINT_COPIES, max: MAXIMUM_BLUEPRINT_COPIES })
  }
}

async function handleCreate() {
  if (items.value.length === 0) return
  if (typeof minimumOutsideSaving.value !== 'number' || !hasValidMinimumOutsideSaving.value) return
  isCreating.value = true
  errorMsg.value = null

  const input: CreateGroupProjectInput = {
    name: projectName.value.trim() || undefined,
    private: isPrivate.value,
    personalUse: isPersonalUse.value,
    items: items.value.map(i => ({
      typeId: i.typeId,
      typeName: i.typeName,
      meLevel: i.meLevel,
      teLevel: i.teLevel,
      runs: i.runs,
    })),
    containerNames: containerNamesInForm.value.length > 0 ? containerNamesInForm.value : undefined,
    blacklistCategoryKeys: blacklistCategories.value
      .filter(cat => cat.checked)
      .map(cat => cat.id),
    blacklistGroupIds: blacklistGroupIds.value.length > 0 ? blacklistGroupIds.value : undefined,
    blacklistTypeIds: blacklistTypeIds.value.length > 0 ? blacklistTypeIds.value : undefined,
    lineRentalRatesOverride: lineRentalOverrideEnabled.value
      ? Object.fromEntries(lineRentalRates.value.map(r => [r.key, r.value]))
      : undefined,
    acceptsOutsideFavoriteSystem: acceptsOutsideFavoriteSystem.value,
    excludesInvention: excludesInvention.value,
    minimumOutsideSaving: minimumOutsideSaving.value,
    templateId: usedTemplateId.value ?? undefined,
    sellStructureId: sellStructureId.value ?? undefined,
  }

  try {
    const project = await groupProjectStore.createProject(input)
    emit('created', project)
  } catch (e) {
    errorMsg.value = e instanceof Error ? e.message : 'Failed to create project'
    isCreating.value = false
  }
}

function handleClose() {
  emit('close')
}
</script>

<template>
  <BaseModal
    :show="show"
    :title="t('groupIndustry.modals.newProject.title')"
    panel-class="border-slate-700 max-w-2xl max-h-[90vh] overflow-y-auto"
    @close="handleClose"
  >

    <!-- Modal Header -->
    <template #title="{ titleId }">
      <div class="px-6 py-5 border-b border-slate-800 flex items-center justify-between">
        <div>
          <h2 :id="titleId" class="text-lg font-bold text-slate-100">{{ t('groupIndustry.modals.newProject.title') }}</h2>
          <p class="text-sm text-slate-500 mt-0.5">{{ t('groupIndustry.modals.newProject.subtitle') }}</p>
        </div>
        <button
          class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 flex items-center justify-center text-slate-400 hover:text-slate-200 transition-colors"
          @click="handleClose"
          :aria-label="t('common.actions.close')"
        >
          <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </template>

    <!-- Modal Body -->
    <div class="px-6 py-5 space-y-5">

      <!-- Add Items Section -->
      <div>
        <p class="text-sm font-medium text-slate-300 mb-3 block">{{ t('groupIndustry.modals.newProject.itemsLabel') }}</p>

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
          <button
            data-testid="new-project-template-tab"
            class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors"
            :class="addMode === 'from-template' ? 'bg-cyan-500/15 text-cyan-400' : 'text-slate-500 hover:text-slate-300'"
            @click="addMode = 'from-template'"
          >
            {{ t('groupIndustry.modals.newProject.fromTemplate') }}
          </button>
        </div>

        <!-- Mode A: Add single item -->
        <div v-if="addMode === 'add-item'">
          <div class="flex gap-2 items-start">
            <div class="flex-1 relative">
              <input
                v-model="searchQuery"
                type="text"
                :aria-label="t('groupIndustry.modals.newProject.searchPlaceholder')"
                :placeholder="t('groupIndustry.modals.newProject.searchPlaceholder')"
                class="form-input w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2.5 text-sm text-slate-200 placeholder-slate-500"
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
                    alt=""
                    class="w-6 h-6 rounded-sm"
                    @error="onImageError"
                  />
                  <span class="text-slate-200">{{ result.typeName }}</span>
                  <span
                    v-if="result.isT2"
                    class="text-xs font-semibold uppercase tracking-wider px-1.5 py-0.5 bg-blue-500/20 text-blue-400 rounded-sm"
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
          <div class="flex gap-10 mt-1.5 ml-0 text-xs text-slate-500 uppercase tracking-wider">
            <span class="flex-1 pl-4">{{ t('groupIndustry.modals.newProject.columnItem') }}</span>
            <span class="w-16 text-center">ME</span>
            <span class="w-16 text-center">TE</span>
            <span class="w-20 text-center">{{ t('groupIndustry.modals.newProject.columnRuns') }}</span>
            <span class="w-[88px]"></span>
          </div>
        </div>

        <!-- Mode C: From a template -->
        <ProjectTemplatesPanel v-else-if="addMode === 'from-template'" @use="applyTemplate" />

        <!-- Mode B: Bulk Paste -->
        <div v-else>
          <textarea
            v-model="bulkText"
            :aria-label="t('groupIndustry.modals.newProject.itemsLabel')"
            rows="5"
            :placeholder="t('groupIndustry.modals.newProject.bulkPlaceholder')"
            class="form-input w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-3 text-sm text-slate-200 font-mono placeholder-slate-500 resize-none"
            style="font-variant-numeric: tabular-nums;"
          ></textarea>
          <p class="text-xs text-slate-500 mt-1.5">
            {{ t('groupIndustry.modals.newProject.bulkFormat') }}
            <span class="font-mono text-slate-500">ItemName ME TE xRuns [*Copies]</span>
          </p>
          <button
            class="btn-action mt-2 px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2"
            :disabled="!bulkText.trim() || isParsingBulk"
            @click="parseBulkText"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            {{ t('groupIndustry.modals.newProject.parseAndAdd') }}
          </button>
          <ul
            v-if="bulkResults.length > 0"
            aria-live="polite"
            class="mt-3 space-y-1.5"
            data-testid="bulk-line-results"
          >
            <li
              v-for="(result, index) in bulkResults"
              :key="`${index}-${result.line}`"
              data-testid="bulk-line-result"
              :data-status="result.status"
              class="flex items-start gap-3 rounded-lg border px-3 py-2 text-sm"
              :class="{
                'border-emerald-500/30 bg-emerald-500/5': result.status === 'recognized',
                'border-amber-500/30 bg-amber-500/5': result.status === 'unknown',
                'border-red-500/30 bg-red-500/5': result.status === 'invalid',
              }"
            >
              <span
                class="shrink-0 w-28 text-xs font-semibold uppercase tracking-wider"
                :class="{
                  'text-emerald-400': result.status === 'recognized',
                  'text-amber-400': result.status === 'unknown',
                  'text-red-400': result.status === 'invalid',
                }"
              >
                {{ t(`groupIndustry.modals.newProject.bulkStatus.${result.status}`) }}
              </span>
              <div class="min-w-0 flex-1 text-slate-300">
                <template v-if="result.status === 'recognized'">
                  {{ t(result.items.length > 1 ? 'groupIndustry.modals.newProject.bulkRecognizedCopies' : 'groupIndustry.modals.newProject.bulkRecognized', {
                    copies: result.items.length,
                    name: result.items[0].typeName,
                    me: result.items[0].meLevel,
                    te: result.items[0].teLevel,
                    runs: result.items[0].runs,
                  }) }}
                </template>
                <template v-else-if="result.status === 'unknown'">
                  <p class="font-mono text-xs break-words">{{ t('groupIndustry.modals.newProject.bulkUnknown', { name: result.request.typeName }) }}</p>
                  <p v-if="result.suggestion" class="mt-0.5 text-slate-400">
                    {{ t('groupIndustry.modals.newProject.bulkSuggestion', { name: result.suggestion.typeName }) }}
                  </p>
                </template>
                <template v-else>
                  <p class="font-mono text-xs break-words">{{ result.line }}</p>
                  <p class="mt-0.5 text-slate-400">{{ bulkRejectionMessage(result.rejection) }}</p>
                </template>
              </div>
              <button
                v-if="result.status === 'unknown' && result.suggestion"
                type="button"
                class="shrink-0 rounded-md border border-cyan-500/40 px-2.5 py-1 text-xs font-medium text-cyan-300 hover:bg-cyan-500/10"
                @click="acceptSuggestion(index)"
              >
                {{ t('groupIndustry.modals.newProject.bulkCorrect') }}
              </button>
            </li>
          </ul>
        </div>
      </div>

      <!-- Items Table -->
      <div>
        <!-- Empty state -->
        <div v-if="items.length === 0" class="bg-slate-800/30 rounded-xl border border-dashed border-slate-700 p-8 text-center">
          <svg class="w-8 h-8 mx-auto text-slate-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
          </svg>
          <p class="text-sm text-slate-500">{{ t('groupIndustry.modals.newProject.noItems') }}</p>
          <p class="text-xs text-slate-500 mt-1">{{ t('groupIndustry.modals.newProject.noItemsHint') }}</p>
        </div>

        <!-- Filled table -->
        <div v-else class="bg-slate-800/30 rounded-xl border border-slate-700 overflow-hidden">
          <DataTable>
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
                <template
                  v-for="(item, index) in items"
                  :key="`${item.typeName}-${index}`"
                >
                <tr class="hover:bg-slate-800/30">
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
                      :aria-label="`${item.typeName} ME`"
                      type="number"
                      min="0"
                      max="10"
                      class="w-12 bg-slate-900 border border-slate-700 rounded px-1.5 py-1 text-xs text-slate-200 font-mono text-center focus:border-cyan-500 focus:outline-none"
                    />
                  </td>
                  <td class="py-2 px-2 text-center">
                    <input
                      v-model.number="item.teLevel"
                      :aria-label="`${item.typeName} TE`"
                      type="number"
                      min="0"
                      max="20"
                      class="w-12 bg-slate-900 border border-slate-700 rounded px-1.5 py-1 text-xs text-slate-200 font-mono text-center focus:border-cyan-500 focus:outline-none"
                    />
                  </td>
                  <td class="py-2 px-2 text-center">
                    <input
                      v-model.number="item.runs"
                      :aria-label="`${item.typeName} ${t('groupIndustry.modals.newProject.columnRuns')}`"
                      type="number"
                      min="1"
                      class="w-16 bg-slate-900 border border-slate-700 rounded px-1.5 py-1 text-xs text-slate-200 font-mono text-center focus:border-cyan-500 focus:outline-none"
                      style="font-variant-numeric: tabular-nums;"
                    />
                  </td>
                  <td class="py-2 px-3 text-center">
                    <button
                      class="text-slate-500 hover:text-red-400 transition-colors"
                      :title="t('common.actions.delete')"
                      @click="removeItem(index)"
                      :aria-label="t('common.actions.delete')"
                    >
                      <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                      </svg>
                    </button>
                  </td>
                </tr>
                <tr
                  v-for="reference in referencesOf(item.typeId)"
                  :key="reference.projectId"
                  data-testid="project-reference"
                  class="bg-slate-900/40"
                >
                  <td></td>
                  <td colspan="5" class="pb-2 px-3 text-xs text-slate-400">
                    <span class="uppercase tracking-wider text-cyan-400">{{ t('groupIndustry.modals.newProject.reference.label') }}</span>
                    &middot; {{ formatDate(reference.completedAt) }}
                    &middot; {{ referenceCostLabel(reference.unitCost) }}
                    &middot; {{ referenceMarginLabel(reference.marginPercent) }}
                  </td>
                </tr>
                </template>
              </tbody>
            </table>
          </DataTable>
          <p v-if="referencesStore.error !== null" data-testid="project-reference-error" class="px-3 py-2 text-xs text-slate-500 border-t border-slate-700">
            {{ referencesStore.error }}
          </p>
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
        <label :for="projectNameFieldId" class="text-sm font-medium text-slate-300 mb-1.5 block">
          {{ t('groupIndustry.modals.newProject.nameLabel') }}
          <span class="text-slate-500 font-normal">({{ t('groupIndustry.modals.newProject.optional') }})</span>
        </label>
        <input
          :id="projectNameFieldId"
          v-model="projectName"
          type="text"
          :placeholder="t('groupIndustry.modals.newProject.namePlaceholder')"
          class="form-input w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2.5 text-sm text-slate-200 placeholder-slate-500"
        />
      </div>

      <div>
        <label class="flex items-center gap-2.5 cursor-pointer">
          <input
            v-model="isPrivate"
            data-testid="project-private"
            type="checkbox"
            class="w-3.5 h-3.5 rounded border-slate-600 bg-slate-800 accent-cyan-500 cursor-pointer"
          />
          <span class="text-sm font-medium text-slate-300">{{ t('groupIndustry.modals.newProject.privateLabel') }}</span>
        </label>
        <p class="text-xs text-slate-500 mt-1.5">{{ t('groupIndustry.modals.newProject.privateHint') }}</p>
      </div>

      <div>
        <label class="flex items-center gap-2.5 cursor-pointer">
          <input
            v-model="isPersonalUse"
            data-testid="project-personal-use"
            type="checkbox"
            class="w-3.5 h-3.5 rounded border-slate-600 bg-slate-800 accent-cyan-500 cursor-pointer"
          />
          <span class="text-sm font-medium text-slate-300">{{ t('groupIndustry.modals.newProject.personalUseLabel') }}</span>
        </label>
        <p class="text-xs text-slate-500 mt-1.5">{{ t('groupIndustry.modals.newProject.personalUseHint') }}</p>
      </div>

      <SellStructureSelect
        v-model="sellStructureId"
        @loaded="selectableSellStructureIds = $event"
      />

      <div>
        <label class="flex items-center gap-2.5 cursor-pointer">
          <input
            v-model="acceptsOutsideFavoriteSystem"
            data-testid="project-outside-favorite"
            type="checkbox"
            class="w-3.5 h-3.5 rounded border-slate-600 bg-slate-800 accent-cyan-500 cursor-pointer"
          />
          <span class="text-sm font-medium text-slate-300">{{ t('groupIndustry.modals.newProject.outsideFavoriteLabel') }}</span>
        </label>
        <div class="mt-2">
          <label :for="minimumOutsideSavingFieldId" class="block text-xs text-slate-500 mb-1.5">{{ t('groupIndustry.modals.newProject.minimumSavingLabel') }}</label>
          <input
            :id="minimumOutsideSavingFieldId"
            v-model.number="minimumOutsideSaving"
            data-testid="project-minimum-outside-saving"
            type="number"
            min="0"
            step="1"
            :disabled="!acceptsOutsideFavoriteSystem"
            class="form-input w-40 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 font-mono disabled:opacity-50"
          />
        </div>
      </div>

      <div>
        <div class="flex items-center gap-2.5">
          <input
            :id="excludeInventionFieldId"
            v-model="excludesInvention"
            data-testid="project-exclude-invention"
            type="checkbox"
            class="w-3.5 h-3.5 rounded border-slate-600 bg-slate-800 accent-cyan-500 cursor-pointer"
            @change="excludesInventionChosen = true"
          />
          <label :for="excludeInventionFieldId" class="text-sm font-medium text-slate-300 cursor-pointer">{{ t('groupIndustry.modals.newProject.excludeInventionLabel') }}</label>
        </div>
        <p class="text-xs text-slate-500 mt-1 ml-6">{{ t('groupIndustry.modals.newProject.excludeInventionHint') }}</p>
      </div>

      <!-- Production Blacklist -->
      <div>
        <p class="text-sm font-medium text-slate-300 mb-1 block">{{ t('groupIndustry.modals.newProject.blacklistLabel') }}</p>
        <p class="text-xs text-slate-500 mb-3">{{ t('groupIndustry.modals.newProject.blacklistDesc') }}</p>

        <!-- Predefined categories -->
        <div class="grid grid-cols-2 gap-x-6 gap-y-2 mb-4">
          <div v-for="cat in blacklistCategories" :key="cat.id" class="flex items-center gap-2">
            <label
              class="flex items-center gap-2.5 group"
              :class="isLockedBySettings(cat.id) ? 'cursor-not-allowed' : 'cursor-pointer'"
            >
              <input
                v-model="cat.checked"
                type="checkbox"
                :disabled="isLockedBySettings(cat.id)"
                @change="blacklistEdited = true"
                class="w-3.5 h-3.5 rounded border-slate-600 bg-slate-800 accent-cyan-500 cursor-pointer disabled:cursor-not-allowed disabled:opacity-60"
              />
              <span
                class="text-sm group-hover:text-slate-100"
                :class="cat.checked ? 'text-slate-200' : 'text-slate-400 group-hover:text-slate-300'"
              >
                {{ cat.label }}
              </span>
            </label>
            <span v-if="isLockedBySettings(cat.id)" class="text-xs uppercase tracking-wide text-cyan-500/80">
              {{ t('groupIndustry.modals.newProject.blacklistFromSettings') }}
            </span>
          </div>
        </div>

        <p v-if="settingsExcludedItemCount > 0" class="mb-1 text-xs text-cyan-400">
          {{ t('groupIndustry.modals.newProject.blacklistSettingsItems', { count: settingsExcludedItemCount }, settingsExcludedItemCount) }}
        </p>
        <p v-if="blacklistDefaultsUnavailable" role="alert" class="mb-1 text-xs text-amber-400">
          {{ t('groupIndustry.modals.newProject.blacklistDefaultsUnavailable') }}
        </p>
        <p class="text-xs text-slate-500">{{ t('groupIndustry.modals.newProject.blacklistHint') }}</p>
      </div>

      <!-- Corp Container (Optional) -->
      <div>
        <label :for="containerNameFieldId" class="text-sm font-medium text-slate-300 mb-1.5 block">
          {{ t('groupIndustry.modals.newProject.containerLabel') }}
          <span class="text-slate-500 font-normal">({{ t('groupIndustry.modals.newProject.optional') }})</span>
        </label>
        <textarea
          :id="containerNameFieldId"
          v-model="containerNames"
          rows="2"
          :placeholder="t('groupIndustry.modals.newProject.containerPlaceholder')"
          class="form-input w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2.5 text-sm text-slate-200 placeholder-slate-500"
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
          <DataTable>
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
                      :aria-label="rate.label"
                      type="text"
                      class="w-24 bg-slate-900 border border-slate-700 rounded px-2.5 py-1 text-sm text-slate-200 font-mono text-right focus:border-cyan-500 focus:outline-none"
                      style="font-variant-numeric: tabular-nums;"
                    />
                  </td>
                  <td class="py-2 px-4 text-xs text-slate-500">{{ rate.unit }}</td>
                </tr>
              </tbody>
            </table>
          </DataTable>
          <div class="px-4 py-2 border-t border-slate-700/50">
            <p class="text-xs text-slate-500">{{ t('groupIndustry.modals.newProject.lineRentalOverrideHint') }}</p>
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
          :disabled="items.length === 0 || isCreating || !hasValidMinimumOutsideSaving"
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

  </BaseModal>
</template>

<style scoped>
.form-input {
  transition: border-color 0.15s ease;
}
.form-input:focus {
  border-color: var(--color-brand);
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
