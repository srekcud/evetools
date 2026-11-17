<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { RevenueTodoItem } from '@/types/revenueGrid'

defineProps<{
  items: RevenueTodoItem[]
}>()

const emit = defineEmits<{
  action: [index: number]
}>()

const { t } = useI18n()

const ACTION_CLASSES =
  'shrink-0 rounded border border-cyan-500/40 px-2.5 py-1 text-xs font-semibold uppercase tracking-wider text-cyan-300 hover:bg-cyan-500/10'
</script>

<template>
  <section v-if="items.length > 0" class="rounded-xl border border-slate-800 bg-slate-900 p-4 md:p-5" data-testid="revenue-todo-panel">
    <h3 class="mb-3 font-mono text-xs uppercase tracking-[0.14em] text-slate-500">{{ t('revenue.todo.title') }}</h3>
    <ul class="grid gap-x-6 gap-y-2 lg:grid-cols-2">
      <li v-for="(item, index) in items" :key="index" class="flex items-start gap-3 border-l-2 border-amber-500/60 py-1 pl-3" :data-todo="index + 1">
        <span class="mt-0.5 inline-grid h-5 w-5 shrink-0 place-items-center rounded-full bg-amber-500 font-mono text-xs text-slate-900">{{ index + 1 }}</span>
        <div class="min-w-0 flex-1">
          <p class="text-sm text-slate-100">{{ item.text }}</p>
          <p class="text-xs text-slate-500">{{ item.effect }}</p>
        </div>
        <RouterLink v-if="item.to" :to="item.to" :class="ACTION_CLASSES">{{ item.actionLabel }}</RouterLink>
        <button v-else type="button" :class="ACTION_CLASSES" @click="emit('action', index)">{{ item.actionLabel }}</button>
      </li>
    </ul>
  </section>
</template>
