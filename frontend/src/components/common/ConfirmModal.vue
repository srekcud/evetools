<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Props {
  show: boolean
  title: string
  subtitle?: string
  message?: string
  confirmLabel?: string
  confirmColor?: 'red' | 'emerald' | 'cyan'
  isLoading?: boolean
  icon?: 'delete' | 'check'
}

const props = withDefaults(defineProps<Props>(), {
  subtitle: undefined,
  message: undefined,
  confirmLabel: undefined,
  confirmColor: 'red',
  isLoading: false,
  icon: 'delete',
})

const emit = defineEmits<{
  confirm: []
  cancel: []
}>()

const colorMap: Record<string, { border: string; shadow: string; iconBg: string; iconText: string; btn: string }> = {
  red:     { border: 'border-red-500/30',     shadow: 'shadow-red-500/10',     iconBg: 'bg-red-500/20 border-red-500/30',     iconText: 'text-red-400',     btn: 'bg-red-600 hover:bg-red-500' },
  emerald: { border: 'border-emerald-500/30', shadow: 'shadow-emerald-500/10', iconBg: 'bg-emerald-500/20 border-emerald-500/30', iconText: 'text-emerald-400', btn: 'bg-emerald-600 hover:bg-emerald-500' },
  cyan:    { border: 'border-cyan-500/30',    shadow: 'shadow-cyan-500/10',    iconBg: 'bg-cyan-500/20 border-cyan-500/30',    iconText: 'text-cyan-400',    btn: 'bg-cyan-600 hover:bg-cyan-500' },
}

function colors() {
  return colorMap[props.confirmColor] ?? colorMap.red
}
</script>

<template>
  <Teleport to="body">
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center">
      <div class="absolute inset-0 bg-slate-950/80" @click="emit('cancel')"></div>
      <div
        class="relative bg-slate-900 rounded-2xl shadow-2xl w-full max-w-sm mx-4"
        :class="[colors().border, colors().shadow, 'border']"
      >
        <div class="p-6 text-center">
          <div
            class="w-12 h-12 rounded-full border flex items-center justify-center mx-auto mb-4"
            :class="colors().iconBg"
          >
            <!-- Delete icon -->
            <svg v-if="icon === 'delete'" class="w-6 h-6" :class="colors().iconText" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/>
            </svg>
            <!-- Check icon -->
            <svg v-else class="w-6 h-6" :class="colors().iconText" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
          </div>
          <h3 class="text-lg font-semibold text-slate-100 mb-2">{{ title }}</h3>
          <p v-if="subtitle" class="text-sm text-slate-400 mb-1">{{ subtitle }}</p>
          <p v-if="message" class="text-xs text-slate-500">{{ message }}</p>
        </div>
        <div class="px-6 pb-6 flex items-center gap-3">
          <button
            @click="emit('cancel')"
            class="flex-1 px-4 py-2.5 rounded-lg text-sm text-slate-400 hover:text-white hover:bg-slate-800 border border-slate-700 transition-colors"
          >{{ t('common.actions.cancel') }}</button>
          <button
            @click="emit('confirm')"
            :disabled="isLoading"
            class="flex-1 px-4 py-2.5 disabled:bg-slate-700 disabled:text-slate-500 rounded-lg text-white text-sm font-medium transition-colors flex items-center justify-center gap-2"
            :class="colors().btn"
          >
            <svg v-if="isLoading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ confirmLabel || t('common.actions.confirm') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
