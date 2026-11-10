<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import MiningSolarSystemSearch, { type SolarSystemResult } from '@/components/mining-operation/MiningSolarSystemSearch.vue'

const LABEL_MAX_LENGTH = 100

const props = withDefaults(defineProps<{ failure: string | null; submitting: boolean; closesActive?: boolean }>(), { closesActive: true })
const emit = defineEmits<{ submit: [input: { label: string; solarSystemId: number | null }]; cancel: [] }>()

const { t } = useI18n()

const label = ref('')
const system = ref<SolarSystemResult | null>(null)
const labelRequired = ref(false)

const errorText = computed(() => (labelRequired.value ? t('miningSite.create.labelRequired') : props.failure))

function submit(): void {
  const trimmedLabel = label.value.trim()
  labelRequired.value = trimmedLabel === ''
  if (labelRequired.value) return
  emit('submit', { label: trimmedLabel, solarSystemId: system.value === null ? null : system.value.solarSystemId })
}
</script>

<template>
  <form class="space-y-2 rounded-sm border border-slate-700 bg-slate-900/80 p-2 text-xs text-slate-300" data-testid="companion-next-site-form" @submit.prevent="submit">
    <label class="block">
      <span class="mb-1 block">{{ t('miningSite.create.label') }}</span>
      <input
        v-model="label"
        name="label"
        type="text"
        :maxlength="LABEL_MAX_LENGTH"
        autocomplete="off"
        class="w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-1 text-slate-100"
      />
    </label>

    <p v-if="closesActive" class="rounded-sm border border-amber-500/40 bg-amber-500/10 px-2 py-1 text-amber-200">{{ t('miningSite.create.closesActive') }}</p>

    <MiningSolarSystemSearch v-model="system" :label="t('miningSite.create.system')" :none-label="t('miningSite.create.systemNone')" />

    <p v-if="errorText !== null && !submitting" class="text-red-400" role="alert" data-testid="companion-next-site-error">{{ errorText }}</p>

    <div class="flex justify-end gap-2">
      <button type="button" data-testid="companion-next-site-cancel" class="rounded-sm border border-slate-600 px-2 py-1 text-slate-200 hover:bg-slate-800" @click="emit('cancel')">{{ t('common.actions.cancel') }}</button>
      <button
        type="submit"
        data-testid="companion-next-site-submit"
        :disabled="submitting"
        class="flex items-center gap-1 rounded-sm bg-cyan-600 px-2 py-1 font-medium text-white hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-50"
      >
        <LoadingSpinner v-if="submitting" size="sm" />
        {{ t('miningSite.create.submit') }}
      </button>
    </div>
  </form>
</template>
