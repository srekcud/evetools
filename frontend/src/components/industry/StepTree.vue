<script setup lang="ts">
import { computed, ref, watch, nextTick } from 'vue'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import type { IndustryProjectStep, SimilarJob } from '@/stores/industry'

const props = defineProps<{
  steps: IndustryProjectStep[]
  readonly?: boolean
}>()

const emit = defineEmits<{
  'toggle-purchased': [stepId: string, purchased: boolean]
  'update-step-runs': [stepId: string, runs: number]
  'delete-step': [stepId: string]
  'add-child-job': [splitGroupId: string | null, stepId: string | null, runs: number]
}>()


// Track inline runs editing
const editingRunsStepId = ref<string | null>(null)
const editRunsValue = ref(0)

// Track adding child job
const addingChildToGroup = ref<string | null>(null)
const addChildRunsValue = ref(1)
const lastAddedToStepId = ref<string | null>(null)

// Delete confirmation modal
const showDeleteModal = ref(false)
const deleteStepId = ref<string | null>(null)
const deleteStepName = ref('')
const deleteModalRef = ref<HTMLElement | null>(null)

// Watch for steps changes to expand newly created split groups
watch(() => props.steps, (newSteps) => {
  if (lastAddedToStepId.value) {
    // Find the step we added to and expand its new splitGroupId
    const step = newSteps.find(s => s.id === lastAddedToStepId.value)
    if (step?.splitGroupId) {
      expandedSplitGroups.value.add(step.splitGroupId)
    }
    lastAddedToStepId.value = null
  }
}, { deep: true })

const { formatIsk } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

// Format duration in seconds to human readable
function formatDuration(seconds: number | null): string {
  if (seconds === null || seconds <= 0) return '-'

  const days = Math.floor(seconds / 86400)
  const hours = Math.floor((seconds % 86400) / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)

  if (days > 0) {
    return `${days}j ${hours}h`
  } else if (hours > 0) {
    return `${hours}h ${minutes}m`
  } else {
    return `${minutes}m`
  }
}

// Calculate total duration for a step (timePerRun * runs)
function stepTotalDuration(step: IndustryProjectStep): number | null {
  if (step.timePerRun === null) return null
  return step.timePerRun * step.runs
}

// Calculate total duration for a split group
function groupTotalDuration(children: IndustryProjectStep[]): number | null {
  // For a split group, the total time is the MAX of individual step durations
  // (they run in parallel on different characters)
  let maxDuration = 0
  let hasTime = false

  for (const step of children) {
    if (step.purchased || step.inStock) continue
    const duration = stepTotalDuration(step)
    if (duration !== null) {
      hasTime = true
      maxDuration = Math.max(maxDuration, duration)
    }
  }

  return hasTime ? maxDuration : null
}

interface SplitGroup {
  splitGroupId: string
  productTypeName: string
  productTypeId: number
  activityType: string
  depth: number
  totalExpectedRuns: number
  children: IndustryProjectStep[]
  // Computed from children
  actualTotalRuns: number
  isValid: boolean
  // Runs breakdown
  runsToLaunch: number      // Steps without ESI job
  runsInProgress: number    // Steps with esiJobStatus === 'active'
  runsCompleted: number     // Steps with esiJobStatus === 'delivered' or 'ready'
  // Structure info
  recommendedStructureName: string | null
  structureBonus: number | null
}

interface StepGroup {
  label: string
  items: SplitGroup[]
}

// Track expanded split groups
const expandedSplitGroups = ref<Set<string>>(new Set())

function toggleSplitGroup(splitGroupId: string) {
  if (expandedSplitGroups.value.has(splitGroupId)) {
    expandedSplitGroups.value.delete(splitGroupId)
  } else {
    expandedSplitGroups.value.add(splitGroupId)
  }
}

// Priority for sorting: À lancer (0) > En cours (1) > Terminé (2) > Acheté (3) > En stock (4)
function stepStatusPriority(step: IndustryProjectStep): number {
  if (step.inStock) return 4
  if (step.purchased) return 3
  if (step.esiJobStatus === 'delivered' || step.esiJobStatus === 'ready') return 2
  if (step.esiJobStatus === 'active') return 1
  return 0 // À lancer
}

// Get priority for a SplitGroup (minimum priority of children)
function groupItemPriority(group: SplitGroup): number {
  if (group.children.length === 0) return 99
  return Math.min(...group.children.map(stepStatusPriority))
}

// Calculate runs breakdown for a group
function calculateRunsBreakdown(children: IndustryProjectStep[]): { toLaunch: number; inProgress: number; completed: number } {
  let toLaunch = 0
  let inProgress = 0
  let completed = 0

  for (const step of children) {
    if (step.purchased || step.inStock) {
      // Purchased or in-stock steps don't count in runs breakdown
      continue
    }
    if (!step.esiJobId) {
      toLaunch += step.runs
    } else if (step.esiJobStatus === 'active') {
      inProgress += step.runs
    } else if (step.esiJobStatus === 'delivered' || step.esiJobStatus === 'ready') {
      completed += step.runs
    } else {
      // ESI job exists but unknown status - count as in progress
      inProgress += step.runs
    }
  }

  return { toLaunch, inProgress, completed }
}

const groupedSteps = computed<StepGroup[]>(() => {
  // Group all steps by product (blueprintTypeId + activityType)
  // This ensures all steps for the same product are grouped together,
  // regardless of whether they share a splitGroupId or not
  const splitGroupsMap = new Map<string, SplitGroup>()

  for (const step of props.steps) {
    // Group by blueprintTypeId + activityType to merge all steps for the same product
    const groupId = `${step.blueprintTypeId}_${step.activityType}`

    let splitGroup = splitGroupsMap.get(groupId)
    if (!splitGroup) {
      // Find the totalGroupRuns from any step that has it (split steps have this info)
      const stepsForProduct = props.steps.filter(
        s => s.blueprintTypeId === step.blueprintTypeId && s.activityType === step.activityType
      )
      const totalExpected = stepsForProduct.find(s => s.totalGroupRuns)?.totalGroupRuns
        ?? stepsForProduct.reduce((sum, s) => sum + s.runs, 0)

      splitGroup = {
        splitGroupId: groupId,
        productTypeName: step.productTypeName,
        productTypeId: step.productTypeId,
        activityType: step.activityType,
        depth: step.depth,
        totalExpectedRuns: totalExpected,
        children: [],
        actualTotalRuns: 0,
        isValid: false,
        runsToLaunch: 0,
        runsInProgress: 0,
        runsCompleted: 0,
        recommendedStructureName: step.recommendedStructureName,
        structureBonus: step.structureBonus,
      }
      splitGroupsMap.set(groupId, splitGroup)
    }
    splitGroup.children.push(step)
  }

  // Calculate totals, validity and runs breakdown for each group
  for (const splitGroup of splitGroupsMap.values()) {
    // Sort children by runs descending (highest first)
    splitGroup.children.sort((a, b) => b.runs - a.runs)
    splitGroup.actualTotalRuns = splitGroup.children.reduce((sum, s) => sum + s.runs, 0)
    splitGroup.isValid = splitGroup.actualTotalRuns === splitGroup.totalExpectedRuns

    const breakdown = calculateRunsBreakdown(splitGroup.children)
    splitGroup.runsToLaunch = breakdown.toLaunch
    splitGroup.runsInProgress = breakdown.inProgress
    splitGroup.runsCompleted = breakdown.completed
  }

  // Now group by depth + activityType
  const groupMap = new Map<string, StepGroup>()

  for (const splitGroup of splitGroupsMap.values()) {
    if (splitGroup.children.length === 0) continue
    const firstChild = splitGroup.children[0]
    const label = groupLabel(firstChild)
    let group = groupMap.get(label)
    if (!group) {
      group = { label, items: [] }
      groupMap.set(label, group)
    }
    group.items.push(splitGroup)
  }

  const groups = Array.from(groupMap.values())

  // Sort items within each group
  for (const group of groups) {
    group.items.sort((a, b) => {
      const priorityDiff = groupItemPriority(a) - groupItemPriority(b)
      if (priorityDiff !== 0) return priorityDiff
      return a.productTypeName.localeCompare(b.productTypeName)
    })
  }

  // Sort groups by priority then activity order (BPC first, then reactions, then manufacturing)
  const activityOrder: Record<string, number> = { 'copy': 0, 'reaction': 1, 'manufacturing': 2 }
  groups.sort((a, b) => {
    const aPriority = Math.min(...a.items.map(groupItemPriority))
    const bPriority = Math.min(...b.items.map(groupItemPriority))
    if (aPriority !== bPriority) return aPriority - bPriority

    const aActivity = a.items[0]?.activityType
    const bActivity = b.items[0]?.activityType
    const activityDiff = (activityOrder[aActivity ?? ''] ?? 99) - (activityOrder[bActivity ?? ''] ?? 99)
    if (activityDiff !== 0) return activityDiff

    // By depth (deeper first)
    const aDepth = a.items[0]?.depth ?? 0
    const bDepth = b.items[0]?.depth ?? 0
    return bDepth - aDepth
  })

  return groups
})

function groupLabel(step: IndustryProjectStep): string {
  const type = step.activityType === 'reaction' ? 'Réactions' : step.activityType === 'copy' ? 'Copies (BPC)' : 'Manufacturing'
  return `${type} — Niveau ${step.depth}`
}

function activityBadgeClass(type: string): string {
  if (type === 'reaction') return 'bg-purple-500/20 text-purple-400'
  if (type === 'copy') return 'bg-blue-500/20 text-blue-400'
  return 'bg-cyan-500/20 text-cyan-400'
}

function activityLabel(type: string): string {
  if (type === 'reaction') return 'Réaction'
  if (type === 'copy') return 'BPC'
  return 'Fab'
}

// Check if step is linked to ESI (not editable/deletable)
function isLinkedToEsi(step: IndustryProjectStep): boolean {
  return step.esiJobId !== null
}

// Format similar jobs warning tooltip
function formatSimilarJobsWarning(similarJobs: SimilarJob[]): string {
  return similarJobs.map(j =>
    `${j.characterName}: ${j.runs} runs (${j.status === 'active' ? 'en cours' : 'termine'})`
  ).join('\n')
}

// Calculate runs covered by stock
function getRunsCoveredByStock(step: IndustryProjectStep): number {
  if (step.inStockQuantity <= 0 || step.runs <= 0) return 0
  const unitsPerRun = step.quantity / step.runs
  return Math.floor(step.inStockQuantity / unitsPerRun)
}

function stepStatusLabel(step: IndustryProjectStep): string {
  if (step.inStock) return 'En stock'
  if (step.inStockQuantity > 0) {
    const runsCovered = getRunsCoveredByStock(step)
    return runsCovered >= step.runs ? 'En stock' : `${runsCovered}/${step.runs} runs`
  }
  if (step.purchased) return 'Acheté'
  if (step.esiJobStatus === 'active') return 'En cours'
  if (step.esiJobStatus === 'delivered' || step.esiJobStatus === 'ready') return 'Terminé'
  return 'À lancer'
}

function stepStatusClass(step: IndustryProjectStep): string {
  if (step.inStock) return 'bg-green-500/20 text-green-400'
  if (step.inStockQuantity > 0) return 'bg-amber-500/20 text-amber-400'
  if (step.purchased) return 'bg-amber-500/20 text-amber-400'
  if (step.esiJobStatus === 'active') return 'bg-cyan-500/20 text-cyan-400'
  if (step.esiJobStatus === 'delivered' || step.esiJobStatus === 'ready') return 'bg-emerald-500/20 text-emerald-400'
  return 'bg-slate-500/20 text-slate-400'
}

function onToggle(step: IndustryProjectStep) {
  if (props.readonly) return
  emit('toggle-purchased', step.id, !step.purchased)
}

// Check if all children in a group are purchased
function allChildrenPurchased(children: IndustryProjectStep[]): boolean {
  return children.length > 0 && children.every(s => s.purchased)
}

// Toggle purchased status for all children in a group
function toggleAllPurchased(children: IndustryProjectStep[]) {
  if (props.readonly) return
  const newStatus = !allChildrenPurchased(children)
  for (const step of children) {
    if (step.purchased !== newStatus) {
      emit('toggle-purchased', step.id, newStatus)
    }
  }
}

function deleteStep(stepId: string, stepName: string) {
  if (props.readonly) return
  deleteStepId.value = stepId
  deleteStepName.value = stepName
  showDeleteModal.value = true
  nextTick(() => deleteModalRef.value?.focus())
}

function confirmDelete() {
  if (deleteStepId.value) {
    emit('delete-step', deleteStepId.value)
  }
  showDeleteModal.value = false
  deleteStepId.value = null
  deleteStepName.value = ''
}

function cancelDelete() {
  showDeleteModal.value = false
  deleteStepId.value = null
  deleteStepName.value = ''
}

// Inline runs editing
function startEditRuns(step: IndustryProjectStep) {
  if (props.readonly) return
  editingRunsStepId.value = step.id
  editRunsValue.value = step.runs
}

function saveEditRuns(stepId: string) {
  if (editRunsValue.value >= 1) {
    emit('update-step-runs', stepId, editRunsValue.value)
  }
  editingRunsStepId.value = null
}

function cancelEditRuns() {
  editingRunsStepId.value = null
}

// Add child job functions
function startAddChild(splitGroupId: string) {
  if (props.readonly) return
  addingChildToGroup.value = splitGroupId
  addChildRunsValue.value = 1
}

function confirmAddChild(groupId: string, firstChildStepId: string) {
  if (addChildRunsValue.value >= 1) {
    // Always use the first step's ID to add a child job
    // The backend will handle linking it to the same product group
    lastAddedToStepId.value = firstChildStepId
    expandedSplitGroups.value.add(groupId)
    emit('add-child-job', null, firstChildStepId, addChildRunsValue.value)
  }
  addingChildToGroup.value = null
}

function cancelAddChild() {
  addingChildToGroup.value = null
}
</script>

<template>
  <div class="space-y-6">
    <div v-for="group in groupedSteps" :key="group.label">
      <h5 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">
        {{ group.label }}
      </h5>
      <div class="space-y-1">
        <template v-for="splitGroup in group.items" :key="splitGroup.splitGroupId">
          <!-- Parent row (all items are now SplitGroups) -->
          <div
            class="flex items-center gap-3 p-3 rounded-lg border cursor-pointer"
            :class="[
              splitGroup.isValid ? 'bg-slate-800/70 border-slate-600' : 'bg-red-900/20 border-red-500/50',
            ]"
            @click="toggleSplitGroup(splitGroup.splitGroupId)"
          >
            <!-- Expand/Collapse icon -->
            <svg
              class="w-4 h-4 text-slate-400 transition-transform"
              :class="{ 'rotate-90': expandedSplitGroups.has(splitGroup.splitGroupId) }"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
            <img
              :src="getTypeIconUrl(splitGroup.productTypeId, 32)"
              class="w-8 h-8 rounded"
              @error="onImageError"
            />
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="font-medium text-slate-200">{{ splitGroup.productTypeName }}</span>
                <span v-if="splitGroup.children.length > 1" class="text-xs text-slate-500">({{ splitGroup.children.length }} jobs)</span>
                <span :class="['text-xs px-1.5 py-0.5 rounded', activityBadgeClass(splitGroup.activityType)]">
                  {{ activityLabel(splitGroup.activityType) }}
                </span>
                <!-- Structure info -->
                <span
                  v-if="splitGroup.recommendedStructureName"
                  class="text-xs text-slate-500 flex items-center gap-1"
                  :title="`Bonus: ${splitGroup.structureBonus}%`"
                >
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                  </svg>
                  {{ splitGroup.recommendedStructureName }}
                  <span class="text-emerald-400">({{ splitGroup.structureBonus }}%)</span>
                </span>
              </div>
              <!-- Runs info with breakdown -->
              <div class="flex items-center gap-3 mt-1 text-xs">
                <span
                  :class="[
                    'font-mono',
                    splitGroup.isValid ? 'text-slate-400' : 'text-red-400'
                  ]"
                >
                  {{ splitGroup.actualTotalRuns }} / {{ splitGroup.totalExpectedRuns }} runs
                </span>
                <span v-if="!splitGroup.isValid" class="text-red-400">
                  ({{ splitGroup.actualTotalRuns > splitGroup.totalExpectedRuns ? '+' : '' }}{{ splitGroup.actualTotalRuns - splitGroup.totalExpectedRuns }})
                </span>
                <!-- Runs breakdown -->
                <span v-if="splitGroup.runsToLaunch > 0" class="text-slate-500">
                  {{ splitGroup.runsToLaunch }} à lancer
                </span>
                <span v-if="splitGroup.runsInProgress > 0" class="text-cyan-400">
                  {{ splitGroup.runsInProgress }} en cours
                </span>
                <span v-if="splitGroup.runsCompleted > 0" class="text-emerald-400">
                  {{ splitGroup.runsCompleted }} terminés
                </span>
                <!-- Duration -->
                <span v-if="groupTotalDuration(splitGroup.children)" class="text-slate-500 flex items-center gap-1">
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  {{ formatDuration(groupTotalDuration(splitGroup.children)) }}
                </span>
              </div>
            </div>

            <!-- Purchased all button (hidden for depth 0 - root products) -->
            <label
              v-if="splitGroup.depth > 0"
              :class="['flex items-center gap-1.5', readonly ? 'cursor-not-allowed opacity-50' : 'cursor-pointer']"
              @click.stop
            >
              <input
                type="checkbox"
                :checked="allChildrenPurchased(splitGroup.children)"
                :disabled="readonly"
                @change="toggleAllPurchased(splitGroup.children)"
                class="w-4 h-4 rounded border-slate-600 bg-slate-800 text-amber-500 focus:ring-amber-500 focus:ring-offset-slate-900 disabled:cursor-not-allowed"
              />
              <span class="text-xs text-slate-400">Acheté</span>
            </label>
          </div>

          <!-- Children rows (when expanded) -->
          <template v-if="expandedSplitGroups.has(splitGroup.splitGroupId)">
            <div
              v-for="step in splitGroup.children"
              :key="step.id"
              class="flex items-center gap-3 p-3 ml-6 rounded-lg bg-slate-800/50 border border-slate-700"
            >
              <img
                :src="getTypeIconUrl(step.productTypeId, 32)"
                class="w-8 h-8 rounded"
                @error="onImageError"
              />
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="font-medium text-slate-200">{{ step.productTypeName }}</span>
                  <span class="text-xs text-slate-500">×{{ step.quantity.toLocaleString() }}</span>
                  <!-- Inline runs editing (disabled for ESI-linked steps and readonly mode) -->
                  <template v-if="!isLinkedToEsi(step) && !readonly">
                    <span v-if="editingRunsStepId === step.id" class="inline-flex items-center gap-1">
                      <span class="text-xs text-slate-600">(</span>
                      <input
                        v-model.number="editRunsValue"
                        type="number"
                        min="1"
                        class="w-16 px-1 py-0.5 bg-slate-700 border border-cyan-500 rounded text-xs text-slate-200 focus:outline-none"
                        @keydown.enter="saveEditRuns(step.id)"
                        @keydown.escape="cancelEditRuns"
                        @blur="saveEditRuns(step.id)"
                        @focus="($event.target as HTMLInputElement).select()"
                        @click.stop
                        autofocus
                      />
                      <span class="text-xs text-slate-600">runs)</span>
                    </span>
                    <span
                      v-else
                      @dblclick.stop="startEditRuns(step)"
                      class="text-xs text-slate-600 cursor-pointer hover:text-cyan-400"
                      title="Double-cliquer pour modifier"
                    >
                      ({{ step.runs }} runs)
                    </span>
                  </template>
                  <span v-else class="text-xs text-slate-600" :title="isLinkedToEsi(step) ? 'Lié à ESI - non modifiable' : 'Projet terminé'">
                    ({{ step.runs }} runs)
                  </span>
                  <span v-if="step.isSplit" class="text-xs text-slate-500">#{{ step.splitIndex + 1 }}</span>
                  <!-- ESI linked indicator -->
                  <span v-if="isLinkedToEsi(step)" class="text-xs px-1.5 py-0.5 rounded bg-blue-500/20 text-blue-400" title="Lie a un job ESI">
                    ESI
                  </span>
                  <!-- Similar jobs warning -->
                  <span
                    v-if="step.similarJobs && step.similarJobs.length > 0"
                    class="text-xs px-1.5 py-0.5 rounded bg-amber-500/20 text-amber-400 cursor-help"
                    :title="formatSimilarJobsWarning(step.similarJobs)"
                  >
                    {{ step.similarJobs.length }} job{{ step.similarJobs.length > 1 ? 's' : '' }} similaire{{ step.similarJobs.length > 1 ? 's' : '' }}
                  </span>
                </div>
                <!-- Job info -->
                <div v-if="step.esiJobId || step.manualJobData" class="flex items-center gap-2 mt-1 text-xs flex-wrap">
                  <span v-if="step.esiJobCharacterName" class="text-slate-500">{{ step.esiJobCharacterName }}</span>
                  <span
                    v-if="step.esiJobsCount && step.esiJobsCount > 1"
                    class="text-slate-600"
                  >
                    ({{ step.esiJobsCount }} jobs)
                  </span>
                  <span
                    :class="[
                      'font-mono',
                      step.esiJobsTotalRuns && step.esiJobsTotalRuns >= step.runs ? 'text-emerald-400' : 'text-amber-400'
                    ]"
                  >
                    {{ step.esiJobsTotalRuns || step.esiJobRuns || 0 }} / {{ step.runs }} runs
                  </span>
                  <span
                    v-if="step.esiJobsActiveRuns || step.esiJobsDeliveredRuns"
                    class="text-slate-500"
                  >
                    ({{ step.esiJobsDeliveredRuns || 0 }} livrés, {{ step.esiJobsActiveRuns || 0 }} en cours)
                  </span>
                  <span v-if="step.manualJobData" class="text-amber-400">(manuel)</span>
                </div>
                <!-- Duration for step -->
                <div v-if="stepTotalDuration(step) && !step.purchased && !step.inStock" class="flex items-center gap-1 mt-1 text-xs text-slate-500">
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  {{ formatDuration(stepTotalDuration(step)) }}
                  <span v-if="step.runs > 1" class="text-slate-600">({{ formatDuration(step.timePerRun) }}/run)</span>
                </div>
              </div>

              <span :class="['text-xs px-2 py-1 rounded', stepStatusClass(step)]">
                {{ stepStatusLabel(step) }}
              </span>

              <!-- Purchased checkbox (hidden for depth 0 - root products, hidden if inStock) -->
              <label
                v-if="step.depth > 0 && !step.inStock"
                :class="['flex items-center gap-1.5', readonly ? 'cursor-not-allowed opacity-50' : 'cursor-pointer']"
                @click.stop
              >
                <input
                  type="checkbox"
                  :checked="step.purchased"
                  :disabled="readonly"
                  @change="onToggle(step)"
                  class="w-4 h-4 rounded border-slate-600 bg-slate-800 text-amber-500 focus:ring-amber-500 focus:ring-offset-slate-900 disabled:cursor-not-allowed"
                />
                <span class="text-xs text-slate-400">Acheté</span>
              </label>

              <!-- Job cost (ESI cost if linked, otherwise nothing) -->
              <span v-if="step.esiJobCost" class="text-xs font-mono text-slate-400">
                {{ formatIsk(step.esiJobCost) }}
              </span>

              <!-- Delete button (hidden for ESI-linked steps and readonly mode) -->
              <button
                v-if="!isLinkedToEsi(step) && !readonly"
                @click.stop="deleteStep(step.id, step.productTypeName)"
                class="p-1.5 text-slate-500 hover:text-red-400 hover:bg-slate-700 rounded"
                title="Supprimer cette étape"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
              <!-- Placeholder for ESI-linked steps or readonly mode to maintain alignment -->
              <div v-else class="w-7"></div>
            </div>

            <!-- Add child job row -->
            <div
              v-if="!readonly"
              class="flex items-center gap-3 p-3 ml-6 rounded-lg border border-dashed border-slate-600 hover:border-cyan-500/50 transition-colors"
            >
              <template v-if="addingChildToGroup === splitGroup.splitGroupId">
                <div class="flex items-center gap-2 flex-1">
                  <span class="text-sm text-slate-400">Runs:</span>
                  <input
                    v-model.number="addChildRunsValue"
                    type="number"
                    min="1"
                    class="w-20 px-2 py-1 bg-slate-700 border border-cyan-500 rounded text-sm text-slate-200 focus:outline-none"
                    @keydown.enter="confirmAddChild(splitGroup.splitGroupId, splitGroup.children[0]?.id)"
                    @keydown.escape="cancelAddChild"
                    autofocus
                  />
                  <button
                    @click="confirmAddChild(splitGroup.splitGroupId, splitGroup.children[0]?.id)"
                    class="px-3 py-1 bg-cyan-600 hover:bg-cyan-500 rounded text-sm text-white"
                  >
                    Ajouter
                  </button>
                  <button
                    @click="cancelAddChild"
                    class="px-3 py-1 bg-slate-700 hover:bg-slate-600 rounded text-sm text-slate-300"
                  >
                    Annuler
                  </button>
                </div>
              </template>
              <template v-else>
                <button
                  @click="startAddChild(splitGroup.splitGroupId)"
                  class="flex items-center gap-2 text-slate-500 hover:text-cyan-400 transition-colors"
                >
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                  </svg>
                  <span class="text-sm">Ajouter un job</span>
                </button>
              </template>
            </div>
          </template>
        </template>
      </div>
    </div>

    <!-- Delete confirmation modal -->
    <Teleport to="body">
      <div
        v-if="showDeleteModal"
        class="fixed inset-0 z-50 flex items-center justify-center"
        @keydown.enter="confirmDelete"
        @keydown.escape="cancelDelete"
      >
        <!-- Backdrop -->
        <div
          class="absolute inset-0 bg-black/70"
          @click="cancelDelete"
        ></div>
        <!-- Modal -->
        <div class="relative bg-slate-900 border border-slate-700 rounded-xl shadow-2xl w-full max-w-md mx-4 p-6" tabindex="-1" ref="deleteModalRef">
          <h3 class="text-lg font-semibold text-slate-100 mb-2">Supprimer l'étape</h3>
          <p class="text-slate-400 mb-6">
            Voulez-vous vraiment supprimer l'étape <span class="text-slate-200 font-medium">"{{ deleteStepName }}"</span> ?
          </p>
          <div class="flex justify-end gap-3">
            <button
              @click="cancelDelete"
              class="px-4 py-2 bg-slate-800 hover:bg-slate-700 border border-slate-600 rounded-lg text-slate-300 text-sm font-medium transition-colors"
            >
              Annuler
            </button>
            <button
              @click="confirmDelete"
              class="px-4 py-2 bg-red-600 hover:bg-red-500 rounded-lg text-white text-sm font-medium transition-colors"
            >
              Supprimer
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
