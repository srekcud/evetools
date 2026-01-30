<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useIndustryStore } from '@/stores/industry'
import type { StructureConfig, RigOption } from '@/stores/industry'

const store = useIndustryStore()

const showAddForm = ref(false)
const editingStructure = ref<StructureConfig | null>(null)

// Form state
const formName = ref('')
const formSecurityType = ref<'highsec' | 'lowsec' | 'nullsec'>('nullsec')
const formStructureType = ref<string>('raitaru')
const formRigs = ref<string[]>([])

// Rig search state
const rigSearchQuery = ref('')
const showRigDropdown = ref(false)

onMounted(() => {
  store.fetchStructures()
})

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

const structureTypeLabels: Record<string, string> = {
  station: 'Station NPC (aucun bonus)',
  raitaru: 'Raitaru (EC Medium) - 1% ME',
  azbel: 'Azbel (EC Large) - 1% ME, 20% TE',
  sotiyo: 'Sotiyo (EC X-Large) - 1% ME, 30% TE',
  athanor: 'Athanor (Refinery Medium) - 25% TE',
  tatara: 'Tatara (Refinery Large) - 25% TE',
  // Legacy support
  engineering_complex: 'Engineering Complex (legacy)',
  refinery: 'Refinery (legacy)',
}

const availableRigs = computed(() => {
  if (!store.rigOptions) return []
  // Refineries use reaction rigs
  if (['athanor', 'tatara', 'refinery'].includes(formStructureType.value)) {
    return store.rigOptions.reaction
  }
  // Engineering complexes use manufacturing rigs
  if (['raitaru', 'azbel', 'sotiyo', 'engineering_complex'].includes(formStructureType.value)) {
    return store.rigOptions.manufacturing
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
  formSecurityType.value = 'nullsec'
  formStructureType.value = 'raitaru'
  formRigs.value = []
  editingStructure.value = null
  rigSearchQuery.value = ''
  showRigDropdown.value = false
}

const isRefinery = computed(() => ['athanor', 'tatara', 'refinery'].includes(formStructureType.value))
const isEngineeringComplex = computed(() => ['raitaru', 'azbel', 'sotiyo', 'engineering_complex'].includes(formStructureType.value))

function openAddForm() {
  resetForm()
  showAddForm.value = true
}

function openEditForm(structure: StructureConfig) {
  formName.value = structure.name
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

async function saveStructure() {
  const data = {
    name: formName.value,
    securityType: formSecurityType.value,
    structureType: formStructureType.value as StructureConfig['structureType'],
    rigs: formRigs.value,
  }

  try {
    if (editingStructure.value) {
      await store.updateStructure(editingStructure.value.id, data)
    } else {
      await store.createStructure(data)
    }
    showAddForm.value = false
    resetForm()
  } catch (e) {
    // Error is handled by store
  }
}

// Delete confirmation modal
const showDeleteModal = ref(false)
const deleteStructureId = ref<string | null>(null)
const deleteStructureName = ref('')

function openDeleteModal(structure: StructureConfig) {
  deleteStructureId.value = structure.id
  deleteStructureName.value = structure.name
  showDeleteModal.value = true
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

function addRig(rig: RigOption) {
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
}

function formatTargetCategories(categories: string[]): string {
  if (!categories || categories.length === 0) return ''
  return categories.map(c => categoryLabels[c] || c).join(', ')
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

const previewManufacturingBonus = computed(() => {
  return calculateBonus(formRigs.value, 'manufacturing', formSecurityType.value)
})

const previewReactionBonus = computed(() => {
  return calculateBonus(formRigs.value, 'reaction', formSecurityType.value)
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h3 class="text-lg font-semibold text-slate-200">Structures de production</h3>
      <button
        v-if="!showAddForm"
        @click="openAddForm"
        class="px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        Ajouter
      </button>
    </div>

    <p class="text-sm text-slate-400">
      Configurez vos structures de production pour calculer les bonus de matériaux appliqués aux projets.
    </p>

    <!-- Add/Edit Form -->
    <div v-if="showAddForm" class="bg-slate-800/50 rounded-lg p-4 border border-slate-700">
      <h4 class="text-sm font-medium text-slate-300 mb-4">
        {{ editingStructure ? 'Modifier la structure' : 'Nouvelle structure' }}
      </h4>

      <div class="space-y-4">
        <!-- Name -->
        <div>
          <label class="block text-xs text-slate-400 mb-1">Nom</label>
          <input
            v-model="formName"
            type="text"
            placeholder="Ex: Tatara C-J6MT"
            class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:outline-none focus:border-cyan-500"
          />
        </div>

        <!-- Structure Type -->
        <div>
          <label class="block text-xs text-slate-400 mb-1">Type de structure</label>
          <select
            v-model="formStructureType"
            class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:outline-none focus:border-cyan-500"
          >
            <optgroup label="NPC">
              <option value="station">Station NPC (aucun bonus)</option>
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
          <label class="block text-xs text-slate-400 mb-1">Sécurité</label>
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
          <label class="block text-xs text-slate-400 mb-2">Rigs (bonus matériaux)</label>

          <!-- Search Input -->
          <div class="relative">
            <input
              v-model="rigSearchQuery"
              type="text"
              placeholder="Rechercher un rig..."
              class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-slate-200 text-sm focus:outline-none focus:border-cyan-500"
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
                  <span class="text-xs text-cyan-400 ml-2">-{{ rig.bonus }}% ({{ rig.size }})</span>
                </div>
                <div class="text-xs text-slate-500 mt-0.5">{{ formatTargetCategories(rig.targetCategories) }}</div>
              </button>
            </div>

            <!-- No results -->
            <div
              v-if="showRigDropdown && rigSearchQuery.length >= 2 && filteredRigs.length === 0"
              class="absolute z-10 mt-1 w-full bg-slate-800 border border-slate-600 rounded-lg shadow-lg p-3 text-sm text-slate-400"
            >
              Aucun rig trouvé
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
                  <span class="text-xs text-cyan-400 shrink-0">-{{ rigInfo.bonus }}% ({{ rigInfo.size }})</span>
                </div>
                <button
                  @click="removeRig(rigInfo.name)"
                  class="ml-2 p-1 text-slate-400 hover:text-red-400 hover:bg-slate-700 rounded shrink-0"
                  title="Retirer"
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
            Aucun rig sélectionné. Recherchez et ajoutez des rigs pour appliquer des bonus.
          </p>
        </div>

        <!-- Preview Bonus -->
        <div class="bg-slate-900/50 rounded-lg p-3 border border-slate-700">
          <p class="text-xs text-slate-400 mb-2">Bonus calculé (base structure + rigs × sécurité)</p>
          <div class="flex gap-4">
            <div v-if="isEngineeringComplex">
              <span class="text-xs text-slate-500">Manufacturing:</span>
              <span class="text-sm text-emerald-400 ml-1">-{{ previewManufacturingBonus }}%</span>
            </div>
            <div v-if="isRefinery">
              <span class="text-xs text-slate-500">Reactions:</span>
              <span class="text-sm text-emerald-400 ml-1">-{{ previewReactionBonus }}%</span>
            </div>
            <div v-if="formStructureType === 'station'" class="text-slate-500 text-sm">
              Les stations NPC n'ont pas de bonus
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
            {{ editingStructure ? 'Enregistrer' : 'Ajouter' }}
          </button>
          <button
            @click="cancelForm"
            class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-300 text-sm"
          >
            Annuler
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
            <h4 class="text-sm font-medium text-slate-200">{{ structure.name }}</h4>
            <p class="text-xs text-slate-500 mt-1">
              {{ structureTypeLabels[structure.structureType] }} -
              {{ securityLabels[structure.securityType] }}
            </p>
            <div class="flex gap-4 mt-2">
              <div v-if="structure.manufacturingMaterialBonus > 0">
                <span class="text-xs text-slate-500">Manufacturing:</span>
                <span class="text-sm text-emerald-400 ml-1">-{{ structure.manufacturingMaterialBonus }}%</span>
              </div>
              <div v-if="structure.reactionMaterialBonus > 0">
                <span class="text-xs text-slate-500">Reactions:</span>
                <span class="text-sm text-emerald-400 ml-1">-{{ structure.reactionMaterialBonus }}%</span>
              </div>
            </div>
            <div v-if="structure.rigs.length > 0" class="mt-2 flex flex-wrap gap-1">
              <span
                v-for="rig in structure.rigs"
                :key="rig"
                class="px-2 py-0.5 bg-slate-700 text-slate-300 text-xs rounded"
              >
                {{ formatRigName(rig) }}
              </span>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button
              @click="openEditForm(structure)"
              class="p-1.5 text-slate-400 hover:text-slate-200 hover:bg-slate-700 rounded"
              title="Modifier"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
              </svg>
            </button>
            <button
              @click="openDeleteModal(structure)"
              class="p-1.5 text-slate-400 hover:text-red-400 hover:bg-slate-700 rounded"
              title="Supprimer"
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
      <p>Aucune structure configurée</p>
      <p class="text-sm mt-1">Ajoutez vos structures pour calculer les bonus de matériaux</p>
    </div>

    <!-- Delete Confirmation Modal -->
    <Teleport to="body">
      <div
        v-if="showDeleteModal"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60"
        @click.self="cancelDelete"
      >
        <div class="bg-slate-900 rounded-xl border border-slate-700 max-w-md w-full p-6">
          <h3 class="text-lg font-semibold text-slate-200 mb-2">Supprimer la structure</h3>
          <p class="text-slate-400 mb-6">
            Voulez-vous vraiment supprimer <span class="text-slate-200 font-medium">{{ deleteStructureName }}</span> ?
          </p>
          <div class="flex gap-3">
            <button
              @click="cancelDelete"
              class="flex-1 py-2 bg-slate-800 hover:bg-slate-700 rounded-lg text-slate-300"
            >
              Annuler
            </button>
            <button
              @click="confirmDelete"
              class="flex-1 py-2 bg-red-600 hover:bg-red-500 rounded-lg text-white font-medium"
            >
              Supprimer
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
