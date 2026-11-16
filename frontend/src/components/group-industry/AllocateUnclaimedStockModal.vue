<script setup lang="ts">
import { computed, onMounted, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseModal from '@/components/common/BaseModal.vue'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { AllocateUnclaimedStockInput, BomItem, GroupMember, UnclaimedStockAllocationMode } from '@/stores/group-industry/types'

const props = defineProps<{
  projectId: string
  item: BomItem
  mode: UnclaimedStockAllocationMode
}>()

const emit = defineEmits<{
  close: []
  done: []
}>()

const { t } = useI18n()
const projectStore = useGroupProjectStore()
const quantityFieldId = useId()
const participantFieldId = useId()

const maximumQuantity = computed(() => props.item.unclaimedQuantity ?? 0)
const quantity = ref<number>(maximumQuantity.value)
const participantId = ref('')
const participants = ref<GroupMember[]>([])
const isSubmitting = ref(false)
const isFailed = ref(false)

const isAssignment = computed(() => props.mode === 'assign')

const isFormValid = computed(() =>
  Number.isInteger(quantity.value)
  && quantity.value >= 1
  && quantity.value <= maximumQuantity.value
  && (!isAssignment.value || participantId.value !== ''),
)

onMounted(async () => {
  if (!isAssignment.value) return
  try {
    const members = await projectStore.fetchMembers(props.projectId)
    participants.value = members.filter(member => member.status === 'accepted')
  } catch {
    isFailed.value = true
  }
})

async function submit(): Promise<void> {
  if (!isFormValid.value || isSubmitting.value) return
  isSubmitting.value = true
  isFailed.value = false

  const input: AllocateUnclaimedStockInput = {
    bomItemId: props.item.id,
    quantity: quantity.value,
    mode: props.mode,
  }
  if (isAssignment.value) {
    input.participantId = participantId.value
  }

  try {
    await projectStore.allocateUnclaimedStock(props.projectId, input)
    emit('done')
  } catch {
    isFailed.value = true
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :show="true"
    :title="t(isAssignment ? 'groupIndustry.bom.allocation.assignTitle' : 'groupIndustry.bom.allocation.donateTitle')"
    panel-class="border-slate-700 max-w-md"
    @close="emit('close')"
  >
    <div class="space-y-4 px-6 pb-6">
      <p class="text-xs text-slate-400">
        {{ item.typeName }} — {{ t(isAssignment ? 'groupIndustry.bom.allocation.assignHint' : 'groupIndustry.bom.allocation.donateHint') }}
      </p>

      <div v-if="isAssignment">
        <label :for="participantFieldId" class="mb-1.5 block text-sm font-medium text-slate-300">{{ t('groupIndustry.bom.allocation.participant') }}</label>
        <select
          :id="participantFieldId"
          v-model="participantId"
          data-testid="allocation-participant"
          class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-200 focus:border-cyan-500 focus:outline-none"
        >
          <option value="" disabled>{{ t('groupIndustry.bom.allocation.participantPlaceholder') }}</option>
          <option v-for="participant in participants" :key="participant.id" :value="participant.id">{{ participant.characterName }}</option>
        </select>
      </div>

      <div>
        <label :for="quantityFieldId" class="mb-1.5 block text-sm font-medium text-slate-300">{{ t('groupIndustry.bom.allocation.quantity', { max: maximumQuantity }) }}</label>
        <input
          :id="quantityFieldId"
          v-model.number="quantity"
          type="number"
          min="1"
          :max="maximumQuantity"
          data-testid="allocation-quantity"
          class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 font-mono text-sm text-slate-200 focus:border-cyan-500 focus:outline-none"
          style="font-variant-numeric: tabular-nums;"
        />
      </div>

      <p v-if="isFailed" data-testid="allocation-error" class="text-xs text-amber-400">{{ t('groupIndustry.bom.allocation.failed') }}</p>

      <div class="flex justify-end gap-2">
        <button
          type="button"
          class="rounded-lg bg-slate-800 px-4 py-2 text-sm text-slate-300 transition-colors hover:bg-slate-700"
          @click="emit('close')"
        >{{ t('common.actions.cancel') }}</button>
        <button
          type="button"
          data-testid="allocation-submit"
          :disabled="!isFormValid || isSubmitting"
          class="rounded-lg bg-cyan-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-50"
          @click="submit"
        >{{ t('groupIndustry.bom.allocation.submit') }}</button>
      </div>
    </div>
  </BaseModal>
</template>
