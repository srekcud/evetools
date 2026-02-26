<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useNotificationsStore } from '@/stores/notifications'
import type { AppNotification, NotificationCategory } from '@/stores/notifications'
import NotificationItem from './NotificationItem.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

const { t } = useI18n()
const router = useRouter()
const store = useNotificationsStore()

const emit = defineEmits<{
  close: []
  openSettings: []
}>()

const dropdownRef = ref<HTMLElement | null>(null)
const activeCategory = ref<NotificationCategory | undefined>(undefined)

const readCount = computed(() => store.notifications.filter(n => n.isRead).length)

const categories: { key: NotificationCategory | undefined; labelKey: string }[] = [
  { key: undefined, labelKey: 'common.status.all' },
  { key: 'planetary', labelKey: 'notificationHub.categories.planetary' },
  { key: 'industry', labelKey: 'notificationHub.categories.industry' },
  { key: 'escalation', labelKey: 'notificationHub.categories.escalation' },
  { key: 'esi', labelKey: 'notificationHub.categories.esi' },
  { key: 'price', labelKey: 'notificationHub.categories.price' },
]

function setCategory(category: NotificationCategory | undefined): void {
  activeCategory.value = category
  store.fetchNotifications(1, category)
}

function handleClickOutside(event: MouseEvent): void {
  if (dropdownRef.value && !dropdownRef.value.contains(event.target as Node)) {
    // Check if click is on the bell button itself (parent handles toggle)
    const bellButton = document.getElementById('notification-bell-btn')
    if (bellButton?.contains(event.target as Node)) return
    emit('close')
  }
}

async function handleNotificationClick(notification: AppNotification): Promise<void> {
  if (!notification.isRead) {
    await store.markAsRead(notification.id)
  }
  if (notification.route) {
    emit('close')
    router.push(notification.route)
  }
}

async function handleMarkAllRead(): Promise<void> {
  await store.markAllAsRead()
}

async function handleDelete(id: string): Promise<void> {
  await store.deleteNotification(id)
}

async function handleClearRead(): Promise<void> {
  await store.clearRead()
}

function loadMore(): void {
  store.fetchNotifications(store.currentPage + 1, activeCategory.value)
}

onMounted(() => {
  store.fetchNotifications(1, activeCategory.value)
  document.addEventListener('mousedown', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('mousedown', handleClickOutside)
})
</script>

<template>
  <div
    ref="dropdownRef"
    class="absolute right-0 top-full mt-2 w-96 bg-slate-900 rounded-xl border border-cyan-500/20 shadow-2xl shadow-black/50 z-50 flex flex-col max-h-[32rem] overflow-hidden"
  >
    <!-- Header -->
    <div class="flex items-center justify-between px-4 py-3 border-b border-slate-800 shrink-0">
      <h3 class="text-sm font-semibold text-slate-200">{{ t('notificationHub.title') }}</h3>
      <div class="flex items-center gap-2">
        <button
          v-if="store.unreadCount > 0"
          @click="handleMarkAllRead"
          class="text-xs text-cyan-400 hover:text-cyan-300 transition-colors"
        >
          {{ t('notificationHub.markAllRead') }}
        </button>
        <button
          v-if="readCount > 0"
          @click="handleClearRead"
          class="p-1 hover:bg-slate-800 rounded-lg transition-colors"
          :title="t('notificationHub.clearRead')"
        >
          <svg class="w-4 h-4 text-slate-400 hover:text-slate-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
          </svg>
        </button>
        <button
          @click="emit('openSettings')"
          class="p-1 hover:bg-slate-800 rounded-lg transition-colors"
          :title="t('notificationHub.settings')"
        >
          <svg class="w-4 h-4 text-slate-400 hover:text-slate-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Category filter pills -->
    <div class="flex items-center gap-1.5 px-4 py-2 border-b border-slate-800/50 shrink-0 overflow-x-auto">
      <button
        v-for="cat in categories"
        :key="cat.key ?? 'all'"
        @click="setCategory(cat.key)"
        :class="[
          'px-2.5 py-1 rounded-md text-xs font-medium transition-colors whitespace-nowrap',
          activeCategory === cat.key
            ? 'bg-cyan-500/20 text-cyan-400 border border-cyan-500/30'
            : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50 border border-transparent'
        ]"
      >
        {{ t(cat.labelKey) }}
      </button>
    </div>

    <!-- Notification list -->
    <div class="flex-1 overflow-y-auto min-h-0">
      <!-- Loading state -->
      <div v-if="store.isLoading" class="flex items-center justify-center py-8">
        <LoadingSpinner size="md+" class="text-cyan-500" />
      </div>

      <!-- Empty state -->
      <div v-else-if="store.notifications.length === 0" class="px-4 py-10 text-center">
        <svg class="w-8 h-8 text-slate-700 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
        </svg>
        <p class="text-sm text-slate-600">{{ t('notificationHub.empty') }}</p>
      </div>

      <!-- Notification items -->
      <div v-else class="divide-y divide-slate-800/50">
        <NotificationItem
          v-for="notif in store.notifications"
          :key="notif.id"
          :notification="notif"
          @click="handleNotificationClick"
          @delete="handleDelete"
        />
      </div>
    </div>

    <!-- Load more -->
    <div v-if="store.hasMore && !store.isLoading" class="px-4 py-2 border-t border-slate-800 shrink-0">
      <button
        @click="loadMore"
        :disabled="store.isLoadingMore"
        class="w-full py-1.5 text-xs text-cyan-400 hover:text-cyan-300 transition-colors disabled:opacity-50"
      >
        <span v-if="store.isLoadingMore" class="flex items-center justify-center gap-2">
          <LoadingSpinner size="xs" />
          {{ t('common.actions.loading') }}
        </span>
        <span v-else>{{ t('notificationHub.loadMore') }}</span>
      </button>
    </div>
  </div>
</template>
