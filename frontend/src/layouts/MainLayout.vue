<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useRateLimitStore } from '@/stores/rateLimit'
import { useAdminStore } from '@/stores/admin'
import { useFormatters } from '@/composables/useFormatters'
import { apiRequest } from '@/services/api'
import { useStructuresStore } from '@/stores/industry/structures'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

const router = useRouter()
const { t, locale } = useI18n()
const authStore = useAuthStore()
const rateLimitStore = useRateLimitStore()
const adminStore = useAdminStore()
const { dateFormat, setDateFormat } = useFormatters()
const industryStructuresStore = useStructuresStore()

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

const DEFAULT_HIDDEN_MODULES: string[] = []

// Navigation types
interface NavItem {
  id: string
  labelKey: string
  icon: string
  route: string
}

interface NavGroup {
  id: string
  labelKey: string
  icon: string
  collapsible: true
  children: NavItem[]
}

type NavEntry = NavItem | NavGroup

function isNavGroup(entry: NavEntry): entry is NavGroup {
  return 'collapsible' in entry && entry.collapsible === true
}

const allNavEntries: NavEntry[] = [
  { id: 'dashboard', labelKey: 'nav.dashboard', route: '/dashboard', icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6' },
  { id: 'ledger', labelKey: 'nav.revenue', route: '/ledger', icon: 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6' },
  {
    id: 'production',
    labelKey: 'nav.production',
    icon: 'M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z',
    collapsible: true,
    children: [
      { id: 'industry', labelKey: 'nav.industry', route: '/industry', icon: 'M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z' },
      { id: 'group-industry', labelKey: 'nav.groupIndustry', route: '/group-industry', icon: 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z' },
      { id: 'appraisal', labelKey: 'nav.appraisal', route: '/appraisal', icon: 'M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z' },
      { id: 'planetary', labelKey: 'nav.pi', route: '/planetary', icon: 'M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9' },
    ]
  },
  { id: 'market', labelKey: 'nav.market', route: '/market', icon: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z' },
  { id: 'assets', labelKey: 'nav.inventory', route: '/assets', icon: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4' },
]

const adminNavItem: NavItem = {
  id: 'admin', labelKey: 'nav.admin', route: '/admin', icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'
}

// Flat list of all settable module IDs (for settings modal)
const settableModules: NavItem[] = [
  { id: 'dashboard', labelKey: 'nav.dashboard', route: '/dashboard', icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6' },
  { id: 'ledger', labelKey: 'nav.revenue', route: '/ledger', icon: 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6' },
  { id: 'industry', labelKey: 'nav.industry', route: '/industry', icon: 'M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z' },
  { id: 'group-industry', labelKey: 'nav.groupIndustry', route: '/group-industry', icon: 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z' },
  { id: 'appraisal', labelKey: 'nav.appraisal', route: '/appraisal', icon: 'M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z' },
  { id: 'planetary', labelKey: 'nav.pi', route: '/planetary', icon: 'M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9' },
  { id: 'market', labelKey: 'nav.market', route: '/market', icon: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z' },
  { id: 'assets', labelKey: 'nav.inventory', route: '/assets', icon: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4' },
]

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
  const hasActiveChild = group.children.some(child => currentPath === child.route)
  if (hasActiveChild) return true
  // Otherwise use stored state (default expanded)
  return !collapsedGroups.value[group.id]
}

// Load settings from localStorage
onMounted(() => {
  loadCollapsedGroups()
  const saved = localStorage.getItem('evetools_hidden_modules')
  if (saved) {
    try {
      hiddenModules.value = JSON.parse(saved)
    } catch {
      hiddenModules.value = [...DEFAULT_HIDDEN_MODULES]
    }
  } else {
    hiddenModules.value = [...DEFAULT_HIDDEN_MODULES]
  }
})

// Save settings to localStorage
function saveSettings(): void {
  localStorage.setItem('evetools_hidden_modules', JSON.stringify(hiddenModules.value))
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

function navigateTo(item: NavItem): void {
  router.push(item.route)
}

function isActiveRoute(item: NavItem): boolean {
  return router.currentRoute.value.path === item.route
}

// Current page title - search all entries including group children
const currentPageTitle = computed(() => {
  const currentPath = router.currentRoute.value.path
  for (const entry of allNavEntries) {
    if (isNavGroup(entry)) {
      const child = entry.children.find(c => currentPath === c.route)
      if (child) return t(child.labelKey)
    } else {
      if (currentPath === entry.route) return t(entry.labelKey)
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
    <!-- Animated background -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
      <div class="absolute -top-1/2 -left-1/2 w-full h-full bg-gradient-radial from-cyan-900/20 via-transparent to-transparent animate-pulse-slow"></div>
      <div class="absolute -bottom-1/2 -right-1/2 w-full h-full bg-gradient-radial from-indigo-900/15 via-transparent to-transparent animate-pulse-slower"></div>
      <div class="absolute inset-0 bg-[linear-gradient(rgba(6,182,212,0.03)_1px,transparent_1px),linear-gradient(90deg,rgba(6,182,212,0.03)_1px,transparent_1px)] bg-size-[50px_50px]"></div>
    </div>

    <!-- Rate Limit Banner -->
    <Transition name="slide-down">
      <div
        v-if="rateLimitStore.isRateLimited"
        class="fixed top-0 left-0 right-0 z-50 bg-amber-600 text-white px-4 py-2 text-center text-sm font-medium shadow-lg"
      >
        <div class="flex items-center justify-center gap-2">
          <svg class="w-5 h-5 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
          <span>{{ t('header.rateLimitBanner', { seconds: rateLimitStore.remainingSeconds }) }}</span>
        </div>
      </div>
    </Transition>

    <div class="relative flex h-screen" :class="{ 'pt-10': rateLimitStore.isRateLimited }">
      <!-- Sidebar -->
      <aside class="w-64 bg-slate-900 border-r border-cyan-500/20 flex flex-col">
        <!-- Logo -->
        <div class="p-6 border-b border-cyan-500/20">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-linear-to-br from-cyan-500 to-blue-600 flex items-center justify-center shadow-lg shadow-cyan-500/30">
              <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
              </svg>
            </div>
            <div>
              <h1 class="text-lg font-bold tracking-tight text-transparent bg-clip-text bg-linear-to-r from-cyan-400 to-blue-400">EVE Tools</h1>
              <p class="text-xs text-slate-500 tracking-widest uppercase">Utilities</p>
            </div>
          </div>
        </div>

        <!-- Navigation -->
        <nav class="flex-1 p-4 space-y-1 overflow-y-auto min-h-0">
          <template v-for="entry in filteredNavEntries" :key="entry.id">
            <!-- Collapsible group -->
            <div v-if="'collapsible' in entry && entry.collapsible">
              <!-- Group header -->
              <button
                @click="toggleGroup(entry.id)"
                class="w-full flex items-center gap-3 px-4 py-3 rounded-lg transition-all duration-300 group relative overflow-hidden border text-slate-400 border-transparent hover:bg-slate-800/50 hover:text-slate-200 hover:border-cyan-500/30 hover:shadow-lg hover:shadow-cyan-500/5"
              >
                <div class="absolute inset-0 bg-linear-to-r from-transparent via-cyan-500/10 to-transparent -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-in-out"></div>
                <svg class="w-5 h-5 relative" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="entry.icon"/>
                </svg>
                <span class="font-medium relative">{{ t(entry.labelKey) }}</span>
                <svg
                  class="w-4 h-4 ml-auto relative transition-transform duration-200"
                  :class="{ 'rotate-90': isGroupExpanded(entry as NavGroup) }"
                  fill="none" stroke="currentColor" viewBox="0 0 24 24"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
              </button>
              <!-- Group children -->
              <div
                v-if="isGroupExpanded(entry as NavGroup)"
                class="mt-1 space-y-0.5 pl-4"
              >
                <button
                  v-for="child in (entry as NavGroup).children"
                  :key="child.id"
                  @click="navigateTo(child)"
                  :class="[
                    'w-full flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all duration-300 group relative overflow-hidden border text-sm',
                    isActiveRoute(child)
                      ? 'bg-linear-to-r from-cyan-500/20 to-blue-500/10 text-cyan-400 shadow-lg shadow-cyan-500/10 border-cyan-500/30'
                      : 'text-slate-400 border-transparent hover:bg-slate-800/50 hover:text-slate-200 hover:border-cyan-500/30 hover:shadow-lg hover:shadow-cyan-500/5 hover:-translate-y-0.5'
                  ]"
                >
                  <div class="absolute inset-0 bg-linear-to-r from-transparent via-cyan-500/10 to-transparent -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-in-out"></div>
                  <svg class="w-4 h-4 relative" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="child.icon"/>
                  </svg>
                  <span class="font-medium relative">{{ t(child.labelKey) }}</span>
                  <div v-if="isActiveRoute(child)" class="ml-auto w-1.5 h-1.5 rounded-full bg-cyan-400 animate-pulse relative"></div>
                </button>
              </div>
            </div>

            <!-- Simple nav item -->
            <button
              v-else
              @click="navigateTo(entry as NavItem)"
              :class="[
                'w-full flex items-center gap-3 px-4 py-3 rounded-lg transition-all duration-300 group relative overflow-hidden border',
                isActiveRoute(entry as NavItem)
                  ? 'bg-linear-to-r from-cyan-500/20 to-blue-500/10 text-cyan-400 shadow-lg shadow-cyan-500/10 border-cyan-500/30'
                  : 'text-slate-400 border-transparent hover:bg-slate-800/50 hover:text-slate-200 hover:border-cyan-500/30 hover:shadow-lg hover:shadow-cyan-500/5 hover:-translate-y-0.5'
              ]"
            >
              <div class="absolute inset-0 bg-linear-to-r from-transparent via-cyan-500/10 to-transparent -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-in-out"></div>
              <svg class="w-5 h-5 relative" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="(entry as NavItem).icon"/>
              </svg>
              <span class="font-medium relative">{{ t((entry as NavItem).labelKey) }}</span>
              <div v-if="isActiveRoute(entry as NavItem)" class="ml-auto w-1.5 h-1.5 rounded-full bg-cyan-400 animate-pulse relative"></div>
            </button>
          </template>
        </nav>

        <!-- User card -->
        <div class="p-4 border-t border-cyan-500/20">
          <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
            <!-- Clickable user info -->
            <div
              @click="showSettings = true"
              class="flex items-center gap-3 cursor-pointer hover:opacity-80 transition-opacity"
              :title="t('auth.openSettings')"
            >
              <div class="relative">
                <img
                  v-if="mainCharacter"
                  :src="`https://images.evetech.net/characters/${mainCharacter.eveCharacterId}/portrait?size=64`"
                  :alt="mainCharacter.name"
                  class="w-12 h-12 rounded-lg ring-2 ring-cyan-500/50"
                />
                <div v-else class="w-12 h-12 rounded-lg ring-2 ring-cyan-500/50 bg-slate-700 animate-pulse"></div>
                <div class="absolute -bottom-1 -right-1 w-4 h-4 bg-emerald-500 rounded-full border-2 border-slate-800"></div>
              </div>
              <div class="flex-1 min-w-0">
                <p class="font-semibold text-slate-100 truncate">{{ mainCharacter?.name || t('common.actions.loading') }}</p>
                <p class="text-xs text-cyan-400 truncate">{{ mainCharacter?.corporationName || '' }}</p>
              </div>
              <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
              </svg>
            </div>
            <div class="mt-4 flex flex-col gap-1.5">
              <!-- Row 1: Characters button (full width) -->
              <button
                @click="router.push('/characters')"
                class="w-full py-2 px-3 bg-slate-700/50 hover:bg-cyan-500/20 rounded-lg text-sm text-slate-400 hover:text-cyan-400 transition-colors flex items-center justify-center gap-2"
              >
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                <span class="truncate">{{ t('nav.characters') }}</span>
              </button>
              <!-- Row 2: Logout + Version -->
              <div class="flex items-center gap-1.5">
                <button
                  @click="logout"
                  :disabled="isLoggingOut"
                  class="flex-1 min-w-0 py-2 px-3 bg-slate-700/50 hover:bg-red-500/20 rounded-lg text-sm text-slate-400 hover:text-red-400 transition-colors flex items-center justify-center gap-2 group disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  <svg v-if="isLoggingOut" class="w-4 h-4 shrink-0 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                  </svg>
                  <svg v-else class="w-4 h-4 shrink-0 group-hover:translate-x-0.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                  </svg>
                  <span class="truncate">{{ isLoggingOut ? t('auth.loggingOut') : t('auth.logout') }}</span>
                </button>
                <button
                  @click="openReleaseNotes"
                  class="shrink-0 p-2 bg-slate-700/50 hover:bg-cyan-500/20 rounded-lg text-xs text-slate-500 hover:text-cyan-400 transition-colors font-mono"
                  :title="t('header.viewReleaseNotes')"
                >
                  v{{ APP_VERSION }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </aside>

      <!-- Settings Modal -->
      <Teleport to="body">
        <div
          v-if="showSettings"
          class="fixed inset-0 z-50 flex items-center justify-center"
        >
          <!-- Backdrop -->
          <div
            class="absolute inset-0 bg-slate-950/80"
            @click="showSettings = false"
          ></div>

          <!-- Modal -->
          <div class="relative bg-slate-900 rounded-2xl border border-cyan-500/30 shadow-2xl shadow-cyan-500/10 w-full max-w-md mx-4 max-h-[85vh] flex flex-col">
            <!-- Header -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800 shrink-0">
              <h3 class="text-lg font-semibold text-slate-100">{{ t('settings.title') }}</h3>
              <button
                @click="showSettings = false"
                class="p-1 hover:bg-slate-800 rounded-lg transition-colors"
              >
                <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

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
          </div>
        </div>
      </Teleport>

      <!-- Release Notes Modal -->
      <Teleport to="body">
        <div
          v-if="showReleaseNotes"
          class="fixed inset-0 z-50 flex items-center justify-center"
        >
          <!-- Backdrop -->
          <div
            class="absolute inset-0 bg-slate-950/80"
            @click="showReleaseNotes = false"
          ></div>

          <!-- Modal -->
          <div class="relative bg-slate-900 rounded-2xl border border-cyan-500/30 shadow-2xl shadow-cyan-500/10 w-full max-w-2xl mx-4 max-h-[80vh] flex flex-col">
            <!-- Header -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
              <h3 class="text-lg font-semibold text-slate-100">{{ t('header.releaseNotes') }}</h3>
              <button
                @click="showReleaseNotes = false"
                class="p-1 hover:bg-slate-800 rounded-lg transition-colors"
              >
                <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

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
          </div>
        </div>
      </Teleport>

      <!-- Main content -->
      <main class="flex-1 overflow-auto">
        <!-- Header -->
        <header class="sticky top-0 z-10 bg-slate-900 border-b border-cyan-500/10 px-8 py-4">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="text-2xl font-bold text-slate-100">{{ currentPageTitle }}</h2>
              <p class="text-sm text-slate-500 mt-1">{{ t('header.welcomeBack') }}</p>
            </div>
            <div class="flex items-center gap-4">
              <!-- Notification Bell -->
              <NotificationBell />
              <!-- ESI Status -->
              <div
                :class="[
                  'flex items-center gap-2 px-3 py-1.5 rounded-lg border text-xs',
                  esiError
                    ? 'bg-red-500/10 border-red-500/30'
                    : esiRateLimited
                      ? 'bg-amber-500/10 border-amber-500/30'
                      : 'bg-emerald-500/10 border-emerald-500/30'
                ]"
                :title="esiError ? t('header.esiUnavailable') : esiRateLimited ? t('header.esiRateLimitedTitle') : t('header.esiOkTitle', { players: esiStatus?.players?.toLocaleString() || '0' })"
              >
                <div
                  :class="[
                    'w-2 h-2 rounded-full',
                    esiError ? 'bg-red-500' : esiRateLimited ? 'bg-amber-500 animate-pulse' : 'bg-emerald-500 animate-pulse'
                  ]"
                ></div>
                <span :class="esiError ? 'text-red-400' : esiRateLimited ? 'text-amber-400' : 'text-emerald-400'">
                  ESI {{ esiError ? 'DOWN' : esiRateLimited ? 'RATE LIMITED' : 'OK' }}
                </span>
                <span v-if="esiStatus?.players && !esiError && !esiRateLimited" class="text-slate-500">
                  {{ (esiStatus.players / 1000).toFixed(1) }}k
                </span>
              </div>
              <!-- Alliance badge -->
              <div v-if="mainCharacter?.allianceId" class="flex items-center gap-2 px-4 py-2 bg-amber-500/10 rounded-lg border border-amber-500/30">
                <img
                  :src="`https://images.evetech.net/alliances/${mainCharacter.allianceId}/logo?size=32`"
                  class="w-6 h-6"
                />
                <span class="text-amber-400 text-sm font-medium">{{ mainCharacter.allianceName }}</span>
              </div>
              <!-- Corporation badge -->
              <div v-if="mainCharacter?.corporationId" class="flex items-center gap-2 px-4 py-2 bg-cyan-500/10 rounded-lg border border-cyan-500/30">
                <img
                  :src="`https://images.evetech.net/corporations/${mainCharacter.corporationId}/logo?size=32`"
                  class="w-6 h-6"
                />
                <span class="text-cyan-400 text-sm font-medium">{{ mainCharacter.corporationName }}</span>
              </div>
            </div>
          </div>
        </header>

        <!-- Page content -->
        <div class="p-8">
          <div class="max-w-7xl mx-auto">
            <slot />
          </div>
        </div>

        <!-- Legal footer -->
        <LegalFooter />
      </main>
    </div>
  </div>
</template>

<style>
.bg-gradient-radial {
  background: radial-gradient(circle, var(--tw-gradient-from) 0%, var(--tw-gradient-via) 50%, var(--tw-gradient-to) 100%);
}

@keyframes pulse-slow {
  0%, 100% { opacity: 0.3; transform: scale(1); }
  50% { opacity: 0.5; transform: scale(1.1); }
}

@keyframes pulse-slower {
  0%, 100% { opacity: 0.2; transform: scale(1); }
  50% { opacity: 0.4; transform: scale(1.15); }
}

.animate-pulse-slow {
  animation: pulse-slow 8s ease-in-out infinite;
}

.animate-pulse-slower {
  animation: pulse-slower 12s ease-in-out infinite;
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

/* Rate limit banner transition */
.slide-down-enter-active,
.slide-down-leave-active {
  transition: transform 0.3s ease, opacity 0.3s ease;
}

.slide-down-enter-from,
.slide-down-leave-to {
  transform: translateY(-100%);
  opacity: 0;
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
