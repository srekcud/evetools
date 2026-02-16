<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { AppNotification, NotificationLevel } from '@/stores/notifications'
import { categoryIcons, categoryColors } from './notificationConstants'

const { t } = useI18n()

interface Props {
  notification: AppNotification
}

const props = defineProps<Props>()

const emit = defineEmits<{
  click: [notification: AppNotification]
}>()

const levelStyles: Record<NotificationLevel, { dot: string; border: string }> = {
  info: { dot: 'bg-cyan-400', border: '' },
  success: { dot: 'bg-emerald-400', border: '' },
  warning: { dot: 'bg-amber-400', border: 'border-l-2 border-l-amber-500/50' },
  error: { dot: 'bg-red-400', border: 'border-l-2 border-l-red-500/50' },
  critical: { dot: 'bg-red-500 animate-pulse', border: 'border-l-2 border-l-red-500' },
}

const icon = computed(() => categoryIcons[props.notification.category] || categoryIcons.esi)
const iconColor = computed(() => categoryColors[props.notification.category] || 'text-slate-400')
const style = computed(() => levelStyles[props.notification.level] || levelStyles.info)

const timeAgo = computed(() => {
  const now = Date.now()
  const created = new Date(props.notification.createdAt).getTime()
  const diffSeconds = Math.floor((now - created) / 1000)

  if (diffSeconds < 60) return t('notificationHub.timeAgo.justNow')
  const minutes = Math.floor(diffSeconds / 60)
  if (minutes < 60) return t('notificationHub.timeAgo.minutesAgo', { n: minutes })
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return t('notificationHub.timeAgo.hoursAgo', { n: hours })
  const days = Math.floor(hours / 24)
  return t('notificationHub.timeAgo.daysAgo', { n: days })
})

function handleClick(): void {
  emit('click', props.notification)
}
</script>

<template>
  <div
    @click="handleClick"
    :class="[
      'px-4 py-3 hover:bg-slate-800/40 transition-colors cursor-pointer group/item',
      style.border,
      !notification.isRead ? 'bg-slate-800/20' : ''
    ]"
  >
    <div class="flex items-start gap-3">
      <!-- Category icon -->
      <div class="shrink-0 mt-0.5">
        <svg class="w-4 h-4" :class="iconColor" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="icon" />
        </svg>
      </div>

      <!-- Content -->
      <div class="flex-1 min-w-0">
        <div class="flex items-center justify-between gap-2">
          <span
            :class="[
              'text-xs leading-tight',
              notification.isRead ? 'text-slate-400 font-normal' : 'text-slate-200 font-medium'
            ]"
          >
            {{ notification.title }}
          </span>
          <span class="text-[10px] text-slate-600 shrink-0 whitespace-nowrap">
            {{ timeAgo }}
          </span>
        </div>
        <p class="text-xs text-slate-500 mt-0.5 line-clamp-2">
          {{ notification.message }}
        </p>
      </div>

      <!-- Unread dot -->
      <div v-if="!notification.isRead" class="shrink-0 mt-1.5">
        <div :class="['w-2 h-2 rounded-full', style.dot]"></div>
      </div>
    </div>
  </div>
</template>
