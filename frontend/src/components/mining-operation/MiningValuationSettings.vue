<script setup lang="ts">
import { computed, reactive, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import MiningBoostFitField, { type MiningBoostFit } from '@/components/mining-operation/MiningBoostFitField.vue'
import { useReprocessingProfile } from '@/composables/useReprocessingProfile'
import { authFetch, safeJsonParse } from '@/services/api'
import type {
  MiningOperationMember,
  MiningSplitMode,
  MiningValuationForm,
  MiningValuationMarket,
  MiningValuationSettings,
  MiningValuationSide,
  UpdateMiningValuationInput,
} from '@/stores/miningOperation'

interface StructureSearchResult {
  id: number
  name: string
}

const FORMS: MiningValuationForm[] = ['raw', 'compressed', 'reprocessed']
const MARKETS: MiningValuationMarket[] = ['jita', 'structure']
const SIDES: MiningValuationSide[] = ['buy', 'sell']
const FULL_PERCENT = 100
const STRUCTURE_SEARCH_MIN_LENGTH = 3
const STRUCTURE_SEARCH_DELAY_MILLISECONDS = 300
const DROPDOWN_CLOSE_DELAY_MILLISECONDS = 200
const YIELD_DISPLAY_DIGITS = 2

const props = defineProps<{
  settings: MiningValuationSettings
  splitMode: MiningSplitMode
  members: MiningOperationMember[]
  editable: boolean
  corporationKnown: boolean
  saving: boolean
}>()

const emit = defineEmits<{
  save: [patch: UpdateMiningValuationInput]
}>()

const { t } = useI18n()
const ids = { yield: useId(), booster: useId(), boosterCut: useId(), corporationCut: useId(), structure: useId() }
const reprocessingProfile = useReprocessingProfile()

const form = reactive({
  valuationForm: props.settings.valuationForm,
  valuationMarket: props.settings.valuationMarket,
  valuationSide: props.settings.valuationSide,
  structureId: props.settings.structureId,
  structureName: props.settings.structureName,
  boosterCutPercent: props.settings.boosterCutPercent,
  corporationCutPercent: props.settings.corporationCutPercent,
  boosterMemberId: props.settings.boosterMemberId,
  boostShipTypeId: props.settings.boostShipTypeId,
  mountedCompressorKinds: [...props.settings.mountedCompressorKinds],
})
const yieldText = ref('')
const yieldEdited = ref(false)
const structureQuery = ref('')
const structureResults = ref<StructureSearchResult[]>([])
const searchingStructures = ref(false)
const structureDropdownOpen = ref(false)
let searchTimeout: ReturnType<typeof setTimeout> | null = null

const eachForThemselves = computed(() => props.splitMode === 'each_for_themselves')
const cutsDisabled = computed(() => !props.editable || eachForThemselves.value)
const acceptedMembers = computed(() => props.members.filter(member => member.status === 'accepted'))
const prefilledYield = computed(() => props.settings.reprocessYield ?? reprocessingProfile.reprocessYield.value)
const yieldFromProfile = computed(() => !yieldEdited.value && props.settings.reprocessYield === null && reprocessingProfile.reprocessYield.value !== null)
const yieldDisplay = computed({
  get: () => {
    if (yieldEdited.value) return yieldText.value
    return prefilledYield.value === null ? '' : (prefilledYield.value * FULL_PERCENT).toFixed(YIELD_DISPLAY_DIGITS)
  },
  set: (value: string) => {
    yieldText.value = value
    yieldEdited.value = true
  },
})
const yieldRatio = computed<number | null>(() => {
  if (!yieldEdited.value) return prefilledYield.value
  const percent = Number.parseFloat(yieldText.value)
  return Number.isFinite(percent) && percent > 0 && percent <= FULL_PERCENT ? percent / FULL_PERCENT : null
})
const restPercent = computed(() => FULL_PERCENT - form.boosterCutPercent - form.corporationCutPercent)

const patch = computed<UpdateMiningValuationInput>(() => {
  const current = props.settings
  const changes: UpdateMiningValuationInput = {}
  if (form.valuationForm !== current.valuationForm) changes.valuationForm = form.valuationForm
  if (form.valuationMarket !== current.valuationMarket) changes.valuationMarket = form.valuationMarket
  if (form.valuationSide !== current.valuationSide) changes.valuationSide = form.valuationSide
  if (form.valuationMarket === 'structure') {
    if (form.structureId !== current.structureId) changes.structureId = form.structureId
    if (form.structureName !== current.structureName) changes.structureName = form.structureName
  }
  if (form.valuationForm === 'reprocessed' && yieldRatio.value !== current.reprocessYield) changes.reprocessYield = yieldRatio.value
  if (form.boosterCutPercent !== current.boosterCutPercent) changes.boosterCutPercent = form.boosterCutPercent
  if (form.corporationCutPercent !== current.corporationCutPercent) changes.corporationCutPercent = form.corporationCutPercent
  if (form.boosterMemberId !== current.boosterMemberId) changes.boosterMemberId = form.boosterMemberId
  if (form.boostShipTypeId !== current.boostShipTypeId || form.mountedCompressorKinds.join() !== current.mountedCompressorKinds.join()) {
    changes.boostShipTypeId = form.boostShipTypeId
    changes.mountedCompressorKinds = form.mountedCompressorKinds
  }
  return changes
})
const boostFit = computed<MiningBoostFit>({
  get: () => ({ boostShipTypeId: form.boostShipTypeId, mountedCompressorKinds: form.mountedCompressorKinds }),
  set: (fit) => {
    form.boostShipTypeId = fit.boostShipTypeId
    form.mountedCompressorKinds = fit.mountedCompressorKinds
  },
})
const complete = computed(() => {
  if (form.valuationMarket === 'structure' && (form.structureId === null || form.structureName === null)) return false
  if (form.valuationForm === 'reprocessed' && yieldRatio.value === null) return false
  const cuts = [form.boosterCutPercent, form.corporationCutPercent]
  return cuts.every(cut => Number.isInteger(cut) && cut >= 0 && cut <= FULL_PERCENT) && restPercent.value >= 0
})
const canSave = computed(() => props.editable && !props.saving && complete.value && Object.keys(patch.value).length > 0)

function resetFromSettings(): void {
  Object.assign(form, {
    valuationForm: props.settings.valuationForm,
    valuationMarket: props.settings.valuationMarket,
    valuationSide: props.settings.valuationSide,
    structureId: props.settings.structureId,
    structureName: props.settings.structureName,
    boosterCutPercent: props.settings.boosterCutPercent,
    corporationCutPercent: props.settings.corporationCutPercent,
    boosterMemberId: props.settings.boosterMemberId,
    boostShipTypeId: props.settings.boostShipTypeId,
    mountedCompressorKinds: [...props.settings.mountedCompressorKinds],
  })
  yieldEdited.value = false
}

async function searchStructures(query: string): Promise<void> {
  searchingStructures.value = true
  try {
    const response = await authFetch(`/api/shopping-list/search-structures?q=${encodeURIComponent(query)}`)
    if (response.ok) {
      structureResults.value = (await safeJsonParse<{ results: StructureSearchResult[] }>(response)).results
      structureDropdownOpen.value = true
    }
  } catch (cause) {
    console.error('Structure search failed:', cause)
  } finally {
    searchingStructures.value = false
  }
}

function selectStructure(structure: StructureSearchResult): void {
  form.structureId = structure.id
  form.structureName = structure.name
  structureQuery.value = ''
  structureResults.value = []
  structureDropdownOpen.value = false
}

function closeStructureDropdownSoon(): void {
  setTimeout(() => { structureDropdownOpen.value = false }, DROPDOWN_CLOSE_DELAY_MILLISECONDS)
}

watch(() => JSON.stringify(props.settings), resetFromSettings)

watch(
  () => form.valuationForm,
  (valuationForm) => {
    if (valuationForm === 'reprocessed' && props.editable && props.settings.reprocessYield === null && reprocessingProfile.profile.value === null) {
      reprocessingProfile.load()
    }
  },
  { immediate: true },
)

watch(structureQuery, (query) => {
  if (searchTimeout !== null) clearTimeout(searchTimeout)
  if (query.length < STRUCTURE_SEARCH_MIN_LENGTH) {
    structureResults.value = []
    return
  }
  searchTimeout = setTimeout(() => searchStructures(query), STRUCTURE_SEARCH_DELAY_MILLISECONDS)
})
</script>

<template>
  <section class="eve-card space-y-4 p-5">
    <h2 class="font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.valuation.title') }}</h2>

    <fieldset class="space-y-1" :disabled="!editable">
      <legend class="mb-1 text-xs uppercase tracking-wider text-slate-400">{{ t('miningOperation.valuation.form.label') }}</legend>
      <div class="flex flex-wrap gap-2">
        <label v-for="option in FORMS" :key="option" class="flex cursor-pointer items-center gap-1.5 rounded-sm border border-slate-700 px-2.5 py-1 text-sm text-slate-200 has-[:checked]:border-cyan-400 has-[:checked]:bg-cyan-500/10">
          <input v-model="form.valuationForm" type="radio" name="valuation-form" :value="option" :disabled="!editable" class="accent-cyan-400">
          {{ t(`miningOperation.valuation.form.${option}`) }}
        </label>
      </div>
    </fieldset>

    <fieldset class="space-y-1" :disabled="!editable">
      <legend class="mb-1 text-xs uppercase tracking-wider text-slate-400">{{ t('miningOperation.valuation.market.label') }}</legend>
      <div class="flex flex-wrap gap-2">
        <label v-for="option in MARKETS" :key="option" class="flex cursor-pointer items-center gap-1.5 rounded-sm border border-slate-700 px-2.5 py-1 text-sm text-slate-200 has-[:checked]:border-cyan-400 has-[:checked]:bg-cyan-500/10">
          <input v-model="form.valuationMarket" type="radio" name="valuation-market" :value="option" :disabled="!editable" class="accent-cyan-400">
          {{ t(`miningOperation.valuation.market.${option}`) }}
        </label>
      </div>
      <div v-if="form.valuationMarket === 'structure'" class="relative pt-1">
        <p v-if="form.structureName !== null" class="mb-1 truncate font-mono text-xs text-cyan-200" :title="form.structureName">{{ form.structureName }}</p>
        <template v-if="editable">
          <label :for="ids.structure" class="sr-only">{{ t('ledger.mining.structureLabel') }}</label>
          <input
            :id="ids.structure"
            v-model="structureQuery"
            type="text"
            class="w-full rounded-sm border border-slate-700 bg-slate-950 px-2 py-1 text-sm text-slate-100"
            :placeholder="t('miningOperation.valuation.structureSearch')"
            @focus="structureDropdownOpen = true"
            @blur="closeStructureDropdownSoon"
          >
          <LoadingSpinner v-if="searchingStructures" size="sm" class="absolute right-2 top-3 text-cyan-400" />
          <ul v-if="structureDropdownOpen && structureResults.length > 0" class="absolute z-10 mt-1 max-h-48 w-full overflow-auto rounded-sm border border-slate-700 bg-slate-900">
            <li v-for="structure in structureResults" :key="structure.id">
              <button type="button" class="block w-full truncate px-2 py-1 text-left text-sm text-slate-200 hover:bg-slate-800" :title="structure.name" @mousedown.prevent="selectStructure(structure)">{{ structure.name }}</button>
            </li>
          </ul>
        </template>
      </div>
    </fieldset>

    <fieldset class="space-y-1" :disabled="!editable">
      <legend class="mb-1 text-xs uppercase tracking-wider text-slate-400">{{ t('miningOperation.valuation.side.label') }}</legend>
      <div class="flex flex-wrap gap-2">
        <label v-for="option in SIDES" :key="option" class="flex cursor-pointer items-center gap-1.5 rounded-sm border border-slate-700 px-2.5 py-1 text-sm text-slate-200 has-[:checked]:border-cyan-400 has-[:checked]:bg-cyan-500/10">
          <input v-model="form.valuationSide" type="radio" name="valuation-side" :value="option" :disabled="!editable" class="accent-cyan-400">
          {{ t(`miningOperation.valuation.side.${option}`) }}
        </label>
      </div>
    </fieldset>

    <MiningBoostFitField v-model="boostFit" :disabled="!editable" />

    <div v-if="form.valuationForm === 'reprocessed'" class="space-y-1">
      <label :for="ids.yield" class="block text-xs uppercase tracking-wider text-slate-400">{{ t('miningOperation.valuation.yield') }}</label>
      <div class="flex items-center gap-2">
        <input
          :id="ids.yield"
          v-model="yieldDisplay"
          type="number"
          min="0"
          max="100"
          step="0.01"
          :disabled="!editable"
          class="w-28 rounded-sm border border-slate-700 bg-slate-950 px-2 py-1 font-mono text-sm text-slate-100"
        >
        <span class="text-sm text-slate-400">%</span>
      </div>
      <p v-if="yieldFromProfile" class="text-xs text-slate-400">{{ t('miningOperation.valuation.yieldFromProfile') }}</p>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
      <div class="space-y-1">
        <label :for="ids.boosterCut" class="block text-xs uppercase tracking-wider text-slate-400">{{ t('miningOperation.valuation.boosterCut') }}</label>
        <div class="flex items-center gap-2">
          <input :id="ids.boosterCut" v-model.number="form.boosterCutPercent" type="number" min="0" max="100" step="1" :disabled="cutsDisabled" class="w-24 rounded-sm border border-slate-700 bg-slate-950 px-2 py-1 font-mono text-sm text-slate-100 disabled:opacity-50">
          <span class="text-sm text-slate-400">%</span>
        </div>
      </div>
      <div class="space-y-1">
        <label :for="ids.corporationCut" class="block text-xs uppercase tracking-wider text-slate-400">{{ t('miningOperation.valuation.corporationCut') }}</label>
        <div class="flex items-center gap-2">
          <input :id="ids.corporationCut" v-model.number="form.corporationCutPercent" type="number" min="0" max="100" step="1" :disabled="cutsDisabled || !corporationKnown" class="w-24 rounded-sm border border-slate-700 bg-slate-950 px-2 py-1 font-mono text-sm text-slate-100 disabled:opacity-50">
          <span class="text-sm text-slate-400">%</span>
        </div>
      </div>
    </div>
    <p v-if="eachForThemselves" class="text-xs text-slate-400">{{ t('miningOperation.valuation.noCutEachForThemselves') }}</p>
    <p v-else-if="!corporationKnown" class="text-xs text-amber-300">{{ t('miningOperation.valuation.corporationUnknown') }}</p>

    <div class="space-y-1">
      <label :for="ids.booster" class="block text-xs uppercase tracking-wider text-slate-400">{{ t('miningOperation.valuation.booster') }}</label>
      <select :id="ids.booster" v-model="form.boosterMemberId" :disabled="cutsDisabled" class="w-full rounded-sm border border-slate-700 bg-slate-950 px-2 py-1 text-sm text-slate-100 disabled:opacity-50">
        <option :value="null">{{ t('miningOperation.valuation.noBooster') }}</option>
        <option v-for="member in acceptedMembers" :key="member.id" :value="member.id">{{ member.characterName }}</option>
      </select>
    </div>

    <p class="text-xs text-slate-400">
      {{ eachForThemselves
        ? t('miningOperation.valuation.ruleEachForThemselves')
        : t('miningOperation.valuation.rule', { booster: form.boosterCutPercent, corporation: form.corporationCutPercent, rest: restPercent }) }}
    </p>

    <button
      v-if="editable"
      type="button"
      class="rounded-md bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-50"
      :disabled="!canSave"
      @click="emit('save', patch)"
    >{{ t('miningOperation.valuation.save') }}</button>
  </section>
</template>
