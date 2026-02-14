<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Character {
  eveCharacterId: number
  name: string
}

interface Props {
  statusFilter: 'active' | 'all'
  visibilityFilter: 'all' | 'perso' | 'corp' | 'alliance' | 'public'
  characterFilter: number | null
  characters: Character[]
  shareableCount: number
}

defineProps<Props>()

const emit = defineEmits<{
  'update:statusFilter': [value: 'active' | 'all']
  'update:visibilityFilter': [value: 'all' | 'perso' | 'corp' | 'alliance' | 'public']
  'update:characterFilter': [value: number | null]
  'share-wts': []
  'share-discord': []
  add: []
}>()

const showShareDropdown = ref(false)

function handleShareWts(): void {
  emit('share-wts')
  showShareDropdown.value = false
}

function handleShareDiscord(): void {
  emit('share-discord')
  showShareDropdown.value = false
}
</script>

<template>
  <div class="flex items-center justify-between flex-wrap gap-3">
    <div class="flex items-center gap-3 flex-wrap">
      <!-- Status filter -->
      <div class="flex items-center gap-2 bg-slate-900 rounded-lg p-1">
        <button
          class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors"
          :class="statusFilter === 'active' ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700/50'"
          @click="emit('update:statusFilter', 'active')"
        >{{ t('escalations.filters.active') }}</button>
        <button
          class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors"
          :class="statusFilter === 'all' ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700/50'"
          @click="emit('update:statusFilter', 'all')"
        >{{ t('escalations.filters.all') }}</button>
      </div>

      <!-- Visibility filter -->
      <div class="flex items-center gap-2 bg-slate-900 rounded-lg p-1">
        <button
          class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors"
          :class="visibilityFilter === 'all' ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700/50'"
          @click="emit('update:visibilityFilter', 'all')"
        >{{ t('escalations.filters.all') }}</button>
        <button
          class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors flex items-center gap-1.5"
          :class="visibilityFilter === 'perso' ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700/50'"
          @click="emit('update:visibilityFilter', 'perso')"
          :title="t('escalations.visibility.myEscalations')"
        >
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
          {{ t('escalations.filters.perso') }}
        </button>
        <button
          class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors flex items-center gap-1.5"
          :class="visibilityFilter === 'corp' ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700/50'"
          @click="emit('update:visibilityFilter', 'corp')"
          :title="t('escalations.visibility.sharedWithCorp')"
        >
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
          {{ t('escalations.filters.corp') }}
        </button>
        <button
          class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors flex items-center gap-1.5"
          :class="visibilityFilter === 'alliance' ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700/50'"
          @click="emit('update:visibilityFilter', 'alliance')"
          :title="t('escalations.visibility.sharedWithAlliance')"
        >
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
          {{ t('escalations.filters.alliance') }}
        </button>
        <button
          class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors flex items-center gap-1.5"
          :class="visibilityFilter === 'public' ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700/50'"
          @click="emit('update:visibilityFilter', 'public')"
          :title="t('escalations.visibility.visibleByAll')"
        >
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"/></svg>
          {{ t('escalations.filters.public') }}
        </button>
      </div>

      <!-- Character filter -->
      <select
        :value="characterFilter"
        @change="emit('update:characterFilter', ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null)"
        class="bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-cyan-500 pr-8 appearance-none"
        style="background-image: url(&quot;data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2394a3b8'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E&quot;); background-repeat: no-repeat; background-position: right 0.5rem center; background-size: 1.25rem;"
      >
        <option :value="''">{{ t('escalations.filters.allCharacters') }}</option>
        <option v-for="char in characters" :key="char.eveCharacterId" :value="char.eveCharacterId">
          {{ char.name }}
        </option>
      </select>
    </div>

    <div class="flex items-center gap-2">
      <!-- Share all button -->
      <div class="relative" @click.stop>
        <button
          @click="showShareDropdown = !showShareDropdown"
          :disabled="shareableCount === 0"
          class="px-3 py-2 rounded-lg text-sm font-medium flex items-center gap-2 transition-colors"
          :class="shareableCount > 0 ? 'bg-slate-700 hover:bg-slate-600 text-slate-200' : 'bg-slate-800 text-slate-600 cursor-not-allowed'"
          :title="t('escalations.shareAll')"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 100 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186l9.566-5.314m-9.566 7.5l9.566 5.314m0 0a2.25 2.25 0 103.935 2.186 2.25 2.25 0 00-3.935-2.186zm0-12.814a2.25 2.25 0 103.933-2.185 2.25 2.25 0 00-3.933 2.185z"/></svg>
          <span v-if="shareableCount > 0" class="bg-cyan-500/20 text-cyan-400 text-xs px-1.5 py-0.5 rounded-full">{{ shareableCount }}</span>
        </button>
        <div v-if="showShareDropdown" class="absolute right-0 mt-2 w-48 bg-slate-800 rounded-lg border border-slate-700 shadow-xl z-20 overflow-hidden">
          <button @click="handleShareWts" class="w-full px-4 py-2.5 text-left text-sm text-slate-300 hover:bg-slate-700 flex items-center gap-2 transition-colors">
            <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9.75a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184"/></svg>
            WTS (in-game)
          </button>
          <button @click="handleShareDiscord" class="w-full px-4 py-2.5 text-left text-sm text-slate-300 hover:bg-slate-700 flex items-center gap-2 transition-colors">
            <svg class="w-4 h-4 text-indigo-400" fill="currentColor" viewBox="0 0 24 24"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03z"/></svg>
            Discord
          </button>
        </div>
      </div>

      <!-- Add button -->
      <button
        @click="emit('add')"
        class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 transition-colors"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        {{ t('common.actions.add') }}
      </button>
    </div>
  </div>
</template>
