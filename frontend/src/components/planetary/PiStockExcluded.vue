<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import { usePlanetarySettingsStore } from '@/stores/planetarySettings'
import { usePlanetaryStockStore } from '@/stores/planetaryStock'

const { t } = useI18n()
const stockStore = usePlanetaryStockStore()
const settingsStore = usePlanetarySettingsStore()
const planner = usePlanetaryPlannerStore()

const excludedTypeIds = computed<number[]>(() => stockStore.stock?.excludedTypeIds ?? [])
const nameByTypeId = computed(() => new Map((planner.catalog?.types ?? []).map(type => [type.typeId, type.name])))

function nameOf(typeId: number): string {
  return nameByTypeId.value.get(typeId) ?? `#${typeId}`
}

function restoreType(typeId: number): void {
  void settingsStore.restoreType(typeId)
}
</script>

<template>
  <section
    v-if="excludedTypeIds.length > 0"
    class="rounded-xl border border-slate-800 bg-slate-900 px-4 py-3"
    data-testid="stock-excluded"
  >
    <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ t('pi.stock.excluded.title', { count: excludedTypeIds.length }, excludedTypeIds.length) }}</p>
    <ul class="flex flex-wrap gap-2">
      <li
        v-for="typeId in excludedTypeIds"
        :key="typeId"
        class="flex max-w-full items-center gap-2 rounded-md border border-slate-700 bg-slate-800/60 px-2.5 py-1 text-sm"
        :data-testid="`stock-excluded-${typeId}`"
      >
        <span class="min-w-0 break-words text-slate-200">{{ nameOf(typeId) }}</span>
        <button
          type="button"
          class="shrink-0 text-xs text-cyan-300 hover:text-cyan-200 hover:underline"
          :data-testid="`stock-restore-${typeId}`"
          @click="restoreType(typeId)"
        >{{ t('pi.stock.excluded.restore') }}</button>
      </li>
    </ul>
  </section>
</template>
