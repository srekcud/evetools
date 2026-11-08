<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  status: string
}>()

const STATUS_CLASSES: Record<string, string> = {
  provisional: 'border-slate-600 text-slate-400',
  unknown: 'border-amber-500/40 text-amber-300',
  cancelled: 'border-slate-600 text-slate-500',
  paid: 'border-emerald-500/40 text-emerald-300',
  late: 'border-red-500/40 text-red-300',
  partial: 'border-cyan-500/40 text-cyan-300',
  due: 'border-amber-500/40 text-amber-300',
}
const UNRECOGNISED_STATUS_CLASSES = 'border-amber-500/40 text-amber-300'

const { t, te } = useI18n()

const label = computed(() => {
  const key = `ledger.mining.moonTax.status.${props.status}`
  return te(key) ? t(key) : props.status
})
</script>

<template>
  <td :data-status="status" class="px-3 py-2 whitespace-nowrap">
    <span :class="['rounded-sm border px-2 py-0.5 text-xs font-medium uppercase tracking-wider', STATUS_CLASSES[status] ?? UNRECOGNISED_STATUS_CLASSES]">
      {{ label }}
    </span>
  </td>
</template>
