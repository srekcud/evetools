<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'

defineProps<{
  visible: boolean
  isSubmitting: boolean
}>()

const emit = defineEmits<{
  close: []
  submit: [payload: { type: string; description: string; amount: number; date: string }]
}>()

const { t } = useI18n()

const newExpense = ref({
  type: 'fuel',
  description: '',
  amount: '',
  date: new Date().toISOString().split('T')[0],
})
const formErrors = ref<{ description?: string; amount?: string }>({})

const expenseTypes = computed(() => [
  { value: 'fuel', label: 'Fuel' },
  { value: 'ammo', label: t('pve.expenseTypes.ammo') },
  { value: 'crab_beacon', label: 'Crab Beacon' },
  { value: 'other', label: t('pve.expenseTypes.other') },
])

function handleSubmit() {
  formErrors.value = {}

  if (!newExpense.value.description.trim()) {
    formErrors.value.description = t('pve.validation.descriptionRequired')
  }
  if (!newExpense.value.amount || parseFloat(newExpense.value.amount) <= 0) {
    formErrors.value.amount = t('pve.validation.amountRequired')
  }

  if (Object.keys(formErrors.value).length > 0) return

  emit('submit', {
    type: newExpense.value.type,
    description: newExpense.value.description,
    amount: parseFloat(newExpense.value.amount),
    date: newExpense.value.date,
  })
}

function resetForm() {
  newExpense.value = {
    type: 'fuel',
    description: '',
    amount: '',
    date: new Date().toISOString().split('T')[0],
  }
  formErrors.value = {}
}

defineExpose({ resetForm })
</script>

<template>
  <Teleport to="body">
    <div v-if="visible" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" @click.self="emit('close')">
      <div class="bg-slate-900 rounded-xl border border-slate-700 max-w-md w-full p-6">
        <h3 class="text-lg font-semibold mb-4">{{ t('pve.addExpenseTitle') }}</h3>

        <div class="space-y-4">
          <!-- Type -->
          <div>
            <label class="block text-sm text-slate-400 mb-1">{{ t('pve.type') }}</label>
            <select
              v-model="newExpense.type"
              class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2 text-sm focus:outline-hidden focus:border-cyan-500"
            >
              <option v-for="et in expenseTypes" :key="et.value" :value="et.value">{{ et.label }}</option>
            </select>
          </div>

          <!-- Description -->
          <div>
            <label class="block text-sm text-slate-400 mb-1">{{ t('pve.description') }} <span class="text-red-400">*</span></label>
            <input
              v-model="newExpense.description"
              type="text"
              :placeholder="t('pve.descriptionPlaceholder')"
              :class="[
                'w-full bg-slate-800 border rounded-lg px-4 py-2 text-sm focus:outline-hidden',
                formErrors.description ? 'border-red-500 focus:border-red-500' : 'border-slate-700 focus:border-cyan-500'
              ]"
            />
            <p v-if="formErrors.description" class="text-red-400 text-xs mt-1">{{ formErrors.description }}</p>
          </div>

          <!-- Amount -->
          <div>
            <label class="block text-sm text-slate-400 mb-1">{{ t('pve.amount') }} <span class="text-red-400">*</span></label>
            <input
              v-model="newExpense.amount"
              type="number"
              placeholder="150000000"
              :class="[
                'w-full bg-slate-800 border rounded-lg px-4 py-2 text-sm focus:outline-hidden',
                formErrors.amount ? 'border-red-500 focus:border-red-500' : 'border-slate-700 focus:border-cyan-500'
              ]"
            />
            <p v-if="formErrors.amount" class="text-red-400 text-xs mt-1">{{ formErrors.amount }}</p>
          </div>

          <!-- Date -->
          <div>
            <label class="block text-sm text-slate-400 mb-1">{{ t('pve.date') }}</label>
            <input
              v-model="newExpense.date"
              type="date"
              class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2 text-sm focus:outline-hidden focus:border-cyan-500"
            />
          </div>
        </div>

        <div class="flex gap-3 mt-6">
          <button
            @click="emit('close')"
            :disabled="isSubmitting"
            class="flex-1 py-2 bg-slate-800 hover:bg-slate-700 rounded-lg disabled:opacity-50"
          >
            {{ t('common.actions.cancel') }}
          </button>
          <button
            type="button"
            @click="handleSubmit"
            :disabled="isSubmitting"
            class="flex-1 py-2 bg-cyan-600 hover:bg-cyan-500 text-white font-medium rounded-lg disabled:opacity-50 flex items-center justify-center gap-2"
          >
            <svg v-if="isSubmitting" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            {{ isSubmitting ? t('pve.adding') : t('common.actions.add') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
