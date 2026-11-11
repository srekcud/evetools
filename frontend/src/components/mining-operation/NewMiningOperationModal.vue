<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseModal from '@/components/common/BaseModal.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import MiningBoostFitField, { type MiningBoostFit } from '@/components/mining-operation/MiningBoostFitField.vue'
import MiningCharacterPicker, { type PickableCharacter } from '@/components/mining-operation/MiningCharacterPicker.vue'
import MiningInvitationLink from '@/components/mining-operation/MiningInvitationLink.vue'
import MoonChunkName from '@/components/ledger/MoonChunkName.vue'
import { useCorporationMiningExtractions, type CorporationMiningExtraction } from '@/composables/useCorporationMiningExtractions'
import { useKnownCorporation } from '@/composables/useKnownCorporation'
import { useAuthStore } from '@/stores/auth'
import {
  useMiningOperationStore,
  type MiningOperation,
  type MiningOperationRecurrenceInput,
  type MiningOperationVisibility,
  type MiningSplitMode,
} from '@/stores/miningOperation'
import { eveInputToIso, isoToEveInput, nextFullHourAsEveInput } from '@/views/MiningOperation/miningOperationTime'

type Step = 'form' | 'created'

type RecurrenceChoice = 'once' | 'weekly' | 'chunk'

interface Refinery {
  structureId: number
  name: string
}

const RECURRENCE_CHOICES = ['once', 'weekly', 'chunk'] as const satisfies readonly RecurrenceChoice[]

const LEGEND_CLASS = 'mb-1.5 text-xs font-medium uppercase tracking-wider text-slate-400'
const SEGMENT_CLASS = 'flex cursor-pointer items-center gap-1.5 rounded-sm border border-slate-700 px-2.5 py-1 text-sm text-slate-200 has-[:checked]:border-cyan-400 has-[:checked]:bg-cyan-500/10 has-[:checked]:text-cyan-100 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-cyan-400 has-[:disabled]:cursor-not-allowed has-[:disabled]:text-slate-500'

const props = defineProps<{
  show: boolean
  chunk?: CorporationMiningExtraction
}>()

const emit = defineEmits<{
  close: []
  open: [operationId: string]
}>()

const { t, locale } = useI18n()
const authStore = useAuthStore()
const store = useMiningOperationStore()
const fieldId = useId()

const step = ref<Step>('form')
const label = ref('')
const startInput = ref(nextFullHourAsEveInput(new Date()))
const visibility = ref<MiningOperationVisibility>('private')
const splitMode = ref<MiningSplitMode>('value_prorata')
const boostFit = ref<MiningBoostFit>({ boostShipTypeId: null, mountedCompressorKinds: [] })
const tickedCharacterIds = ref<string[]>([])
const submitting = ref(false)
const failure = ref<string | null>(null)
const created = ref<MiningOperation | null>(null)

const recurrenceChoice = ref<RecurrenceChoice>('once')
const recurrenceStructureId = ref<number | null>(null)

const hasKnownCorporation = useKnownCorporation()
const extractions = useCorporationMiningExtractions()

const refineries = computed<Refinery[]>(() => {
  const corporationId = authStore.user?.corporationId
  const known = new Map<number, Refinery>()
  for (const corporation of extractions.corporations.value) {
    if (corporation.corporationId !== corporationId) continue
    for (const extraction of corporation.extractions) {
      known.set(extraction.structureId, { structureId: extraction.structureId, name: extraction.structureName ?? String(extraction.structureId) })
    }
  }
  return [...known.values()]
})

const availableRecurrenceChoices = computed(() =>
  RECURRENCE_CHOICES.filter(choice => choice !== 'chunk' || refineries.value.length > 0),
)

const startWeekday = computed(() => {
  const start = new Date(`${startInput.value}:00+00:00`)
  if (Number.isNaN(start.getTime())) return null
  return {
    day: new Intl.DateTimeFormat(locale.value, { weekday: 'long', timeZone: 'UTC' }).format(start),
    time: startInput.value.slice(startInput.value.indexOf('T') + 1),
  }
})

const pickableCharacters = computed<PickableCharacter[]>(() =>
  [...(authStore.user?.characters ?? [])]
    .sort((left, right) => Number(right.isMain) - Number(left.isMain) || left.name.localeCompare(right.name))
    .map(character => ({
      id: character.id,
      name: character.name,
      isMain: character.isMain,
      eligibility: character.hasValidToken ? 'eligible' : 'reconnect_required',
      busyOperationLabel: null,
    })),
)

const readyCharacterIds = computed(() => pickableCharacters.value.filter(character => character.eligibility === 'eligible').map(character => character.id))

function tickAllReady(): void {
  tickedCharacterIds.value = readyCharacterIds.value
}

function untickAll(): void {
  tickedCharacterIds.value = []
}

const recurrenceInput = computed<MiningOperationRecurrenceInput | undefined>(() => {
  if (recurrenceChoice.value === 'weekly') return { kind: 'weekly' }
  if (recurrenceChoice.value === 'chunk' && recurrenceStructureId.value !== null) return { kind: 'chunk', structureId: recurrenceStructureId.value }
  return undefined
})

const recurrenceComplete = computed(() => recurrenceChoice.value !== 'chunk' || recurrenceStructureId.value !== null)

const canSubmit = computed(() => label.value.trim().length > 0 && tickedCharacterIds.value.length > 0 && recurrenceComplete.value && !submitting.value)

function resetForm(): void {
  step.value = 'form'
  label.value = props.chunk === undefined ? '' : (props.chunk.moonName ?? String(props.chunk.moonId))
  startInput.value = props.chunk === undefined ? nextFullHourAsEveInput(new Date()) : isoToEveInput(props.chunk.chunkArrivalTime)
  visibility.value = hasKnownCorporation.value ? 'corporation' : 'private'
  splitMode.value = 'value_prorata'
  boostFit.value = { boostShipTypeId: null, mountedCompressorKinds: [] }
  tickedCharacterIds.value = pickableCharacters.value.filter(character => character.isMain && character.eligibility === 'eligible').map(character => character.id)
  recurrenceChoice.value = 'once'
  recurrenceStructureId.value = null
  failure.value = null
  created.value = null
}

async function submit(): Promise<void> {
  submitting.value = true
  failure.value = null
  try {
    created.value = await store.create({
      label: label.value.trim(),
      plannedStartAt: eveInputToIso(startInput.value),
      visibility: visibility.value,
      splitMode: splitMode.value,
      characterIds: tickedCharacterIds.value,
      ...(recurrenceInput.value === undefined ? {} : { recurrence: recurrenceInput.value }),
      boostShipTypeId: boostFit.value.boostShipTypeId,
      mountedCompressorKinds: boostFit.value.mountedCompressorKinds,
      ...(props.chunk === undefined ? {} : { chunkId: props.chunk.id }),
    })
    step.value = 'created'
  } catch (cause) {
    failure.value = cause instanceof Error ? cause.message : String(cause)
  } finally {
    submitting.value = false
  }
}

watch(recurrenceChoice, (choice) => {
  if (choice === 'chunk' && recurrenceStructureId.value === null) recurrenceStructureId.value = refineries.value[0]?.structureId ?? null
})

watch(() => props.show, (show) => {
  if (!show) return
  resetForm()
  if (hasKnownCorporation.value) void extractions.load()
}, { immediate: true })
</script>

<template>
  <BaseModal :show="show" :title="step === 'form' ? t('miningOperation.create.title') : t('miningOperation.created.title')" :panel-class="step === 'form' ? 'flex max-h-[calc(100dvh-2rem)] max-w-4xl flex-col border-slate-700' : 'max-w-xl border-slate-700'" @close="emit('close')">
    <template #title="{ titleId }">
      <h2 :id="titleId" class="shrink-0 border-b border-slate-800 px-6 py-4 font-display text-xl font-bold text-slate-100">
        {{ step === 'form' ? t('miningOperation.create.title') : t('miningOperation.created.title') }}
      </h2>
    </template>

    <form v-if="step === 'form'" class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
      <div class="grid min-h-0 flex-1 gap-6 overflow-y-auto px-6 py-5 md:grid-cols-[minmax(0,1fr)_minmax(0,19rem)]">
        <div class="min-w-0 space-y-4">
          <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
            <div class="min-w-0">
              <label :for="`${fieldId}-label`" :class="LEGEND_CLASS" class="block">{{ t('miningOperation.create.label') }}</label>
              <input :id="`${fieldId}-label`" v-model="label" type="text" maxlength="100" required class="w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100" />
            </div>
            <div>
              <label :for="`${fieldId}-start`" :class="LEGEND_CLASS" class="block">{{ t('miningOperation.create.start') }}</label>
              <input :id="`${fieldId}-start`" v-model="startInput" type="datetime-local" required class="w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-slate-100" />
            </div>
          </div>

          <div v-if="chunk !== undefined" data-chunk-system class="min-w-0 rounded-md border border-slate-700 bg-slate-950/60 px-3 py-2">
            <span class="block text-xs font-medium uppercase tracking-wider text-slate-400">{{ t('miningOperation.create.chunkSystem') }}</span>
            <span class="block text-sm text-slate-100">
              <span aria-hidden="true" class="text-cyan-300">◔ </span><MoonChunkName :name="chunk.solarSystemName" :id="chunk.solarSystemId" />
            </span>
            <span class="mt-1 block text-xs text-slate-400">{{ t('miningOperation.create.chunkSystemHint') }}</span>
          </div>

          <fieldset>
            <legend :class="LEGEND_CLASS">{{ t('miningOperation.create.visibility') }}</legend>
            <div class="flex flex-wrap gap-2">
              <label :class="SEGMENT_CLASS">
                <input v-model="visibility" type="radio" :name="`${fieldId}-visibility`" value="private" class="sr-only" />
                {{ t('miningOperation.visibility.private') }}
              </label>
              <label :class="SEGMENT_CLASS">
                <input v-model="visibility" type="radio" :name="`${fieldId}-visibility`" value="corporation" :disabled="!hasKnownCorporation" class="sr-only" />
                {{ t('miningOperation.visibility.corporation') }}
              </label>
            </div>
            <p v-if="!hasKnownCorporation" class="mt-1 text-xs text-amber-300">{{ t('miningOperation.create.corporationUnavailable') }}</p>
          </fieldset>

          <fieldset>
            <legend :class="LEGEND_CLASS">{{ t('miningOperation.create.splitMode') }}</legend>
            <div class="flex flex-wrap gap-2">
              <label :class="SEGMENT_CLASS">
                <input v-model="splitMode" type="radio" :name="`${fieldId}-split`" value="value_prorata" class="sr-only" />
                {{ t('miningOperation.splitMode.value_prorata') }}
              </label>
              <label :class="SEGMENT_CLASS">
                <input type="radio" :name="`${fieldId}-split`" value="weighted_time" disabled class="sr-only" />
                {{ t('miningOperation.splitMode.weighted_time') }}
                <span class="rounded-sm border border-slate-700 px-1 font-mono text-[0.65rem] uppercase">{{ t('miningOperation.splitMode.soon') }}</span>
              </label>
              <label :class="SEGMENT_CLASS">
                <input v-model="splitMode" type="radio" :name="`${fieldId}-split`" value="each_for_themselves" class="sr-only" />
                {{ t('miningOperation.splitMode.each_for_themselves') }}
              </label>
            </div>
            <p class="mt-1 text-xs text-slate-400">{{ t(`miningOperation.splitMode.hint.${splitMode}`) }}</p>
          </fieldset>

          <MiningBoostFitField v-model="boostFit" />

          <fieldset v-if="chunk === undefined">
            <legend :class="LEGEND_CLASS">{{ t('miningOperation.recurrence.field.legend') }}</legend>
            <div class="flex flex-wrap gap-2">
              <label v-for="choice in availableRecurrenceChoices" :key="choice" :class="SEGMENT_CLASS">
                <input v-model="recurrenceChoice" type="radio" :name="`${fieldId}-recurrence`" :value="choice" class="sr-only" />
                <span v-if="choice !== 'once'" class="text-violet-300" aria-hidden="true">↻</span>
                {{ t(`miningOperation.recurrence.field.${choice}`) }}
              </label>
            </div>
            <p v-if="recurrenceChoice === 'weekly'" class="mt-1 text-xs text-slate-400">
              {{ t('miningOperation.recurrence.field.weeklyHint') }}
              <span v-if="startWeekday !== null" class="font-mono text-cyan-200">{{ t('miningOperation.recurrence.rule.weekly', startWeekday) }}</span>
            </p>
            <div v-else-if="recurrenceChoice === 'chunk'" class="mt-2">
              <label :for="`${fieldId}-refinery`" class="mb-1 block text-xs font-medium text-slate-300">{{ t('miningOperation.recurrence.field.refinery') }}</label>
              <select
                :id="`${fieldId}-refinery`"
                v-model.number="recurrenceStructureId"
                data-testid="recurrence-structure"
                class="w-full max-w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100"
              >
                <option v-for="refinery in refineries" :key="refinery.structureId" :value="refinery.structureId">{{ refinery.name }}</option>
              </select>
              <p class="mt-1 text-xs text-slate-400">{{ t('miningOperation.recurrence.field.chunkHint') }}</p>
            </div>
          </fieldset>
        </div>

        <fieldset class="flex min-h-0 min-w-0 flex-col md:border-l md:border-slate-800 md:pl-6">
          <legend class="sr-only">{{ t('miningOperation.create.characters') }}</legend>
          <div class="mb-1.5 flex items-baseline justify-between gap-2" aria-hidden="true">
            <span class="text-xs font-medium uppercase tracking-wider text-slate-400">{{ t('miningOperation.create.characters') }}</span>
            <span class="font-mono text-xs text-cyan-300">{{ t('miningOperation.create.selectedCount', { selected: tickedCharacterIds.length, total: pickableCharacters.length }) }}</span>
          </div>
          <div class="mb-2 flex gap-2 text-xs">
            <button type="button" class="rounded-sm border border-slate-700 px-2 py-0.5 text-slate-300 hover:bg-slate-800 disabled:opacity-50" :disabled="readyCharacterIds.length === 0" @click="tickAllReady">{{ t('miningOperation.create.selectAllReady') }}</button>
            <button type="button" class="rounded-sm border border-slate-700 px-2 py-0.5 text-slate-300 hover:bg-slate-800 disabled:opacity-50" :disabled="tickedCharacterIds.length === 0" @click="untickAll">{{ t('miningOperation.create.selectNone') }}</button>
          </div>
          <div class="min-h-0 md:max-h-[26rem] md:overflow-y-auto md:pr-1">
            <MiningCharacterPicker v-model="tickedCharacterIds" :characters="pickableCharacters" dense />
          </div>
        </fieldset>
      </div>

      <div class="flex shrink-0 items-center justify-end gap-3 border-t border-slate-800 px-6 py-4">
        <p v-if="failure !== null" class="mr-auto min-w-0 truncate text-sm text-red-400" role="alert" :title="failure">{{ failure }}</p>
        <button type="button" class="rounded-md border border-slate-700 px-4 py-2 text-sm text-slate-300 hover:bg-slate-800" @click="emit('close')">{{ t('common.actions.cancel') }}</button>
        <button type="submit" :disabled="!canSubmit" class="flex items-center gap-2 rounded-md bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-50">
          <LoadingSpinner v-if="submitting" size="sm" />
          {{ t('miningOperation.create.submit') }}
        </button>
      </div>
    </form>

    <div v-else-if="created !== null" class="space-y-4 p-6">
      <p class="rounded-md border border-cyan-500/40 bg-cyan-500/10 px-4 py-2 text-sm text-cyan-200" role="status">{{ t('miningOperation.created.planned') }}</p>
      <MiningInvitationLink v-if="created.joinCode !== null" :join-code="created.joinCode" />
      <div class="flex justify-end">
        <button type="button" class="rounded-md bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-500" @click="emit('open', created.id)">{{ t('miningOperation.created.open') }}</button>
      </div>
    </div>
  </BaseModal>
</template>
