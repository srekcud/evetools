<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import type { Escalation, CreateEscalationInput } from '@/stores/escalation'
import { ESCALATION_SITES, SUGGESTED_PRICES } from '@/constants/escalationConstants'
import { useEscalationTimers } from '@/composables/useEscalationTimers'

const { t } = useI18n()
const authStore = useAuthStore()
const { timerBarColor, timerTextColor } = useEscalationTimers()

interface Character {
  eveCharacterId: number
  name: string
  corporationName: string
}

interface Props {
  visible: boolean
  editingEscalation: Escalation | null
  characters: Character[]
}

const props = defineProps<Props>()

const emit = defineEmits<{
  close: []
  submit: [input: CreateEscalationInput | { updates: Partial<Pick<Escalation, 'type' | 'price' | 'notes' | 'visibility'>>; id: string }]
}>()

// ========== Form State ==========

const isSubmitting = ref(false)
const showCharDropdown = ref(false)
const showTypeDropdown = ref(false)
const showSystemDropdown = ref(false)

const formCharacterId = ref<number | null>(null)
const formCharacterName = ref('')
const formType = ref('')
const formSystemSearch = ref('')
const formSystemId = ref<number | null>(null)
const formSystemName = ref('')
const formSystemSec = ref(0)
const formSystemRegion = ref('')
const formTimerDays = ref(1)
const formTimerHours = ref(0)
const formTimerMinutes = ref(0)
const formPrice = ref(150)
const formNotes = ref('')
const formVisibility = ref<'perso' | 'corp' | 'alliance' | 'public'>('perso')

const isEditMode = computed(() => props.editingEscalation !== null)

// ========== Computed ==========

const escalationOptions = computed(() => {
  return Object.entries(ESCALATION_SITES).map(([faction, sites]) => ({
    label: faction,
    options: sites.map(site => ({
      value: `${site.name} (${site.level}/10)`,
      label: `${site.name} (${site.level}/10)`,
    })),
  }))
})

const selectedLevel = computed(() => {
  if (!formType.value) return null
  const match = formType.value.match(/\((\d+)\/10\)$/)
  return match ? match[1] : null
})

const currentSuggestedPrices = computed(() => {
  if (!selectedLevel.value) return [50, 100, 150, 200]
  return SUGGESTED_PRICES[selectedLevel.value] ?? [50, 100, 150, 200]
})

const formTotalHours = computed(() => {
  return formTimerDays.value * 24 + formTimerHours.value + formTimerMinutes.value / 60
})

const formTimerPercent = computed(() => {
  return Math.min(100, (formTotalHours.value / 72) * 100)
})

const formTimerPreviewText = computed(() => {
  const h = Math.round(formTotalHours.value)
  return h >= 24 ? `~${Math.floor(h / 24)}j ${h % 24}h` : `~${h}h`
})

const formTimerZone = computed(() => {
  if (formTimerPercent.value > 50) return 'safe'
  if (formTimerPercent.value > 20) return 'warning'
  return 'danger'
})

const isFormValid = computed(() => {
  return (
    formCharacterId.value !== null &&
    formType.value !== '' &&
    formSystemId.value !== null &&
    formTotalHours.value > 0 &&
    formPrice.value >= 0
  )
})

const previewSystem = computed(() => formSystemName.value || '--')
const previewChar = computed(() => formCharacterName.value || '--')
const previewType = computed(() => formType.value || '--')

// ========== Watchers ==========

// Update suggestions when type changes (only in add mode)
watch(() => formType.value, () => {
  if (!isEditMode.value && selectedLevel.value && SUGGESTED_PRICES[selectedLevel.value]) {
    formPrice.value = SUGGESTED_PRICES[selectedLevel.value][1] ?? 100
  }
})

// Initialize form when modal opens
watch(() => props.visible, (newVal) => {
  if (newVal) {
    if (props.editingEscalation) {
      // Edit mode
      formType.value = props.editingEscalation.type
      formPrice.value = props.editingEscalation.price
      formNotes.value = props.editingEscalation.notes ?? ''
      formVisibility.value = props.editingEscalation.visibility
      formCharacterId.value = props.editingEscalation.characterId
      formCharacterName.value = props.editingEscalation.characterName
      formSystemId.value = props.editingEscalation.solarSystemId
      formSystemName.value = props.editingEscalation.solarSystemName
      formSystemSec.value = props.editingEscalation.securityStatus
      formSystemRegion.value = ''
    } else {
      // Add mode
      resetForm()
      if (props.characters.length > 0 && !formCharacterId.value) {
        formCharacterId.value = props.characters[0].eveCharacterId
        formCharacterName.value = props.characters[0].name
      }
    }
  }
})

// ========== Helpers ==========

function getCharInitials(name: string): string {
  return name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase()
}

function selectFormType(value: string): void {
  formType.value = value
  showTypeDropdown.value = false
}

function selectFormCharacter(charId: number, charName: string): void {
  formCharacterId.value = charId
  formCharacterName.value = charName
  showCharDropdown.value = false
}

function clearFormSystem(): void {
  formSystemId.value = null
  formSystemName.value = ''
  formSystemSec.value = 0
  formSystemRegion.value = ''
  formSystemSearch.value = ''
}

function setFormPrice(val: number): void {
  formPrice.value = val
}

function secBadgeClasses(sec: number): string {
  if (sec >= 0.9) return 'bg-emerald-500/20 text-emerald-400'
  if (sec >= 0.5) return 'bg-green-500/20 text-green-400'
  if (sec > 0.0) return 'bg-amber-500/20 text-amber-400'
  return 'bg-red-500/20 text-red-400'
}

function visibilityTitle(vis: string): string {
  if (vis === 'perso') return t('escalations.visibility.perso')
  if (vis === 'corp') return t('escalations.visibility.corp')
  if (vis === 'alliance') return t('escalations.visibility.alliance')
  return t('escalations.visibility.public')
}

function visibilityDescription(vis: string): string {
  if (vis === 'perso') return t('escalations.visibility.persoDesc')
  if (vis === 'corp') return t('escalations.visibility.corpDesc')
  if (vis === 'alliance') return t('escalations.visibility.allianceDesc')
  return t('escalations.visibility.publicDesc')
}

// ========== System Search ==========

const systemSearchResults = ref<Array<{ name: string; sec: number; region: string; id: number }>>([])
let searchTimeout: ReturnType<typeof setTimeout> | null = null

function onSystemSearch(query: string): void {
  formSystemSearch.value = query
  if (searchTimeout) clearTimeout(searchTimeout)

  if (!query || query.length < 2) {
    systemSearchResults.value = []
    showSystemDropdown.value = false
    return
  }

  searchTimeout = setTimeout(async () => {
    try {
      const response = await fetch(`/api/sde/solar-systems?q=${encodeURIComponent(query)}`, {
        headers: {
          'Authorization': `Bearer ${authStore.token}`,
          'Accept': 'application/ld+json',
        },
      })

      if (response.ok) {
        const data = await response.json()
        const items = data['member'] ?? []
        systemSearchResults.value = items.slice(0, 8).map((s: Record<string, unknown>) => ({
          name: (s.solarSystemName as string) ?? '',
          sec: (s.security as number) ?? 0,
          region: (s.regionName as string) ?? '',
          id: (s.solarSystemId as number) ?? 0,
        }))
        showSystemDropdown.value = systemSearchResults.value.length > 0
      }
    } catch {
      systemSearchResults.value = []
      showSystemDropdown.value = false
    }
  }, 300)
}

function selectSystem(system: { name: string; sec: number; region: string; id: number }): void {
  formSystemId.value = system.id
  formSystemName.value = system.name
  formSystemSec.value = system.sec
  formSystemRegion.value = system.region
  formSystemSearch.value = ''
  systemSearchResults.value = []
  showSystemDropdown.value = false
}

// ========== Submit ==========

async function submitForm(): Promise<void> {
  if (isSubmitting.value) return

  const editing = props.editingEscalation
  isSubmitting.value = true

  try {
    if (editing) {
      const updates: Partial<Pick<Escalation, 'type' | 'price' | 'notes' | 'visibility'>> = {}
      if (formType.value !== editing.type) updates.type = formType.value
      if (formPrice.value !== editing.price) updates.price = formPrice.value
      if (formVisibility.value !== editing.visibility) updates.visibility = formVisibility.value
      const newNotes = formNotes.value || null
      if (newNotes !== editing.notes) updates.notes = newNotes
      emit('submit', { updates, id: editing.id })
    } else {
      if (!isFormValid.value) return
      const input: CreateEscalationInput = {
        characterId: formCharacterId.value!,
        type: formType.value,
        solarSystemId: formSystemId.value!,
        solarSystemName: formSystemName.value,
        securityStatus: formSystemSec.value,
        price: formPrice.value,
        notes: formNotes.value || null,
        timerHours: formTotalHours.value,
        visibility: formVisibility.value,
      }
      emit('submit', input)
    }
  } finally {
    isSubmitting.value = false
  }
}

function closeModal(): void {
  showCharDropdown.value = false
  showTypeDropdown.value = false
  showSystemDropdown.value = false
  emit('close')
}

function resetForm(): void {
  formType.value = ''
  formSystemSearch.value = ''
  formSystemId.value = null
  formSystemName.value = ''
  formSystemSec.value = 0
  formSystemRegion.value = ''
  formTimerDays.value = 1
  formTimerHours.value = 0
  formTimerMinutes.value = 0
  formPrice.value = 150
  formNotes.value = ''
  formVisibility.value = 'perso'
}

</script>

<template>
  <Teleport to="body">
    <div v-if="visible" class="fixed inset-0 z-50 flex items-center justify-center" @click.self="closeModal">
      <!-- Backdrop -->
      <div class="absolute inset-0 bg-slate-950/80" @click="closeModal"></div>

      <!-- Modal content -->
      <div class="relative bg-slate-900 rounded-2xl border border-cyan-500/30 shadow-2xl shadow-cyan-500/10 w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
          <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-cyan-500/20 border border-cyan-500/30 flex items-center justify-center">
              <svg v-if="isEditMode" class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg>
              <svg v-else class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
              </svg>
            </div>
            <div>
              <h3 class="text-lg font-semibold text-slate-100">{{ isEditMode ? t('escalations.modal.editTitle') : t('escalations.modal.addTitle') }}</h3>
              <p class="text-xs text-slate-500">{{ isEditMode ? t('escalations.modal.editSubtitle', { system: editingEscalation!.solarSystemName, character: editingEscalation!.characterName }) : t('escalations.modal.addSubtitle') }}</p>
            </div>
          </div>
          <button @click="closeModal" class="p-1.5 hover:bg-slate-800 rounded-lg transition-colors">
            <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>

        <!-- Body -->
        <div class="p-6 space-y-5">

          <!-- Row 1: Character + Type -->
          <div :class="isEditMode ? '' : 'grid grid-cols-5 gap-4'">
            <!-- Character (2 cols) - only in add mode -->
            <div v-if="!isEditMode" class="col-span-2">
              <label class="block text-sm font-medium text-slate-400 mb-1.5">{{ t('escalations.modal.character') }}</label>
              <div class="relative">
                <div
                  class="flex items-center gap-2 w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 cursor-pointer hover:border-cyan-500/50 transition-colors"
                  @click.stop="showCharDropdown = !showCharDropdown"
                >
                  <div class="w-6 h-6 rounded-sm bg-slate-700 flex items-center justify-center text-cyan-400 text-xs font-bold shrink-0">
                    {{ formCharacterName ? getCharInitials(formCharacterName) : '?' }}
                  </div>
                  <span class="text-sm truncate">{{ formCharacterName || t('escalations.modal.selectCharacter') }}</span>
                  <svg class="w-4 h-4 text-slate-500 ml-auto shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </div>

                <!-- Character dropdown -->
                <div v-if="showCharDropdown" class="absolute z-10 mt-1 w-full bg-slate-800 border border-slate-700 rounded-lg shadow-xl overflow-hidden">
                  <button
                    v-for="char in characters"
                    :key="char.eveCharacterId"
                    class="w-full flex items-center gap-2 px-3 py-2.5 hover:bg-cyan-500/10 transition-colors text-left"
                    @click.stop="selectFormCharacter(char.eveCharacterId, char.name)"
                  >
                    <div class="w-6 h-6 rounded-sm bg-slate-700 flex items-center justify-center text-cyan-400 text-xs font-bold">
                      {{ getCharInitials(char.name) }}
                    </div>
                    <div>
                      <p class="text-sm text-slate-200">{{ char.name }}</p>
                      <p class="text-xs text-slate-500">{{ char.corporationName }}</p>
                    </div>
                  </button>
                </div>
              </div>
            </div>

            <!-- Escalation type (3 cols in add mode, full width in edit mode) -->
            <div :class="isEditMode ? '' : 'col-span-3'">
              <label class="block text-sm font-medium text-slate-400 mb-1.5">{{ t('escalations.modal.type') }}</label>
              <div class="relative">
                <div
                  class="flex items-center justify-between w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 cursor-pointer hover:border-cyan-500/50 transition-colors"
                  @click.stop="showTypeDropdown = !showTypeDropdown; showCharDropdown = false"
                >
                  <span class="text-sm" :class="formType ? 'text-slate-200' : 'text-slate-500'">{{ formType || t('escalations.modal.selectType') }}</span>
                  <svg class="w-4 h-4 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </div>

                <div v-if="showTypeDropdown" class="absolute z-10 mt-1 w-full bg-slate-800 border border-slate-700 rounded-lg shadow-xl overflow-hidden max-h-64 overflow-y-auto">
                  <template v-for="group in escalationOptions" :key="group.label">
                    <div class="px-3 py-1.5 text-xs text-slate-500 uppercase tracking-wider bg-slate-900/50 sticky top-0">{{ group.label }}</div>
                    <button
                      v-for="opt in group.options"
                      :key="opt.value"
                      class="w-full px-3 py-2 text-sm text-left transition-colors"
                      :class="formType === opt.value ? 'bg-cyan-500/20 text-cyan-400' : 'text-slate-300 hover:bg-cyan-500/10 hover:text-cyan-300'"
                      @click.stop="selectFormType(opt.value)"
                    >{{ opt.label }}</button>
                  </template>
                </div>
              </div>
            </div>
          </div>

          <!-- Solar system search - only in add mode -->
          <div v-if="!isEditMode">
            <label class="block text-sm font-medium text-slate-400 mb-1.5">{{ t('escalations.modal.system') }}</label>
            <div class="relative">
              <!-- Search input (hidden when system selected) -->
              <template v-if="!formSystemId">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                </svg>
                <input
                  type="text"
                  :value="formSystemSearch"
                  @input="onSystemSearch(($event.target as HTMLInputElement).value)"
                  @focus="onSystemSearch(formSystemSearch)"
                  :placeholder="t('escalations.modal.searchSystem')"
                  autocomplete="off"
                  class="w-full bg-slate-800 border border-slate-700 rounded-lg pl-9 pr-3 py-2 text-sm placeholder-slate-500 focus:outline-hidden focus:border-cyan-500 hover:border-cyan-500/50 transition-colors"
                >

                <!-- Autocomplete dropdown -->
                <div
                  v-if="showSystemDropdown && systemSearchResults.length > 0"
                  class="absolute z-10 mt-1 w-full bg-slate-800 border border-slate-700 rounded-lg shadow-xl overflow-hidden max-h-48 overflow-y-auto"
                >
                  <button
                    v-for="sys in systemSearchResults"
                    :key="sys.id"
                    class="w-full flex items-center gap-3 px-3 py-2.5 hover:bg-cyan-500/10 transition-colors text-left"
                    @click.stop="selectSystem(sys)"
                  >
                    <span class="text-xs font-mono font-bold px-1.5 py-0.5 rounded-sm" :class="secBadgeClasses(sys.sec)">
                      {{ sys.sec.toFixed(1) }}
                    </span>
                    <span class="text-sm text-slate-200">{{ sys.name }}</span>
                    <span class="text-xs text-slate-500 ml-auto">{{ sys.region }}</span>
                  </button>
                </div>
              </template>

              <!-- Selected system badge -->
              <div v-else class="inline-flex items-center gap-2 px-3 py-1.5 bg-slate-800/80 rounded-lg border border-slate-700/50">
                <span class="text-xs font-mono font-bold px-1.5 py-0.5 rounded-sm" :class="secBadgeClasses(formSystemSec)">
                  {{ formSystemSec.toFixed(1) }}
                </span>
                <span class="text-sm text-slate-200">{{ formSystemName }}</span>
                <span class="text-xs text-slate-500">{{ formSystemRegion }}</span>
                <button class="ml-1 text-slate-500 hover:text-red-400 transition-colors" @click="clearFormSystem">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
              </div>
            </div>
          </div>

          <!-- Row 2: Timer + Price -->
          <div :class="isEditMode ? '' : 'grid grid-cols-2 gap-4'">
            <!-- Timer - only in add mode -->
            <div v-if="!isEditMode">
              <label class="block text-sm font-medium text-slate-400 mb-1.5">{{ t('escalations.modal.timer') }}</label>
              <div class="flex items-center gap-2">
                <div class="flex items-center gap-1.5">
                  <input
                    type="number"
                    v-model.number="formTimerDays"
                    min="0"
                    max="3"
                    class="w-14 bg-slate-800 border border-slate-700 rounded-lg px-2 py-2 text-sm text-center focus:outline-hidden focus:border-cyan-500 font-mono hover:border-cyan-500/50 transition-colors"
                  >
                  <span class="text-xs text-slate-500">j</span>
                </div>
                <div class="flex items-center gap-1.5">
                  <input
                    type="number"
                    v-model.number="formTimerHours"
                    min="0"
                    max="23"
                    class="w-14 bg-slate-800 border border-slate-700 rounded-lg px-2 py-2 text-sm text-center focus:outline-hidden focus:border-cyan-500 font-mono hover:border-cyan-500/50 transition-colors"
                  >
                  <span class="text-xs text-slate-500">h</span>
                </div>
                <div class="flex items-center gap-1.5">
                  <input
                    type="number"
                    v-model.number="formTimerMinutes"
                    min="0"
                    max="59"
                    class="w-14 bg-slate-800 border border-slate-700 rounded-lg px-2 py-2 text-sm text-center focus:outline-hidden focus:border-cyan-500 font-mono hover:border-cyan-500/50 transition-colors"
                  >
                  <span class="text-xs text-slate-500">m</span>
                </div>
              </div>
              <!-- Timer preview bar -->
              <div class="flex items-center gap-2 mt-2">
                <div class="flex-1 h-1.5 bg-slate-700 rounded-full overflow-hidden">
                  <div
                    class="h-full rounded-full transition-all duration-300"
                    :class="timerBarColor(formTimerZone)"
                    :style="{ width: formTimerPercent + '%' }"
                  ></div>
                </div>
                <span class="text-xs font-mono" :class="timerTextColor(formTimerZone)">{{ formTimerPreviewText }}</span>
              </div>
            </div>

            <!-- Price -->
            <div>
              <label class="block text-sm font-medium text-slate-400 mb-1.5">{{ t('escalations.modal.price') }}</label>
              <div class="relative">
                <input
                  type="number"
                  v-model.number="formPrice"
                  min="0"
                  class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm focus:outline-hidden focus:border-cyan-500 font-mono pr-8 hover:border-cyan-500/50 transition-colors"
                >
                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-500">m</span>
              </div>
              <!-- Suggested prices -->
              <div class="flex flex-wrap gap-1.5 mt-2">
                <button
                  v-for="sp in currentSuggestedPrices"
                  :key="sp"
                  class="px-2 py-0.5 text-xs rounded-sm border transition-colors font-mono"
                  :class="formPrice === sp
                    ? 'bg-cyan-500/10 text-cyan-400 border-cyan-500/30'
                    : 'bg-slate-800 text-slate-400 hover:text-cyan-400 hover:bg-slate-700 border-slate-700/50'"
                  @click="setFormPrice(sp)"
                >{{ sp }}m</button>
              </div>
            </div>
          </div>

          <!-- Notes -->
          <div>
            <label class="block text-sm font-medium text-slate-400 mb-1.5">
              {{ t('escalations.modal.notes') }}
              <span class="text-slate-600 font-normal ml-1">({{ t('escalations.modal.optional') }})</span>
            </label>
            <input
              type="text"
              v-model="formNotes"
              :placeholder="t('escalations.modal.notesPlaceholder')"
              class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm placeholder-slate-500 focus:outline-hidden focus:border-cyan-500 hover:border-cyan-500/50 transition-colors"
            >
          </div>

          <!-- Visibility -->
          <div>
            <label class="block text-sm font-medium text-slate-400 mb-1.5">{{ t('escalations.modal.visibility') }}</label>
            <div class="grid grid-cols-4 gap-2">
              <button
                v-for="vis in ['perso', 'corp', 'alliance', 'public'] as const"
                :key="vis"
                type="button"
                @click="formVisibility = vis"
                class="px-3 py-2 rounded-lg border text-sm font-medium transition-all flex items-center justify-center gap-1.5"
                :class="formVisibility === vis
                  ? 'bg-cyan-500/20 border-cyan-500/50 text-cyan-400'
                  : 'bg-slate-800/50 border-slate-700 text-slate-400 hover:border-cyan-500/30 hover:text-slate-300'"
              >
                <svg v-if="vis === 'perso'" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                <svg v-else-if="vis === 'corp'" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
                <svg v-else-if="vis === 'alliance'" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
                <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"/></svg>
                {{ visibilityTitle(vis) }}
              </button>
            </div>
            <p class="text-xs text-slate-500 mt-1.5">{{ visibilityDescription(formVisibility) }}</p>
          </div>

          <!-- Preview card - only in add mode -->
          <div v-if="!isEditMode" class="bg-slate-800/30 rounded-xl border border-slate-700/30 p-4">
            <div class="flex items-center gap-2 mb-3">
              <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
              <span class="text-xs text-slate-500 uppercase tracking-wider">{{ t('escalations.modal.preview') }}</span>
            </div>
            <div class="flex items-center justify-between">
              <div>
                <div class="flex items-center gap-2 mb-1">
                  <span class="text-xs px-2 py-0.5 rounded-sm bg-slate-500/20 text-slate-400 line-through">BM</span>
                  <span class="text-sm text-slate-300">{{ previewType }}</span>
                </div>
                <p class="text-xs text-slate-500 flex items-center gap-3">
                  <span class="flex items-center gap-1">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                    {{ previewSystem }}
                  </span>
                  <span class="flex items-center gap-1">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0"/></svg>
                    {{ previewChar }}
                  </span>
                </p>
              </div>
              <div class="text-right">
                <p class="text-lg font-bold text-cyan-400 font-mono">{{ formPrice }}<span class="text-xs text-slate-500 ml-0.5">m</span></p>
                <p class="text-xs font-mono text-slate-500">{{ formTimerPreviewText }} {{ t('common.time.remaining') }}</p>
              </div>
            </div>
          </div>

        </div>

        <!-- Footer -->
        <div class="px-6 py-4 border-t border-slate-800 flex items-center justify-between">
          <p v-if="!isEditMode" class="text-xs text-slate-600">{{ t('escalations.modal.expiresIn72h') }}</p>
          <span v-else></span>
          <div class="flex items-center gap-3">
            <button
              @click="closeModal"
              class="px-4 py-2 rounded-lg text-sm text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
            >{{ t('common.actions.cancel') }}</button>
            <button
              @click="submitForm"
              :disabled="(!isEditMode && !isFormValid) || isSubmitting"
              class="px-5 py-2 bg-cyan-600 hover:bg-cyan-500 disabled:bg-slate-700 disabled:text-slate-500 disabled:cursor-not-allowed rounded-lg text-white text-sm font-medium transition-colors flex items-center gap-2 shadow-lg shadow-cyan-500/20 disabled:shadow-none"
            >
              <svg v-if="isSubmitting" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ isSubmitting ? (isEditMode ? t('escalations.modal.saving') : t('escalations.modal.adding')) : (isEditMode ? t('escalations.modal.save') : t('escalations.modal.add')) }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
/* Hide number input spinners */
input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
input[type=number] {
  -moz-appearance: textfield;
}
</style>
