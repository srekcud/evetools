<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useEventListener, useMediaQuery } from '@vueuse/core'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useRateLimitStore } from '@/stores/rateLimit'
import { useAdminStore } from '@/stores/admin'
import { useFormatters } from '@/composables/useFormatters'
import { apiRequest } from '@/services/api'
import { useStructuresStore } from '@/stores/industry/structures'
import { useIndustryActionsStore } from '@/stores/industry/actions'
import BaseModal from '@/components/common/BaseModal.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import CommandPalette from '@/components/common/CommandPalette.vue'
import AccountMenu from '@/components/layout/AccountMenu.vue'
import {
  adminNavItem,
  allNavEntries,
  isNavGroup,
  isNavItemActive,
  isProjectsHidden,
  readHiddenModules,
  saveHiddenModules,
  settableModules,
  type NavEntry,
  type NavGroup,
  type NavItem,
} from '@/constants/sidebarNavigation'

const router = useRouter()
const { t, locale } = useI18n()
const authStore = useAuthStore()
const rateLimitStore = useRateLimitStore()
const adminStore = useAdminStore()
const { dateFormat, setDateFormat } = useFormatters()
const industryStructuresStore = useStructuresStore()
const industryActionsStore = useIndustryActionsStore()
const INDUSTRY_NAV_ITEM_ID = 'industry'

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
    } catch (e) {
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

// Settings
const showSettings = ref(false)
const hiddenModules = ref<string[]>([])

// Collapse state for nav groups
const collapsedGroups = ref<Record<string, boolean>>({})

function loadCollapsedGroups(): void {
  const saved = localStorage.getItem('evetools_nav_collapsed')
  if (saved) {
    try {
      collapsedGroups.value = JSON.parse(saved)
    } catch {
      collapsedGroups.value = {}
    }
  }
}

function saveCollapsedGroups(): void {
  localStorage.setItem('evetools_nav_collapsed', JSON.stringify(collapsedGroups.value))
}

function toggleGroup(groupId: string): void {
  collapsedGroups.value[groupId] = !collapsedGroups.value[groupId]
  saveCollapsedGroups()
}

function isGroupExpanded(group: NavGroup): boolean {
  // Auto-expand if a child route is active
  const currentPath = router.currentRoute.value.path
  const hasActiveChild = group.children.some(child => isNavItemActive(child, currentPath))
  if (hasActiveChild) return true
  // Otherwise use stored state (default expanded)
  return !collapsedGroups.value[group.id]
}

const NAVIGATION_DRAWER_ID = 'navigation-drawer'
const NAV_SECTION_HEADING_CLASS = 'w-full flex items-center gap-2 px-3 pb-1 font-display text-xs font-semibold uppercase tracking-[0.2em] text-slate-400 hover:text-slate-200 transition-colors'
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
watch(showSettings, (opened) => {
  if (opened) closeDrawer()
})

// Load settings from localStorage
onMounted(() => {
  loadCollapsedGroups()
  hiddenModules.value = readHiddenModules()
  if (!isProjectsHidden(hiddenModules.value)) {
    void industryActionsStore.fetchActions()
  }
})

// Save settings to localStorage
function saveSettings(): void {
  saveHiddenModules(hiddenModules.value)
}

function toggleModule(moduleId: string): void {
  const index = hiddenModules.value.indexOf(moduleId)
  if (index === -1) {
    hiddenModules.value.push(moduleId)
  } else {
    hiddenModules.value.splice(index, 1)
  }
  saveSettings()
}

function isModuleVisible(moduleId: string): boolean {
  return !hiddenModules.value.includes(moduleId)
}

// Filtered nav entries (visible only)
const filteredNavEntries = computed((): NavEntry[] => {
  const result: NavEntry[] = []
  for (const entry of allNavEntries) {
    if (isNavGroup(entry)) {
      const visibleChildren = entry.children.filter(child => isModuleVisible(child.id))
      if (visibleChildren.length > 0) {
        result.push({ ...entry, children: visibleChildren })
      }
    } else {
      if (isModuleVisible(entry.id)) {
        result.push(entry)
      }
    }
  }
  // Add admin item if admin
  if (isAdmin.value) {
    result.push(adminNavItem)
  }
  return result
})

function isActiveRoute(item: NavItem): boolean {
  return isNavItemActive(item, router.currentRoute.value.path)
}

// Current page title - search all entries including group children
const currentPageTitle = computed(() => {
  const currentPath = router.currentRoute.value.path
  for (const entry of allNavEntries) {
    if (isNavGroup(entry)) {
      const child = entry.children.find(c => isNavItemActive(c, currentPath))
      if (child) return t(child.labelKey)
    } else {
      if (isNavItemActive(entry, currentPath)) return t(entry.labelKey)
    }
  }
  if (currentPath === adminNavItem.route) return t(adminNavItem.labelKey)
  if (currentPath === '/characters') return t('nav.characters')
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

// Language switcher
function setLocale(lang: 'fr' | 'en') {
  locale.value = lang
  localStorage.setItem('locale', lang)
}

// Market structures list
const marketStructures = ref<Array<{ id: number; name: string }>>([])
const loadingStructures = ref(false)
const savingStructure = ref(false)
const structureSaveSuccess = ref(false)

// ESI structure search state
const structureSearchQuery = ref('')
const structureSearchResults = ref<{ locationId: number; locationName: string; solarSystemName: string | null }[]>([])
const isSearchingStructures = ref(false)
const showStructureSearchDropdown = ref(false)
let structureSearchTimeout: number | undefined

function onStructureSearchInput(): void {
  clearTimeout(structureSearchTimeout)
  if (structureSearchQuery.value.length < 3) {
    structureSearchResults.value = []
    showStructureSearchDropdown.value = false
    return
  }
  structureSearchTimeout = window.setTimeout(async () => {
    isSearchingStructures.value = true
    const result = await industryStructuresStore.searchStructures(structureSearchQuery.value)
    structureSearchResults.value = result.structures.map(s => ({
      locationId: s.locationId,
      locationName: s.locationName,
      solarSystemName: s.solarSystemName,
    }))
    showStructureSearchDropdown.value = result.structures.length > 0
    isSearchingStructures.value = false
  }, 500)
}

function selectStructureSearchResult(result: { locationId: number; locationName: string; solarSystemName: string | null }): void {
  if (!marketStructures.value.some(s => s.id === result.locationId)) {
    marketStructures.value.push({ id: result.locationId, name: result.locationName })
    saveMarketStructures()
  }
  structureSearchQuery.value = ''
  structureSearchResults.value = []
  showStructureSearchDropdown.value = false
}

function hideStructureDropdownDelayed(): void {
  window.setTimeout(() => {
    showStructureSearchDropdown.value = false
  }, 200)
}

function removeMarketStructure(id: number): void {
  marketStructures.value = marketStructures.value.filter(s => s.id !== id)
  saveMarketStructures()
}

async function loadMarketSettings(): Promise<void> {
  loadingStructures.value = true
  try {
    const settings = await apiRequest<{
      preferredMarketStructureId: number | null
      preferredMarketStructureName: string | null
      marketStructures: Array<{ id: number; name: string }>
    }>('/me/settings')
    if (settings.marketStructures?.length > 0) {
      marketStructures.value = settings.marketStructures
    } else if (settings.preferredMarketStructureId) {
      // Fallback: migrate single preferred to list
      marketStructures.value = [{ id: settings.preferredMarketStructureId, name: settings.preferredMarketStructureName ?? 'Unknown' }]
    }
  } catch {
    // Non-blocking: settings section will still show with defaults
  } finally {
    loadingStructures.value = false
  }
}

async function saveMarketStructures(): Promise<void> {
  savingStructure.value = true
  structureSaveSuccess.value = false
  try {
    await apiRequest('/me/settings', {
      method: 'PATCH',
      body: JSON.stringify({ marketStructures: marketStructures.value }),
    })
    structureSaveSuccess.value = true
    setTimeout(() => { structureSaveSuccess.value = false }, 2000)
  } catch {
    await loadMarketSettings()
  } finally {
    savingStructure.value = false
  }
}

// Load market settings when the settings modal opens
watch(showSettings, (opened) => {
  if (opened) {
    loadMarketSettings()
    // Reset search state
    structureSearchQuery.value = ''
    structureSearchResults.value = []
    showStructureSearchDropdown.value = false
  }
})
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
          <template v-for="entry in filteredNavEntries" :key="entry.id">
            <div v-if="'collapsible' in entry && entry.collapsible" class="pt-3">
              <button
                @click="toggleGroup(entry.id)"
                :aria-expanded="isGroupExpanded(entry as NavGroup)"
                :class="NAV_SECTION_HEADING_CLASS"
              >
                <span>{{ t(entry.labelKey) }}</span>
                <svg
                  class="w-3.5 h-3.5 ml-auto transition-transform duration-200"
                  :class="{ 'rotate-90': isGroupExpanded(entry as NavGroup) }"
                  fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
              </button>
              <div v-if="isGroupExpanded(entry as NavGroup)" class="mt-0.5 space-y-0.5">
                <RouterLink
                  v-for="child in (entry as NavGroup).children"
                  :key="child.id"
                  :to="child.route"
                  :aria-current="isActiveRoute(child) ? 'page' : undefined"
                  @click="closeDrawerOnNavigationClick"
                  :class="navLinkClass(isActiveRoute(child))"
                >
                  <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="child.icon"/>
                  </svg>
                  <span class="truncate">{{ t(child.labelKey) }}</span>
                  <span
                    v-if="child.id === INDUSTRY_NAV_ITEM_ID && industryActionsStore.count"
                    data-testid="nav-industry-action-count"
                    class="ml-auto shrink-0 rounded border border-cyan-500/30 bg-cyan-500/10 px-1.5 font-mono text-xs text-cyan-400"
                  >{{ industryActionsStore.count }}</span>
                </RouterLink>
              </div>
            </div>

            <RouterLink
              v-else
              :to="(entry as NavItem).route"
              :aria-current="isActiveRoute(entry as NavItem) ? 'page' : undefined"
              @click="closeDrawerOnNavigationClick"
              :class="navLinkClass(isActiveRoute(entry as NavItem))"
            >
              <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="(entry as NavItem).icon"/>
              </svg>
              <span class="truncate">{{ t((entry as NavItem).labelKey) }}</span>
            </RouterLink>
          </template>

          <div class="pt-3">
            <p :class="NAV_SECTION_HEADING_CLASS">{{ t('nav.account') }}</p>
            <div class="mt-0.5 space-y-0.5">
              <RouterLink
                to="/characters"
                :aria-current="router.currentRoute.value.path === '/characters' ? 'page' : undefined"
                @click="closeDrawerOnNavigationClick"
                :class="navLinkClass(router.currentRoute.value.path === '/characters')"
              >
                <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                <span class="truncate">{{ t('nav.characters') }}</span>
              </RouterLink>
              <button
                type="button"
                :title="t('auth.openSettings')"
                :class="navLinkClass(false)"
                @click="showSettings = true"
              >
                <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <span class="truncate">{{ t('settings.title') }}</span>
              </button>
            </div>
          </div>
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

      <!-- Settings Modal -->
      <BaseModal
        :show="showSettings"
        :title="t('settings.title')"
        panel-class="border-cyan-500/30 shadow-cyan-500/10 max-w-md max-h-[85vh] flex flex-col"
        @close="showSettings = false"
      >
        <!-- Header -->
        <template #title="{ titleId }">
          <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800 shrink-0">
            <h3 :id="titleId" class="text-lg font-semibold text-slate-100">{{ t('settings.title') }}</h3>
            <button
              @click="showSettings = false"
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
        <div class="p-6 overflow-y-auto min-h-0">
          <h4 class="text-sm font-medium text-slate-400 uppercase tracking-wider mb-4">{{ t('settings.visibleModules') }}</h4>
          <div class="space-y-2">
            <label
              v-for="item in settableModules"
              :key="item.id"
              class="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-800/50 cursor-pointer transition-colors"
            >
              <input
                type="checkbox"
                :checked="isModuleVisible(item.id)"
                @change="toggleModule(item.id)"
                class="w-4 h-4 rounded-sm border-slate-600 bg-slate-800 text-cyan-500 focus:ring-cyan-500 focus:ring-offset-slate-900"
              />
              <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="item.icon"/>
              </svg>
              <span class="text-slate-200">{{ t(item.labelKey) }}</span>
            </label>
          </div>

          <p class="mt-4 text-xs text-slate-500">
            {{ t('settings.hiddenModulesNote') }}
          </p>

          <!-- Language selector -->
          <h4 class="text-sm font-medium text-slate-400 uppercase tracking-wider mb-4 mt-6">{{ t('settings.language') }}</h4>
          <div class="flex gap-2">
            <button
              @click="setLocale('fr')"
              :class="[
                'flex-1 px-4 py-2.5 rounded-lg text-sm font-medium transition-colors border',
                locale === 'fr'
                  ? 'bg-cyan-500/20 text-cyan-400 border-cyan-500/30'
                  : 'bg-slate-800 text-slate-400 border-slate-700 hover:border-cyan-500/30 hover:text-slate-200'
              ]"
            >
              {{ t('settings.french') }}
            </button>
            <button
              @click="setLocale('en')"
              :class="[
                'flex-1 px-4 py-2.5 rounded-lg text-sm font-medium transition-colors border',
                locale === 'en'
                  ? 'bg-cyan-500/20 text-cyan-400 border-cyan-500/30'
                  : 'bg-slate-800 text-slate-400 border-slate-700 hover:border-cyan-500/30 hover:text-slate-200'
              ]"
            >
              {{ t('settings.english') }}
            </button>
          </div>
          <p class="mt-2 text-xs text-slate-500">
            {{ t('settings.languageNote') }}
          </p>

          <!-- Date format selector -->
          <h4 class="text-sm font-medium text-slate-400 uppercase tracking-wider mb-4 mt-6">{{ t('settings.dateFormat') }}</h4>
          <div class="flex gap-2">
            <button
              @click="setDateFormat('eu')"
              :class="[
                'flex-1 px-4 py-2.5 rounded-lg text-sm font-medium transition-colors border',
                dateFormat === 'eu'
                  ? 'bg-cyan-500/20 text-cyan-400 border-cyan-500/30'
                  : 'bg-slate-800 text-slate-400 border-slate-700 hover:border-cyan-500/30 hover:text-slate-200'
              ]"
            >
              JJ/MM/AA
            </button>
            <button
              @click="setDateFormat('us')"
              :class="[
                'flex-1 px-4 py-2.5 rounded-lg text-sm font-medium transition-colors border',
                dateFormat === 'us'
                  ? 'bg-cyan-500/20 text-cyan-400 border-cyan-500/30'
                  : 'bg-slate-800 text-slate-400 border-slate-700 hover:border-cyan-500/30 hover:text-slate-200'
              ]"
            >
              MM/DD/YY
            </button>
          </div>
          <p class="mt-2 text-xs text-slate-500">
            {{ t('settings.dateFormatNote') }}
          </p>

          <!-- Market structures list -->
          <h4 class="text-sm font-medium text-slate-400 uppercase tracking-wider mb-2 mt-6">{{ t('settings.marketStructure') }}</h4>
          <p class="text-xs text-slate-500 mb-3">{{ t('settings.marketStructureDesc') }}</p>
          <div v-if="loadingStructures" class="flex items-center gap-2 text-sm text-slate-500 py-2">
            <LoadingSpinner size="sm" class="text-cyan-500" />
            {{ t('settings.marketStructureLoading') }}
          </div>
          <div v-else>
            <!-- Current structures list -->
            <div v-if="marketStructures.length > 0" class="space-y-1.5 mb-3">
              <div
                v-for="struct in marketStructures"
                :key="struct.id"
                class="flex items-center justify-between bg-cyan-900/20 border border-cyan-700/50 rounded-lg px-3 py-2"
              >
                <div class="flex items-center gap-2 min-w-0">
                  <svg class="w-4 h-4 text-cyan-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                  </svg>
                  <span class="text-sm text-cyan-300 truncate">{{ struct.name }}</span>
                </div>
                <button
                  @click="removeMarketStructure(struct.id)"
                  class="p-1 text-slate-400 hover:text-red-400 hover:bg-slate-700 rounded-sm shrink-0 ml-2"
                  :title="t('common.actions.delete')"
                  :aria-label="t('common.actions.delete')"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
            </div>

            <!-- Search to add new structure -->
            <div class="relative">
              <input
                v-model="structureSearchQuery"
                type="text"
                :aria-label="t('industry.structures.searchStructure')"
                :placeholder="t('industry.structures.searchStructure')"
                @input="onStructureSearchInput"
                @focus="showStructureSearchDropdown = structureSearchResults.length > 0"
                @blur="hideStructureDropdownDelayed"
                class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-100 text-sm focus:outline-none focus:border-cyan-500/50 disabled:opacity-50"
                :disabled="savingStructure"
              />
              <div v-if="isSearchingStructures" class="absolute right-3 top-1/2 -translate-y-1/2">
                <LoadingSpinner size="sm" class="text-slate-400" />
              </div>

              <!-- Search results dropdown -->
              <div
                v-if="showStructureSearchDropdown && structureSearchResults.length > 0"
                class="absolute z-10 mt-1 w-full bg-slate-800 border border-slate-600 rounded-lg shadow-lg max-h-48 overflow-y-auto"
              >
                <button
                  v-for="result in structureSearchResults"
                  :key="result.locationId"
                  @mousedown.prevent="selectStructureSearchResult(result)"
                  class="w-full px-3 py-2 hover:bg-slate-700 text-left"
                >
                  <div class="text-sm text-slate-200">{{ result.locationName }}</div>
                  <div v-if="result.solarSystemName" class="text-xs text-slate-500">{{ result.solarSystemName }}</div>
                </button>
              </div>
            </div>
          </div>
          <Transition name="fade">
            <p v-if="structureSaveSuccess" class="mt-2 text-xs text-emerald-400">
              {{ t('settings.marketStructureSaved') }}
            </p>
          </Transition>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 border-t border-slate-800 flex justify-end shrink-0">
          <button
            @click="showSettings = false"
            class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium transition-colors"
          >
            {{ t('common.actions.close') }}
          </button>
        </div>
      </BaseModal>

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
              @open-settings="showSettings = true"
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
