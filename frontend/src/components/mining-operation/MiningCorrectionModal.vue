<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseModal from '@/components/common/BaseModal.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import type { MiningLedgerCharacter, MiningOperationLedger } from '@/stores/miningOperation'
import { formatCount } from '@/views/MiningOperation/miningOperationQuantity'

interface MeasuredType {
  typeId: number
  typeName: string
}

const props = defineProps<{
  show: boolean
  ledger: MiningOperationLedger
  submitting: boolean
  failure: string | null
}>()

const emit = defineEmits<{
  close: []
  submit: [operationCharacterId: string, typeId: number, quantity: number]
}>()

const { t, locale } = useI18n()
const fieldId = useId()

const characterId = ref('')
const typeId = ref<number | null>(null)
const quantity = ref<number | null>(null)

const correctableCharacters = computed<MiningLedgerCharacter[]>(() =>
  props.ledger.participants.flatMap(participant => participant.characters).filter(character => character.state !== 'measuring'),
)

const measuredTypes = computed<MeasuredType[]>(() => {
  const types = new Map<number, string>()
  for (const participant of props.ledger.participants) {
    for (const character of participant.characters) {
      for (const measured of character.quantities) types.set(measured.typeId, measured.typeName)
    }
  }
  return [...types.entries()].map(([id, name]) => ({ typeId: id, typeName: name })).sort((left, right) => left.typeName.localeCompare(right.typeName))
})

const selectedCharacter = computed(() => correctableCharacters.value.find(character => character.id === characterId.value) ?? null)

const measuredText = computed(() => {
  const character = selectedCharacter.value
  if (character === null || typeId.value === null) return null
  const row = character.quantities.find(measured => measured.typeId === typeId.value)
  if (row !== undefined) {
    return row.measuredQuantity === null ? t('miningOperation.quantities.unknown') : formatCount(row.measuredQuantity, locale.value)
  }
  const bothSnapshotsTaken = character.startSnapshot?.status === 'taken' && character.endSnapshot?.status === 'taken'
  return bothSnapshotsTaken ? formatCount(0, locale.value) : t('miningOperation.quantities.unknown')
})

const canSubmit = computed(() => selectedCharacter.value !== null && typeId.value !== null && quantity.value !== null && quantity.value >= 0 && !props.submitting)

function submit(): void {
  if (!canSubmit.value || typeId.value === null || quantity.value === null) return
  emit('submit', characterId.value, typeId.value, quantity.value)
}

watch(() => props.show, (show) => {
  if (!show) return
  characterId.value = correctableCharacters.value[0]?.id ?? ''
  typeId.value = measuredTypes.value[0]?.typeId ?? null
  quantity.value = null
})
</script>

<template>
  <BaseModal :show="show" :title="t('miningOperation.correction.title')" panel-class="max-w-md border-slate-700" @close="emit('close')">
    <template #title="{ titleId }">
      <h2 :id="titleId" class="px-6 pt-6 font-display text-xl font-bold text-slate-100">{{ t('miningOperation.correction.title') }}</h2>
    </template>

    <form class="space-y-4 p-6" @submit.prevent="submit">
      <div>
        <label :for="`${fieldId}-character`" class="mb-1 block text-sm font-medium text-slate-300">{{ t('miningOperation.correction.character') }}</label>
        <select :id="`${fieldId}-character`" v-model="characterId" class="w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100">
          <option v-for="character in correctableCharacters" :key="character.id" :value="character.id">{{ character.name }}</option>
        </select>
      </div>

      <div>
        <label :for="`${fieldId}-type`" class="mb-1 block text-sm font-medium text-slate-300">{{ t('miningOperation.correction.type') }}</label>
        <select :id="`${fieldId}-type`" v-model="typeId" class="w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100">
          <option v-for="measuredType in measuredTypes" :key="measuredType.typeId" :value="measuredType.typeId">{{ measuredType.typeName }}</option>
        </select>
      </div>

      <div>
        <label :for="`${fieldId}-quantity`" class="mb-1 block text-sm font-medium text-slate-300">{{ t('miningOperation.correction.quantity') }}</label>
        <input :id="`${fieldId}-quantity`" v-model.number="quantity" type="number" min="0" step="1" required class="w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-slate-100" />
        <p v-if="measuredText !== null" class="mt-1 font-mono text-xs text-slate-400">{{ t('miningOperation.correction.measured', { value: measuredText }) }}</p>
      </div>

      <p v-if="failure !== null" class="text-sm text-red-400" role="alert">{{ failure }}</p>

      <div class="flex justify-end gap-3">
        <button type="button" class="rounded-md border border-slate-700 px-4 py-2 text-sm text-slate-300 hover:bg-slate-800" @click="emit('close')">{{ t('common.actions.cancel') }}</button>
        <button type="button" :disabled="!canSubmit" class="flex items-center gap-2 rounded-md bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-50" @click="submit">
          <LoadingSpinner v-if="submitting" size="sm" />
          {{ t('miningOperation.correction.submit') }}
        </button>
      </div>
    </form>
  </BaseModal>
</template>
