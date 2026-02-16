<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { AdminStats, AdminQueues } from '@/stores/admin'

defineProps<{
  stats: AdminStats | null
  queues: AdminQueues | null
}>()

const { t } = useI18n()

function formatNumber(n: number | null | undefined): string {
  if (n === null || n === undefined) return '--'
  return n.toLocaleString('fr-FR')
}
</script>

<template>
  <!-- KPI Cards - Row 1: Users -->
  <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
    <!-- Total Users -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-cyan-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.users') }}</span>
      </div>
      <p class="text-2xl font-bold text-slate-100">{{ formatNumber(stats?.users?.total) }}</p>
    </div>

    <!-- Valid Auth -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-emerald-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.validAuth') }}</span>
      </div>
      <p class="text-2xl font-bold text-emerald-400">{{ formatNumber(stats?.users?.valid) }}</p>
    </div>

    <!-- Invalid Auth -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-red-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.invalidAuth') }}</span>
      </div>
      <p class="text-2xl font-bold text-red-400">{{ formatNumber(stats?.users?.invalid) }}</p>
    </div>

    <!-- Active 7d -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-blue-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.active7d') }}</span>
      </div>
      <p class="text-2xl font-bold text-blue-400">{{ formatNumber(stats?.users?.activeLastWeek) }}</p>
    </div>

    <!-- Active 30d -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-purple-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.active30d') }}</span>
      </div>
      <p class="text-2xl font-bold text-purple-400">{{ formatNumber(stats?.users?.activeLastMonth) }}</p>
    </div>
  </div>

  <!-- KPI Cards - Row 2: Characters & Tokens -->
  <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
    <!-- Total Characters -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-cyan-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.characters') }}</span>
      </div>
      <p class="text-2xl font-bold text-slate-100">{{ formatNumber(stats?.characters?.total) }}</p>
    </div>

    <!-- Tokens Total -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-amber-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.tokens') }}</span>
      </div>
      <p class="text-2xl font-bold text-amber-400">{{ formatNumber(stats?.tokens?.total) }}</p>
    </div>

    <!-- Tokens Healthy -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-emerald-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.tokensHealthy') }}</span>
      </div>
      <p class="text-2xl font-bold text-emerald-400">{{ formatNumber(stats?.tokens?.healthy) }}</p>
    </div>

    <!-- Tokens Expiring -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-orange-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.expiring24h') }}</span>
      </div>
      <p class="text-2xl font-bold text-orange-400">{{ formatNumber(stats?.tokens?.expiring24h) }}</p>
    </div>

    <!-- Tokens Expired -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-red-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.expired') }}</span>
      </div>
      <p class="text-2xl font-bold text-red-400">{{ formatNumber(stats?.tokens?.expired) }}</p>
    </div>

    <!-- Sync Scope -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-yellow-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.syncScope') }}</span>
      </div>
      <p class="text-2xl font-bold text-yellow-400">{{ formatNumber(stats?.characters?.activeSyncScope) }}</p>
      <p class="text-xs text-slate-500 mt-1">/ {{ formatNumber(stats?.characters?.total) }} {{ t('admin.kpi.characters').toLowerCase() }}</p>
    </div>
  </div>

  <!-- KPI Cards - Row 3: Assets, Industry, Queues -->
  <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
    <!-- Total Assets -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-amber-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.assets') }}</span>
      </div>
      <p class="text-2xl font-bold text-amber-400">{{ formatNumber(stats?.assets?.totalItems) }}</p>
    </div>

    <!-- Industry Projects -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-indigo-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.activeProjects') }}</span>
      </div>
      <p class="text-2xl font-bold text-indigo-400">{{ formatNumber(stats?.industry?.activeProjects) }}</p>
    </div>

    <!-- Industry Jobs -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-violet-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.esiJobs') }}</span>
      </div>
      <p class="text-2xl font-bold text-violet-400">{{ formatNumber(stats?.industryJobs?.activeJobs) }}</p>
    </div>

    <!-- Structures -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-teal-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-teal-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.structures') }}</span>
      </div>
      <p class="text-2xl font-bold text-teal-400">{{ formatNumber(stats?.syncs?.structuresCached) }}</p>
    </div>

    <!-- Queue Async -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-cyan-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.queue') }}</span>
      </div>
      <p class="text-2xl font-bold text-cyan-400">{{ queues?.queues?.async ?? '--' }}</p>
    </div>

    <!-- Queue Failed -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-red-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.failed') }}</span>
      </div>
      <p class="text-2xl font-bold text-red-400">{{ queues?.queues?.failed ?? '--' }}</p>
    </div>
  </div>

  <!-- KPI Cards - Row 4: Market & Notifications -->
  <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-7 gap-4">
    <!-- Notifications Total -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-sky-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-sky-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.notifications') }}</span>
      </div>
      <p class="text-2xl font-bold text-sky-400">{{ formatNumber(stats?.notifications?.total) }}</p>
    </div>

    <!-- Notifications Unread -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-orange-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.unread') }}</span>
      </div>
      <p class="text-2xl font-bold text-orange-400">{{ formatNumber(stats?.notifications?.unread) }}</p>
    </div>

    <!-- Push Subscriptions -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-pink-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-pink-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.pushSubs') }}</span>
      </div>
      <p class="text-2xl font-bold text-pink-400">{{ formatNumber(stats?.notifications?.pushSubscriptions) }}</p>
    </div>

    <!-- Market Types Tracked -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-emerald-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.historyTypes') }}</span>
      </div>
      <p class="text-2xl font-bold text-emerald-400">{{ formatNumber(stats?.market?.historyTypes) }}</p>
    </div>

    <!-- Market Alerts Active -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-yellow-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.alertsActive') }}</span>
      </div>
      <p class="text-2xl font-bold text-yellow-400">{{ formatNumber(stats?.market?.alertsActive) }}</p>
    </div>

    <!-- Market Alerts Triggered -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-red-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.alertsTriggered') }}</span>
      </div>
      <p class="text-2xl font-bold text-red-400">{{ formatNumber(stats?.market?.alertsTriggered) }}</p>
    </div>

    <!-- Market Favorites -->
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-8 h-8 rounded-lg bg-amber-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
          </svg>
        </div>
        <span class="text-xs text-slate-500 uppercase">{{ t('admin.kpi.favorites') }}</span>
      </div>
      <p class="text-2xl font-bold text-amber-400">{{ formatNumber(stats?.market?.favorites) }}</p>
    </div>
  </div>
</template>
