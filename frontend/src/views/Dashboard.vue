<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { authFetch, safeJsonParse } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore } from '@/stores/sync'
import { useFormatters } from '@/composables/useFormatters'
import { useNotificationFeed } from '@/composables/useNotificationFeed'
import MainLayout from '@/layouts/MainLayout.vue'

const { t } = useI18n()
const authStore = useAuthStore()
const syncStore = useSyncStore()
const { formatIsk } = useFormatters()
const { notifications, unreadCount, clearAll, removeNotification } = useNotificationFeed()

const user = computed(() => authStore.user)

const totalBalance = ref<number | null>(null)
const isLoadingWallet = ref(false)
const isAddingCharacter = ref(false)

async function fetchWallets() {
  isLoadingWallet.value = true
  try {
    const response = await authFetch('/api/me/wallets', {
      headers: { 'Authorization': `Bearer ${authStore.token}` }
    })
    if (response.ok) {
      const data = await safeJsonParse<{ totalBalance?: number }>(response)
      totalBalance.value = data.totalBalance ?? null
    }
  } catch (e) {
    console.error('Failed to fetch wallets:', e)
  } finally {
    isLoadingWallet.value = false
  }
}

async function addCharacter() {
  isAddingCharacter.value = true
  try {
    const response = await authFetch('/auth/eve/redirect', {
      headers: { 'Authorization': `Bearer ${authStore.token}` }
    })
    const data = await safeJsonParse<{ redirect_url?: string; state?: string }>(response)

    if (data.redirect_url) {
      sessionStorage.setItem('eve_oauth_state', data.state || '')
      sessionStorage.setItem('eve_oauth_action', 'add-character')
      window.location.href = data.redirect_url
    }
  } catch (e) {
    console.error('Failed to add character:', e)
  } finally {
    isAddingCharacter.value = false
  }
}

function formatTimeAgo(date: Date): string {
  const seconds = Math.floor((Date.now() - date.getTime()) / 1000)
  if (seconds < 60) return t('common.time.justNow')
  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return t('common.time.minutesAgo', { minutes })
  const hours = Math.floor(minutes / 60)
  return t('common.time.hoursAgo', { hours })
}

function getLevelDot(level: string): string {
  switch (level) {
    case 'success': return 'bg-emerald-400'
    case 'error': return 'bg-red-400'
    case 'warning': return 'bg-amber-400'
    default: return 'bg-cyan-400'
  }
}

onMounted(() => {
  fetchWallets()
})
</script>

<template>
  <MainLayout>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Left column: Stats + Characters (2 cols) -->
      <div class="lg:col-span-2 space-y-6">
        <!-- Stats cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <!-- Characters -->
          <div class="bg-slate-900 rounded-2xl p-6 border border-slate-800 hover:border-cyan-500/30 transition-colors group">
            <div class="flex items-center justify-between mb-4">
              <div class="w-12 h-12 rounded-xl bg-cyan-500/20 flex items-center justify-center">
                <svg class="w-6 h-6 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
              </div>
              <span class="text-xs text-slate-500 uppercase tracking-wider">{{ t('dashboard.kpi.characters') }}</span>
            </div>
            <p class="text-4xl font-bold text-slate-100 mb-1">{{ user?.characters?.length || 0 }}</p>
            <p class="text-sm text-slate-500">{{ t('dashboard.kpi.linkedAccounts') }}</p>
          </div>

          <!-- Wallet -->
          <div class="bg-slate-900 rounded-2xl p-6 border border-slate-800 hover:border-amber-500/30 transition-colors group">
            <div class="flex items-center justify-between mb-4">
              <div class="w-12 h-12 rounded-xl bg-amber-500/20 flex items-center justify-center">
                <svg class="w-6 h-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
              </div>
              <span class="text-xs text-slate-500 uppercase tracking-wider">{{ t('dashboard.kpi.wallet') }}</span>
            </div>
            <p v-if="isLoadingWallet" class="text-4xl font-bold text-slate-100 mb-1">
              <svg class="w-8 h-8 animate-spin text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
            </p>
            <p v-else class="text-4xl font-bold text-amber-400 mb-1">{{ totalBalance !== null ? formatIsk(totalBalance) : '\u2014' }}</p>
            <p class="text-sm text-slate-500">{{ t('dashboard.kpi.iskTotal') }}</p>
          </div>
        </div>

        <!-- Mercure Connection Status -->
        <div v-if="syncStore.connectionError" class="flex items-center gap-2 px-3 py-2 bg-red-500/10 border border-red-500/30 rounded-lg text-sm">
          <div class="w-2 h-2 rounded-full bg-red-500"></div>
          <span class="text-red-400">{{ syncStore.connectionError }}</span>
        </div>

        <!-- Character Cards -->
        <div>
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-slate-200">{{ t('dashboard.yourCharacters') }}</h3>
            <button
              @click="addCharacter"
              :disabled="isAddingCharacter"
              class="px-4 py-2 bg-cyan-500/20 hover:bg-cyan-500/30 text-cyan-400 rounded-lg text-sm font-medium transition-colors flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <svg v-if="isAddingCharacter" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
              </svg>
              {{ t('dashboard.addCharacter') }}
            </button>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div
              v-for="character in (user?.characters || [])"
              :key="character.id"
              class="bg-slate-900 rounded-xl p-5 border border-slate-800 hover:border-cyan-500/40 transition-all duration-300 group cursor-pointer"
            >
              <div class="flex items-start gap-4">
                <div class="relative">
                  <img
                    :src="`https://images.evetech.net/characters/${character.eveCharacterId}/portrait?size=128`"
                    :alt="character.name"
                    class="w-16 h-16 rounded-xl ring-2 ring-slate-600 group-hover:ring-cyan-500/50 transition-all"
                  />
                  <div v-if="character.isMain" class="absolute -top-1 -right-1 w-5 h-5 bg-amber-500 rounded-full flex items-center justify-center">
                    <svg class="w-3 h-3 text-amber-900" fill="currentColor" viewBox="0 0 20 20">
                      <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                    </svg>
                  </div>
                </div>
                <div class="flex-1 min-w-0">
                  <h4 class="font-semibold text-slate-100 truncate group-hover:text-cyan-400 transition-colors">
                    {{ character.name }}
                  </h4>
                  <p class="text-sm text-slate-400 truncate">{{ character.corporationName }}</p>
                  <p class="text-xs text-amber-400/70 truncate mt-1">{{ character.allianceName }}</p>
                  <div class="flex items-center gap-2 mt-3">
                    <span
                      :class="[
                        'inline-flex items-center px-2 py-0.5 rounded-sm text-xs font-medium',
                        character.hasValidToken
                          ? 'bg-emerald-500/20 text-emerald-400'
                          : 'bg-red-500/20 text-red-400'
                      ]"
                    >
                      {{ character.hasValidToken ? t('dashboard.tokenValid') : t('dashboard.tokenInvalid') }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Right column: Activity Feed -->
      <div class="lg:col-span-1">
        <div class="bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden sticky top-4">
          <div class="flex items-center justify-between px-5 py-4 border-b border-slate-800">
            <div class="flex items-center gap-2">
              <h3 class="text-sm font-semibold text-slate-200 uppercase tracking-wider">{{ t('dashboard.activity') }}</h3>
              <span v-if="unreadCount > 0" class="text-[10px] px-1.5 py-0.5 bg-cyan-500/20 text-cyan-400 rounded-full font-medium">
                {{ unreadCount }}
              </span>
            </div>
            <div class="flex items-center gap-2">
              <div :class="['w-2 h-2 rounded-full', syncStore.isConnected ? 'bg-emerald-400' : 'bg-slate-600']" :title="syncStore.isConnected ? t('dashboard.mercureConnected') : t('dashboard.disconnected')"></div>
              <button v-if="notifications.length > 0" @click="clearAll" class="text-xs text-slate-500 hover:text-slate-300 transition-colors">
                {{ t('common.actions.clear') }}
              </button>
            </div>
          </div>
          <div class="max-h-[600px] overflow-y-auto">
            <div v-if="notifications.length === 0" class="px-5 py-12 text-center">
              <svg class="w-8 h-8 text-slate-700 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
              </svg>
              <p class="text-sm text-slate-600">{{ t('dashboard.noRecentActivity') }}</p>
              <p class="text-xs text-slate-700 mt-1">{{ t('dashboard.realtimeEventsWillAppear') }}</p>
            </div>
            <div v-else class="divide-y divide-slate-800/50">
              <div
                v-for="notif in notifications"
                :key="notif.id"
                class="px-4 py-3 hover:bg-slate-800/30 transition-colors group/notif"
              >
                <div class="flex items-start gap-3">
                  <div :class="['w-2 h-2 rounded-full mt-1.5 shrink-0', getLevelDot(notif.level)]"></div>
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2">
                      <span class="text-xs font-medium text-slate-300">{{ notif.title }}</span>
                      <span class="text-[10px] text-slate-600 shrink-0">{{ formatTimeAgo(notif.timestamp) }}</span>
                    </div>
                    <p class="text-xs text-slate-500 mt-0.5 truncate">{{ notif.message }}</p>
                  </div>
                  <button
                    @click="removeNotification(notif.id)"
                    class="opacity-0 group-hover/notif:opacity-100 text-slate-600 hover:text-slate-400 transition-all shrink-0"
                  >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </MainLayout>
</template>
