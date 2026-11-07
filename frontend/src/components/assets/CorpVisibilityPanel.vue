<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePilotName } from '@/composables/usePilotName'
import type { CorpAssetVisibility } from '@/types'

const props = defineProps<{
  visibility: CorpAssetVisibility
}>()

const emit = defineEmits<{
  save: [payload: { visibleDivisions: number[]; restrictedDivisions: Record<number, string[]> }]
}>()

const { t } = useI18n()
const { pilotName } = usePilotName()

const isExpanded = ref(false)
const isSaving = ref(false)
const saveSuccess = ref(false)

type DivisionMode = 'all' | 'list' | 'hidden'

const DIVISION_MODES: DivisionMode[] = ['all', 'list', 'hidden']
const MODE_LABEL_KEYS: Record<DivisionMode, string> = {
  all: 'assets.visibility.modeAll',
  list: 'assets.visibility.modeList',
  hidden: 'assets.visibility.modeHidden',
}

const divisionModes = ref<Record<number, DivisionMode>>({})
const listedPlayerIds = ref<Record<number, string[]>>({})

const isEditable = computed(() => props.visibility.isDirector)

function modeOf(divisionNumber: number): DivisionMode {
  if (props.visibility.visibleDivisions.includes(divisionNumber)) return 'all'
  if (props.visibility.restrictedDivisions[divisionNumber] !== undefined) return 'list'
  return 'hidden'
}

function savedPlayerIds(divisionNumber: number): string[] {
  return (props.visibility.restrictedDivisions[divisionNumber] ?? []).map(player => player.id)
}

function resetSelections() {
  const modes: Record<number, DivisionMode> = {}
  const players: Record<number, string[]> = {}
  for (const division of sortedDivisions.value) {
    modes[division.number] = modeOf(division.number)
    players[division.number] = savedPlayerIds(division.number)
  }
  divisionModes.value = modes
  listedPlayerIds.value = players
}

const sortedDivisions = computed(() =>
  Object.entries(props.visibility.allDivisions)
    .map(([key, name]) => ({ number: Number(key), name }))
    .sort((a, b) => a.number - b.number)
)

resetSelections()

watch(() => props.visibility, resetSelections)

function currentMode(divisionNumber: number): DivisionMode {
  return divisionModes.value[divisionNumber] ?? modeOf(divisionNumber)
}

const pendingPayload = computed(() => {
  const visibleDivisions: number[] = []
  const restrictedDivisions: Record<number, string[]> = {}
  for (const division of sortedDivisions.value) {
    const mode = currentMode(division.number)
    const playerIds = listedPlayerIds.value[division.number] ?? []
    if (mode === 'all') {
      visibleDivisions.push(division.number)
    } else if (mode === 'list' && playerIds.length > 0) {
      restrictedDivisions[division.number] = [...playerIds].sort()
    }
  }
  return { visibleDivisions, restrictedDivisions }
})

const savedPayload = computed(() => {
  const restrictedDivisions: Record<number, string[]> = {}
  for (const [number, players] of Object.entries(props.visibility.restrictedDivisions)) {
    if (players.length > 0) restrictedDivisions[Number(number)] = players.map(player => player.id).sort()
  }
  return { visibleDivisions: [...props.visibility.visibleDivisions].sort(), restrictedDivisions }
})

const hasChanges = computed(() => JSON.stringify(pendingPayload.value) !== JSON.stringify(savedPayload.value))

const visibleCount = computed(() =>
  isEditable.value
    ? sortedDivisions.value.filter(division => currentMode(division.number) !== 'hidden').length
    : props.visibility.accessibleDivisions.length
)

function setMode(divisionNumber: number, mode: DivisionMode) {
  divisionModes.value = { ...divisionModes.value, [divisionNumber]: mode }
}

function isPlayerListed(divisionNumber: number, playerId: string): boolean {
  return (listedPlayerIds.value[divisionNumber] ?? []).includes(playerId)
}

function togglePlayer(divisionNumber: number, playerId: string) {
  const current = listedPlayerIds.value[divisionNumber] ?? []
  const next = current.includes(playerId) ? current.filter(id => id !== playerId) : [...current, playerId]
  listedPlayerIds.value = { ...listedPlayerIds.value, [divisionNumber]: next }
}

function handleSave() {
  isSaving.value = true
  saveSuccess.value = false
  emit('save', pendingPayload.value)
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
      data-testid="visibility-panel-toggle"
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
        <span v-if="!isExpanded && props.visibility.updatedAt" class="text-xs text-slate-500 hidden sm:inline">
          {{ t('assets.visibility.configuredBy', { name: pilotName(props.visibility.configuredByName) }) }}
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
      <p class="text-xs text-slate-400 mt-3">
        {{ t(isEditable ? 'assets.visibility.description' : 'assets.visibility.readOnlyDescription') }}
      </p>

      <div
        v-if="!props.visibility.directorConnected"
        data-testid="no-director-warning"
        class="flex items-start gap-2 px-3 py-2 rounded-lg border border-amber-500/30 bg-amber-500/10 text-xs text-amber-300"
      >
        <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
        </svg>
        <span>{{ t('assets.visibility.noDirectorWarning') }}</span>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-2">
        <div
          v-for="division in sortedDivisions"
          :key="division.number"
          :data-testid="`division-${division.number}`"
          class="rounded-lg border border-slate-700 bg-slate-800/50 px-3 py-2 space-y-2"
        >
          <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="text-sm text-slate-200 truncate min-w-0" :title="division.name">{{ division.name }}</span>
            <div v-if="isEditable" class="inline-flex rounded-lg border border-slate-700 overflow-hidden shrink-0">
              <button
                v-for="mode in DIVISION_MODES"
                :key="mode"
                type="button"
                :data-testid="`division-${division.number}-mode-${mode}`"
                :aria-pressed="currentMode(division.number) === mode"
                :class="[
                  'px-2.5 py-1 text-xs font-medium transition-colors',
                  currentMode(division.number) === mode
                    ? 'bg-cyan-600 text-white'
                    : 'bg-slate-900 text-slate-400 hover:text-slate-200'
                ]"
                @click="setMode(division.number, mode)"
              >
                {{ t(MODE_LABEL_KEYS[mode]) }}
              </button>
            </div>
            <span
              v-else
              :data-testid="`division-${division.number}-mode-label`"
              class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-slate-900 text-cyan-400 border border-slate-700"
            >
              {{ t(MODE_LABEL_KEYS[modeOf(division.number)]) }}
            </span>
          </div>

          <div
            v-if="isEditable && currentMode(division.number) === 'list'"
            :data-testid="`division-${division.number}-players`"
            class="space-y-1.5"
          >
            <p
              v-if="(listedPlayerIds[division.number] ?? []).length === 0"
              class="text-xs text-slate-500"
            >
              {{ t('assets.visibility.noPlayerListed') }}
            </p>
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="player in props.visibility.players"
                :key="player.id"
                type="button"
                :data-testid="`division-${division.number}-player-${player.id}`"
                :aria-pressed="isPlayerListed(division.number, player.id)"
                :title="player.name"
                :class="[
                  'max-w-[12rem] truncate px-2 py-0.5 rounded text-xs font-medium border transition-colors',
                  isPlayerListed(division.number, player.id)
                    ? 'bg-cyan-500/10 text-cyan-400 border-cyan-500/40'
                    : 'bg-slate-900 text-slate-500 border-slate-700 hover:border-slate-600'
                ]"
                @click="togglePlayer(division.number, player.id)"
              >
                {{ player.name }}
              </button>
            </div>
          </div>

          <div
            v-else-if="!isEditable && modeOf(division.number) === 'list'"
            :data-testid="`division-${division.number}-listed-players`"
            class="flex flex-wrap gap-1.5"
          >
            <span
              v-for="player in props.visibility.restrictedDivisions[division.number] ?? []"
              :key="player.id"
              :title="player.name"
              class="max-w-[12rem] truncate inline-flex px-2 py-0.5 rounded text-xs font-medium bg-cyan-500/10 text-cyan-400 border border-cyan-500/20"
            >
              {{ player.name }}
            </span>
          </div>
        </div>
      </div>

      <!-- Footer: metadata + save -->
      <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 pt-2">
        <div class="text-xs text-slate-500 space-y-0.5">
          <div v-if="props.visibility.updatedAt">
            {{ t('assets.visibility.configuredBy', { name: pilotName(props.visibility.configuredByName) }) }}
          </div>
          <div v-if="props.visibility.updatedAt">
            {{ t('assets.visibility.lastUpdate') }}: {{ formatDate(props.visibility.updatedAt) }}
          </div>
        </div>
        <button
          v-if="isEditable"
          data-testid="save"
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
