<script setup lang="ts">
import { computed, onMounted, ref, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useIndustryStore } from '@/stores/industry'
import type { StructureConfig, RigOption, CorporationStructure, StructureSearchResult } from '@/stores/industry'
import FavoriteSystemsConfig from './FavoriteSystemsConfig.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

const { t } = useI18n()
const store = useIndustryStore()

const showAddForm = ref(false)
const editingStructure = ref<StructureConfig | null>(null)

// Form state
const formName = ref('')
const formLocationId = ref<number | null>(null)
const formSecurityType = ref<'highsec' | 'lowsec' | 'nullsec'>('nullsec')
const formStructureType = ref<string>('raitaru')
const formRigs = ref<string[]>([])

// Rig search state
const rigSearchQuery = ref('')
const showRigDropdown = ref(false)

// ESI structure search
const esiSearchQuery = ref('')
const esiSearchResults = ref<StructureSearchResult[]>([])
const isSearchingEsi = ref(false)
const showEsiSearchDropdown = ref(false)

const corporationStructuresLoaded = ref(false)

onMounted(async () => {
  store.fetchStructures()
  await store.fetchCorporationStructures()
  corporationStructuresLoaded.value = true
})

// Corporation structure selection
const selectedCorpStructure = ref<CorporationStructure | null>(null)
const isKnownCorpStructure = ref(false)

function onCorpStructureSelected() {
  if (selectedCorpStructure.value) {
    const struct = selectedCorpStructure.value
    formName.value = struct.locationName
    formLocationId.value = struct.locationId

    // If this is a known corporation structure, mark it
    if (struct.isCorporationOwned) {
      isKnownCorpStructure.value = true
    }

    // If there's a shared config from another corp member, use it
    if (struct.sharedConfig) {
      formSecurityType.value = struct.sharedConfig.securityType
      formStructureType.value = struct.sharedConfig.structureType
      formRigs.value = [...struct.sharedConfig.rigs]
    } else {
      // Use the structure type from ESI data if available
      if (struct.structureType) {
        formStructureType.value = struct.structureType
      }
    }

    selectedCorpStructure.value = null
  }
}

function clearCorpStructure() {
  formLocationId.value = null
  isKnownCorpStructure.value = false
}

// ESI structure search functions
let searchTimeout: number | undefined
const esiSearchError = ref<string | null>(null)

function onEsiSearchInput() {
  clearTimeout(searchTimeout)
  esiSearchError.value = null
  if (esiSearchQuery.value.length < 3) {
    esiSearchResults.value = []
    showEsiSearchDropdown.value = false
    return
  }
  searchTimeout = window.setTimeout(async () => {
    isSearchingEsi.value = true
    const result = await store.searchStructures(esiSearchQuery.value)
    esiSearchResults.value = result.structures
    esiSearchError.value = result.error || null
    showEsiSearchDropdown.value = result.structures.length > 0
    isSearchingEsi.value = false
  }, 500) // Increased debounce to reduce API calls
}

function selectEsiSearchResult(result: StructureSearchResult) {
  formName.value = result.locationName
  formLocationId.value = result.locationId
  if (result.structureType) {
    formStructureType.value = result.structureType
  }
  // Mark as known corp structure if it's corporation-owned
  if (result.isCorporationOwned) {
    isKnownCorpStructure.value = true
  }
  esiSearchQuery.value = ''
  esiSearchResults.value = []
  showEsiSearchDropdown.value = false
}

function hideEsiDropdownDelayed() {
  window.setTimeout(() => {
    showEsiSearchDropdown.value = false
  }, 200)
}

const securityMultipliers = {
  highsec: 1.0,
  lowsec: 1.9,
  nullsec: 2.1,
}

const securityLabels = {
  highsec: 'High-Sec (x1.0)',
  lowsec: 'Low-Sec (x1.9)',
  nullsec: 'Null-Sec (x2.1)',
}

const structureTypeLabels = computed<Record<string, string>>(() => ({
  station: t('industry.structures.npcStationOption'),
  raitaru: 'Raitaru (EC Medium) - 1% ME',
  azbel: 'Azbel (EC Large) - 1% ME, 20% TE',
  sotiyo: 'Sotiyo (EC X-Large) - 1% ME, 30% TE',
  athanor: 'Athanor (Refinery Medium) - 25% TE',
  tatara: 'Tatara (Refinery Large) - 25% TE',
  // Legacy support
  engineering_complex: 'Engineering Complex (legacy)',
  refinery: 'Refinery (legacy)',
}))

// Map structure type to rig size
const structureSizeMap: Record<string, string> = {
  raitaru: 'M',
  azbel: 'L',
  sotiyo: 'XL',
  athanor: 'M',
  tatara: 'L',
}

const availableRigs = computed(() => {
  if (!store.rigOptions) return []

  const structureSize = structureSizeMap[formStructureType.value]

  // Refineries use reaction rigs
  if (['athanor', 'tatara', 'refinery'].includes(formStructureType.value)) {
    const rigs = store.rigOptions.reaction
    // Filter by size if we know the structure size
    return structureSize ? rigs.filter(r => r.size === structureSize) : rigs
  }
  // Engineering complexes use manufacturing rigs
  if (['raitaru', 'azbel', 'sotiyo', 'engineering_complex'].includes(formStructureType.value)) {
    const rigs = store.rigOptions.manufacturing
    // Filter by size if we know the structure size
    return structureSize ? rigs.filter(r => r.size === structureSize) : rigs
  }
  // NPC stations have no rigs
  return []
})

// Filter rigs based on search query, excluding already selected rigs
const filteredRigs = computed(() => {
  const query = rigSearchQuery.value.toLowerCase().trim()
  if (query.length < 2) return []

  return availableRigs.value.filter((rig) => {
    // Exclude already selected rigs
    if (formRigs.value.includes(rig.name)) return false
    // Match on name
    return rig.name.toLowerCase().includes(query)
  }).slice(0, 10) // Limit to 10 results
})

// Get full rig info for selected rigs
const selectedRigsInfo = computed(() => {
  return formRigs.value.map((rigName) => {
    const rig = availableRigs.value.find((r) => r.name === rigName)
    return rig || { name: rigName, bonus: 0, category: '', size: 'M' as const, targetCategories: [] }
  })
})

// Clear rig search when structure type changes (different rig lists)
watch(formStructureType, () => {
  rigSearchQuery.value = ''
  // Remove rigs that are no longer available for this structure type
  const availableNames = new Set(availableRigs.value.map((r) => r.name))
  formRigs.value = formRigs.value.filter((name) => availableNames.has(name))
})

function resetForm() {
  formName.value = ''
  formLocationId.value = null
  formSecurityType.value = 'nullsec'
  formStructureType.value = 'raitaru'
  formRigs.value = []
  isKnownCorpStructure.value = false
  editingStructure.value = null
  rigSearchQuery.value = ''
  showRigDropdown.value = false
  selectedCorpStructure.value = null
  esiSearchQuery.value = ''
  esiSearchResults.value = []
  showEsiSearchDropdown.value = false
}

const isRefinery = computed(() => ['athanor', 'tatara', 'refinery'].includes(formStructureType.value))
const isEngineeringComplex = computed(() => ['raitaru', 'azbel', 'sotiyo', 'engineering_complex'].includes(formStructureType.value))

function openAddForm() {
  resetForm()
  showAddForm.value = true
}

function openEditForm(structure: StructureConfig) {
  formName.value = structure.name
  formLocationId.value = structure.locationId
  formSecurityType.value = structure.securityType
  formStructureType.value = structure.structureType
  formRigs.value = [...structure.rigs]
  editingStructure.value = structure
  rigSearchQuery.value = ''
  showRigDropdown.value = false
  showAddForm.value = true
}

function cancelForm() {
  showAddForm.value = false
  resetForm()
}

// Corporation structure edit confirmation modal
const showCorpEditModal = ref(false)
const pendingSave = ref(false)

function hasCorpStructureChanges(): boolean {
  if (!editingStructure.value || !editingStructure.value.isCorporationStructure) {
    return false
  }
  // Check if rigs changed
  const originalRigs = [...editingStructure.value.rigs].sort()
  const newRigs = [...formRigs.value].sort()
  if (JSON.stringify(originalRigs) !== JSON.stringify(newRigs)) {
    return true
  }
  // Check if structure type changed
  if (editingStructure.value.structureType !== formStructureType.value) {
    return true
  }
  // Check if security type changed
  if (editingStructure.value.securityType !== formSecurityType.value) {
    return true
  }
  return false
}

async function saveStructure() {
  // If editing a corp structure with changes, ask for confirmation
  if (editingStructure.value && hasCorpStructureChanges() && !pendingSave.value) {
    showCorpEditModal.value = true
    nextTick(() => corpEditModalRef.value?.focus())
    return
  }

  try {
    if (editingStructure.value) {
      await store.updateStructure(editingStructure.value.id, {
        name: formName.value,
        securityType: formSecurityType.value,
        structureType: formStructureType.value as StructureConfig['structureType'],
        rigs: formRigs.value,
      })
    } else {
      await store.createStructure({
        name: formName.value,
        locationId: formLocationId.value,
        securityType: formSecurityType.value,
        structureType: formStructureType.value,
        rigs: formRigs.value,
      })
    }
    showAddForm.value = false
    resetForm()
    pendingSave.value = false
  } catch (e) {
    pendingSave.value = false
    // Error is handled by store
  }
}

async function confirmCorpEdit() {
  showCorpEditModal.value = false
  pendingSave.value = true
  await saveStructure()
}

function cancelCorpEdit() {
  showCorpEditModal.value = false
  pendingSave.value = false
}

// Delete confirmation modal
const showDeleteModal = ref(false)
const deleteStructureId = ref<string | null>(null)
const deleteStructureName = ref('')
const deleteModalRef = ref<HTMLElement | null>(null)
const corpEditModalRef = ref<HTMLElement | null>(null)

function openDeleteModal(structure: StructureConfig) {
  deleteStructureId.value = structure.id
  deleteStructureName.value = structure.name
  showDeleteModal.value = true
  nextTick(() => deleteModalRef.value?.focus())
}

async function confirmDelete() {
  if (deleteStructureId.value) {
    await store.deleteStructure(deleteStructureId.value)
  }
  showDeleteModal.value = false
  deleteStructureId.value = null
  deleteStructureName.value = ''
}

function cancelDelete() {
  showDeleteModal.value = false
  deleteStructureId.value = null
  deleteStructureName.value = ''
}

const MAX_RIGS = 3

const canAddMoreRigs = computed(() => formRigs.value.length < MAX_RIGS)

function addRig(rig: RigOption) {
  if (!canAddMoreRigs.value) return
  if (!formRigs.value.includes(rig.name)) {
    formRigs.value.push(rig.name)
  }
  rigSearchQuery.value = ''
  showRigDropdown.value = false
}

function removeRig(rigName: string) {
  const idx = formRigs.value.indexOf(rigName)
  if (idx !== -1) {
    formRigs.value.splice(idx, 1)
  }
}

function hideDropdownDelayed() {
  window.setTimeout(() => {
    showRigDropdown.value = false
  }, 200)
}

function formatRigName(name: string): string {
  // Remove common prefixes for display
  return name
    .replace('Standup M-Set ', '')
    .replace('Standup L-Set ', '')
    .replace('Standup XL-Set ', '')
}

const categoryLabels: Record<string, string> = {
  basic_small_ship: 'T1 Small Ships',
  basic_medium_ship: 'T1 Medium Ships',
  basic_large_ship: 'T1 Large Ships',
  advanced_small_ship: 'T2 Small Ships',
  advanced_medium_ship: 'T2 Medium Ships',
  advanced_large_ship: 'T2 Large Ships',
  capital_ship: 'Capitals',
  basic_capital_component: 'Capital Components',
  advanced_component: 'T2/T3 Components',
  structure_component: 'Structure Components',
  equipment: 'Modules',
  ammunition: 'Ammo',
  drone: 'Drones',
  fighter: 'Fighters',
  structure: 'Structures',
  composite_reaction: 'Composites',
  biochemical_reaction: 'Biochem',
  hybrid_reaction: 'Hybrid',
  research: 'Research/Invention/Copy',
}

function formatTargetCategories(categories: string[]): string {
  if (!categories || categories.length === 0) return ''
  return categories.map(c => categoryLabels[c] || c).join(', ')
}

// Check if a rig provides time bonus (L-Set/XL-Set "Efficiency" or "Reactor Efficiency")
function hasTimeBonus(rigName: string): boolean {
  const isEfficiencyRig = rigName.includes('Efficiency') && !rigName.includes('Material Efficiency')
  const isLargeOrXL = rigName.includes('L-Set') || rigName.includes('XL-Set')
  const isReactorEfficiency = rigName.includes('Reactor Efficiency')
  return (isEfficiencyRig && isLargeOrXL) || isReactorEfficiency
}

function calculateBonus(rigs: string[], type: 'manufacturing' | 'reaction', security: string): number {
  if (!store.rigOptions) return 0
  const options = type === 'reaction' ? store.rigOptions.reaction : store.rigOptions.manufacturing
  let bonus = 0
  for (const rigName of rigs) {
    const rig = options.find((r) => r.name === rigName)
    if (rig) bonus += rig.bonus
  }
  const multiplier = securityMultipliers[security as keyof typeof securityMultipliers] ?? 1
  return Math.round(bonus * multiplier * 100) / 100
}

// Reaction security multipliers are different
const reactionSecurityMultipliers = {
  highsec: 1.0,
  lowsec: 1.0,
  nullsec: 1.1,
}

// Calculate time bonus (base structure + rig, multiplicative)
function calculateTimeBonus(structureType: string, rigs: string[], type: 'manufacturing' | 'reaction', security: string): number {
  // Base structure time bonus
  const baseBonus = type === 'manufacturing'
    ? ({ raitaru: 15, azbel: 20, sotiyo: 30 }[structureType] ?? 0)
    : ({ athanor: 25, tatara: 25 }[structureType] ?? 0)

  // Rig time bonus (only L-Set/XL-Set "Efficiency" rigs, not "Material Efficiency")
  if (!store.rigOptions) return baseBonus
  const options = type === 'reaction' ? store.rigOptions.reaction : store.rigOptions.manufacturing
  let rigBonus = 0
  for (const rigName of rigs) {
    const rig = options.find((r) => r.name === rigName)
    if (!rig) continue
    // Only "Efficiency" rigs provide time bonus, not "Material Efficiency"
    const isEfficiencyRig = rigName.includes('Efficiency') && !rigName.includes('Material Efficiency')
    const isLargeOrXL = rigName.includes('L-Set') || rigName.includes('XL-Set')
    const isReactorEfficiency = rigName.includes('Reactor Efficiency')
    if (type === 'manufacturing' && isEfficiencyRig && isLargeOrXL) {
      rigBonus += rig.bonus
    } else if (type === 'reaction' && isReactorEfficiency) {
      rigBonus += rig.bonus
    }
  }

  // Apply security multiplier to rig bonus
  const multiplier = type === 'reaction'
    ? reactionSecurityMultipliers[security as keyof typeof reactionSecurityMultipliers] ?? 1
    : securityMultipliers[security as keyof typeof securityMultipliers] ?? 1
  rigBonus *= multiplier

  // Multiplicative stacking: 1 - (1 - base) × (1 - rig)
  if (baseBonus > 0 || rigBonus > 0) {
    const totalBonus = 1 - (1 - baseBonus / 100) * (1 - rigBonus / 100)
    return Math.round(totalBonus * 10000) / 100
  }
  return 0
}

const previewManufacturingBonus = computed(() => {
  return calculateBonus(formRigs.value, 'manufacturing', formSecurityType.value)
})

const previewReactionBonus = computed(() => {
  return calculateBonus(formRigs.value, 'reaction', formSecurityType.value)
})

const previewManufacturingTimeBonus = computed(() => {
  return calculateTimeBonus(formStructureType.value, formRigs.value, 'manufacturing', formSecurityType.value)
})

const previewReactionTimeBonus = computed(() => {
  return calculateTimeBonus(formStructureType.value, formRigs.value, 'reaction', formSecurityType.value)
})
</script>

<template>
  <div class="bg-slate-900 rounded-xl border border-slate-800 p-6 space-y-4">
    <!-- Favorite systems -->
    <FavoriteSystemsConfig />

    <div class="flex items-center justify-between">
      <h3 class="text-lg font-semibold text-slate-200">{{ t('industry.structures.title') }}</h3>
      <button
        v-if="!showAddForm"
        @click="openAddForm"
        class="px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        {{ t('common.actions.add') }}
      </button>
    </div>

    <p class="text-sm text-slate-400">
      {{ t('industry.structures.description') }}
    </p>

    <!-- Add/Edit Form -->
    <div v-if="showAddForm" class="bg-slate-800/50 rounded-lg p-4 border border-slate-700">
      <h4 class="text-sm font-medium text-slate-300 mb-4">
        {{ editingStructure ? t('industry.structures.editStructure') : t('industry.structures.newStructure') }}
      </h4>

      <div class="space-y-4">
        <!-- Corporation structures dropdown -->
        <div v-if="!editingStructure">
          <label class="block text-xs text-slate-400 mb-1">{{ t('industry.structures.corpStructures') }}</label>
          <div v-if="!corporationStructuresLoaded" class="text-xs text-slate-500">
            {{ t('common.status.loading') }}
          </div>
          <div v-else-if="store.corporationStructures.length === 0" class="text-xs text-slate-500 bg-slate-900/50 rounded-lg p-2 border border-slate-700">
            {{ t('industry.structures.noCorpStructures') }}
          </div>
          <template v-else>
            <select
              v-model="selectedCorpStructure"
              @change="onCorpStructureSelected"
              class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:outline-hidden focus:border-cyan-500"
            >
              <option :value="null">-- {{ t('industry.structures.selectStructure') }} --</option>
              <option
                v-for="struct in store.corporationStructures"
                :key="struct.locationId"
                :value="struct"
              >
                {{ struct.locationName }}
                ({{ struct.solarSystemName || t('industry.structures.unknownSystem') }})
                {{ struct.sharedConfig ? ` - ${t('industry.structures.rigsConfigured')}` : '' }}
              </option>
            </select>
            <p class="text-xs text-slate-500 mt-1">
              {{ t('industry.structures.selectOrCustom') }}
            </p>
          </template>

          <!-- ESI Search -->
          <div class="mt-3 relative">
            <label class="block text-xs text-slate-400 mb-1">{{ t('industry.structures.searchByNameEsi') }}</label>
            <input
              v-model="esiSearchQuery"
              type="text"
              :placeholder="t('industry.structures.searchStructure')"
              @input="onEsiSearchInput"
              @focus="showEsiSearchDropdown = esiSearchResults.length > 0"
              @blur="hideEsiDropdownDelayed"
              class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:outline-hidden focus:border-cyan-500"
            />
            <div v-if="isSearchingEsi" class="absolute right-3 top-7">
              <LoadingSpinner size="sm" class="text-slate-400" />
            </div>

            <!-- ESI Search Dropdown -->
            <div
              v-if="showEsiSearchDropdown && esiSearchResults.length > 0"
              class="absolute z-10 mt-1 w-full bg-slate-800 border border-slate-600 rounded-lg shadow-lg max-h-60 overflow-y-auto"
            >
              <button
                v-for="result in esiSearchResults"
                :key="result.locationId"
                @mousedown.prevent="selectEsiSearchResult(result)"
                class="w-full px-3 py-2 hover:bg-slate-700 text-left"
              >
                <div class="text-sm text-slate-200">
                  {{ result.isCorporationOwned ? '★ ' : '' }}{{ result.locationName }}
                  <span v-if="result.solarSystemName" class="text-slate-400">({{ result.solarSystemName }})</span>
                </div>
                <div class="text-xs text-slate-500">
                  {{ result.structureType ? result.structureType.charAt(0).toUpperCase() + result.structureType.slice(1) : 'Structure' }}
                  {{ result.isCorporationOwned ? ` - ${t('industry.structures.corpStructure')}` : '' }}
                </div>
              </button>
            </div>

            <!-- ESI Search Error -->
            <div v-if="esiSearchError" class="mt-1 text-xs text-amber-400">
              {{ esiSearchError }}
            </div>
          </div>
        </div>

        <!-- Selected corporation structure info -->
        <div v-if="formLocationId" class="bg-cyan-900/20 border border-cyan-700/50 rounded-lg p-3">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
              </svg>
              <span class="text-sm text-cyan-300">{{ t('industry.structures.corpStructure') }}</span>
            </div>
            <button
              @click="clearCorpStructure"
              class="text-xs text-slate-400 hover:text-slate-200"
            >
              {{ t('industry.structures.switchToCustom') }}
            </button>
          </div>
          <p class="text-xs text-slate-400 mt-1">
            {{ t('industry.structures.rigsSharedWithCorp') }}
          </p>
        </div>

        <!-- Name -->
        <div>
          <label class="block text-xs text-slate-400 mb-1">{{ t('industry.structures.name') }}</label>
          <input
            v-model="formName"
            type="text"
            placeholder="Ex: Tatara C-J6MT"
            class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:outline-hidden focus:border-cyan-500"
          />
        </div>

        <!-- Structure Type -->
        <div>
          <label class="block text-xs text-slate-400 mb-1">{{ t('industry.structures.structureType') }}</label>
          <select
            v-model="formStructureType"
            class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:outline-hidden focus:border-cyan-500"
          >
            <optgroup label="NPC">
              <option value="station">{{ t('industry.structures.npcStationOption') }}</option>
            </optgroup>
            <optgroup label="Engineering Complex">
              <option value="raitaru">Raitaru (Medium) - 1% ME, 15% TE</option>
              <option value="azbel">Azbel (Large) - 1% ME, 20% TE</option>
              <option value="sotiyo">Sotiyo (X-Large) - 1% ME, 30% TE</option>
            </optgroup>
            <optgroup label="Refinery">
              <option value="athanor">Athanor (Medium) - 25% TE reactions</option>
              <option value="tatara">Tatara (Large) - 25% TE reactions</option>
            </optgroup>
          </select>
        </div>

        <!-- Security Type -->
        <div>
          <label class="block text-xs text-slate-400 mb-1">{{ t('industry.structures.security') }}</label>
          <div class="flex gap-3">
            <label
              v-for="(label, key) in securityLabels"
              :key="key"
              class="flex items-center gap-2 cursor-pointer"
            >
              <input
                type="radio"
                :value="key"
                v-model="formSecurityType"
                class="text-cyan-500 focus:ring-cyan-500"
              />
              <span class="text-sm text-slate-300">{{ label }}</span>
            </label>
          </div>
        </div>

        <!-- Rigs Search -->
        <div>
          <label class="block text-xs text-slate-400 mb-2">
            {{ t('industry.structures.rigsLabel') }} - {{ formRigs.length }}/{{ MAX_RIGS }}
          </label>

          <!-- Search Input -->
          <div class="relative">
            <input
              v-model="rigSearchQuery"
              type="text"
              :placeholder="canAddMoreRigs ? t('industry.structures.searchRig') : t('industry.structures.maxRigsReached')"
              :disabled="!canAddMoreRigs"
              class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:outline-hidden focus:border-cyan-500 disabled:opacity-50 disabled:cursor-not-allowed"
              @focus="showRigDropdown = filteredRigs.length > 0"
              @blur="hideDropdownDelayed"
              @input="showRigDropdown = true"
            />

            <!-- Dropdown -->
            <div
              v-if="showRigDropdown && filteredRigs.length > 0"
              class="absolute z-10 mt-1 w-full bg-slate-800 border border-slate-600 rounded-lg shadow-lg max-h-60 overflow-y-auto"
            >
              <button
                v-for="rig in filteredRigs"
                :key="rig.name"
                @mousedown.prevent="addRig(rig)"
                class="w-full px-3 py-2 hover:bg-slate-700 text-left"
              >
                <div class="flex items-center justify-between">
                  <span class="text-sm text-slate-200">{{ formatRigName(rig.name) }}</span>
                  <span class="text-xs ml-2">
                    <template v-if="hasTimeBonus(rig.name)">
                      <span class="text-emerald-400">ME: -{{ rig.bonus }}%</span>
                      <span class="text-amber-400 ml-1">TE: -{{ rig.bonus }}%</span>
                    </template>
                    <span v-else class="text-emerald-400">ME: -{{ rig.bonus }}%</span>
                    <span class="text-slate-500 ml-1">({{ rig.size }})</span>
                  </span>
                </div>
                <div class="text-xs text-slate-500 mt-0.5">{{ formatTargetCategories(rig.targetCategories) }}</div>
              </button>
            </div>

            <!-- No results -->
            <div
              v-if="showRigDropdown && rigSearchQuery.length >= 2 && filteredRigs.length === 0"
              class="absolute z-10 mt-1 w-full bg-slate-800 border border-slate-600 rounded-lg shadow-lg p-3 text-sm text-slate-400"
            >
              {{ t('industry.structures.noRigFound') }}
            </div>
          </div>

          <!-- Selected Rigs -->
          <div v-if="formRigs.length > 0" class="mt-3 space-y-1">
            <div
              v-for="rigInfo in selectedRigsInfo"
              :key="rigInfo.name"
              class="bg-slate-900/50 rounded-lg px-3 py-2"
            >
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-2 flex-1 min-w-0">
                  <span class="text-sm text-slate-300 truncate">{{ formatRigName(rigInfo.name) }}</span>
                  <span class="text-xs shrink-0">
                    <template v-if="hasTimeBonus(rigInfo.name)">
                      <span class="text-emerald-400">ME: -{{ rigInfo.bonus }}%</span>
                      <span class="text-amber-400 ml-1">TE: -{{ rigInfo.bonus }}%</span>
                    </template>
                    <span v-else class="text-emerald-400">ME: -{{ rigInfo.bonus }}%</span>
                    <span class="text-slate-500 ml-1">({{ rigInfo.size }})</span>
                  </span>
                </div>
                <button
                  @click="removeRig(rigInfo.name)"
                  class="ml-2 p-1 text-slate-400 hover:text-red-400 hover:bg-slate-700 rounded-sm shrink-0"
                  :title="t('industry.structures.removeRig')"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
              <div class="text-xs text-slate-500 mt-0.5">{{ formatTargetCategories(rigInfo.targetCategories) }}</div>
            </div>
          </div>

          <p v-else class="mt-2 text-xs text-slate-500">
            {{ t('industry.structures.noRigsSelected') }}
          </p>
        </div>

        <!-- Preview Bonus -->
        <div class="bg-slate-900/50 rounded-lg p-3 border border-slate-700">
          <p class="text-xs text-slate-400 mb-2">{{ t('industry.structures.bonusPreview') }}</p>
          <div class="space-y-2">
            <div v-if="isEngineeringComplex" class="flex flex-wrap gap-4">
              <div>
                <span class="text-xs text-slate-500">ME Manufacturing:</span>
                <span class="text-sm text-emerald-400 ml-1">-{{ previewManufacturingBonus }}%</span>
              </div>
              <div>
                <span class="text-xs text-slate-500">TE Manufacturing:</span>
                <span class="text-sm text-amber-400 ml-1">-{{ previewManufacturingTimeBonus }}%</span>
              </div>
            </div>
            <div v-if="isRefinery" class="flex flex-wrap gap-4">
              <div>
                <span class="text-xs text-slate-500">ME Reactions:</span>
                <span class="text-sm text-emerald-400 ml-1">-{{ previewReactionBonus }}%</span>
              </div>
              <div>
                <span class="text-xs text-slate-500">TE Reactions:</span>
                <span class="text-sm text-amber-400 ml-1">-{{ previewReactionTimeBonus }}%</span>
              </div>
            </div>
            <div v-if="formStructureType === 'station'" class="text-slate-500 text-sm">
              {{ t('industry.structures.npcNoBonus') }}
            </div>
          </div>
        </div>

        <!-- Actions -->
        <div class="flex gap-3 pt-2">
          <button
            @click="saveStructure"
            :disabled="!formName.trim()"
            class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 disabled:bg-slate-600 disabled:cursor-not-allowed rounded-lg text-white text-sm font-medium"
          >
            {{ editingStructure ? t('common.actions.save') : t('common.actions.add') }}
          </button>
          <button
            @click="cancelForm"
            class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-300 text-sm"
          >
            {{ t('common.actions.cancel') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Structures List -->
    <div v-if="store.structures.length > 0" class="space-y-2">
      <div
        v-for="structure in store.structures"
        :key="structure.id"
        class="bg-slate-800/50 rounded-lg p-4 border border-slate-700 hover:border-slate-600"
      >
        <div class="flex items-start justify-between">
          <div class="flex-1">
            <div class="flex items-center gap-2">
              <h4 class="text-sm font-medium text-slate-200">{{ structure.name }}</h4>
              <span
                v-if="structure.isCorporationStructure"
                class="px-1.5 py-0.5 bg-cyan-900/50 text-cyan-400 text-xs rounded-sm border border-cyan-700/50"
              >
                CORPO
              </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">
              {{ structureTypeLabels[structure.structureType] }} -
              {{ securityLabels[structure.securityType] }}
            </p>
            <div class="flex flex-wrap gap-x-4 gap-y-1 mt-2">
              <template v-if="structure.manufacturingMaterialBonus > 0 || structure.manufacturingTimeBonus > 0">
                <div>
                  <span class="text-xs text-slate-500">ME Manuf:</span>
                  <span class="text-sm text-emerald-400 ml-1">-{{ structure.manufacturingMaterialBonus }}%</span>
                </div>
                <div>
                  <span class="text-xs text-slate-500">TE Manuf:</span>
                  <span class="text-sm text-amber-400 ml-1">-{{ structure.manufacturingTimeBonus }}%</span>
                </div>
              </template>
              <template v-if="structure.reactionMaterialBonus > 0 || structure.reactionTimeBonus > 0">
                <div>
                  <span class="text-xs text-slate-500">ME Reactions:</span>
                  <span class="text-sm text-emerald-400 ml-1">-{{ structure.reactionMaterialBonus }}%</span>
                </div>
                <div>
                  <span class="text-xs text-slate-500">TE Reactions:</span>
                  <span class="text-sm text-amber-400 ml-1">-{{ structure.reactionTimeBonus }}%</span>
                </div>
              </template>
            </div>
            <div v-if="structure.rigs.length > 0" class="mt-2 flex flex-wrap gap-1">
              <span
                v-for="rig in structure.rigs"
                :key="rig"
                class="px-2 py-0.5 bg-slate-700 text-slate-300 text-xs rounded-sm"
              >
                {{ formatRigName(rig) }}
              </span>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button
              @click="openEditForm(structure)"
              class="p-1.5 text-slate-400 hover:text-slate-200 hover:bg-slate-700 rounded-sm"
              :title="t('common.actions.edit')"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
              </svg>
            </button>
            <button
              @click="openDeleteModal(structure)"
              class="p-1.5 text-slate-400 hover:text-red-400 hover:bg-slate-700 rounded-sm"
              :title="t('common.actions.delete')"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div
      v-else-if="!showAddForm"
      class="text-center py-8 text-slate-500"
    >
      <p>{{ t('industry.structures.noStructures') }}</p>
      <p class="text-sm mt-1">{{ t('industry.structures.noStructuresHint') }}</p>
    </div>

    <!-- Delete Confirmation Modal -->
    <Teleport to="body">
      <div
        v-if="showDeleteModal"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60"
        @click.self="cancelDelete"
        @keydown.enter="confirmDelete"
        @keydown.escape="cancelDelete"
      >
        <div class="bg-slate-900 rounded-xl border border-slate-700 max-w-md w-full p-6" tabindex="-1" ref="deleteModalRef">
          <h3 class="text-lg font-semibold text-slate-200 mb-2">{{ t('industry.structures.deleteTitle') }}</h3>
          <p class="text-slate-400 mb-6">
            {{ t('industry.structures.deleteConfirm', { name: deleteStructureName }) }}
          </p>
          <div class="flex gap-3">
            <button
              @click="cancelDelete"
              class="flex-1 py-2 bg-slate-800 hover:bg-slate-700 rounded-lg text-slate-300"
            >
              {{ t('common.actions.cancel') }}
            </button>
            <button
              @click="confirmDelete"
              class="flex-1 py-2 bg-red-600 hover:bg-red-500 rounded-lg text-white font-medium"
            >
              {{ t('common.actions.delete') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Corporation Structure Edit Confirmation Modal -->
    <Teleport to="body">
      <div
        v-if="showCorpEditModal"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60"
        @click.self="cancelCorpEdit"
        @keydown.enter="confirmCorpEdit"
        @keydown.escape="cancelCorpEdit"
      >
        <div class="bg-slate-900 rounded-xl border border-amber-700/50 max-w-md w-full p-6" tabindex="-1" ref="corpEditModalRef">
          <div class="flex items-center gap-3 mb-4">
            <div class="p-2 bg-amber-500/20 rounded-lg">
              <svg class="w-6 h-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
            </div>
            <h3 class="text-lg font-semibold text-slate-200">{{ t('industry.structures.editCorpTitle') }}</h3>
          </div>
          <p class="text-slate-400 mb-2">
            {{ t('industry.structures.editCorpWarning1') }}
          </p>
          <p class="text-slate-400 mb-6">
            {{ t('industry.structures.editCorpWarning2') }}
          </p>
          <div class="flex gap-3">
            <button
              @click="cancelCorpEdit"
              class="flex-1 py-2 bg-slate-800 hover:bg-slate-700 rounded-lg text-slate-300"
            >
              {{ t('common.actions.cancel') }}
            </button>
            <button
              @click="confirmCorpEdit"
              class="flex-1 py-2 bg-amber-600 hover:bg-amber-500 rounded-lg text-white font-medium"
            >
              {{ t('common.actions.confirm') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
