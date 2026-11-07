<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { ProjectDetailTab } from '@/views/GroupIndustry/projectDetailTabs'
import { COMING_SOON_TABS, DETAIL_TABS } from '@/views/GroupIndustry/projectDetailTabs'

const activeTab = defineModel<ProjectDetailTab>({ required: true })

defineProps<{
  materialsCount: string
  contributionCount: number
  memberCount: number
}>()

const { t } = useI18n()

const TAB_LABEL_KEYS: Record<ProjectDetailTab, string> = {
  bom: 'groupIndustry.tabs.materialsJobs',
  contributions: 'groupIndustry.tabs.contributions',
  distribution: 'groupIndustry.tabs.finances',
  sales: 'groupIndustry.tabs.sales',
  members: 'groupIndustry.tabs.team',
  chain: 'groupIndustry.tabs.chain',
  planning: 'groupIndustry.tabs.planning',
}

function tabClasses(tab: ProjectDetailTab): string {
  return activeTab.value === tab ? 'text-sky-400 border-sky-400' : 'text-slate-500 border-transparent hover:text-slate-400'
}

function badgeClasses(tab: ProjectDetailTab): string {
  return activeTab.value === tab ? 'bg-cyan-500/15 text-cyan-400' : 'bg-slate-700 text-slate-400'
}
</script>

<template>
  <div class="border-b border-slate-800 mb-6 overflow-x-auto">
    <div class="flex gap-6 min-w-max" role="tablist">
      <button
        v-for="tab in DETAIL_TABS"
        :key="tab"
        :data-testid="`project-tab-${tab}`"
        type="button"
        role="tab"
        :aria-selected="activeTab === tab"
        class="px-1 pb-3 text-sm font-medium transition-colors border-b-2 whitespace-nowrap"
        :class="tabClasses(tab)"
        @click="activeTab = tab"
      >
        {{ t(TAB_LABEL_KEYS[tab]) }}
        <span v-if="tab === 'bom'" class="ml-1.5 text-xs px-1.5 py-0.5 rounded" :class="badgeClasses(tab)">{{ materialsCount }}</span>
        <span v-else-if="tab === 'contributions'" class="ml-1.5 text-xs px-1.5 py-0.5 rounded" :class="badgeClasses(tab)">{{ contributionCount }}</span>
        <span v-else-if="tab === 'members'" class="ml-1.5 text-xs px-1.5 py-0.5 rounded" :class="badgeClasses(tab)">{{ memberCount }}</span>
      </button>
      <button
        v-for="tab in COMING_SOON_TABS"
        :key="tab"
        :data-testid="`project-tab-${tab}`"
        type="button"
        role="tab"
        aria-selected="false"
        aria-disabled="true"
        disabled
        class="px-1 pb-3 text-sm font-medium border-b-2 border-transparent text-slate-600 cursor-not-allowed whitespace-nowrap"
      >
        {{ t(`groupIndustry.tabs.${tab}`) }}
        <span class="ml-1.5 text-xs px-1.5 py-0.5 rounded bg-slate-800 text-slate-500">{{ t('groupIndustry.tabs.comingSoon') }}</span>
      </button>
    </div>
  </div>
</template>
