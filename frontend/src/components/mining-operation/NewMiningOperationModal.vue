<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseModal from '@/components/common/BaseModal.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import MiningCharacterPicker, { type PickableCharacter } from '@/components/mining-operation/MiningCharacterPicker.vue'
import MiningInvitationLink from '@/components/mining-operation/MiningInvitationLink.vue'
import { useKnownCorporation } from '@/composables/useKnownCorporation'
import { useAuthStore } from '@/stores/auth'
import { useMiningOperationStore, type MiningOperation, type MiningOperationVisibility, type MiningSplitMode } from '@/stores/miningOperation'
import { eveInputToIso, nextFullHourAsEveInput } from '@/views/MiningOperation/miningOperationTime'

type Step = 'form' | 'created'

const props = defineProps<{
  show: boolean
}>()

const emit = defineEmits<{
  close: []
  open: [operationId: string]
}>()

const { t } = useI18n()
const authStore = useAuthStore()
const store = useMiningOperationStore()
const fieldId = useId()

const step = ref<Step>('form')
const label = ref('')
const startInput = ref(nextFullHourAsEveInput(new Date()))
const visibility = ref<MiningOperationVisibility>('private')
const splitMode = ref<MiningSplitMode>('value_prorata')
const tickedCharacterIds = ref<string[]>([])
const submitting = ref(false)
const failure = ref<string | null>(null)
const created = ref<MiningOperation | null>(null)

const hasKnownCorporation = useKnownCorporation()

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

const canSubmit = computed(() => label.value.trim().length > 0 && tickedCharacterIds.value.length > 0 && !submitting.value)

function resetForm(): void {
  step.value = 'form'
  label.value = ''
  startInput.value = nextFullHourAsEveInput(new Date())
  visibility.value = hasKnownCorporation.value ? 'corporation' : 'private'
  splitMode.value = 'value_prorata'
  tickedCharacterIds.value = pickableCharacters.value.filter(character => character.isMain && character.eligibility === 'eligible').map(character => character.id)
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
    })
    step.value = 'created'
  } catch (cause) {
    failure.value = cause instanceof Error ? cause.message : String(cause)
  } finally {
    submitting.value = false
  }
}

watch(() => props.show, (show) => {
  if (show) resetForm()
}, { immediate: true })
</script>

<template>
  <BaseModal :show="show" :title="step === 'form' ? t('miningOperation.create.title') : t('miningOperation.created.title')" panel-class="max-w-xl border-slate-700" @close="emit('close')">
    <template #title="{ titleId }">
      <h2 :id="titleId" class="px-6 pt-6 font-display text-xl font-bold text-slate-100">
        {{ step === 'form' ? t('miningOperation.create.title') : t('miningOperation.created.title') }}
      </h2>
    </template>

    <form v-if="step === 'form'" class="space-y-5 p-6" @submit.prevent="submit">
      <div>
        <label :for="`${fieldId}-label`" class="mb-1 block text-sm font-medium text-slate-300">{{ t('miningOperation.create.label') }}</label>
        <input :id="`${fieldId}-label`" v-model="label" type="text" maxlength="100" required class="w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100" />
      </div>

      <div>
        <label :for="`${fieldId}-start`" class="mb-1 block text-sm font-medium text-slate-300">{{ t('miningOperation.create.start') }}</label>
        <input :id="`${fieldId}-start`" v-model="startInput" type="datetime-local" required class="rounded-md border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-slate-100" />
      </div>

      <fieldset>
        <legend class="mb-1 text-sm font-medium text-slate-300">{{ t('miningOperation.create.visibility') }}</legend>
        <div class="flex flex-wrap gap-4">
          <label class="flex items-center gap-2 text-sm text-slate-200">
            <input v-model="visibility" type="radio" :name="`${fieldId}-visibility`" value="private" class="accent-cyan-500" />
            {{ t('miningOperation.visibility.private') }}
          </label>
          <label class="flex items-center gap-2 text-sm" :class="hasKnownCorporation ? 'text-slate-200' : 'text-slate-500'">
            <input v-model="visibility" type="radio" :name="`${fieldId}-visibility`" value="corporation" :disabled="!hasKnownCorporation" class="accent-cyan-500" />
            {{ t('miningOperation.visibility.corporation') }}
          </label>
        </div>
        <p v-if="!hasKnownCorporation" class="mt-1 text-xs text-amber-300">{{ t('miningOperation.create.corporationUnavailable') }}</p>
      </fieldset>

      <fieldset>
        <legend class="mb-1 text-sm font-medium text-slate-300">{{ t('miningOperation.create.splitMode') }}</legend>
        <div class="flex flex-wrap gap-4">
          <label class="flex items-center gap-2 text-sm text-slate-200">
            <input v-model="splitMode" type="radio" :name="`${fieldId}-split`" value="value_prorata" class="accent-cyan-500" />
            {{ t('miningOperation.splitMode.value_prorata') }}
          </label>
          <label class="flex items-center gap-2 text-sm text-slate-500">
            <input type="radio" :name="`${fieldId}-split`" value="weighted_time" disabled class="accent-cyan-500" />
            {{ t('miningOperation.splitMode.weighted_time') }}
            <span class="rounded-sm border border-slate-700 px-1.5 py-0.5 font-mono text-xs">{{ t('miningOperation.splitMode.soon') }}</span>
          </label>
          <label class="flex items-center gap-2 text-sm text-slate-200">
            <input v-model="splitMode" type="radio" :name="`${fieldId}-split`" value="each_for_themselves" class="accent-cyan-500" />
            {{ t('miningOperation.splitMode.each_for_themselves') }}
          </label>
        </div>
        <p class="mt-1 text-xs text-slate-400">{{ t(`miningOperation.splitMode.hint.${splitMode}`) }}</p>
      </fieldset>

      <fieldset>
        <legend class="mb-2 text-sm font-medium text-slate-300">{{ t('miningOperation.create.characters') }}</legend>
        <MiningCharacterPicker v-model="tickedCharacterIds" :characters="pickableCharacters" />
      </fieldset>

      <p v-if="failure !== null" class="text-sm text-red-400" role="alert">{{ failure }}</p>

      <div class="flex justify-end gap-3">
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
