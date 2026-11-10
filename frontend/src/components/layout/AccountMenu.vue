<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { onClickOutside } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import type { Character } from '@/types'

defineProps<{
  character: Character | null | undefined
  isLoggingOut: boolean
}>()

const emit = defineEmits<{
  openReleaseNotes: []
  logout: []
}>()

const MENU_ID = 'account-menu'
const PORTRAIT_SIZE = 64
const MENU_ITEM_CLASS = 'flex w-full items-center px-3.5 py-2 text-left text-sm text-slate-300 hover:bg-slate-800 hover:text-slate-50 focus-visible:bg-slate-800 focus-visible:text-slate-50 focus-visible:outline-none disabled:opacity-50'

const { t } = useI18n()
const { getCharacterPortraitUrl, onImageError } = useEveImages()

const isOpen = ref(false)
const root = ref<HTMLElement | null>(null)
const toggleButton = ref<HTMLButtonElement | null>(null)
const menu = ref<HTMLElement | null>(null)

async function open(): Promise<void> {
  isOpen.value = true
  await nextTick()
  menu.value?.querySelector<HTMLElement>('[role="menuitem"]')?.focus()
}

function close(): void {
  isOpen.value = false
}

async function closeAndFocusButton(): Promise<void> {
  close()
  await nextTick()
  toggleButton.value?.focus()
}

function toggle(): void {
  if (isOpen.value) {
    close()
  } else {
    open()
  }
}

function moveFocus(step: number): void {
  const items = Array.from(menu.value?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [])
  const currentIndex = items.indexOf(document.activeElement as HTMLElement)
  items[(currentIndex + step + items.length) % items.length]?.focus()
}

function choose(action: 'openReleaseNotes' | 'logout'): void {
  close()
  if (action === 'openReleaseNotes') emit('openReleaseNotes')
  else emit('logout')
}

onClickOutside(root, close)
</script>

<template>
  <div ref="root" class="relative flex min-w-0">
    <button
      ref="toggleButton"
      type="button"
      aria-haspopup="menu"
      :aria-expanded="isOpen"
      :aria-controls="isOpen ? MENU_ID : undefined"
      class="flex items-center gap-2.5 min-w-0 max-w-60 h-10 pl-1 pr-2 rounded-md border border-transparent hover:border-slate-700 hover:bg-slate-900/70 transition-colors"
      @click="toggle"
    >
      <img
        v-if="character"
        :src="getCharacterPortraitUrl(character.eveCharacterId, PORTRAIT_SIZE)"
        alt=""
        class="hud-hex w-8 h-8 shrink-0"
        @error="onImageError"
      />
      <span v-else class="hud-hex w-8 h-8 shrink-0 bg-slate-800"></span>
      <span class="hidden md:flex flex-col min-w-0 text-left leading-tight">
        <b class="text-sm font-semibold text-slate-100 truncate" :title="character?.name">{{ character?.name ?? t('common.actions.loading') }}</b>
        <small class="text-xs text-slate-400 truncate" :title="character?.corporationName">{{ character?.corporationName }}</small>
      </span>
      <svg class="w-3.5 h-3.5 shrink-0 text-slate-400 transition-transform" :class="{ 'rotate-180': isOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 9l6 6 6-6" />
      </svg>
    </button>

    <div
      v-if="isOpen"
      :id="MENU_ID"
      ref="menu"
      role="menu"
      tabindex="-1"
      :aria-label="t('header.accountMenu')"
      class="absolute right-0 top-full mt-2 z-30 w-60 py-1.5 bg-slate-900 border border-slate-700 rounded-md shadow-xl shadow-black/40"
      @keydown.esc.stop.prevent="closeAndFocusButton"
      @keydown.down.prevent="moveFocus(1)"
      @keydown.up.prevent="moveFocus(-1)"
      @keydown.tab="close"
    >
      <RouterLink to="/settings" role="menuitem" :class="MENU_ITEM_CLASS" @click="close">{{ t('settings.title') }}</RouterLink>
      <RouterLink to="/characters" role="menuitem" :class="MENU_ITEM_CLASS" @click="close">{{ t('nav.characters') }}</RouterLink>
      <button type="button" role="menuitem" :class="MENU_ITEM_CLASS" @click="choose('openReleaseNotes')">{{ t('header.releaseNotes') }}</button>
      <div class="my-1.5 border-t border-slate-800" role="none"></div>
      <button
        type="button"
        role="menuitem"
        :class="[MENU_ITEM_CLASS, 'hover:text-red-300 focus-visible:text-red-300']"
        :disabled="isLoggingOut"
        @click="choose('logout')"
      >{{ isLoggingOut ? t('auth.loggingOut') : t('auth.logout') }}</button>
    </div>
  </div>
</template>

