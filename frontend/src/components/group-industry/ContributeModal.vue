<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useGroupContributionStore } from '@/stores/group-industry/contribution'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import type { BomItem, ContributionType, SubmitContributionInput } from '@/stores/group-industry/types'

const { t } = useI18n()
const projectStore = useGroupProjectStore()
const contributionStore = useGroupContributionStore()
const { getTypeIconUrl, onImageError } = useEveImages()
const { formatIsk, formatNumber, formatIskFull } = useFormatters()

const props = defineProps<{
  show: boolean
  projectId: string
  prefilledBomItem?: BomItem
}>()

const emit = defineEmits<{
  close: []
  submitted: []
}>()

// Form state
const selectedType = ref<ContributionType>('material')
const selectedBomItemId = ref<string>('')
const quantity = ref<number>(0)
const estimatedValue = ref<number>(0)
const note = ref('')
const isSubmitting = ref(false)

// Reset form when modal opens
watch(() => props.show, (visible) => {
  if (visible) {
    note.value = ''
    isSubmitting.value = false
    if (props.prefilledBomItem) {
      selectedType.value = props.prefilledBomItem.isJob ? 'job_install' : 'material'
      selectedBomItemId.value = props.prefilledBomItem.id
      quantity.value = props.prefilledBomItem.remainingQuantity
      estimatedValue.value = 0
    } else {
      selectedType.value = 'material'
      selectedBomItemId.value = ''
      quantity.value = 0
      estimatedValue.value = 0
    }
  }
})

// BOM items for dropdown, filtered by type
const availableBomItems = computed(() => {
  if (selectedType.value === 'material') {
    return projectStore.materials.filter(m => !m.isFulfilled)
  }
  // For job_install, bpc, line_rental: show jobs
  return projectStore.jobs.filter(j => !j.isFulfilled)
})

const selectedBomItem = computed(() =>
  projectStore.bomItems.find(i => i.id === selectedBomItemId.value) ?? null
)

// Auto-calculate estimated value for materials from BOM data
const autoEstimatedValue = computed(() => {
  if (selectedType.value !== 'material' || !selectedBomItem.value) return null
  if (selectedBomItem.value.estimatedPrice == null) return null
  return selectedBomItem.value.estimatedPrice * quantity.value
})

const displayedValue = computed(() => {
  if (selectedType.value === 'material' && autoEstimatedValue.value != null) {
    return autoEstimatedValue.value
  }
  return estimatedValue.value
})

const isFormValid = computed(() => {
  if (!selectedBomItemId.value && selectedType.value !== 'line_rental') return false
  if (quantity.value <= 0) return false
  if (selectedType.value === 'bpc' && estimatedValue.value <= 0) return false
  return true
})

const contributionTypeOptions: { key: ContributionType; icon: string; label: string; activeColor: string }[] = [
  {
    key: 'material',
    icon: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
    label: 'Material',
    activeColor: 'text-cyan-400',
  },
  {
    key: 'job_install',
    icon: 'M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z',
    label: 'Job Install',
    activeColor: 'text-amber-400',
  },
  {
    key: 'bpc',
    icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    label: 'BPC',
    activeColor: 'text-violet-400',
  },
  {
    key: 'line_rental',
    icon: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
    label: 'Line Rental',
    activeColor: 'text-pink-400',
  },
]

function selectType(type: ContributionType): void {
  selectedType.value = type
  // Reset BOM selection when type changes
  selectedBomItemId.value = ''
  quantity.value = 0
  estimatedValue.value = 0
}

async function submit(): Promise<void> {
  if (!isFormValid.value) return
  isSubmitting.value = true

  const input: SubmitContributionInput = {
    type: selectedType.value,
    quantity: quantity.value,
  }

  if (selectedBomItemId.value) {
    input.bomItemId = selectedBomItemId.value
  }

  if (selectedType.value === 'bpc' || selectedType.value === 'line_rental') {
    input.estimatedValue = estimatedValue.value
  } else if (autoEstimatedValue.value != null) {
    input.estimatedValue = autoEstimatedValue.value
  }

  if (note.value.trim()) {
    input.note = note.value.trim()
  }

  try {
    await contributionStore.submitContribution(props.projectId, input)
    emit('submitted')
  } catch {
    // Error is already set in the store
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="show" class="fixed inset-0 z-50">
      <!-- Overlay -->
      <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="emit('close')"></div>

      <!-- Modal -->
      <div class="relative flex items-center justify-center min-h-screen p-4 pointer-events-none">
        <div class="bg-slate-900 border border-slate-700 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto pointer-events-auto animate-modal-in">

          <!-- Header -->
          <div class="px-6 py-5 border-b border-slate-800 flex items-center justify-between">
            <div>
              <h2 class="text-lg font-bold text-slate-100">{{ t('groupIndustry.contribute.title') }}</h2>
              <p class="text-sm text-slate-500 mt-0.5">
                Project: {{ projectStore.currentProject?.name || '---' }}
              </p>
            </div>
            <button
              @click="emit('close')"
              class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 flex items-center justify-center text-slate-400 hover:text-slate-200 transition-colors"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>

          <!-- Body -->
          <div class="px-6 py-5 space-y-6">

            <!-- Type Selector (4 cards) -->
            <div>
              <label class="text-sm font-medium text-slate-300 mb-3 block">{{ t('groupIndustry.contribute.type') }}</label>
              <div class="grid grid-cols-4 gap-2">
                <div
                  v-for="opt in contributionTypeOptions"
                  :key="opt.key"
                  class="rounded-xl border p-3 cursor-pointer text-center transition-all"
                  :class="selectedType === opt.key
                    ? 'border-cyan-500/50 bg-cyan-500/5'
                    : 'border-slate-700 hover:bg-slate-800/80 hover:border-slate-600'"
                  @click="selectType(opt.key)"
                >
                  <div
                    class="w-8 h-8 mx-auto mb-2 rounded-lg flex items-center justify-center"
                    :class="selectedType === opt.key ? 'bg-cyan-500/10' : 'bg-slate-800'"
                  >
                    <svg
                      class="w-4 h-4"
                      :class="selectedType === opt.key ? opt.activeColor : 'text-slate-500'"
                      fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    >
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="opt.icon" />
                    </svg>
                  </div>
                  <span
                    class="text-xs font-medium"
                    :class="selectedType === opt.key ? opt.activeColor : 'text-slate-500'"
                  >
                    {{ opt.label }}
                  </span>
                </div>
              </div>
            </div>

            <div class="border-t border-slate-800"></div>

            <!-- Form fields -->
            <div class="space-y-4">
              <!-- BOM Item selector -->
              <div>
                <label class="text-sm font-medium text-slate-300 mb-1.5 block">
                  {{ selectedType === 'material' ? t('groupIndustry.contribute.item') : t('groupIndustry.contribute.bomStep') }}
                </label>
                <!-- Pre-filled display -->
                <div v-if="selectedBomItem" class="bg-slate-800 border border-cyan-500/50 rounded-lg px-4 py-2.5 text-sm text-slate-200 flex items-center gap-3">
                  <div class="w-7 h-7 rounded bg-slate-700 border border-slate-600 overflow-hidden flex-shrink-0">
                    <img
                      :src="getTypeIconUrl(selectedBomItem.typeId, 32)"
                      :alt="selectedBomItem.typeName"
                      class="w-full h-full"
                      @error="onImageError"
                    />
                  </div>
                  <span>{{ selectedBomItem.typeName }}</span>
                  <span class="text-xs text-slate-500 ml-auto">Type ID: {{ selectedBomItem.typeId }}</span>
                  <button
                    @click="selectedBomItemId = ''"
                    class="text-slate-500 hover:text-slate-300 ml-2"
                  >
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                  </button>
                </div>
                <!-- Dropdown -->
                <select
                  v-else
                  v-model="selectedBomItemId"
                  class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2.5 text-sm text-slate-300 focus:border-cyan-500 focus:outline-none transition-colors"
                >
                  <option value="" disabled>{{ t('groupIndustry.contribute.selectItem') }}</option>
                  <option v-for="item in availableBomItems" :key="item.id" :value="item.id">
                    {{ item.typeName }}
                    <template v-if="item.remainingQuantity > 0"> ({{ formatNumber(item.remainingQuantity, 0) }} remaining)</template>
                  </option>
                </select>
              </div>

              <!-- Quantity -->
              <div>
                <label class="text-sm font-medium text-slate-300 mb-1.5 block">{{ t('groupIndustry.contribute.quantity') }}</label>
                <input
                  v-model.number="quantity"
                  type="number"
                  min="1"
                  class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2.5 text-sm text-slate-200 font-mono focus:border-cyan-500 focus:outline-none transition-colors"
                  style="font-variant-numeric: tabular-nums;"
                />
              </div>

              <!-- Auto-calculated price for materials -->
              <div v-if="selectedType === 'material' && selectedBomItem && autoEstimatedValue != null" class="bg-slate-800/50 rounded-lg p-4 border border-slate-700/50">
                <div class="flex items-center justify-between mb-2">
                  <span class="text-sm text-slate-400">Jita Weighted Sell Price</span>
                  <span class="text-xs px-2 py-0.5 rounded bg-cyan-500/10 text-cyan-400 border border-cyan-500/20">Auto-calculated</span>
                </div>
                <div class="flex items-center justify-between">
                  <span class="text-sm text-slate-500">Unit price:</span>
                  <span class="font-mono text-sm text-slate-300" style="font-variant-numeric: tabular-nums;">
                    {{ selectedBomItem.estimatedPrice != null ? formatIskFull(selectedBomItem.estimatedPrice) : '---' }}
                  </span>
                </div>
                <div class="flex items-center justify-between mt-1">
                  <span class="text-sm text-slate-500">{{ formatNumber(quantity, 0) }} x {{ selectedBomItem.estimatedPrice != null ? formatIsk(selectedBomItem.estimatedPrice, 2) : '---' }} =</span>
                  <span class="font-mono text-sm text-slate-300" style="font-variant-numeric: tabular-nums;">
                    {{ formatIskFull(autoEstimatedValue) }}
                  </span>
                </div>
              </div>

              <!-- Manual estimated value for BPC / Line Rental -->
              <div v-if="selectedType === 'bpc' || selectedType === 'line_rental'">
                <label class="text-sm font-medium text-slate-300 mb-1.5 block">
                  {{ t('groupIndustry.contribute.estimatedValue') }} (ISK)
                </label>
                <input
                  v-model.number="estimatedValue"
                  type="number"
                  min="0"
                  class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2.5 text-sm text-slate-200 font-mono focus:border-cyan-500 focus:outline-none transition-colors"
                  style="font-variant-numeric: tabular-nums;"
                />
              </div>

              <!-- Total Valuation display -->
              <div v-if="displayedValue > 0" class="bg-emerald-500/5 rounded-xl border border-emerald-500/30 p-5 text-center">
                <p class="text-xs text-emerald-400/60 uppercase tracking-wider mb-1">{{ t('groupIndustry.contribute.totalValuation') }}</p>
                <p class="text-3xl font-mono font-bold text-emerald-400" style="font-variant-numeric: tabular-nums;">
                  {{ formatIskFull(displayedValue) }}
                </p>
                <p v-if="selectedType === 'material'" class="text-xs text-slate-500 mt-2">Method: Jita weighted sell price</p>
              </div>

              <!-- Notes -->
              <div>
                <label class="text-sm font-medium text-slate-300 mb-1.5 block">
                  {{ t('groupIndustry.contribute.notes') }} <span class="text-slate-600 font-normal">({{ t('groupIndustry.contribute.optional') }})</span>
                </label>
                <textarea
                  v-model="note"
                  :placeholder="t('groupIndustry.contribute.notesPlaceholder')"
                  rows="2"
                  class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2.5 text-sm text-slate-400 resize-none placeholder-slate-600 focus:border-cyan-500 focus:outline-none transition-colors"
                ></textarea>
              </div>

              <!-- Error from store -->
              <p v-if="contributionStore.error" class="text-sm text-red-400">{{ contributionStore.error }}</p>
            </div>

          </div>

          <!-- Footer -->
          <div class="px-6 py-4 border-t border-slate-800 flex items-center justify-between">
            <p class="text-xs text-slate-500">
              {{ t('groupIndustry.contribute.pendingNote') }}
            </p>
            <div class="flex gap-3">
              <button
                @click="emit('close')"
                class="px-4 py-2.5 bg-slate-800 hover:bg-slate-700 rounded-lg text-slate-300 text-sm font-medium transition-colors"
              >
                {{ t('common.actions.cancel') }}
              </button>
              <button
                @click="submit"
                :disabled="!isFormValid || isSubmitting"
                class="px-6 py-2.5 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 transition-all disabled:opacity-50 disabled:cursor-not-allowed hover:-translate-y-px"
              >
                <svg v-if="isSubmitting" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                {{ t('groupIndustry.contribute.submit') }}
              </button>
            </div>
          </div>

        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
@keyframes modal-in {
  from { opacity: 0; transform: translateY(24px) scale(0.97); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}
.animate-modal-in {
  animation: modal-in 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
}
</style>
