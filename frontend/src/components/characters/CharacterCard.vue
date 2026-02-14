<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'

interface Character {
  id: string
  eveCharacterId: number
  name: string
  corporationId: number
  corporationName: string
  allianceId: number | null
  allianceName: string | null
  isMain: boolean
  hasValidToken: boolean
  hasMissingScopes: boolean
  lastSyncAt: string | null
}

interface SkillQueue {
  characterId: string
  skillId: number
  skillName: string
  finishedLevel: number | null
  finishDate: string
  queueSize: number
}

const props = defineProps<{
  character: Character
  wallet: number | undefined
  skillQueue: SkillQueue | undefined
  isLoading: boolean
  variant: 'corp' | 'other'
}>()

const emit = defineEmits<{
  reauthorize: [character: Character]
  'set-main': [character: Character]
  delete: [character: Character]
}>()

const { t } = useI18n()
const { formatIsk } = useFormatters()

function formatSkillRemaining(finishDate: string): string {
  const finish = new Date(finishDate)
  const now = new Date()
  const diffMs = finish.getTime() - now.getTime()
  if (diffMs <= 0) return 'Done'

  const days = Math.floor(diffMs / (1000 * 60 * 60 * 24))
  const hours = Math.floor((diffMs % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60))
  const minutes = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60))

  if (days > 0) return `${days}j ${hours}h`
  if (hours > 0) return `${hours}h ${minutes}m`
  return `${minutes}m`
}

function romanLevel(level: number | null): string {
  const map: Record<number, string> = { 1: 'I', 2: 'II', 3: 'III', 4: 'IV', 5: 'V' }
  return level ? map[level] ?? `${level}` : ''
}

const isCorp = props.variant === 'corp'
</script>

<template>
  <div
    :class="[
      'group relative bg-slate-900 rounded-xl border p-5 overflow-hidden transition-all duration-300 hover:shadow-lg hover:-translate-y-1',
      isCorp
        ? 'border-slate-800 hover:border-cyan-500/40 hover:shadow-cyan-500/10'
        : 'border-slate-800 hover:border-slate-500/40 hover:shadow-slate-500/5'
    ]"
  >
    <!-- Scan effect on hover -->
    <div :class="[
      'absolute inset-0 bg-gradient-to-r from-transparent to-transparent -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-in-out',
      isCorp ? 'via-cyan-500/10' : 'via-slate-400/10'
    ]"></div>

    <div class="relative flex items-start gap-4">
      <img
        :src="`https://images.evetech.net/characters/${props.character.eveCharacterId}/portrait?size=128`"
        :alt="props.character.name"
        :class="[
          'w-16 h-16 rounded-lg border transition-colors',
          isCorp
            ? 'border-slate-700 group-hover:border-cyan-500/30'
            : 'border-slate-700 opacity-80 group-hover:opacity-100 transition-opacity'
        ]"
      />

      <div class="flex-1 min-w-0">
        <h4 :class="[
          'font-semibold truncate transition-colors',
          isCorp ? 'text-slate-100 group-hover:text-cyan-300' : 'text-slate-200 group-hover:text-slate-100'
        ]">{{ props.character.name }}</h4>
        <p :class="['text-sm truncate', isCorp ? 'text-slate-400' : 'text-slate-500']">{{ props.character.corporationName }}</p>
        <p v-if="!isCorp && props.character.allianceName" class="text-xs text-slate-600 truncate">{{ props.character.allianceName }}</p>

        <div class="flex items-center gap-2 mt-2">
          <span :class="[
            'inline-flex items-center gap-1.5 text-xs px-2 py-0.5 rounded-md',
            props.character.hasValidToken
              ? 'bg-emerald-500/10 text-emerald-400'
              : props.character.hasMissingScopes
                ? 'bg-amber-500/10 text-amber-400'
                : 'bg-red-500/10 text-red-400'
          ]">
            <span :class="['w-1.5 h-1.5 rounded-full', props.character.hasValidToken ? 'bg-emerald-400' : props.character.hasMissingScopes ? 'bg-amber-400' : 'bg-red-400']"></span>
            {{ props.character.hasValidToken ? t('common.status.valid') : props.character.hasMissingScopes ? t('characters.missingScopes') : t('common.status.expired') }}
          </span>
        </div>

        <!-- Wallet -->
        <div class="mt-3 pt-3 border-t border-slate-800">
          <p class="text-xs text-slate-500">Wallet</p>
          <p :class="[
            'text-sm font-semibold font-mono transition-colors',
            isCorp ? 'text-cyan-400 group-hover:text-cyan-300' : 'text-cyan-400/80 group-hover:text-cyan-400'
          ]">{{ formatIsk(props.wallet, 1) }} ISK</p>
        </div>

        <!-- Skill training -->
        <div v-if="props.skillQueue" class="mt-2">
          <p class="text-xs text-slate-500">{{ t('characters.skillTraining') }}</p>
          <p class="text-sm text-slate-300">
            {{ props.skillQueue.skillName }}
            <span class="text-amber-400">{{ romanLevel(props.skillQueue.finishedLevel) }}</span>
          </p>
          <p class="text-xs text-slate-500">
            {{ formatSkillRemaining(props.skillQueue.finishDate) }}
            <span v-if="props.skillQueue.queueSize > 1">
              ({{ t('characters.skillsInQueue', { count: props.skillQueue.queueSize }) }})
            </span>
          </p>
        </div>
        <div v-else class="mt-2">
          <p class="text-xs text-slate-500">{{ t('characters.skillTraining') }}</p>
          <p class="text-xs text-red-400 italic">{{ t('characters.noSkillTraining') }}</p>
        </div>
      </div>
    </div>

    <!-- Actions -->
    <div class="relative flex items-center justify-end gap-1 mt-4 pt-3 border-t border-slate-800">
      <button
        v-if="!props.character.hasValidToken || props.character.hasMissingScopes"
        @click="emit('reauthorize', props.character)"
        :disabled="props.isLoading"
        :class="[
          'p-2 rounded-lg transition-colors disabled:opacity-50',
          props.character.hasMissingScopes
            ? 'hover:bg-amber-500/10 text-amber-400 hover:text-amber-300'
            : 'hover:bg-red-500/10 text-slate-400 hover:text-red-400'
        ]"
        :title="props.character.hasMissingScopes ? t('characters.missingScopes') : t('characters.reauthorize')"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
      </button>
      <button
        @click="emit('set-main', props.character)"
        :disabled="props.isLoading"
        class="p-2 rounded-lg hover:bg-amber-500/10 text-slate-400 hover:text-amber-400 transition-colors disabled:opacity-50"
        title="Set as Main"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
        </svg>
      </button>
      <button
        @click="emit('delete', props.character)"
        :disabled="props.isLoading"
        class="p-2 rounded-lg hover:bg-red-500/10 text-slate-400 hover:text-red-400 transition-colors disabled:opacity-50"
        title="Remove"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
        </svg>
      </button>
    </div>
  </div>
</template>
