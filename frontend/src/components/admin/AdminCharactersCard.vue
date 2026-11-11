<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { AdminCharacters } from '@/stores/admin'

defineProps<{
  adminCharacters: AdminCharacters
}>()

const { t } = useI18n()
</script>

<template>
  <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
    <div class="flex items-center justify-between mb-1">
      <h3 class="text-lg font-semibold text-slate-200">{{ t('admin.adminCharacters.title') }}</h3>
      <span
        data-testid="admin-characters-count"
        class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-cyan-500/20 text-cyan-400"
      >{{ adminCharacters.count }}</span>
    </div>
    <p class="text-xs text-slate-500 mb-4">{{ t('admin.adminCharacters.readOnlyHint') }}</p>

    <div v-if="adminCharacters.characters.length > 0" class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-slate-500 text-xs uppercase">
            <th class="text-left pb-2 font-medium">{{ t('admin.adminCharacters.character') }}</th>
            <th class="text-left pb-2 font-medium">{{ t('admin.adminCharacters.eveCharacterId') }}</th>
            <th class="text-left pb-2 font-medium">{{ t('admin.adminCharacters.user') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800">
          <tr v-for="character in adminCharacters.characters" :key="character.eveCharacterId" data-testid="admin-character">
            <td class="py-1.5 text-slate-300" data-testid="admin-character-name">
              <template v-if="character.characterName">{{ character.characterName }}</template>
              <span v-else class="text-slate-500 italic">{{ t('admin.adminCharacters.unknownName') }}</span>
            </td>
            <td class="py-1.5 font-mono text-xs text-slate-400">{{ character.eveCharacterId }}</td>
            <td class="py-1.5">
              <span
                data-testid="admin-character-user"
                :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', character.hasUser ? 'bg-emerald-500/20 text-emerald-400' : 'bg-slate-700/50 text-slate-400']"
              >{{ t(character.hasUser ? 'admin.adminCharacters.hasUser' : 'admin.adminCharacters.noUser') }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-else data-testid="admin-characters-empty" class="text-sm text-slate-500">{{ t('admin.adminCharacters.empty') }}</p>
  </div>
</template>
