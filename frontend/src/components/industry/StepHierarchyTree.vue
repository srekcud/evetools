<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import type { ProductionTreeNode, IndustryProjectStep } from '@/stores/industry'

const { t } = useI18n()

const props = defineProps<{
  tree: ProductionTreeNode
  steps: IndustryProjectStep[]
  readonly?: boolean
}>()

const emit = defineEmits<{
  'toggle-purchased': [stepId: string, purchased: boolean]
}>()

const { getTypeIconUrl, onImageError } = useEveImages()

// Find the step matching this tree node
function findStep(node: ProductionTreeNode): IndustryProjectStep | undefined {
  return props.steps.find(
    (s) => s.blueprintTypeId === node.blueprintTypeId && s.activityType === node.activityType,
  )
}

function activityBadgeClass(type: string): string {
  if (type === 'reaction') return 'bg-purple-500/20 text-purple-400'
  if (type === 'copy') return 'bg-blue-500/20 text-blue-400'
  return 'bg-cyan-500/20 text-cyan-400'
}

function activityLabel(type: string): string {
  if (type === 'reaction') return t('industry.activity.reaction')
  if (type === 'copy') return t('industry.activity.copy')
  return t('industry.activity.manufacturing')
}

// Calculate runs covered by stock
function getRunsCoveredByStock(step: IndustryProjectStep | undefined): number {
  if (!step || step.inStockQuantity <= 0 || step.runs <= 0) return 0
  const unitsPerRun = step.quantity / step.runs
  return Math.floor(step.inStockQuantity / unitsPerRun)
}

function stepStatusLabel(step: IndustryProjectStep | undefined): string {
  if (!step) return '-'
  if (step.inStock) return t('industry.step.inStock')
  if (step.inStockQuantity > 0) {
    const runsCovered = getRunsCoveredByStock(step)
    return runsCovered >= step.runs ? t('industry.step.inStock') : `${runsCovered}/${step.runs} runs`
  }
  if (step.purchased) return t('industry.step.purchased')
  if (step.esiJobStatus === 'active') return t('industry.stepStatus.active')
  if (step.esiJobStatus === 'delivered' || step.esiJobStatus === 'ready') return t('industry.stepStatus.completed')
  return t('industry.stepStatus.toLaunch')
}

function stepStatusClass(step: IndustryProjectStep | undefined): string {
  if (!step) return 'bg-slate-500/20 text-slate-400'
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
</script>

<template>
  <div class="space-y-2">
    <!-- Root node -->
    <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-3">
      <div class="flex items-center gap-3">
        <img
          :src="getTypeIconUrl(tree.productTypeId, 32)"
          class="w-8 h-8 rounded-sm"
          @error="onImageError"
        />
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="font-medium text-slate-200">{{ tree.productTypeName }}</span>
            <span class="text-xs text-slate-500">×{{ tree.quantity.toLocaleString() }}</span>
            <span class="text-xs text-slate-600">({{ tree.runs }} runs)</span>
            <span :class="['text-xs px-1.5 py-0.5 rounded-sm', activityBadgeClass(tree.activityType)]">
              {{ activityLabel(tree.activityType) }}
            </span>
          </div>
          <div v-if="tree.structureName" class="text-xs text-slate-500 flex items-center gap-1 mt-1">
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
            </svg>
            {{ tree.structureName }}
            <span class="text-emerald-400">({{ tree.structureBonus }}%)</span>
          </div>
        </div>
        <template v-if="findStep(tree)">
          <span :class="['text-xs px-2 py-1 rounded-sm', stepStatusClass(findStep(tree))]">
            {{ stepStatusLabel(findStep(tree)) }}
          </span>
          <!-- Purchased checkbox (hidden for depth 0 - root products, hidden if inStock) -->
          <label
            v-if="(findStep(tree)?.depth ?? 0) > 0 && !findStep(tree)?.inStock"
            :class="['flex items-center gap-1.5', readonly ? 'cursor-not-allowed opacity-50' : 'cursor-pointer']"
          >
            <input
              type="checkbox"
              :checked="findStep(tree)?.purchased"
              :disabled="readonly"
              @change="onToggle(findStep(tree)!)"
              class="w-4 h-4 rounded-sm border-slate-600 bg-slate-800 text-amber-500 focus:ring-amber-500 focus:ring-offset-slate-900 disabled:cursor-not-allowed"
            />
            <span class="text-xs text-slate-400">{{ t('industry.step.purchased') }}</span>
          </label>
        </template>
      </div>
    </div>

    <!-- Materials (children) -->
    <div v-if="tree.materials && tree.materials.length > 0" class="pl-6 border-l-2 border-slate-700 ml-4 space-y-2">
      <div
        v-for="mat in tree.materials"
        :key="mat.typeId"
        class="relative"
      >
        <!-- Connection line -->
        <div class="absolute -left-6 top-4 w-6 h-0.5 bg-slate-700"></div>

        <!-- Non-buildable material (raw) -->
        <div
          v-if="!mat.isBuildable"
          class="bg-slate-800/30 border border-slate-700/50 rounded-lg p-2"
        >
          <div class="flex items-center gap-2">
            <img
              :src="getTypeIconUrl(mat.typeId, 32)"
              class="w-6 h-6 rounded-sm"
              @error="onImageError"
            />
            <span class="text-sm text-slate-400">{{ mat.typeName }}</span>
            <span class="text-xs text-slate-500 ml-auto">×{{ mat.quantity.toLocaleString() }}</span>
          </div>
        </div>

        <!-- Buildable material (recursive) -->
        <StepHierarchyTree
          v-else-if="mat.blueprint"
          :tree="mat.blueprint"
          :steps="steps"
          :readonly="readonly"
          @toggle-purchased="(stepId, purchased) => emit('toggle-purchased', stepId, purchased)"
        />
      </div>
    </div>
  </div>
</template>
