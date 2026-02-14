<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useStructuresStore } from '@/stores/industry/structures'
import type { CharacterSkill } from '@/stores/industry/types'

const { t } = useI18n()

const store = useStructuresStore()

const syncing = ref(false)
const syncWarning = ref<string | null>(null)

onMounted(() => {
  store.fetchCharacterSkills()
})

async function syncAll() {
  syncing.value = true
  syncWarning.value = null
  try {
    const warning = await store.syncCharacterSkills()
    syncWarning.value = warning
  } catch (e) {
    // error handled by store
  } finally {
    syncing.value = false
  }
}

async function saveSkill(character: CharacterSkill, field: string, value: number) {
  const clamped = Math.max(0, Math.min(5, value))
  const update: Partial<CharacterSkill> = {}
  if (field === 'industry') update.industry = clamped
  else if (field === 'advancedIndustry') update.advancedIndustry = clamped
  else if (field === 'reactions') update.reactions = clamped

  await store.updateCharacterSkill(character.characterId, update)
}

function getInitials(name: string): string {
  return name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase()
}

function formatRelativeTime(dateStr: string | null): string {
  if (!dateStr) return '-'
  const d = new Date(dateStr)
  const now = new Date()
  const diffMs = now.getTime() - d.getTime()
  const diffMins = Math.floor(diffMs / 60000)
  const diffHours = Math.floor(diffMins / 60)
  const diffDays = Math.floor(diffHours / 24)

  if (diffMins < 1) return t('common.time.justNow')
  if (diffMins < 60) return t('common.time.minutesAgo', { minutes: diffMins })
  if (diffHours < 24) return t('common.time.hoursAgo', { hours: diffHours })
  if (diffDays < 7) return t('industry.skills.daysAgo', { days: diffDays })
  return d.toLocaleDateString(undefined, { day: '2-digit', month: '2-digit', year: 'numeric' })
}
</script>

<template>
  <div class="bg-slate-900 rounded-xl border border-slate-800">
    <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
      <div>
        <h3 class="text-lg font-semibold text-slate-100">{{ t('industry.skills.title') }}</h3>
        <p class="text-sm text-slate-400 mt-1">
          {{ t('industry.skills.description') }}
          <span class="group relative inline-block ml-1 cursor-help">
            <svg class="w-3.5 h-3.5 inline-block text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-width="2"/><path stroke-linecap="round" stroke-width="2" d="M12 16h.01M12 8v4"/></svg>
            <span class="invisible group-hover:visible absolute bottom-full left-1/2 -translate-x-1/2 mb-2 w-72 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-xs text-slate-300 shadow-lg z-10">
              {{ t('industry.skills.syncTooltip') }}
            </span>
          </span>
        </p>
      </div>
      <button
        @click="syncAll"
        :disabled="syncing"
        class="px-4 py-2 bg-cyan-500/20 border border-cyan-500/50 text-cyan-400 rounded-lg text-sm font-medium disabled:opacity-50 flex items-center gap-2 hover:bg-cyan-500/30 transition-colors"
      >
        <svg
          v-if="syncing"
          class="w-4 h-4 animate-spin"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
        {{ t('industry.skills.syncAll') }}
      </button>
    </div>

    <div class="p-6">
      <!-- Warning from sync -->
      <div v-if="syncWarning" class="mb-4 p-3 bg-amber-500/20 border border-amber-500/30 rounded-lg text-amber-400 text-sm">
        {{ syncWarning }}
      </div>

      <div v-if="store.characterSkills.length === 0" class="text-slate-500 text-sm py-4">
        {{ t('industry.skills.noCharacters') }}
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-800 text-slate-400 text-xs uppercase">
              <th class="text-left py-3 px-3">{{ t('industry.skills.character') }}</th>
              <th class="text-center py-3 px-3">Industry</th>
              <th class="text-center py-3 px-3">Adv. Industry</th>
              <th class="text-center py-3 px-3">Reactions</th>
              <th class="text-center py-3 px-3">{{ t('industry.skills.source') }}</th>
              <th class="text-center py-3 px-3">{{ t('industry.skills.lastSync') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="char in store.characterSkills"
              :key="char.characterId"
              class="border-b border-slate-800/50 hover:bg-slate-800/30"
            >
              <!-- Character with avatar -->
              <td class="py-3 px-3">
                <div class="flex items-center gap-3">
                  <div class="w-8 h-8 rounded bg-gradient-to-br from-slate-700 to-slate-800 flex items-center justify-center text-cyan-400 text-xs font-bold ring-1 ring-cyan-500/30">
                    {{ getInitials(char.characterName) }}
                  </div>
                  <div class="text-slate-200 font-medium">{{ char.characterName }}</div>
                </div>
              </td>

              <!-- Industry skill -->
              <td class="py-3 px-3 text-center">
                <select
                  :value="char.industry"
                  @change="(e) => saveSkill(char, 'industry', parseInt((e.target as HTMLSelectElement).value))"
                  class="bg-slate-800 border border-slate-700 rounded px-2 py-1 text-sm text-center text-cyan-400 font-mono focus:outline-none focus:border-cyan-500"
                >
                  <option v-for="n in 6" :key="n-1" :value="n-1">{{ n-1 }}</option>
                </select>
              </td>

              <!-- Advanced Industry skill -->
              <td class="py-3 px-3 text-center">
                <select
                  :value="char.advancedIndustry"
                  @change="(e) => saveSkill(char, 'advancedIndustry', parseInt((e.target as HTMLSelectElement).value))"
                  class="bg-slate-800 border border-slate-700 rounded px-2 py-1 text-sm text-center text-cyan-400 font-mono focus:outline-none focus:border-cyan-500"
                >
                  <option v-for="n in 6" :key="n-1" :value="n-1">{{ n-1 }}</option>
                </select>
              </td>

              <!-- Reactions skill -->
              <td class="py-3 px-3 text-center">
                <select
                  :value="char.reactions"
                  @change="(e) => saveSkill(char, 'reactions', parseInt((e.target as HTMLSelectElement).value))"
                  class="bg-slate-800 border border-slate-700 rounded px-2 py-1 text-sm text-center text-cyan-400 font-mono focus:outline-none focus:border-cyan-500"
                >
                  <option v-for="n in 6" :key="n-1" :value="n-1">{{ n-1 }}</option>
                </select>
              </td>

              <!-- Source -->
              <td class="py-3 px-3 text-center">
                <span
                  :class="[
                    'px-2 py-1 rounded text-xs',
                    char.source === 'esi'
                      ? 'bg-cyan-500/10 text-cyan-400 border border-cyan-500/20'
                      : 'bg-amber-500/10 text-amber-400 border border-amber-500/20'
                  ]"
                >
                  {{ char.source === 'esi' ? 'ESI' : t('industry.skills.manual') }}
                </span>
              </td>

              <!-- Last sync -->
              <td class="py-3 px-3 text-center text-slate-500 text-xs">
                {{ formatRelativeTime(char.lastSyncAt) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
