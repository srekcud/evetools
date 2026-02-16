<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useNotificationsStore } from '@/stores/notifications'
import NotificationDropdown from './NotificationDropdown.vue'
import NotificationSettings from './NotificationSettings.vue'

const store = useNotificationsStore()

const isOpen = ref(false)
const showSettings = ref(false)

function toggleDropdown(): void {
  isOpen.value = !isOpen.value
}

function closeDropdown(): void {
  isOpen.value = false
}

function openSettings(): void {
  isOpen.value = false
  showSettings.value = true
}

function closeSettings(): void {
  showSettings.value = false
}

onMounted(() => {
  store.fetchUnreadCount()
})
</script>

<template>
  <div class="relative">
    <!-- Bell button -->
    <button
      id="notification-bell-btn"
      @click="toggleDropdown"
      class="relative p-2 rounded-lg hover:bg-slate-800/50 transition-colors group"
      :class="isOpen ? 'bg-slate-800/50' : ''"
    >
      <svg
        class="w-5 h-5 text-slate-400 group-hover:text-slate-200 transition-colors"
        :class="isOpen ? 'text-cyan-400' : ''"
        fill="none"
        stroke="currentColor"
        viewBox="0 0 24 24"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="1.5"
          d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"
        />
      </svg>

      <!-- Unread badge -->
      <span
        v-if="store.unreadCount > 0"
        class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] flex items-center justify-center px-1 text-[10px] font-bold text-white bg-red-500 rounded-full leading-none"
      >
        {{ store.unreadCount > 99 ? '99+' : store.unreadCount }}
      </span>
    </button>

    <!-- Dropdown -->
    <NotificationDropdown
      v-if="isOpen"
      @close="closeDropdown"
      @open-settings="openSettings"
    />

    <!-- Settings modal -->
    <NotificationSettings
      v-if="showSettings"
      @close="closeSettings"
    />
  </div>
</template>
