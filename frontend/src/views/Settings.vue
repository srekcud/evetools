<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/layouts/MainLayout.vue'
import AccountModuleTabs from '@/components/account/AccountModuleTabs.vue'
import AccountSettings from '@/components/settings/AccountSettings.vue'
import DisplaySettings from '@/components/settings/DisplaySettings.vue'
import MarketStructuresSettings from '@/components/settings/MarketStructuresSettings.vue'
import MiningSettings from '@/components/settings/MiningSettings.vue'
import PveSettings from '@/components/settings/PveSettings.vue'
import IndustrySettings from '@/components/industry/IndustrySettings.vue'
import NotificationSettings from '@/components/notifications/NotificationSettings.vue'
import { DEFAULT_SETTINGS_SECTION, SETTINGS_SECTIONS, isSettingsSection, settingsSectionLabelKey } from '@/constants/settingsSections'

const SECTION_LINK_BASE_CLASS = 'flex w-full items-center rounded-md border-l-2 px-3 py-2 font-display text-sm font-semibold uppercase tracking-wider transition-colors'
const SECTION_LINK_ACTIVE_CLASS = 'border-cyan-400 bg-cyan-400/10 text-slate-50'
const SECTION_LINK_INACTIVE_CLASS = 'border-transparent text-slate-300 hover:bg-slate-800/60 hover:text-slate-50'

const { t } = useI18n()
const route = useRoute()

const currentSection = computed(() => isSettingsSection(route.params.section) ? route.params.section : DEFAULT_SETTINGS_SECTION)
</script>

<template>
  <MainLayout>
    <AccountModuleTabs active="settings" />
    <h1 class="mb-6 font-display text-2xl font-bold uppercase tracking-[0.08em] text-slate-100">{{ t('settings.title') }}</h1>
    <div class="flex flex-col gap-6 md:flex-row">
      <nav :aria-label="t('settings.title')" class="flex shrink-0 flex-col gap-0.5 md:w-56">
        <RouterLink
          v-for="section in SETTINGS_SECTIONS"
          :key="section"
          :to="{ name: 'settings', params: { section } }"
          :aria-current="section === currentSection ? 'page' : undefined"
          :class="[SECTION_LINK_BASE_CLASS, section === currentSection ? SECTION_LINK_ACTIVE_CLASS : SECTION_LINK_INACTIVE_CLASS]"
        >{{ t(settingsSectionLabelKey(section)) }}</RouterLink>
      </nav>
      <section :data-section="currentSection" class="min-w-0 flex-1 space-y-4">
        <h2 class="font-display text-lg font-semibold uppercase tracking-wider text-slate-200">{{ t(settingsSectionLabelKey(currentSection)) }}</h2>
        <div :class="['bg-slate-900 rounded-2xl border border-slate-800', currentSection === 'industry' || currentSection === 'notifications' ? 'overflow-hidden' : 'p-6']">
          <AccountSettings v-if="currentSection === 'account'" />
          <DisplaySettings v-else-if="currentSection === 'display'" />
          <MarketStructuresSettings v-else-if="currentSection === 'market'" />
          <IndustrySettings v-else-if="currentSection === 'industry'" />
          <MiningSettings v-else-if="currentSection === 'mining'" />
          <PveSettings v-else-if="currentSection === 'pve'" />
          <NotificationSettings v-else inline />
        </div>
      </section>
    </div>
  </MainLayout>
</template>
