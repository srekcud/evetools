<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { settableModules } from '@/constants/sidebarNavigation'
import { useFormatters } from '@/composables/useFormatters'
import { useHiddenModules } from '@/composables/useHiddenModules'
import { useInterfaceLocale } from '@/composables/useInterfaceLocale'

const CHOICE_BASE_CLASS = 'flex-1 px-4 py-2.5 rounded-lg text-sm font-medium transition-colors border'
const CHOICE_ACTIVE_CLASS = 'bg-cyan-500/20 text-cyan-400 border-cyan-500/30'
const CHOICE_INACTIVE_CLASS = 'bg-slate-800 text-slate-400 border-slate-700 hover:border-cyan-500/30 hover:text-slate-200'

const { t } = useI18n()
const { dateFormat, setDateFormat } = useFormatters()
const { reloadHiddenModules, isModuleVisible, toggleModule } = useHiddenModules()
const { locale, setLocale } = useInterfaceLocale()

onMounted(reloadHiddenModules)
</script>

<template>
  <div>
    <h4 class="text-sm font-medium text-slate-400 uppercase tracking-wider mb-4">{{ t('settings.visibleModules') }}</h4>
    <div class="space-y-2">
      <label
        v-for="item in settableModules"
        :key="item.id"
        class="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-800/50 cursor-pointer transition-colors"
      >
        <input
          type="checkbox"
          :checked="isModuleVisible(item.id)"
          @change="toggleModule(item.id)"
          class="w-4 h-4 rounded-sm border-slate-600 bg-slate-800 text-cyan-500 focus:ring-cyan-500 focus:ring-offset-slate-900"
        />
        <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="item.icon"/>
        </svg>
        <span class="min-w-0 truncate text-slate-200" :title="t(item.labelKey)">{{ t(item.labelKey) }}</span>
      </label>
    </div>
    <p class="mt-4 text-xs text-slate-500">{{ t('settings.hiddenModulesNote') }}</p>

    <h4 class="text-sm font-medium text-slate-400 uppercase tracking-wider mb-4 mt-6">{{ t('settings.language') }}</h4>
    <div class="flex gap-2">
      <button
        type="button"
        @click="setLocale('fr')"
        :class="[CHOICE_BASE_CLASS, locale === 'fr' ? CHOICE_ACTIVE_CLASS : CHOICE_INACTIVE_CLASS]"
      >{{ t('settings.french') }}</button>
      <button
        type="button"
        @click="setLocale('en')"
        :class="[CHOICE_BASE_CLASS, locale === 'en' ? CHOICE_ACTIVE_CLASS : CHOICE_INACTIVE_CLASS]"
      >{{ t('settings.english') }}</button>
    </div>
    <p class="mt-2 text-xs text-slate-500">{{ t('settings.languageNote') }}</p>

    <h4 class="text-sm font-medium text-slate-400 uppercase tracking-wider mb-4 mt-6">{{ t('settings.dateFormat') }}</h4>
    <div class="flex gap-2">
      <button
        type="button"
        @click="setDateFormat('eu')"
        :class="[CHOICE_BASE_CLASS, dateFormat === 'eu' ? CHOICE_ACTIVE_CLASS : CHOICE_INACTIVE_CLASS]"
      >JJ/MM/AA</button>
      <button
        type="button"
        @click="setDateFormat('us')"
        :class="[CHOICE_BASE_CLASS, dateFormat === 'us' ? CHOICE_ACTIVE_CLASS : CHOICE_INACTIVE_CLASS]"
      >MM/DD/YY</button>
    </div>
    <p class="mt-2 text-xs text-slate-500">{{ t('settings.dateFormatNote') }}</p>
  </div>
</template>
