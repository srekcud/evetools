<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  useAdminStore,
  type AdminUserSearchHit,
  type AdminUserSheet,
  type AdminUserTokenState,
} from '@/stores/admin'

const MINIMUM_TERM_LENGTH = 2

const props = defineProps<{
  selectedUserId: string | null
}>()

const { t, locale } = useI18n()
const adminStore = useAdminStore()

const term = ref('')
const hits = ref<AdminUserSearchHit[] | null>(null)
const sheet = ref<AdminUserSheet | null>(null)
const sheetError = ref(false)
const isLoadingSheet = ref(false)
const triggerMessage = ref<{ success: boolean; text: string } | null>(null)
const triggeringType = ref<string | null>(null)

const TOKEN_STATE_CLASSES: Record<AdminUserTokenState, string> = {
  valid: 'bg-emerald-500/20 text-emerald-400',
  expiring: 'bg-amber-500/20 text-amber-400',
  invalid: 'bg-red-500/20 text-red-400',
  no_token: 'bg-slate-700 text-slate-300',
}

function formatDateTime(isoDate: string): string {
  return new Date(isoDate).toLocaleString(locale.value, { dateStyle: 'medium', timeStyle: 'short' })
}

async function search(): Promise<void> {
  const trimmedTerm = term.value.trim()
  if (trimmedTerm.length < MINIMUM_TERM_LENGTH) return
  try {
    hits.value = await adminStore.searchUsers(trimmedTerm)
  } catch {
    hits.value = []
  }
}

async function loadSheet(userId: string): Promise<void> {
  isLoadingSheet.value = true
  sheetError.value = false
  try {
    sheet.value = await adminStore.fetchUserSheet(userId)
  } catch {
    sheet.value = null
    sheetError.value = true
  } finally {
    isLoadingSheet.value = false
  }
}

async function refresh(): Promise<void> {
  if (sheet.value) await loadSheet(sheet.value.userId)
}

async function trigger(type: string): Promise<void> {
  if (!sheet.value) return
  triggeringType.value = type
  const result = await adminStore.triggerUserSync(sheet.value.userId, type)
  triggerMessage.value = {
    success: result.success,
    text: result.success ? t('admin.userSupport.triggered', { type: t(`admin.userSupport.syncType.${type}`) }) : result.message,
  }
  triggeringType.value = null
  await loadSheet(sheet.value.userId)
}

watch(() => props.selectedUserId, (userId) => {
  if (userId) void loadSheet(userId)
}, { immediate: true })
</script>

<template>
  <div id="user-support" class="bg-slate-900 rounded-xl p-6 border border-slate-800">
    <h3 class="text-lg font-semibold text-slate-200 mb-4">{{ t('admin.userSupport.title') }}</h3>

    <form data-testid="user-search-form" class="flex gap-2 mb-4" @submit.prevent="search">
      <input
        v-model="term"
        data-testid="user-search-input"
        type="search"
        :placeholder="t('admin.userSupport.searchPlaceholder')"
        :aria-label="t('admin.userSupport.searchPlaceholder')"
        class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 placeholder-slate-500"
      >
      <button type="submit" class="px-4 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-sm text-white">
        {{ t('admin.userSupport.search') }}
      </button>
    </form>

    <div v-if="hits !== null" class="mb-4">
      <ul v-if="hits.length > 0" class="divide-y divide-slate-800">
        <li v-for="hit in hits" :key="hit.userId">
          <button
            type="button"
            data-testid="user-search-hit"
            class="w-full flex items-center justify-between py-2 text-left text-sm hover:bg-slate-800/60 px-2 rounded"
            @click="loadSheet(hit.userId)"
          >
            <span class="text-slate-200">{{ hit.mainCharacterName ?? t('admin.userSupport.noMainCharacter') }}</span>
            <span class="text-slate-500 text-xs">{{ t('admin.userSupport.characterCount', { count: hit.characterCount }) }}</span>
          </button>
        </li>
      </ul>
      <p v-else data-testid="user-search-empty" class="text-sm text-slate-500">{{ t('admin.userSupport.noResult') }}</p>
    </div>

    <p v-if="sheetError" data-testid="user-sheet-error" class="text-sm text-red-400">{{ t('admin.userSupport.loadError') }}</p>

    <div v-if="sheet" data-testid="user-sheet" class="space-y-6">
      <div class="flex items-center justify-between">
        <div class="text-sm text-slate-400 space-x-4">
          <span data-testid="user-sheet-last-login">
            {{ t('admin.userSupport.lastLogin') }} :
            <template v-if="sheet.lastLoginAt">{{ formatDateTime(sheet.lastLoginAt) }}</template>
            <template v-else>{{ t('admin.userSupport.never') }}</template>
          </span>
          <span>{{ t('admin.userSupport.createdAt') }} : {{ formatDateTime(sheet.createdAt) }}</span>
        </div>
        <button
          type="button"
          data-testid="user-sheet-refresh"
          :disabled="isLoadingSheet"
          class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs text-slate-200 disabled:opacity-50"
          @click="refresh"
        >{{ t('admin.userSupport.refresh') }}</button>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-slate-500 text-xs uppercase">
              <th class="text-left pb-2 font-medium">{{ t('admin.userSupport.character') }}</th>
              <th class="text-left pb-2 font-medium">{{ t('admin.userSupport.tokenStateColumn') }}</th>
              <th class="text-left pb-2 font-medium">{{ t('admin.userSupport.missingScopes') }}</th>
              <th class="text-right pb-2 font-medium">{{ t('admin.userSupport.lastSync') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800">
            <tr v-for="character in sheet.characters" :key="character.eveCharacterId" data-testid="user-sheet-character">
              <td class="py-1.5 text-slate-300">
                {{ character.name }}
                <span v-if="character.isMain" class="ml-2 text-xs px-1.5 py-0.5 rounded bg-cyan-500/20 text-cyan-300">{{ t('admin.userSupport.main') }}</span>
              </td>
              <td class="py-1.5">
                <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', TOKEN_STATE_CLASSES[character.tokenState]]">
                  {{ t(`admin.userSupport.tokenState.${character.tokenState}`) }}
                </span>
              </td>
              <td class="py-1.5 font-mono text-xs text-slate-400 break-all">{{ character.missingScopes.join(', ') }}</td>
              <td class="py-1.5 text-right text-slate-400">
                <template v-if="character.lastSyncAt">{{ formatDateTime(character.lastSyncAt) }}</template>
                <template v-else>{{ t('admin.userSupport.unknown') }}</template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-slate-500 text-xs uppercase">
              <th class="text-left pb-2 font-medium">{{ t('admin.userSupport.syncColumn') }}</th>
              <th class="text-left pb-2 font-medium">{{ t('admin.userSupport.lastData') }}</th>
              <th class="text-right pb-2 font-medium" />
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800">
            <tr v-for="sync in sheet.syncs" :key="sync.type" data-testid="user-sheet-sync">
              <td class="py-1.5 text-slate-300">{{ t(`admin.userSupport.syncType.${sync.type}`) }}</td>
              <td class="py-1.5 text-slate-400">
                <template v-if="sync.lastDataAt">{{ formatDateTime(sync.lastDataAt) }}</template>
                <template v-else>{{ t('admin.userSupport.unknown') }}</template>
              </td>
              <td class="py-1.5 text-right">
                <button
                  v-if="sync.triggerable"
                  type="button"
                  data-testid="user-sync-trigger"
                  :disabled="triggeringType !== null"
                  class="px-3 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs text-slate-200 disabled:opacity-50"
                  @click="trigger(sync.type)"
                >{{ t('admin.userSupport.trigger') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p
        v-if="triggerMessage"
        data-testid="user-sync-message"
        :class="['text-sm', triggerMessage.success ? 'text-emerald-400' : 'text-red-400']"
      >{{ triggerMessage.text }}</p>
    </div>
  </div>
</template>
