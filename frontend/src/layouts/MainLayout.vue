<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useEventListener, useMediaQuery } from '@vueuse/core'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useRateLimitStore } from '@/stores/rateLimit'
import { useAdminStore } from '@/stores/admin'
import { useIndustryActionsStore } from '@/stores/industry/actions'
import BaseModal from '@/components/common/BaseModal.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import CommandPalette from '@/components/common/CommandPalette.vue'
import AccountMenu from '@/components/layout/AccountMenu.vue'
import { useModuleLastPage } from '@/composables/useModuleLastPage'
import { useHiddenModules } from '@/composables/useHiddenModules'
import {
  adminNavItem,
  allNavEntries,
  isNavItemActive,
  isProjectsHidden,
  pageOfPath,
  type NavItem,
} from '@/constants/sidebarNavigation'

const router = useRouter()
const { t } = useI18n()
const authStore = useAuthStore()
const rateLimitStore = useRateLimitStore()
const adminStore = useAdminStore()
const { lastPageOf } = useModuleLastPage()
const { hiddenModules, reloadHiddenModules, isModuleVisible } = useHiddenModules()
const industryActionsStore = useIndustryActionsStore()
const INDUSTRY_NAV_ITEM_ID = 'industry'
const TITLE_SEPARATOR = ' › '

// Check admin access on mount
const isAdmin = ref(false)
onMounted(async () => {
  isAdmin.value = await adminStore.checkAccess()
})

import LegalFooter from '@/components/LegalFooter.vue'
import NotificationBell from '@/components/notifications/NotificationBell.vue'
import { APP_VERSION } from '@/version'

// Release notes modal
const showReleaseNotes = ref(false)
const releaseNotes = ref('')
const loadingReleaseNotes = ref(false)

async function openReleaseNotes() {
  showReleaseNotes.value = true
  if (!releaseNotes.value) {
    loadingReleaseNotes.value = true
    try {
      const response = await fetch('/RELEASE_NOTES.txt')
      releaseNotes.value = await response.text()
    } catch {
      releaseNotes.value = t('header.releaseNotesLoadError')
    } finally {
      loadingReleaseNotes.value = false
    }
  }
}

// ESI Status
interface EsiStatus {
  players: number
  server_version: string
  start_time: string
  vip: boolean
}
const esiStatus = ref<EsiStatus | null>(null)
const esiError = ref(false)
const esiRateLimited = ref(false)
let esiStatusInterval: ReturnType<typeof setInterval> | null = null

async function fetchEsiStatus() {
  try {
    const response = await fetch('https://esi.evetech.net/latest/status/?datasource=tranquility')
    if (response.ok) {
      esiStatus.value = await response.json()
      esiError.value = false
      esiRateLimited.value = false
    } else if (response.status === 420) {
      // Rate limited - ESI is not down, just rate limited
      esiError.value = false
      esiRateLimited.value = true
    } else {
      esiError.value = true
      esiRateLimited.value = false
    }
  } catch {
    esiError.value = true
    esiRateLimited.value = false
  }
}

// Fetch ESI status on mount and every 5 minutes
onMounted(() => {
  fetchEsiStatus()
  esiStatusInterval = setInterval(fetchEsiStatus, 300000)
})

onUnmounted(() => {
  if (esiStatusInterval) {
    clearInterval(esiStatusInterval)
  }
})

const user = computed(() => authStore.user)
const mainCharacter = computed(() => user.value?.mainCharacter || user.value?.characters?.[0])

const NAVIGATION_DRAWER_ID = 'navigation-drawer'
const NAV_LINK_BASE_CLASS = 'w-full flex items-center gap-3 px-3 py-2 rounded-md border-l-2 font-display text-base font-semibold tracking-wide transition-colors'

function navLinkClass(isActive: boolean): string[] {
  return [
    NAV_LINK_BASE_CLASS,
    isActive
      ? 'border-cyan-400 bg-cyan-400/10 text-slate-50'
      : 'border-transparent text-slate-300 hover:bg-slate-800/60 hover:text-slate-50',
  ]
}
const isWideScreen = useMediaQuery('(min-width: 1024px)')
const isDrawerOpen = ref(false)
const menuButton = ref<HTMLButtonElement | null>(null)
const drawerCloseButton = ref<HTMLButtonElement | null>(null)

async function openDrawer(): Promise<void> {
  isDrawerOpen.value = true
  await nextTick()
  drawerCloseButton.value?.focus()
}

function closeDrawer(): void {
  isDrawerOpen.value = false
}

async function closeDrawerAndFocusMenuButton(): Promise<void> {
  closeDrawer()
  await nextTick()
  menuButton.value?.focus()
}

function closeDrawerOnNavigationClick(event: MouseEvent): void {
  const isPlainClick = event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey
  const isOnLink = event.target instanceof Element && event.target.closest('a[href]') !== null
  if (isDrawerOpen.value && isPlainClick && isOnLink) {
    closeDrawer()
  }
}

useEventListener(window, 'keydown', (event: KeyboardEvent) => {
  if (isDrawerOpen.value && event.key === 'Escape') {
    closeDrawerAndFocusMenuButton()
  }
})

const isCommandPaletteOpen = ref(false)

function isCommandPaletteShortcut(event: KeyboardEvent): boolean {
  return event.key.toLowerCase() === 'k' && (event.ctrlKey || event.metaKey) && !event.shiftKey && !event.altKey
}

useEventListener(window, 'keydown', (event: KeyboardEvent) => {
  if (isCommandPaletteShortcut(event)) {
    event.preventDefault()
    isCommandPaletteOpen.value = !isCommandPaletteOpen.value
  }
})

watch(isWideScreen, (isWide) => {
  if (isWide) closeDrawer()
})
watch(() => router.currentRoute.value.fullPath, closeDrawer)

onMounted(() => {
  reloadHiddenModules()
  if (!isProjectsHidden(hiddenModules.value)) {
    void industryActionsStore.fetchActions()
  }
})

function navTargetOf(item: NavItem): string {
  return lastPageOf(item.id, item.route)
}

const visibleNavEntries = computed((): NavItem[] => {
  const visibleModules: NavItem[] = allNavEntries.filter(module => isModuleVisible(module.id))
  return isAdmin.value ? [...visibleModules, adminNavItem] : visibleModules
})

function isActiveRoute(item: NavItem): boolean {
  return isNavItemActive(item, router.currentRoute.value.path)
}

const currentPageTitle = computed(() => {
  const currentPath = router.currentRoute.value.path
  const activeModule = allNavEntries.find(module => isNavItemActive(module, currentPath))
  if (activeModule !== undefined) {
    const activePage = pageOfPath(activeModule, currentPath)
    return activePage === undefined ? t(activeModule.labelKey) : `${t(activeModule.labelKey)}${TITLE_SEPARATOR}${t(activePage.labelKey)}`
  }
  if (currentPath === adminNavItem.route) return t(adminNavItem.labelKey)
  return t('nav.dashboard')
})

const isLoggingOut = ref(false)

const logout = async () => {
  isLoggingOut.value = true
  try {
    await fetch('/auth/logout', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${authStore.token}`,
      },
    })
  } catch (e) {
    console.error('Logout error:', e)
  } finally {
    authStore.logout()
    router.push('/login')
  }
}

</script>

<template>
  <div class="min-h-screen bg-slate-950 text-slate-100 font-sans">
    <div class="fixed inset-0 overflow-hidden pointer-events-none" aria-hidden="true">
      <div class="absolute -top-1/2 -left-1/2 w-full h-full bg-gradient-radial from-cyan-900/20 via-transparent to-transparent opacity-40"></div>
      <div class="absolute -bottom-1/2 -right-1/2 w-full h-full bg-gradient-radial from-indigo-900/15 via-transparent to-transparent opacity-30"></div>
      <div class="absolute inset-0 bg-[linear-gradient(rgba(6,182,212,0.03)_1px,transparent_1px),linear-gradient(90deg,rgba(6,182,212,0.03)_1px,transparent_1px)] bg-size-[50px_50px]"></div>
    </div>

    <div class="relative flex h-screen">
      <div
        v-if="isDrawerOpen"
        data-testid="navigation-drawer-backdrop"
        role="presentation"
        class="fixed inset-0 z-30 bg-slate-950/70"
        @click="closeDrawerAndFocusMenuButton"
      ></div>

      <aside
        v-show="isWideScreen || isDrawerOpen"
        :id="isWideScreen ? undefined : NAVIGATION_DRAWER_ID"
        :role="isWideScreen ? undefined : 'dialog'"
        :aria-modal="isWideScreen ? undefined : 'true'"
        :aria-label="isWideScreen ? undefined : t('nav.menu')"
        :class="[
          'w-60 bg-slate-950/95 border-r border-slate-800 flex flex-col',
          isWideScreen ? '' : 'fixed inset-y-0 left-0 z-40 max-w-[85vw]'
        ]"
      >
        <div class="h-14 px-4 flex items-center gap-3 border-b border-slate-800 shrink-0">
          <div class="hud-hex w-8 h-8 shrink-0 grid place-items-center bg-linear-to-br from-cyan-300 to-blue-600 text-slate-950 font-display font-bold text-sm" aria-hidden="true">ET</div>
          <h1 class="font-display font-bold text-lg uppercase tracking-[0.14em] text-slate-100">EVE <span class="text-cyan-400">Tools</span></h1>
          <button
            v-if="!isWideScreen"
            ref="drawerCloseButton"
            type="button"
            class="ml-auto p-1 hover:bg-slate-800 rounded-md transition-colors"
            :aria-label="t('nav.closeMenu')"
            @click="closeDrawerAndFocusMenuButton"
          >
            <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>

        <nav class="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto min-h-0">
          <RouterLink
            v-for="entry in visibleNavEntries"
            :key="entry.id"
            :to="navTargetOf(entry)"
            :aria-current="isActiveRoute(entry) ? 'page' : undefined"
            @click="closeDrawerOnNavigationClick"
            :class="navLinkClass(isActiveRoute(entry))"
          >
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="entry.icon"/>
            </svg>
            <span class="truncate">{{ t(entry.labelKey) }}</span>
            <span
              v-if="entry.id === INDUSTRY_NAV_ITEM_ID && industryActionsStore.count"
              data-testid="nav-industry-action-count"
              class="ml-auto shrink-0 rounded border border-cyan-500/30 bg-cyan-500/10 px-1.5 font-mono text-xs text-cyan-400"
            >{{ industryActionsStore.count }}</span>
          </RouterLink>
        </nav>

        <div class="px-4 py-3 border-t border-slate-800 shrink-0">
          <button
            type="button"
            class="text-xs text-slate-400 hover:text-cyan-300 transition-colors font-mono"
            :title="t('header.viewReleaseNotes')"
            @click="openReleaseNotes"
          >
            v{{ APP_VERSION }} · {{ t('header.releaseNotes') }}
          </button>
        </div>
      </aside>

      <!-- Release Notes Modal -->
      <BaseModal
        :show="showReleaseNotes"
        :title="t('header.releaseNotes')"
        panel-class="border-cyan-500/30 shadow-cyan-500/10 max-w-2xl max-h-[80vh] flex flex-col"
        @close="showReleaseNotes = false"
      >
        <!-- Header -->
        <template #title="{ titleId }">
          <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
            <h3 :id="titleId" class="text-lg font-semibold text-slate-100">{{ t('header.releaseNotes') }}</h3>
            <button
              @click="showReleaseNotes = false"
              :aria-label="t('common.actions.close')"
              class="p-1 hover:bg-slate-800 rounded-lg transition-colors"
            >
              <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>
        </template>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-6">
          <div v-if="loadingReleaseNotes" class="flex items-center justify-center py-8">
            <LoadingSpinner size="lg" class="text-cyan-500" />
          </div>
          <pre v-else class="text-sm text-slate-300 whitespace-pre-wrap font-mono leading-relaxed">{{ releaseNotes }}</pre>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 border-t border-slate-800 flex justify-end">
          <button
            @click="showReleaseNotes = false"
            class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium transition-colors"
          >
            {{ t('common.actions.close') }}
          </button>
        </div>
      </BaseModal>

      <main class="flex-1 min-w-0 overflow-auto" :inert="isDrawerOpen">
        <header class="sticky top-0 z-10 h-14 bg-slate-950/90 backdrop-blur border-b border-slate-800 px-3 md:px-6 flex items-center gap-2 md:gap-3">
          <button
            v-if="!isWideScreen"
            ref="menuButton"
            type="button"
            class="shrink-0 p-2 hover:bg-slate-800 rounded-md transition-colors"
            :aria-label="t('nav.openMenu')"
            :aria-controls="NAVIGATION_DRAWER_ID"
            :aria-expanded="isDrawerOpen"
            @click="openDrawer"
          >
            <svg class="w-6 h-6 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
          </button>
          <h2 class="min-w-24 truncate font-display font-bold uppercase tracking-[0.08em] text-lg text-slate-100">{{ currentPageTitle }}</h2>

          <div class="ml-auto flex items-center gap-1.5 md:gap-3 min-w-0">
            <button
              type="button"
              class="flex items-center gap-2 h-9 px-2.5 xl:w-72 rounded-md border border-slate-700 bg-slate-900/70 text-sm text-slate-400 hover:border-cyan-500/50 hover:text-slate-200 transition-colors"
              :aria-label="t('commandPalette.open')"
              @click="isCommandPaletteOpen = true"
            >
              <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
              </svg>
              <span class="hidden xl:inline truncate">{{ t('commandPalette.placeholder') }}</span>
              <kbd class="hidden md:inline ml-auto shrink-0 px-1.5 py-0.5 rounded border border-slate-700 font-mono text-xs text-slate-400">Ctrl K</kbd>
            </button>

            <div
              class="hidden xl:flex items-center gap-2 h-8 px-3 rounded-md border border-slate-800 bg-slate-900/60 text-xs"
              :title="esiError ? t('header.esiUnavailable') : esiRateLimited ? t('header.esiRateLimitedTitle') : t('header.esiOkTitle', { players: esiStatus?.players?.toLocaleString() || '0' })"
            >
              <span
                :class="['w-2 h-2 rotate-45 shrink-0', esiError ? 'bg-red-400' : esiRateLimited ? 'bg-amber-400' : 'bg-emerald-400']"
                aria-hidden="true"
              ></span>
              <span class="font-display font-semibold uppercase tracking-wider text-slate-200">Tranquility</span>
              <span v-if="esiError" class="text-red-300">{{ t('header.esiUnavailable') }}</span>
              <span v-else-if="esiRateLimited" class="text-amber-300">{{ t('header.esiRateLimitedTitle') }}</span>
              <span v-else-if="esiStatus?.players" class="font-mono text-slate-400">{{ esiStatus.players.toLocaleString() }}</span>
            </div>

            <NotificationBell />

            <AccountMenu
              :character="mainCharacter"
              :is-logging-out="isLoggingOut"
              @open-release-notes="openReleaseNotes"
              @logout="logout"
            />
          </div>
        </header>

        <div role="status" class="empty:hidden mx-4 md:mx-8 mt-4 flex items-center gap-2 rounded-md border border-amber-500/40 bg-amber-500/10 px-4 py-2 text-sm text-amber-200"><template v-if="rateLimitStore.isRateLimited"><svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg><span>{{ t('header.rateLimitBanner', { seconds: rateLimitStore.remainingSeconds }) }}</span></template></div>

        <!-- Page content -->
        <div class="p-4 md:p-8">
          <div class="max-w-7xl mx-auto">
            <slot />
          </div>
        </div>

        <!-- Legal footer -->
        <LegalFooter />
      </main>
    </div>

    <CommandPalette
      :show="isCommandPaletteOpen"
      :is-admin="isAdmin"
      @close="isCommandPaletteOpen = false"
    />
  </div>
</template>

<style>
.bg-gradient-radial {
  background: radial-gradient(circle, var(--tw-gradient-from) 0%, var(--tw-gradient-via) 50%, var(--tw-gradient-to) 100%);
}

::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}

::-webkit-scrollbar-track {
  background: rgba(15, 23, 42, 0.5);
}

::-webkit-scrollbar-thumb {
  background: rgba(6, 182, 212, 0.3);
  border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
  background: rgba(6, 182, 212, 0.5);
}

/* Fade transition for save feedback */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
