<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useEveImages } from '@/composables/useEveImages'
import type { CorporationRoleAccess, CorporationRoleAccessCharacter } from '@/composables/useCorporationRoleAccess'

const props = defineProps<{
  access: CorporationRoleAccess
  isRechecking: boolean
}>()

defineEmits<{ recheck: [] }>()

const PORTRAIT_SIZE = 64

const { t } = useI18n()
const { getCharacterPortraitUrl, onImageError } = useEveImages()

function hasRoleList(character: CorporationRoleAccessCharacter): boolean {
  return character.roles !== null
}

const characterMarks: Record<CorporationRoleAccessCharacter['state'], { key: string; dataAttribute: string; classes: string }> = {
  without_role: { key: 'withoutRole', dataAttribute: 'data-without-role', classes: 'border-amber-500/40 text-amber-300' },
  reconnect: { key: 'toReconnect', dataAttribute: 'data-to-reconnect', classes: 'border-cyan-500/40 text-cyan-300' },
  unreadable: { key: 'unreadable', dataAttribute: 'data-unreadable', classes: 'border-slate-600 text-slate-400' },
  unknown: { key: 'unknown', dataAttribute: 'data-unverified', classes: 'border-slate-600 text-slate-400' },
}

function markOf(character: CorporationRoleAccessCharacter) {
  return characterMarks[character.state]
}
</script>

<template>
  <div
    data-access-required
    :data-access-state="props.access.status"
    role="status"
    class="space-y-3 rounded-sm border border-amber-500/30 bg-slate-900 px-4 py-3 text-sm text-slate-300"
  >
    <p class="text-amber-300">
      <span aria-hidden="true">◈ </span>{{ t(`ledger.mining.accessRequired.${props.access.status}`, { role: props.access.requiredRole }) }}
    </p>

    <ul v-if="props.access.characters.length > 0" class="flex flex-wrap gap-2" :aria-label="t('ledger.mining.accessRequired.checkedCharacters')">
      <li
        v-for="character in props.access.characters"
        :key="character.characterId"
        data-access-character
        :data-character-id="character.characterId"
        class="flex min-w-0 items-center gap-2 rounded-sm border border-slate-700 bg-slate-950/60 px-2 py-1"
      >
        <img
          :src="getCharacterPortraitUrl(character.characterId, PORTRAIT_SIZE)"
          :alt="character.name"
          class="h-8 w-8 shrink-0 rounded-sm"
          @error="onImageError"
        />
        <span class="truncate text-slate-200" :title="character.name">{{ character.name }}</span>
        <span v-if="character.isMain" class="shrink-0 text-xs uppercase tracking-wider text-slate-500">{{ t('ledger.mining.accessRequired.main') }}</span>
        <span
          v-bind="{ [markOf(character).dataAttribute]: '' }"
          :class="['shrink-0 rounded-sm border px-1.5 py-0.5 text-xs uppercase tracking-wider', markOf(character).classes]"
        >
          {{ t(`ledger.mining.accessRequired.${markOf(character).key}`) }}
        </span>
      </li>
    </ul>

    <p v-if="props.access.status === 'role_missing'" data-access-steps class="text-slate-400">
      {{ t('ledger.mining.accessRequired.steps', { role: props.access.requiredRole }) }}
    </p>

    <div class="flex flex-wrap items-center gap-3">
      <RouterLink
        v-if="props.access.status === 'reconnect'"
        data-reconnect-link
        :to="{ name: 'characters' }"
        class="rounded-sm border border-cyan-500/60 bg-cyan-500/10 px-3 py-1 text-xs font-medium uppercase tracking-wider text-cyan-300 transition-colors hover:bg-cyan-500/20"
      >
        {{ t('ledger.mining.accessRequired.reconnectLink') }}
      </RouterLink>
      <button
        type="button"
        data-recheck
        :disabled="props.isRechecking"
        class="rounded-sm border border-slate-600 px-3 py-1 text-xs font-medium uppercase tracking-wider text-slate-300 transition-colors hover:text-white disabled:cursor-not-allowed disabled:opacity-40"
        @click="$emit('recheck')"
      >
        {{ props.isRechecking ? t('ledger.mining.accessRequired.rechecking') : t('ledger.mining.accessRequired.recheck') }}
      </button>
      <slot />
    </div>

    <details v-if="props.access.characters.some(hasRoleList)" data-access-roles class="text-xs text-slate-400">
      <summary class="cursor-pointer uppercase tracking-wider">{{ t('ledger.mining.accessRequired.rolesDetail') }}</summary>
      <dl class="mt-2 space-y-1">
        <div v-for="character in props.access.characters.filter(hasRoleList)" :key="character.characterId" class="break-words">
          <dt class="inline text-slate-300">{{ character.name }} :</dt>
          <dd class="inline">{{ character.roles?.length ? character.roles.join(', ') : t('ledger.mining.accessRequired.noRole') }}</dd>
        </div>
      </dl>
    </details>
  </div>
</template>
