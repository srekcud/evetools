<script setup lang="ts">
import { useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { useProjectsStore } from '@/stores/industry/projects'
import BlacklistConfig from '@/components/industry/BlacklistConfig.vue'
import StructureConfig from '@/components/industry/StructureConfig.vue'
import SkillsConfig from '@/components/industry/SkillsConfig.vue'
import FeesConfig from '@/components/industry/FeesConfig.vue'

export type IndustryConfigSection = 'fees' | 'skills' | 'structures' | 'blacklist'

const DEFAULT_EXPANDED_SECTION: IndustryConfigSection = 'fees'
const FALLBACK_MAX_JOB_DURATION_DAYS = 2.0

const CONFIG_SECTIONS: { id: IndustryConfigSection, labelKey: string }[] = [
  { id: 'fees', labelKey: 'industry.configTabs.fees' },
  { id: 'skills', labelKey: 'industry.configTabs.skills' },
  { id: 'structures', labelKey: 'industry.configTabs.structures' },
  { id: 'blacklist', labelKey: 'industry.configTabs.blacklist' },
]

const { t } = useI18n()
const store = useProjectsStore()
const maxJobDurationFieldId = useId()
const expandedSection = defineModel<IndustryConfigSection | null>('expandedSection', { default: DEFAULT_EXPANDED_SECTION })

function toggleSection(section: IndustryConfigSection): void {
  expandedSection.value = expandedSection.value === section ? null : section
}

function onMaxJobDurationChange(event: Event): void {
  store.setDefaultMaxJobDurationDays(parseFloat((event.target as HTMLInputElement).value) || FALLBACK_MAX_JOB_DURATION_DAYS)
}
</script>

<template>
  <div>
    <div class="px-6 py-4 border-b border-slate-800">
      <label :for="maxJobDurationFieldId" class="block text-xs text-slate-500 mb-1.5">{{ t('industry.config.maxJobDuration') }}</label>
      <div class="flex items-center gap-3">
        <input
          :id="maxJobDurationFieldId"
          :value="store.defaultMaxJobDurationDays.toFixed(1)"
          @change="onMaxJobDurationChange"
          type="number"
          min="0.5"
          step="0.1"
          class="w-32 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm focus:outline-hidden focus:border-cyan-500"
        />
        <p class="text-xs text-slate-500">{{ t('industry.config.maxJobDurationHint') }}</p>
      </div>
    </div>

    <div class="divide-y divide-slate-800">
      <div v-for="section in CONFIG_SECTIONS" :key="section.id">
        <button
          @click="toggleSection(section.id)"
          class="w-full px-6 py-3.5 flex items-center justify-between hover:bg-slate-800/50 transition-colors"
        >
          <span class="text-sm font-medium text-slate-200">{{ t(section.labelKey) }}</span>
          <svg
            class="w-4 h-4 text-slate-400 transition-transform duration-200"
            :class="{ 'rotate-180': expandedSection === section.id }"
            fill="none" stroke="currentColor" viewBox="0 0 24 24"
            aria-hidden="true"
          >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
          </svg>
        </button>
        <div v-if="expandedSection === section.id" class="px-6 pb-4">
          <FeesConfig v-if="section.id === 'fees'" />
          <SkillsConfig v-else-if="section.id === 'skills'" />
          <StructureConfig v-else-if="section.id === 'structures'" />
          <BlacklistConfig v-else />
        </div>
      </div>
    </div>
  </div>
</template>
