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
}>()

const emit = defineEmits<{
  reauthorize: [character: Character]
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
</script>

<template>
  <div class="relative group">
    <!-- Glow effect -->
    <div class="absolute -inset-0.5 bg-linear-to-r from-amber-500/50 via-amber-400/30 to-amber-500/50 rounded-2xl blur-sm opacity-40 group-hover:opacity-60 transition-opacity duration-500"></div>

    <!-- Card -->
    <div class="relative bg-linear-to-br from-slate-900 via-slate-900 to-amber-950/20 rounded-2xl border border-amber-500/40 p-6 overflow-hidden">
      <!-- Top accent line -->
      <div class="absolute top-0 left-0 right-0 h-1 bg-linear-to-r from-amber-500 via-amber-400 to-amber-500"></div>

      <div class="flex items-start gap-6">
        <!-- Avatar with badge -->
        <div class="relative shrink-0">
          <div class="relative">
            <img
              :src="`https://images.evetech.net/characters/${props.character.eveCharacterId}/portrait?size=256`"
              :alt="props.character.name"
              class="w-24 h-24 rounded-xl border-2 border-amber-500/50 shadow-lg shadow-amber-500/20"
            />
            <!-- Crown badge -->
            <div class="absolute -top-2 -right-2 w-8 h-8 bg-linear-to-br from-amber-400 to-amber-600 rounded-full flex items-center justify-center shadow-lg shadow-amber-500/40 animate-pulse" style="animation-duration: 2s;">
              <svg class="w-5 h-5 text-amber-900" fill="currentColor" viewBox="0 0 20 20">
                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
              </svg>
            </div>
          </div>
        </div>

        <!-- Info -->
        <div class="flex-1 min-w-0">
          <h3 class="text-xl font-bold text-slate-100 tracking-wide">{{ props.character.name }}</h3>
          <p class="text-slate-400 mt-0.5">{{ props.character.corporationName }}</p>
          <p v-if="props.character.allianceName" class="text-sm text-amber-400/80 mt-0.5">{{ props.character.allianceName }}</p>

          <!-- Stats -->
          <div class="grid grid-cols-2 gap-4 mt-4 pt-4 border-t border-slate-800">
            <div>
              <p class="text-xs text-slate-500 uppercase tracking-wide">Wallet</p>
              <p class="text-lg font-semibold text-cyan-400 font-mono">{{ formatIsk(props.wallet, 1) }} ISK</p>
            </div>
            <div>
              <p class="text-xs text-slate-500 uppercase tracking-wide">{{ t('characters.skillTraining') }}</p>
              <template v-if="props.skillQueue">
                <p class="text-sm text-slate-200">
                  {{ props.skillQueue.skillName }}
                  <span class="text-amber-400">{{ romanLevel(props.skillQueue.finishedLevel) }}</span>
                </p>
                <p class="text-xs text-slate-400">
                  {{ formatSkillRemaining(props.skillQueue.finishDate) }}
                  <span v-if="props.skillQueue.queueSize > 1" class="text-slate-500">
                    ({{ t('characters.skillsInQueue', { count: props.skillQueue.queueSize }) }})
                  </span>
                </p>
              </template>
              <p v-else class="text-sm text-slate-500 italic">{{ t('characters.noSkillTraining') }}</p>
            </div>
          </div>

          <!-- Token status -->
          <div :class="[
            'inline-flex items-center gap-2 mt-4 px-3 py-1.5 rounded-lg text-sm font-medium',
            props.character.hasValidToken
              ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20'
              : props.character.hasMissingScopes
                ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20'
                : 'bg-red-500/10 text-red-400 border border-red-500/20'
          ]">
            <span :class="[
              'w-2 h-2 rounded-full',
              props.character.hasValidToken ? 'bg-emerald-400 animate-pulse' : props.character.hasMissingScopes ? 'bg-amber-400' : 'bg-red-400'
            ]"></span>
            {{ props.character.hasValidToken ? t('characters.tokenValid') : props.character.hasMissingScopes ? t('characters.missingScopes') : t('characters.tokenExpired') }}
          </div>
        </div>

        <!-- Actions -->
        <div class="shrink-0">
          <button
            v-if="!props.character.hasValidToken || props.character.hasMissingScopes"
            @click="emit('reauthorize', props.character)"
            :disabled="props.isLoading"
            :class="[
              'px-4 py-2 rounded-lg text-sm font-medium transition-colors disabled:opacity-50 border',
              props.character.hasMissingScopes
                ? 'bg-amber-500/20 hover:bg-amber-500/30 text-amber-400 border-amber-500/30'
                : 'bg-red-500/20 hover:bg-red-500/30 text-red-400 border-red-500/30'
            ]"
          >
            {{ t('characters.reauthorize') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
