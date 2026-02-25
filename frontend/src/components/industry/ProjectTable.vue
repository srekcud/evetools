<script setup lang="ts">
import { ref, computed, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useIndustryStore, type IndustryProject } from '@/stores/industry'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import { parseIskValue } from '@/composables/useIskParser'
import ConfirmModal from '@/components/common/ConfirmModal.vue'

const { t } = useI18n()

const props = withDefaults(defineProps<{
  compact?: boolean
  activeOnly?: boolean
}>(), {
  compact: false,
  activeOnly: false,
})

const emit = defineEmits<{
  'view-project': [id: string]
  'duplicate-project': [project: IndustryProject]
}>()

const store = useIndustryStore()
const { formatIsk } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

// Computed for filtered projects based on activeOnly prop
const displayedProjects = computed(() => {
  if (props.activeOnly) {
    return store.projects.filter(p => p.status === 'active')
  }
  return store.projects
})

// Helper to clean type names (remove BPC suffix if present)
function cleanTypeName(name: string): string {
  return name.replace(/\s*\(BPC\)\s*$/i, '').trim()
}

// Sorting state
type SortField = 'name' | 'status' | 'runs' | 'materials' | 'jobCost' | 'taxes' | 'sellPrice' | 'profit'
const sortField = ref<SortField>('profit')
const sortDirection = ref<'asc' | 'desc'>('desc')

function toggleSort(field: SortField) {
  if (sortField.value === field) {
    sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortField.value = field
    sortDirection.value = 'desc'
  }
}

function getSortValue(project: IndustryProject, field: SortField): number | string {
  switch (field) {
    case 'name': return project.displayName.toLowerCase()
    case 'status': return project.status
    case 'runs': return project.runs
    case 'materials': return project.materialCost ?? project.estimatedMaterialCost ?? 0
    case 'jobCost': return project.jobsCost ?? project.estimatedJobCost ?? 0
    case 'taxes': return project.taxAmount ?? project.estimatedTaxAmount ?? 0
    case 'sellPrice': return project.sellPrice ?? project.estimatedSellPrice ?? 0
    case 'profit': return project.profit ?? 0
  }
}

const sortedProjects = computed(() => {
  const projects = [...displayedProjects.value]
  const dir = sortDirection.value === 'asc' ? 1 : -1
  return projects.sort((a, b) => {
    const va = getSortValue(a, sortField.value)
    const vb = getSortValue(b, sortField.value)
    if (typeof va === 'string' && typeof vb === 'string') {
      return va.localeCompare(vb) * dir
    }
    return ((va as number) - (vb as number)) * dir
  })
})

// Inline editing state
const editingCell = ref<{ id: string; field: string } | null>(null)
const editValue = ref<string>('')

function isEditable(project: IndustryProject): boolean {
  return project.status !== 'completed'
}

function startEdit(project: IndustryProject, field: string) {
  if (!isEditable(project)) return
  const value = (project as unknown as Record<string, unknown>)[field]
  editValue.value = value !== null && value !== undefined ? String(value) : ''
  editingCell.value = { id: project.id, field }
}

// ME/TE combined editing
const editingMeTe = ref<string | null>(null)
const editMeValue = ref<string>('')
const editTeValue = ref<string>('')
const meInputRef = ref<HTMLInputElement | null>(null)

function startEditMeTe(project: IndustryProject) {
  if (!isEditable(project)) return
  editMeValue.value = String(project.meLevel)
  editTeValue.value = String(project.teLevel)
  editingMeTe.value = project.id
  nextTick(() => meInputRef.value?.focus())
}

async function saveMeTe(project: IndustryProject) {
  if (!editingMeTe.value) return
  const me = parseInt(editMeValue.value, 10)
  const te = parseInt(editTeValue.value, 10)
  editingMeTe.value = null

  const updates: Partial<IndustryProject> = {}
  if (!isNaN(me) && me >= 0 && me <= 10) updates.meLevel = me
  if (!isNaN(te) && te >= 0 && te <= 20) updates.teLevel = te

  if (Object.keys(updates).length > 0) {
    await store.updateProject(project.id, updates)
    await store.fetchProjects()
  }
}

function cancelMeTe() {
  editingMeTe.value = null
}

async function saveEdit(project: IndustryProject) {
  if (!editingCell.value) return
  const field = editingCell.value.field

  let numValue: number | null
  if (field === 'runs') {
    const parsed = parseInt(editValue.value, 10)
    numValue = isNaN(parsed) || parsed < 1 ? project.runs : parsed
  } else {
    numValue = parseIskValue(editValue.value)
  }

  editingCell.value = null

  await store.updateProject(project.id, { [field]: numValue } as Partial<IndustryProject>)
  await store.fetchProjects()
}

function cancelEdit() {
  editingCell.value = null
}

function isEditing(id: string, field: string): boolean {
  return editingCell.value?.id === id && editingCell.value?.field === field
}

function isEditingMeTe(id: string): boolean {
  return editingMeTe.value === id
}

// Merged value display: show actual if set, otherwise show estimated with "est." prefix
function getMergedValue(actual: number | null | undefined, estimated: number | null | undefined): { value: number | null; isEstimated: boolean } {
  if (actual != null && actual > 0) return { value: actual, isEstimated: false }
  if (estimated != null) return { value: estimated, isEstimated: true }
  return { value: null, isEstimated: false }
}

function formatValue(value: number | null | undefined): string {
  if (value == null) return '-'
  return formatIsk(value)
}

function profitClass(profit: number | null | undefined): string {
  if (profit == null) return 'text-slate-400'
  return profit >= 0 ? 'text-emerald-400' : 'text-red-400'
}

function profitSign(percent: number | null | undefined): string {
  if (percent == null) return ''
  return percent >= 0 ? '+' : ''
}

async function toggleStatus(project: IndustryProject) {
  const newStatus = project.status === 'active' ? 'completed' : 'active'
  await store.updateProject(project.id, { status: newStatus })
  await store.fetchProjects()
}

// Delete confirmation state
const showDeleteModal = ref(false)
const deleteTarget = ref<IndustryProject | null>(null)
const isDeleting = ref(false)

function requestDelete(project: IndustryProject) {
  deleteTarget.value = project
  showDeleteModal.value = true
}

async function confirmDelete() {
  if (!deleteTarget.value) return
  isDeleting.value = true
  try {
    await store.deleteProject(deleteTarget.value.id)
    showDeleteModal.value = false
    deleteTarget.value = null
  } finally {
    isDeleting.value = false
  }
}

function cancelDelete() {
  showDeleteModal.value = false
  deleteTarget.value = null
}

function isMultiRoot(project: IndustryProject): boolean {
  if (!project.rootProducts) return false
  if (project.rootProducts.length > 1) return true
  return project.rootProducts.length === 1 && (project.rootProducts[0].count ?? 1) > 1
}

function rootStepCount(project: IndustryProject): number {
  if (!project.rootProducts) return 0
  return project.rootProducts.reduce((s, p) => s + (p.count ?? 1), 0)
}

function navigateToMargins(project: IndustryProject): void {
  store.navigationIntent = { target: 'margins', typeId: project.productTypeId }
}

function formatRootProducts(project: IndustryProject): string {
  if (!project.rootProducts) return ''
  return project.rootProducts.map(p => {
    const me = p.meLevel ?? project.meLevel
    const count = p.count ?? 1
    return count > 1
      ? `${cleanTypeName(p.typeName)} ${count}x${p.runs / count} runs (ME${me})`
      : `${cleanTypeName(p.typeName)} x${p.runs} (ME${me})`
  }).join(', ')
}
</script>

<template>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase tracking-wider">
          <th class="text-left py-3 px-4 cursor-pointer select-none hover:text-slate-200 transition-colors" @click="toggleSort('name')">
            <span class="flex items-center gap-1">
              {{ t('industry.table.product') }}
              <svg v-if="sortField === 'name'" class="w-3 h-3 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path v-if="sortDirection === 'asc'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11l5-5m0 0l5 5m-5-5v12" />
                <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
              </svg>
            </span>
          </th>
          <th class="text-center py-3 px-2 cursor-pointer select-none hover:text-slate-200 transition-colors" @click="toggleSort('status')">
            Status
          </th>
          <th class="text-right py-3 px-2 cursor-pointer select-none hover:text-slate-200 transition-colors" @click="toggleSort('runs')">
            Runs
          </th>
          <template v-if="!props.compact">
            <th class="text-center py-3 px-2">ME/TE</th>
            <th class="text-right py-3 px-2 cursor-pointer select-none hover:text-slate-200 transition-colors" @click="toggleSort('materials')">
              <span class="flex items-center justify-end gap-1">
                {{ t('industry.table.materials') }}
                <svg v-if="sortField === 'materials'" class="w-3 h-3 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path v-if="sortDirection === 'asc'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11l5-5m0 0l5 5m-5-5v12" />
                  <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                </svg>
              </span>
            </th>
            <th class="text-right py-3 px-2 cursor-pointer select-none hover:text-slate-200 transition-colors" @click="toggleSort('jobCost')">
              {{ t('industry.table.jobCost') }}
            </th>
            <th class="text-right py-3 px-2 cursor-pointer select-none hover:text-slate-200 transition-colors" @click="toggleSort('taxes')">
              {{ t('industry.table.taxes') }}
            </th>
            <th class="text-right py-3 px-2 cursor-pointer select-none hover:text-slate-200 transition-colors" @click="toggleSort('sellPrice')">
              {{ t('industry.table.sell') }}
            </th>
            <th class="text-right py-3 px-3 cursor-pointer select-none hover:text-slate-200 transition-colors" @click="toggleSort('profit')">
              <span class="flex items-center justify-end gap-1">
                {{ t('industry.table.profit') }}
                <svg v-if="sortField === 'profit'" class="w-3 h-3 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path v-if="sortDirection === 'asc'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11l5-5m0 0l5 5m-5-5v12" />
                  <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                </svg>
              </span>
            </th>
          </template>
          <th class="text-center py-3 px-2 w-20"></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-800">
        <tr
          v-for="(project, index) in sortedProjects"
          :key="project.id"
          class="hover:bg-slate-800/50 group"
          :class="{ 'bg-slate-900/30': index % 2 === 1 }"
        >
          <!-- Product name + icon -->
          <td class="py-3.5 px-4">
            <button
              @click="emit('view-project', project.id)"
              class="text-left flex items-center gap-3"
            >
              <!-- Multi-product indicator -->
              <template v-if="project.rootProducts && isMultiRoot(project)">
                <span class="inline-flex items-center justify-center w-8 h-8 text-xs bg-cyan-500/20 text-cyan-400 rounded border border-cyan-500/20 flex-shrink-0 font-semibold">
                  {{ rootStepCount(project) }}
                </span>
                <div>
                  <span class="text-cyan-400 hover:text-cyan-300 font-medium">{{ project.displayName }}</span>
                  <div class="text-xs text-slate-500 mt-0.5">{{ formatRootProducts(project) }}</div>
                </div>
              </template>
              <!-- Single product -->
              <template v-else>
                <div class="w-8 h-8 rounded bg-slate-800 border border-slate-700 overflow-hidden flex-shrink-0">
                  <img
                    :src="getTypeIconUrl(project.productTypeId, 64)"
                    :alt="project.displayName"
                    class="w-full h-full object-cover"
                    @error="onImageError"
                  />
                </div>
                <div class="flex items-center gap-2">
                  <span class="text-cyan-400 hover:text-cyan-300 font-medium">{{ project.displayName }}</span>
                  <span v-if="project.name" class="text-slate-500 text-xs">({{ project.productTypeName }})</span>
                  <span v-if="project.isT2" class="text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 bg-blue-500/20 text-blue-400 rounded">T2</span>
                </div>
              </template>
            </button>
          </td>

          <!-- Status badge -->
          <td class="py-3.5 px-2 text-center">
            <button
              @click="toggleStatus(project)"
              :class="[
                'text-xs px-2 py-0.5 rounded border',
                project.status === 'completed'
                  ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20'
                  : 'bg-cyan-500/15 text-cyan-400 border-cyan-500/20',
              ]"
            >
              {{ project.status === 'completed' ? t('industry.stepStatus.completed') : t('common.status.active') }}
            </button>
          </td>

          <!-- Runs (editable) -->
          <td class="py-3.5 px-2 text-right">
            <template v-if="project.rootProducts && project.rootProducts.length > 1">
              <span class="text-slate-500 text-xs" :title="t('industry.table.multipleProducts')">Multi</span>
            </template>
            <template v-else>
              <input
                v-if="isEditing(project.id, 'runs')"
                v-model="editValue"
                type="number"
                min="1"
                class="w-16 bg-slate-700 border border-cyan-500 rounded px-2 py-1 text-sm text-right focus:outline-hidden"
                @keydown.enter="saveEdit(project)"
                @keydown.escape="cancelEdit"
                @blur="saveEdit(project)"
                autofocus
              />
              <span
                v-else-if="isEditable(project)"
                @dblclick="startEdit(project, 'runs')"
                class="editable-value font-mono text-slate-300 inline-block relative cursor-pointer"
                :title="t('industry.table.doubleClickEdit')"
              >
                {{ project.runs }}
                <span class="pencil-icon">
                  <svg class="w-3 h-3 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                  </svg>
                </span>
              </span>
              <span v-else class="font-mono text-slate-500">{{ project.runs }}</span>
            </template>
          </td>

          <template v-if="!props.compact">
            <!-- ME/TE (merged, editable) -->
            <td class="py-3.5 px-2 text-center">
              <template v-if="isEditingMeTe(project.id)">
                <div class="flex items-center justify-center gap-1">
                  <input
                    ref="meInputRef"
                    v-model="editMeValue"
                    type="number"
                    min="0"
                    max="10"
                    class="w-10 bg-slate-700 border border-cyan-500 rounded px-1 py-1 text-sm text-center focus:outline-hidden"
                    @keydown.enter="saveMeTe(project)"
                    @keydown.escape="cancelMeTe"
                  />
                  <span class="text-slate-600">/</span>
                  <input
                    v-model="editTeValue"
                    type="number"
                    min="0"
                    max="20"
                    class="w-10 bg-slate-700 border border-cyan-500 rounded px-1 py-1 text-sm text-center focus:outline-hidden"
                    @keydown.enter="saveMeTe(project)"
                    @keydown.escape="cancelMeTe"
                    @blur="saveMeTe(project)"
                  />
                </div>
              </template>
              <span
                v-else-if="isEditable(project)"
                @dblclick="startEditMeTe(project)"
                class="editable-value font-mono text-slate-300 inline-block relative cursor-pointer"
                :title="t('industry.table.doubleClickEdit')"
              >
                {{ project.meLevel }}/{{ project.teLevel }}
                <span class="pencil-icon" style="right: -18px;">
                  <svg class="w-3 h-3 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                  </svg>
                </span>
              </span>
              <span v-else class="font-mono text-slate-500">{{ project.meLevel }}/{{ project.teLevel }}</span>
            </td>

            <!-- Materials (merged actual/estimated) -->
            <td class="py-3.5 px-2 text-right">
              <span class="font-mono" :class="getMergedValue(project.materialCost, project.estimatedMaterialCost).isEstimated ? 'italic text-slate-500' : 'text-slate-300'">
                <span v-if="getMergedValue(project.materialCost, project.estimatedMaterialCost).isEstimated" class="text-[9px] uppercase tracking-wide text-slate-600 mr-0.5">est.</span>
                {{ formatValue(getMergedValue(project.materialCost, project.estimatedMaterialCost).value) }}
              </span>
            </td>

            <!-- Job Cost (merged actual/estimated) -->
            <td class="py-3.5 px-2 text-right">
              <span class="font-mono" :class="getMergedValue(project.jobsCost, project.estimatedJobCost).isEstimated ? 'italic text-slate-500' : 'text-slate-300'">
                <span v-if="getMergedValue(project.jobsCost, project.estimatedJobCost).isEstimated" class="text-[9px] uppercase tracking-wide text-slate-600 mr-0.5">est.</span>
                {{ formatValue(getMergedValue(project.jobsCost, project.estimatedJobCost).value) }}
              </span>
            </td>

            <!-- Taxes (merged actual/estimated, or N/A for personal use) -->
            <td class="py-3.5 px-2 text-right">
              <template v-if="project.personalUse">
                <span class="text-slate-600 font-mono">N/A</span>
              </template>
              <template v-else>
                <span class="font-mono" :class="getMergedValue(project.taxAmount, project.estimatedTaxAmount).isEstimated ? 'italic text-slate-500' : 'text-slate-300'">
                  <span v-if="getMergedValue(project.taxAmount, project.estimatedTaxAmount).isEstimated" class="text-[9px] uppercase tracking-wide text-slate-600 mr-0.5">est.</span>
                  {{ formatValue(getMergedValue(project.taxAmount, project.estimatedTaxAmount).value) }}
                </span>
              </template>
            </td>

            <!-- Sell Price (editable, merged actual/estimated, or N/A for personal use) -->
            <td class="py-3.5 px-2 text-right">
              <span v-if="project.personalUse" class="text-slate-600 font-mono">N/A</span>
              <input
                v-else-if="isEditing(project.id, 'sellPrice')"
                v-model="editValue"
                type="text"
                placeholder="ex: 10M, 1.5B"
                class="w-24 bg-slate-700 border border-cyan-500 rounded px-2 py-1 text-right text-sm focus:outline-hidden"
                @keydown.enter="saveEdit(project)"
                @keydown.escape="cancelEdit"
                @blur="saveEdit(project)"
                autofocus
              />
              <!-- Editable sell price (active projects with a value) -->
              <span
                v-else-if="getMergedValue(project.sellPrice, project.estimatedSellPrice).value != null && isEditable(project)"
                @dblclick="startEdit(project, 'sellPrice')"
                class="editable-value font-mono inline-block relative cursor-pointer"
                :class="getMergedValue(project.sellPrice, project.estimatedSellPrice).isEstimated ? 'italic text-slate-500' : 'text-slate-300'"
                :title="t('industry.table.doubleClickEdit')"
              >
                <span v-if="getMergedValue(project.sellPrice, project.estimatedSellPrice).isEstimated" class="text-[9px] uppercase tracking-wide text-slate-600 mr-0.5 not-italic">est.</span>
                {{ formatValue(getMergedValue(project.sellPrice, project.estimatedSellPrice).value) }}
                <span class="pencil-icon">
                  <svg class="w-3 h-3 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                  </svg>
                </span>
              </span>
              <!-- Non-editable sell price (completed projects with a value) -->
              <span
                v-else-if="getMergedValue(project.sellPrice, project.estimatedSellPrice).value != null"
                class="font-mono"
                :class="getMergedValue(project.sellPrice, project.estimatedSellPrice).isEstimated ? 'italic text-slate-500' : 'text-slate-500'"
              >
                <span v-if="getMergedValue(project.sellPrice, project.estimatedSellPrice).isEstimated" class="text-[9px] uppercase tracking-wide text-slate-600 mr-0.5 not-italic">est.</span>
                {{ formatValue(getMergedValue(project.sellPrice, project.estimatedSellPrice).value) }}
              </span>
              <!-- No price -->
              <span v-else class="font-mono text-slate-600">-</span>
            </td>

            <!-- Profit (merged amount + percentage) -->
            <td class="py-3.5 px-3 text-right">
              <template v-if="project.personalUse">
                <span class="text-slate-600 font-mono">N/A</span>
              </template>
              <template v-else-if="project.profit != null">
                <div class="flex items-center justify-end gap-1.5">
                  <span class="font-mono font-semibold tabular-nums" :class="profitClass(project.profit)">
                    {{ formatIsk(project.profit) }}
                  </span>
                  <span v-if="project.profitPercent != null" class="text-xs font-mono" :class="project.profit >= 0 ? 'text-emerald-400/70' : 'text-red-400/70'">
                    ({{ profitSign(project.profitPercent) }}{{ project.profitPercent.toFixed(1) }}%)
                  </span>
                </div>
              </template>
              <template v-else>
                <span class="font-mono text-slate-600">-</span>
              </template>
            </td>
          </template>

          <!-- Actions -->
          <td class="py-3.5 px-2">
            <div class="flex items-center justify-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
              <button
                @click="emit('view-project', project.id)"
                class="p-1.5 hover:bg-slate-700 rounded text-slate-400 hover:text-cyan-400"
                :title="t('industry.table.details')"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                </svg>
              </button>
              <!-- Analyze Margin -->
              <button
                @click="navigateToMargins(project)"
                class="p-1.5 hover:bg-slate-700 rounded text-slate-400 hover:text-cyan-400"
                :title="t('industry.tabs.margins')"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
              </button>
              <template v-if="!props.compact">
                <button
                  @click="emit('duplicate-project', project)"
                  class="p-1.5 hover:bg-slate-700 rounded text-slate-400 hover:text-cyan-400"
                  :title="t('industry.table.duplicate')"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
                <button
                  @click="requestDelete(project)"
                  class="p-1.5 hover:bg-red-500/20 rounded text-slate-400 hover:text-red-400"
                  :title="t('common.actions.delete')"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                </button>
              </template>
            </div>
          </td>
        </tr>
      </tbody>

      <!-- Total row (hidden in compact mode) -->
      <tfoot v-if="store.projects.length > 0 && !props.compact">
        <tr class="border-t border-slate-600 font-semibold text-slate-200">
          <td colspan="8" class="py-3.5 px-4 text-right">{{ t('industry.table.totalProfit') }}</td>
          <td class="py-3.5 px-3 text-right">
            <span class="font-mono text-lg tabular-nums" :class="profitClass(store.totalProfit)">
              {{ formatValue(store.totalProfit) }}
            </span>
          </td>
          <td></td>
        </tr>
      </tfoot>
    </table>

    <!-- Legend footer (hidden in compact mode) -->
    <div v-if="!props.compact && displayedProjects.length > 0" class="px-6 py-3 border-t border-slate-800 flex items-center gap-6 text-xs text-slate-500">
      <div class="flex items-center gap-2">
        <span class="inline-block border-b border-dashed border-slate-400/30 px-1 text-slate-400">{{ t('industry.table.legendEditable') }}</span>
        <span>= {{ t('industry.table.legendEditableDesc') }}</span>
      </div>
      <div class="flex items-center gap-2">
        <span class="italic text-slate-500 text-[11px]"><span class="text-[9px] uppercase tracking-wide text-slate-600 not-italic">est.</span>value</span>
        <span>= {{ t('industry.table.legendEstimatedDesc') }}</span>
      </div>
      <div class="flex items-center gap-2">
        <span class="text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 bg-blue-500/20 text-blue-400 rounded">T2</span>
        <span>= {{ t('industry.table.legendT2Desc') }}</span>
      </div>
    </div>

    <div v-if="displayedProjects.length === 0" class="text-center py-12 text-slate-500">
      {{ t('industry.project.noProjectsDescription') }}
    </div>
  </div>

  <!-- Delete Confirmation Modal -->
  <ConfirmModal
    :show="showDeleteModal && deleteTarget != null"
    :title="t('industry.project.confirmDelete')"
    :subtitle="deleteTarget?.displayName"
    :message="t('industry.project.confirmDeleteMessage')"
    :confirm-label="isDeleting ? t('common.actions.loading') : t('common.actions.delete')"
    :is-loading="isDeleting"
    confirm-color="red"
    icon="delete"
    @confirm="confirmDelete"
    @cancel="cancelDelete"
  />
</template>

<style scoped>
/* Editable cell styling matching mockup */
.editable-value {
  border-bottom: 1px dashed rgba(148, 163, 184, 0.3);
  transition: border-color 0.15s ease, color 0.15s ease;
}
.editable-value:hover {
  border-bottom-color: rgba(34, 211, 238, 0.5);
  color: #22d3ee;
}

/* Pencil icon on hover */
.editable-value .pencil-icon {
  opacity: 0;
  transition: opacity 0.15s ease;
  position: absolute;
  right: -16px;
  top: 50%;
  transform: translateY(-50%);
}
.editable-value:hover .pencil-icon {
  opacity: 1;
}

/* Tabular nums for profit alignment */
.tabular-nums {
  font-variant-numeric: tabular-nums;
}
</style>
