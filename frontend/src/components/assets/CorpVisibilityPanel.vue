<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { CorpAssetVisibility } from '@/types'

const props = defineProps<{
  visibility: CorpAssetVisibility
}>()

const emit = defineEmits<{
  save: [divisions: number[]]
}>()

const { t } = useI18n()

const isExpanded = ref(false)
const isSaving = ref(false)
const saveSuccess = ref(false)

// Local copy of selected divisions for editing
const selectedDivisions = ref<Set<number>>(new Set(props.visibility.visibleDivisions))

// Reset local state when visibility prop changes
function resetSelections() {
  selectedDivisions.value = new Set(props.visibility.visibleDivisions)
}

const hasChanges = computed(() => {
  const current = new Set(props.visibility.visibleDivisions)
  if (current.size !== selectedDivisions.value.size) return true
  for (const div of selectedDivisions.value) {
    if (!current.has(div)) return true
  }
  return false
})

const sortedDivisions = computed(() =>
  Object.entries(props.visibility.allDivisions)
    .map(([key, name]) => ({ number: Number(key), name }))
    .sort((a, b) => a.number - b.number)
)

const visibleCount = computed(() => selectedDivisions.value.size)

function toggleDivision(divisionNumber: number) {
  const next = new Set(selectedDivisions.value)
  if (next.has(divisionNumber)) {
    next.delete(divisionNumber)
  } else {
    next.add(divisionNumber)
  }
  selectedDivisions.value = next
}

function handleSave() {
  isSaving.value = true
  saveSuccess.value = false
  emit('save', Array.from(selectedDivisions.value).sort())
}

// Called by parent after save completes
function onSaveComplete() {
  isSaving.value = false
  saveSuccess.value = true
  setTimeout(() => { saveSuccess.value = false }, 3000)
}

function onSaveError() {
  isSaving.value = false
}

function togglePanel() {
  isExpanded.value = !isExpanded.value
  if (isExpanded.value) {
    resetSelections()
  }
}

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

defineExpose({ onSaveComplete, onSaveError })
</script>

<template>
  <div class="bg-slate-900 rounded-lg border border-slate-800">
    <!-- Collapsed header / summary -->
    <button
      @click="togglePanel"
      class="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-slate-800/50 transition-colors rounded-lg"
    >
      <div class="flex items-center gap-3">
        <!-- Shield icon -->
        <svg class="w-5 h-5 text-cyan-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
        </svg>
        <div>
          <span class="text-sm font-medium text-slate-200">{{ t('assets.visibility.title') }}</span>
          <span class="text-xs text-slate-500 ml-2">
            {{ visibleCount }}/{{ sortedDivisions.length }}
          </span>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <!-- Success indicator -->
        <span v-if="saveSuccess" class="text-xs text-emerald-400">{{ t('assets.visibility.saved') }}</span>
        <!-- Configured by info (collapsed state) -->
        <span v-if="!isExpanded && props.visibility.configuredByName" class="text-xs text-slate-500 hidden sm:inline">
          {{ t('assets.visibility.configuredBy', { name: props.visibility.configuredByName }) }}
        </span>
        <!-- Chevron -->
        <svg
          :class="['w-4 h-4 text-slate-400 transition-transform', isExpanded && 'rotate-180']"
          fill="none" stroke="currentColor" viewBox="0 0 24 24"
        >
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
      </div>
    </button>

    <!-- Expanded content -->
    <div v-if="isExpanded" class="px-4 pb-4 space-y-4 border-t border-slate-800">
      <p class="text-xs text-slate-400 mt-3">{{ t('assets.visibility.description') }}</p>

      <!-- Division toggles -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
        <button
          v-for="division in sortedDivisions"
          :key="division.number"
          @click="toggleDivision(division.number)"
          :class="[
            'flex items-center gap-3 px-3 py-2 rounded-lg border transition-colors text-left',
            selectedDivisions.has(division.number)
              ? 'border-cyan-500/50 bg-cyan-500/10 text-slate-200'
              : 'border-slate-700 bg-slate-800/50 text-slate-500 hover:border-slate-600'
          ]"
        >
          <!-- Toggle indicator -->
          <div
            :class="[
              'w-8 h-5 rounded-full relative transition-colors shrink-0',
              selectedDivisions.has(division.number) ? 'bg-cyan-600' : 'bg-slate-700'
            ]"
          >
            <div
              :class="[
                'absolute top-0.5 w-4 h-4 rounded-full bg-white transition-all',
                selectedDivisions.has(division.number) ? 'left-3.5' : 'left-0.5'
              ]"
            ></div>
          </div>
          <span class="text-sm truncate">{{ division.name }}</span>
        </button>
      </div>

      <!-- Footer: metadata + save -->
      <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 pt-2">
        <div class="text-xs text-slate-500 space-y-0.5">
          <div v-if="props.visibility.configuredByName">
            {{ t('assets.visibility.configuredBy', { name: props.visibility.configuredByName }) }}
          </div>
          <div v-if="props.visibility.updatedAt">
            {{ t('assets.visibility.lastUpdate') }}: {{ formatDate(props.visibility.updatedAt) }}
          </div>
        </div>
        <button
          @click="handleSave"
          :disabled="!hasChanges || isSaving"
          :class="[
            'px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center gap-2',
            hasChanges && !isSaving
              ? 'bg-cyan-600 hover:bg-cyan-500 text-white'
              : 'bg-slate-800 text-slate-500 cursor-not-allowed'
          ]"
        >
          <svg v-if="isSaving" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          {{ t('assets.visibility.save') }}
        </button>
      </div>
    </div>
  </div>
</template>
