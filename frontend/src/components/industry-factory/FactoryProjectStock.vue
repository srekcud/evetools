<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useFactoryStore } from '@/stores/industry/factory'
import type { FactoryProjectStockState } from '@/stores/industry/types'

const STATE_CLASSES: Record<FactoryProjectStockState, string> = {
  reserved: 'text-cyan-400',
  consumed: 'text-slate-400',
  returned: 'text-emerald-400',
}

const { t } = useI18n()
const { formatNumber } = useFormatters()
const store = useFactoryStore()

const lines = computed(() => store.projectStock ?? [])

onMounted(() => store.fetchProjectStock())
</script>

<template>
  <section data-testid="factory-project-stock" class="mb-8">
    <div class="flex items-baseline gap-3 mb-3">
      <h2 class="text-lg font-semibold text-slate-100">{{ t('industryFactory.projectStock.title') }}</h2>
      <span class="text-sm text-slate-400">{{ t('industryFactory.projectStock.subtitle') }}</span>
    </div>

    <p v-if="store.projectStock === null && store.error" class="text-sm text-red-400">{{ store.error }}</p>
    <p v-else-if="store.projectStock === null" class="text-sm text-slate-400">{{ t('common.actions.loading') }}</p>
    <p v-else-if="lines.length === 0" data-testid="factory-project-stock-empty" class="text-sm text-slate-400">{{ t('industryFactory.projectStock.empty') }}</p>

    <div v-else class="overflow-x-auto bg-slate-900 rounded-xl border border-slate-800">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
            <th class="px-3 py-2">{{ t('industryFactory.projectStock.columns.item') }}</th>
            <th class="px-3 py-2 text-right">{{ t('industryFactory.projectStock.columns.quantity') }}</th>
            <th class="px-3 py-2">{{ t('industryFactory.projectStock.columns.project') }}</th>
            <th class="px-3 py-2">{{ t('industryFactory.projectStock.columns.state') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="line in lines" :key="line.id" data-testid="factory-project-stock-row" :data-state="line.state" class="border-t border-slate-800">
            <td class="px-3 py-2 text-slate-200">{{ line.typeName }}</td>
            <td class="px-3 py-2 text-right text-slate-200">{{ formatNumber(line.quantity, 0) }}</td>
            <td class="px-3 py-2">
              <RouterLink :to="{ name: 'industry-project', params: { id: line.projectId } }" class="text-cyan-400 hover:text-cyan-300">{{ line.projectName ?? t('industryFactory.unnamedProject') }}</RouterLink>
            </td>
            <td class="px-3 py-2" :class="STATE_CLASSES[line.state]">{{ t(`industryFactory.projectStock.state.${line.state}`) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
