<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useBlueprintsStore } from '@/stores/industry/blueprints'
import type { BlueprintLibraryEntry } from '@/stores/industry/types'
import { normalizeSearchText } from '@/utils/commandPaletteSearch'

const NOT_APPLICABLE = '—'

const { t } = useI18n()
const store = useBlueprintsStore()
const search = ref('')

const entries = computed<BlueprintLibraryEntry[]>(() => {
  const blueprints = store.library?.blueprints ?? []
  const wanted = normalizeSearchText(search.value)
  return wanted === '' ? blueprints : blueprints.filter(entry => normalizeSearchText(entry.name).includes(wanted))
})

const corporationState = computed(() => store.library?.corporationState ?? 'known')

function whereOf(entry: BlueprintLibraryEntry): string {
  if (entry.divisionName !== null) return entry.divisionName
  if (entry.divisionNumber !== null) return t('industry.blueprints.division', { number: entry.divisionNumber })
  return entry.ownerName ?? NOT_APPLICABLE
}

onMounted(() => store.fetchLibrary())
</script>

<template>
  <section data-testid="factory-blueprints" class="mb-8">
    <div class="flex flex-wrap items-baseline gap-3 mb-3">
      <h2 class="text-lg font-semibold text-slate-100">{{ t('industryFactory.blueprints.title') }}</h2>
      <span class="text-sm text-slate-400">{{ t('industryFactory.blueprints.subtitle') }}</span>
      <input
        v-model="search"
        data-testid="factory-blueprint-search"
        type="search"
        :placeholder="t('industry.blueprints.searchPlaceholder')"
        :aria-label="t('industry.blueprints.searchLabel')"
        class="ml-auto min-w-40 max-w-xs px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-sm text-slate-200 placeholder-slate-500 focus:outline-hidden focus:border-cyan-500"
      />
    </div>

    <p
      v-if="corporationState !== 'known'"
      data-testid="factory-blueprint-corporation-state"
      :data-state="corporationState"
      role="status"
      class="mb-3 p-3 bg-amber-500/20 border border-amber-500/50 rounded-lg text-sm text-amber-200"
    >{{ corporationState === 'no_director' ? t('industry.blueprints.noDirector.title') : t('industry.blueprints.noVisibility.title') }}</p>

    <p v-if="store.library === null && store.error" class="text-sm text-red-400">{{ store.error }}</p>
    <p v-else-if="store.library === null" class="text-sm text-slate-400">{{ t('common.actions.loading') }}</p>
    <p v-else-if="entries.length === 0" data-testid="factory-blueprint-empty" class="text-sm text-slate-400">{{ t('industry.blueprints.empty') }}</p>

    <div v-else class="overflow-x-auto bg-slate-900 rounded-xl border border-slate-800">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
            <th class="px-3 py-2">{{ t('industry.blueprints.columns.blueprint') }}</th>
            <th class="px-3 py-2">{{ t('industry.blueprints.columns.kind') }}</th>
            <th class="px-3 py-2">{{ t('industry.blueprints.columns.efficiency') }}</th>
            <th class="px-3 py-2">{{ t('industry.blueprints.columns.where') }}</th>
            <th class="px-3 py-2">{{ t('industryFactory.blueprints.columns.usedBy') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="(entry, index) in entries"
            :key="`${entry.typeId}-${entry.locationId}-${entry.kind}-${index}`"
            data-testid="factory-blueprint-row"
            :data-in-job="entry.usedByRunningJob ? 'true' : 'false'"
            class="border-t border-slate-800"
          >
            <td class="px-3 py-2 text-slate-200">{{ entry.name }}<span v-if="entry.count > 1" class="ml-1 text-slate-500">× {{ entry.count }}</span></td>
            <td class="px-3 py-2 text-slate-300">{{ t(`industry.blueprints.kind.${entry.kind}`) }}</td>
            <td class="px-3 py-2 text-slate-300">{{ t('industry.blueprints.efficiency', { me: entry.me, te: entry.te }) }}</td>
            <td class="px-3 py-2 text-slate-400">{{ whereOf(entry) }}</td>
            <td class="px-3 py-2 text-slate-400">{{ entry.usedByRunningJob ? t('industry.blueprints.inUse') : NOT_APPLICABLE }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
