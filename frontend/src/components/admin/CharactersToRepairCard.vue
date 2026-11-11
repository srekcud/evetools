<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { AdminCharactersToRepair, AdminCharacterRepairState } from '@/stores/admin'

defineProps<{
  charactersToRepair: AdminCharactersToRepair
}>()

const { t, locale } = useI18n()

const STATE_CLASSES: Record<AdminCharacterRepairState, string> = {
  invalid: 'bg-red-500/20 text-red-400',
  missing_scopes: 'bg-amber-500/20 text-amber-400',
}

function formatDay(isoDate: string): string {
  return new Date(isoDate).toLocaleDateString(locale.value, { dateStyle: 'medium' })
}
</script>

<template>
  <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-semibold text-slate-200">{{ t('admin.charactersToRepair.title') }}</h3>
      <span
        data-testid="characters-to-repair-count"
        :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', charactersToRepair.count > 0 ? 'bg-amber-500/20 text-amber-400' : 'bg-emerald-500/20 text-emerald-400']"
      >{{ charactersToRepair.count }}</span>
    </div>

    <div v-if="charactersToRepair.characters.length > 0" class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-slate-500 text-xs uppercase">
            <th class="text-left pb-2 font-medium">{{ t('admin.charactersToRepair.character') }}</th>
            <th class="text-left pb-2 font-medium">{{ t('admin.charactersToRepair.lastLogin') }}</th>
            <th class="text-left pb-2 font-medium">{{ t('admin.charactersToRepair.stateColumn') }}</th>
            <th class="text-left pb-2 font-medium">{{ t('admin.charactersToRepair.missingScopes') }}</th>
            <th class="text-right pb-2 font-medium">{{ t('admin.charactersToRepair.since') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800">
          <tr v-for="character in charactersToRepair.characters" :key="character.eveCharacterId" data-testid="character-to-repair">
            <td class="py-1.5 text-slate-300">{{ character.characterName }}</td>
            <td class="py-1.5 text-slate-400">
              <template v-if="character.userLastLoginAt">{{ formatDay(character.userLastLoginAt) }}</template>
              <template v-else>{{ t('admin.charactersToRepair.never') }}</template>
            </td>
            <td class="py-1.5">
              <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', STATE_CLASSES[character.state]]">
                {{ t(`admin.charactersToRepair.state.${character.state}`) }}
              </span>
            </td>
            <td class="py-1.5 font-mono text-xs text-slate-400 break-all">{{ character.missingScopes.join(', ') }}</td>
            <td class="py-1.5 text-right text-slate-400">{{ formatDay(character.sinceAt) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-else data-testid="characters-to-repair-empty" class="text-sm text-slate-500">{{ t('admin.charactersToRepair.empty') }}</p>
  </div>
</template>
