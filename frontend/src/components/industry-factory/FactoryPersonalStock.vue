<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFactoryStore } from '@/stores/industry/factory'
import type { PersonalStockContainer } from '@/stores/industry/types'

const { t, te } = useI18n()
const store = useFactoryStore()
const typedItemId = ref('')

const containers = computed(() => store.personalContainers ?? [])
const itemId = computed(() => (/^\d+$/.test(typedItemId.value.trim()) ? Number(typedItemId.value.trim()) : null))

function reasonOf(container: PersonalStockContainer): string {
  const key = `industryFactory.personalStock.unknownReason.${container.unknownReason}`
  return container.unknownReason !== null && te(key) ? t(key) : t('industryFactory.personalStock.unknownReason.other')
}

function placeOf(container: PersonalStockContainer): string {
  return [container.divisionName, container.stationName].filter((part): part is string => part !== null).join(' · ')
}

async function declare(): Promise<void> {
  if (itemId.value === null) return
  if (await store.declareContainer(itemId.value)) typedItemId.value = ''
}

onMounted(() => store.fetchPersonalContainers())
</script>

<template>
  <section data-testid="factory-personal-stock" class="mb-8">
    <div class="flex items-baseline gap-3 mb-3">
      <h2 class="text-lg font-semibold text-slate-100">{{ t('industryFactory.personalStock.title') }}</h2>
      <span class="text-sm text-slate-400">{{ t('industryFactory.personalStock.subtitle') }}</span>
    </div>

    <p v-if="store.personalContainers === null && store.error" class="text-sm text-red-400">{{ store.error }}</p>
    <p v-else-if="store.personalContainers === null" class="text-sm text-slate-400">{{ t('common.actions.loading') }}</p>
    <p v-else-if="containers.length === 0" data-testid="factory-container-empty" class="mb-3 text-sm text-slate-400">{{ t('industryFactory.personalStock.empty') }}</p>

    <div v-if="containers.length > 0" class="overflow-x-auto bg-slate-900 rounded-xl border border-slate-800 mb-3">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
            <th class="px-3 py-2">{{ t('industryFactory.personalStock.columns.container') }}</th>
            <th class="px-3 py-2">{{ t('industryFactory.personalStock.columns.place') }}</th>
            <th class="px-3 py-2">{{ t('industryFactory.personalStock.columns.reading') }}</th>
            <th class="px-3 py-2"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="container in containers" :key="container.id" data-testid="factory-container-row" :data-status="container.status" class="border-t border-slate-800">
            <td class="px-3 py-2 text-slate-200">{{ container.name }}</td>
            <td class="px-3 py-2 text-slate-400">{{ placeOf(container) }}</td>
            <td class="px-3 py-2">
              <span v-if="container.status === 'known' && container.itemCount !== null" data-testid="factory-container-items" class="text-slate-200">{{ t('industryFactory.personalStock.items', container.itemCount) }}</span>
              <span v-else class="text-amber-400" data-unknown="true">{{ reasonOf(container) }}</span>
            </td>
            <td class="px-3 py-2 text-right">
              <button
                type="button"
                data-testid="factory-container-remove"
                class="px-3 py-1 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-200 text-xs transition-colors"
                @click="store.removeContainer(container.id)"
              >{{ t('industryFactory.personalStock.remove') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <form class="flex flex-wrap items-center gap-3" @submit.prevent="declare">
      <label class="text-sm text-slate-400" for="factory-container-item-id">{{ t('industryFactory.personalStock.itemIdLabel') }}</label>
      <input
        id="factory-container-item-id"
        v-model="typedItemId"
        data-testid="factory-container-declare-input"
        type="text"
        inputmode="numeric"
        class="px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-sm text-slate-200 focus:outline-hidden focus:border-cyan-500"
      />
      <button
        type="button"
        data-testid="factory-container-declare"
        class="px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 rounded-lg text-white text-sm font-medium transition-colors"
        :disabled="itemId === null"
        @click="declare"
      >{{ t('industryFactory.personalStock.declare') }}</button>
    </form>
    <p v-if="store.declareError" data-testid="factory-container-error" role="alert" class="mt-2 text-sm text-red-400">{{ store.declareError }}</p>
  </section>
</template>
