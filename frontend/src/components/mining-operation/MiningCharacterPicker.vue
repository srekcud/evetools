<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { MiningCharacterEligibility } from '@/stores/miningOperation'

export interface PickableCharacter {
  id: string
  name: string
  isMain: boolean
  eligibility: MiningCharacterEligibility
  busyOperationLabel: string | null
}

const props = defineProps<{
  characters: PickableCharacter[]
  modelValue: string[]
}>()

const emit = defineEmits<{
  'update:modelValue': [characterIds: string[]]
}>()

const { t } = useI18n()

const ticked = computed({
  get: () => props.modelValue,
  set: (characterIds: string[]) => emit('update:modelValue', characterIds),
})

const INITIALS_LENGTH = 2

function initialsOf(name: string): string {
  return name
    .split(/\s+/)
    .filter(part => part.length > 0)
    .map(part => part[0])
    .join('')
    .slice(0, INITIALS_LENGTH)
    .toUpperCase()
}

function isDisabled(character: PickableCharacter): boolean {
  return character.eligibility !== 'eligible'
}

function eligibilityLabel(character: PickableCharacter): string {
  return t(`miningOperation.join.eligibility.${character.eligibility}`, { label: character.busyOperationLabel ?? '' })
}

const ELIGIBILITY_BADGE_CLASS: Record<MiningCharacterEligibility, string> = {
  eligible: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300',
  reconnect_required: 'border-amber-500/40 bg-amber-500/10 text-amber-300',
  in_another_operation: 'border-slate-600 bg-slate-800 text-slate-300',
}
</script>

<template>
  <ul class="space-y-2">
    <li v-for="character in characters" :key="character.id">
      <label
        class="flex items-center gap-3 rounded-md border px-3 py-2.5 transition-colors"
        :class="isDisabled(character) ? 'border-slate-800 bg-slate-900/40 opacity-80' : 'border-slate-700 bg-slate-900 hover:border-cyan-500/60 cursor-pointer'"
      >
        <input
          v-model="ticked"
          type="checkbox"
          :value="character.id"
          :disabled="isDisabled(character)"
          class="h-4 w-4 shrink-0 accent-cyan-500"
        />
        <span
          class="hud-hex flex h-9 w-9 shrink-0 items-center justify-center bg-slate-800 font-display text-sm font-semibold text-cyan-300"
          aria-hidden="true"
        >{{ initialsOf(character.name) }}</span>
        <span class="min-w-0 flex-1 truncate font-display text-base font-semibold text-slate-100" :title="character.name">{{ character.name }}</span>
        <span
          v-if="character.isMain"
          class="shrink-0 rounded-sm border border-cyan-500/40 bg-cyan-500/10 px-1.5 py-0.5 font-mono text-xs uppercase tracking-wider text-cyan-300"
        >{{ t('miningOperation.join.main') }}</span>
        <span
          class="max-w-[45%] shrink-0 truncate rounded-sm border px-2 py-0.5 font-mono text-xs"
          :class="ELIGIBILITY_BADGE_CLASS[character.eligibility]"
          :title="eligibilityLabel(character)"
        >{{ eligibilityLabel(character) }}</span>
        <router-link
          v-if="character.eligibility === 'reconnect_required'"
          to="/characters"
          class="shrink-0 text-xs font-medium text-cyan-400 underline hover:text-cyan-300"
        >{{ t('miningOperation.join.reconnect') }}</router-link>
      </label>
    </li>
  </ul>
</template>
