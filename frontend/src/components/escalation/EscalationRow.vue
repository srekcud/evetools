<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { Escalation } from '@/stores/escalation'
import type { EscalationTimerInfo } from '@/composables/useEscalationTimers'

const { t } = useI18n()

interface Props {
  escalation: Escalation
  timerInfo: EscalationTimerInfo
  activeVisPopoverId: string | null
  timerBarColor: string
  timerTextColor: string
  priceColorClass: string
  priceSubColorClass: string
  secStatusColorClass: string
  visibilityBadgeClasses: string
  visibilityLabel: string
  visibilityTitle: string
  showShareButtons: boolean
}

defineProps<Props>()

const emit = defineEmits<{
  'toggle-bm': [escalation: Escalation]
  'toggle-sale': [escalation: Escalation]
  'change-visibility': [escalation: Escalation, vis: 'perso' | 'corp' | 'alliance' | 'public']
  'toggle-vis-popover': [id: string, event: Event]
  'share-wts': [escalation: Escalation]
  'share-discord': [escalation: Escalation]
  edit: [escalation: Escalation]
  delete: [escalation: Escalation]
}>()

function visibilityBadgeClassesForVis(vis: string): string {
  if (vis === 'perso') return 'bg-slate-500/20 text-slate-400 hover:ring-slate-400/30'
  if (vis === 'corp') return 'bg-indigo-500/20 text-indigo-400 hover:ring-indigo-400/30'
  if (vis === 'alliance') return 'bg-blue-500/20 text-blue-400 hover:ring-blue-400/30'
  return 'bg-purple-500/20 text-purple-400 hover:ring-purple-400/30'
}

function visTitleFor(vis: string): string {
  if (vis === 'perso') return t('escalations.visibility.perso')
  if (vis === 'corp') return t('escalations.visibility.corp')
  if (vis === 'alliance') return t('escalations.visibility.alliance')
  return t('escalations.visibility.public')
}

function visDescFor(vis: string): string {
  if (vis === 'perso') return t('escalations.visibility.persoDesc')
  if (vis === 'corp') return t('escalations.visibility.corpDesc')
  if (vis === 'alliance') return t('escalations.visibility.allianceDesc')
  return t('escalations.visibility.publicDesc')
}
</script>

<template>
  <div
    class="px-5 py-4 hover:bg-slate-800/30 transition-colors"
    :class="{
      'opacity-50': escalation.saleStatus === 'vendu',
      'bg-red-500/5': timerInfo.zone === 'danger' && escalation.saleStatus !== 'vendu',
    }"
  >
    <div class="flex items-center justify-between gap-4">
      <!-- Left: Info -->
      <div class="min-w-0 flex-1">
        <!-- Badges row -->
        <div class="flex items-center gap-2 mb-1 flex-wrap">
          <!-- Visibility badge (clickable) -->
          <div class="relative" v-if="escalation.isOwner">
            <button
              class="text-xs px-1.5 py-0.5 rounded-sm flex items-center gap-1 hover:ring-1 transition-all cursor-pointer"
              :class="visibilityBadgeClasses"
              :title="`${visibilityTitle} — ${t('escalations.visibility.clickToChange')}`"
              @click="emit('toggle-vis-popover', escalation.id, $event)"
            >
              <!-- Perso icon -->
              <svg v-if="escalation.visibility === 'perso'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
              <!-- Corp icon -->
              <svg v-else-if="escalation.visibility === 'corp'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
              <!-- Alliance icon -->
              <svg v-else-if="escalation.visibility === 'alliance'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
              <!-- Public icon -->
              <svg v-else class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"/></svg>
              {{ visibilityLabel }}
            </button>

            <!-- Visibility popover -->
            <div
              v-if="activeVisPopoverId === escalation.id"
              class="absolute z-50 mt-1 left-0 bg-slate-800 border border-slate-700 rounded-lg shadow-xl overflow-hidden w-48"
              @click.stop
            >
              <button
                v-for="vis in (['perso', 'corp', 'alliance', 'public'] as const)"
                :key="vis"
                class="w-full flex items-center gap-2.5 px-3 py-2 text-left transition-colors"
                :class="escalation.visibility === vis
                  ? visibilityBadgeClassesForVis(vis).replace('hover:ring-', '').split(' ').slice(0, 2).join(' ')
                  : 'text-slate-400 hover:bg-slate-700/50 hover:text-slate-200'"
                @click="emit('change-visibility', escalation, vis)"
              >
                <!-- Icons -->
                <span class="shrink-0">
                  <svg v-if="vis === 'perso'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                  <svg v-else-if="vis === 'corp'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
                  <svg v-else-if="vis === 'alliance'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
                  <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"/></svg>
                </span>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium">{{ visTitleFor(vis) }}</p>
                  <p class="text-[10px]" :class="escalation.visibility === vis ? 'opacity-70' : 'text-slate-500'">{{ visDescFor(vis) }}</p>
                </div>
                <!-- Checkmark for active -->
                <svg v-if="escalation.visibility === vis" class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
              </button>
            </div>
          </div>

          <!-- Non-owner visibility badge (read-only) -->
          <span
            v-else
            class="text-xs px-1.5 py-0.5 rounded-sm flex items-center gap-1"
            :class="visibilityBadgeClasses.replace('hover:ring-', '').split(' ').slice(0, 2).join(' ')"
          >
            <svg v-if="escalation.visibility === 'corp'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
            <svg v-else-if="escalation.visibility === 'alliance'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
            <svg v-else class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"/></svg>
            {{ visibilityLabel }}
          </span>

          <!-- BM badge -->
          <span
            class="text-xs px-2 py-0.5 rounded-sm"
            :class="escalation.bmStatus === 'nouveau' ? 'bg-slate-500/20 text-slate-400' : 'bg-emerald-500/20 text-emerald-400'"
          ><span v-if="escalation.bmStatus === 'nouveau'" class="line-through">BM</span><span v-else>BM</span></span>

          <!-- Sale badge -->
          <span
            class="text-xs px-2 py-0.5 rounded-sm"
            :class="escalation.saleStatus === 'envente' ? 'bg-amber-500/20 text-amber-400' : 'bg-emerald-500/20 text-emerald-400'"
          >{{ escalation.saleStatus === 'envente' ? t('escalations.saleStatus.forSale') : t('escalations.saleStatus.sold') }}</span>

          <!-- Urgent badge -->
          <span
            v-if="timerInfo.zone === 'danger' && escalation.saleStatus !== 'vendu'"
            class="text-xs px-2 py-0.5 rounded-sm bg-red-500/20 text-red-400 animate-pulse"
          >{{ t('escalations.urgent') }}</span>

          <!-- Type name -->
          <span class="text-xs text-slate-500">{{ escalation.type }}</span>
        </div>

        <!-- System + Character -->
        <p class="text-sm text-slate-300 flex items-center gap-3">
          <span class="flex items-center gap-1">
            <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
            <span :class="secStatusColorClass">{{ escalation.solarSystemName }}</span>
          </span>
          <span class="flex items-center gap-1">
            <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0"/></svg>
            {{ escalation.characterName }}
          </span>
        </p>

        <!-- Notes -->
        <p v-if="escalation.notes && escalation.visibility !== 'public'" class="text-xs text-slate-500 italic mt-1">{{ escalation.notes }}</p>

        <!-- Timer -->
        <div class="flex items-center gap-3 mt-2" v-if="escalation.saleStatus !== 'vendu'">
          <template v-if="timerInfo.percent > 0">
            <div class="w-32 h-1.5 bg-slate-700 rounded-full overflow-hidden">
              <div
                class="h-full rounded-full transition-[width] duration-1000 linear"
                :class="timerBarColor"
                :style="{ width: timerInfo.percent + '%' }"
              ></div>
            </div>
            <span
              class="text-xs font-mono"
              :class="timerTextColor"
            >{{ timerInfo.text }}</span>
          </template>
          <span v-else class="text-xs font-mono text-slate-600">{{ t('escalations.timer.expired') }}</span>
        </div>
        <div v-else class="flex items-center gap-3 mt-2">
          <span class="text-xs font-mono text-slate-600">{{ t('escalations.finished') }}</span>
        </div>
      </div>

      <!-- Right: Actions -->
      <div class="flex items-center gap-4 shrink-0">
        <!-- Toggle buttons (only for owner) -->
        <div v-if="escalation.isOwner" class="flex items-center gap-2">
          <button
            class="px-2.5 py-1 rounded-sm text-xs font-medium border transition-colors"
            :class="escalation.bmStatus === 'nouveau'
              ? 'bg-slate-500/20 text-slate-400 border-slate-500/30 hover:bg-slate-500/30'
              : 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30 hover:bg-emerald-500/30'"
            @click="emit('toggle-bm', escalation)"
          ><span v-if="escalation.bmStatus === 'nouveau'" class="line-through">BM</span><span v-else>BM</span></button>
          <button
            class="px-2.5 py-1 rounded-sm text-xs font-medium border transition-colors"
            :class="escalation.saleStatus === 'envente'
              ? 'bg-amber-500/20 text-amber-400 border-amber-500/30 hover:bg-amber-500/30'
              : 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30 hover:bg-emerald-500/30'"
            @click="emit('toggle-sale', escalation)"
          >{{ escalation.saleStatus === 'envente' ? t('escalations.saleStatus.forSale') : t('escalations.saleStatus.sold') }}</button>
        </div>

        <!-- Price -->
        <div class="text-right min-w-[80px]">
          <p class="text-lg font-bold font-mono" :class="priceColorClass">
            {{ escalation.price }}<span class="text-xs ml-0.5" :class="priceSubColorClass">m</span>
          </p>
        </div>

        <!-- Share buttons -->
        <div v-if="showShareButtons" class="flex items-center gap-1">
          <!-- WTS copy -->
          <button
            class="p-2 rounded-lg text-slate-500 hover:text-cyan-400 hover:bg-slate-800 transition-colors relative"
            :title="t('escalations.copyForWts')"
            @click="emit('share-wts', escalation)"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9.75a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184"/></svg>
          </button>
          <!-- Discord copy -->
          <button
            class="p-2 rounded-lg text-slate-500 hover:text-indigo-400 hover:bg-slate-800 transition-colors relative"
            :title="t('escalations.copyForDiscord')"
            @click="emit('share-discord', escalation)"
          >
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M20.317 4.37a19.791 19.791 0 00-4.885-1.515.074.074 0 00-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 00-5.487 0 12.64 12.64 0 00-.617-1.25.077.077 0 00-.079-.037A19.736 19.736 0 003.677 4.37a.07.07 0 00-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 00.031.057 19.9 19.9 0 005.993 3.03.078.078 0 00.084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 00-.041-.106 13.107 13.107 0 01-1.872-.892.077.077 0 01-.008-.128 10.2 10.2 0 00.372-.292.074.074 0 01.077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 01.078.01c.12.098.246.198.373.292a.077.077 0 01-.006.127 12.299 12.299 0 01-1.873.892.077.077 0 00-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 00.084.028 19.839 19.839 0 006.002-3.03.077.077 0 00.032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 00-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.095 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.095 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/></svg>
          </button>
        </div>

        <!-- Edit button (owner only) -->
        <button
          v-if="escalation.isOwner"
          class="p-2 rounded-lg text-slate-600 hover:text-cyan-400 hover:bg-cyan-500/10 transition-colors"
          :title="t('common.actions.edit')"
          @click="emit('edit', escalation)"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg>
        </button>

        <!-- Delete button (owner only) -->
        <button
          v-if="escalation.isOwner"
          class="p-2 rounded-lg text-slate-600 hover:text-red-400 hover:bg-red-500/10 transition-colors"
          :title="t('common.actions.delete')"
          @click="emit('delete', escalation)"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
        </button>
      </div>
    </div>
  </div>
</template>
