<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import type { AmmoType } from '@/types/pve'

const props = defineProps<{
  visible: boolean
  title: string
  description: string
  searchPlaceholder: string
  accentColor: 'cyan' | 'amber'
  configuredTypes: AmmoType[]
  isSearching: boolean
  isAdding: boolean
  searchResults: AmmoType[]
}>()

const emit = defineEmits<{
  close: []
  search: [query: string]
  add: [typeId: number]
  remove: [typeId: number]
}>()

const { t } = useI18n()
const { getTypeIconUrl, onImageError } = useEveImages()

const searchQuery = ref('')
let searchTimeout: ReturnType<typeof setTimeout> | null = null

function onSearchInput() {
  if (searchTimeout) clearTimeout(searchTimeout)
  if (searchQuery.value.length < 2) {
    return
  }
  searchTimeout = setTimeout(() => emit('search', searchQuery.value), 300)
}

function handleAdd(typeId: number) {
  emit('add', typeId)
  searchQuery.value = ''
}

const focusClass = props.accentColor === 'amber' ? 'focus:border-amber-500' : 'focus:border-cyan-500'
const spinnerClass = props.accentColor === 'amber' ? 'text-amber-500' : 'text-cyan-500'
const addIconClass = props.accentColor === 'amber' ? 'text-amber-500' : 'text-cyan-500'
</script>

<template>
  <Teleport to="body">
    <div v-if="visible" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" @click.self="emit('close')">
      <div class="bg-slate-900 rounded-xl border border-slate-700 max-w-lg w-full p-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-semibold">{{ title }}</h3>
          <button @click="emit('close')" class="text-slate-400 hover:text-slate-300">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>

        <p class="text-sm text-slate-400 mb-4">
          {{ description }}
        </p>

        <!-- Search input -->
        <div class="relative mb-4">
          <input
            v-model="searchQuery"
            @input="onSearchInput"
            type="text"
            :placeholder="searchPlaceholder"
            :class="['w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2 text-sm focus:outline-hidden pr-10', focusClass]"
          />
          <div v-if="isSearching" class="absolute right-3 top-2.5">
            <svg :class="['w-5 h-5 animate-spin', spinnerClass]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
          </div>
        </div>

        <!-- Search results -->
        <div v-if="searchResults.length > 0" class="mb-4 bg-slate-800 rounded-lg border border-slate-700 max-h-48 overflow-y-auto">
          <button
            v-for="result in searchResults"
            :key="result.typeId"
            @click="handleAdd(result.typeId)"
            :disabled="isAdding"
            class="w-full px-4 py-2 text-left hover:bg-slate-700 text-sm flex items-center justify-between group disabled:opacity-50"
          >
            <span>{{ result.typeName }}</span>
            <svg :class="['w-4 h-4 opacity-0 group-hover:opacity-100', addIconClass]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
          </button>
        </div>

        <!-- Configured types -->
        <div class="mb-2 text-sm text-slate-400">{{ t('pve.configuredItems') }}</div>
        <div v-if="configuredTypes.length > 0" class="space-y-2 max-h-48 overflow-y-auto">
          <div
            v-for="item in configuredTypes"
            :key="item.typeId"
            class="flex items-center justify-between bg-slate-800 rounded-lg px-4 py-2"
          >
            <div class="flex items-center gap-3">
              <img
                :src="getTypeIconUrl(item.typeId, 32)"
                :alt="item.typeName"
                class="w-6 h-6 rounded-sm"
                @error="onImageError"
              />
              <span class="text-sm">{{ item.typeName }}</span>
            </div>
            <button
              @click="emit('remove', item.typeId)"
              class="p-1 hover:bg-red-500/20 rounded-sm text-slate-400 hover:text-red-400 transition-colors"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>
        </div>
        <div v-else class="text-center py-6 text-slate-500">
          <slot name="empty-message">
            {{ t('pve.noItemConfigured') }}
          </slot>
        </div>

        <div class="mt-6 pt-4 border-t border-slate-800">
          <button
            @click="emit('close')"
            class="w-full py-2 bg-slate-800 hover:bg-slate-700 rounded-lg text-white font-medium"
          >
            {{ t('common.actions.close') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
