<script setup lang="ts" generic="Tab extends string">
export interface MiningSubTab<Id extends string> {
  id: Id
  label: string
  count?: number
}

defineProps<{
  tabs: MiningSubTab<Tab>[]
  modelValue: Tab
}>()

defineEmits<{ 'update:modelValue': [tab: Tab] }>()
</script>

<template>
  <div class="mb-6 flex flex-wrap gap-x-6 border-b border-slate-800" role="tablist">
    <button
      v-for="tab in tabs"
      :key="tab.id"
      type="button"
      role="tab"
      :aria-selected="modelValue === tab.id"
      class="border-b-2 px-1 pb-3 font-display text-base font-semibold tracking-wide transition-colors"
      :class="modelValue === tab.id ? 'border-cyan-400 text-slate-50' : 'border-transparent text-slate-400 hover:text-slate-200'"
      @click="$emit('update:modelValue', tab.id)"
    >
      {{ tab.label }}
      <span v-if="tab.count !== undefined" class="ml-1.5 rounded-sm bg-slate-800 px-1.5 py-0.5 font-mono text-xs text-slate-300">{{ tab.count }}</span>
    </button>
  </div>
</template>
