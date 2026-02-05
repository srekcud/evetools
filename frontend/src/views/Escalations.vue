<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useEscalationStore, type Escalation, type CreateEscalationInput } from '@/stores/escalation'
import { useAuthStore } from '@/stores/auth'
import MainLayout from '@/layouts/MainLayout.vue'

const escalationStore = useEscalationStore()
const authStore = useAuthStore()

// ========== Constants ==========

const TOTAL_HOURS = 72

const ESCALATION_SITES: Record<string, { level: string; name: string }[]> = {
  'Angel Cartel': [
    { level: '3', name: 'Angel Repurposed Outpost' },
    { level: '4', name: 'Angel Cartel Occupied Mining Colony' },
    { level: '5', name: "Angel's Red Light District" },
    { level: '6', name: 'Angel Mineral Acquisition Outpost' },
    { level: '7', name: 'Angel Military Operations Complex' },
    { level: '8', name: 'Cartel Prisoner Retention' },
    { level: '10', name: 'Angel Cartel Naval Shipyard' },
  ],
  'Blood Raiders': [
    { level: '3', name: 'Blood Raider Intelligence Collection Point' },
    { level: '4', name: 'Mul-Zatah Monastery' },
    { level: '5', name: 'Blood Raider Psychotropics Depot' },
    { level: '6', name: 'Crimson Hand Supply Depot' },
    { level: '7', name: 'Blood Raider Coordination Center' },
    { level: '8', name: 'Blood Raider Prison Camp' },
    { level: '10', name: 'Blood Raider Naval Shipyard' },
  ],
  'Guristas': [
    { level: '3', name: 'Guristas Guerilla Grounds' },
    { level: '4', name: 'Guristas Scout Outpost' },
    { level: '5', name: 'Guristas Hallucinogen Supply Waypoint' },
    { level: '6', name: 'Guristas Troop Reinvigoration Camp' },
    { level: '7', name: 'Gurista Military Operations Complex' },
    { level: '8', name: "Pith's Penal Complex" },
    { level: '10', name: 'The Maze' },
  ],
  "Sansha's Nation": [
    { level: '3', name: "Sansha's Command Relay Outpost" },
    { level: '4', name: "Sansha's Nation Occupied Mining Colony" },
    { level: '5', name: "Sansha's Nation Neural Paralytic Facility" },
    { level: '6', name: 'Sansha War Supply Complex' },
    { level: '7', name: 'Sansha Military Operations Complex' },
    { level: '8', name: 'Sansha Prison Camp' },
    { level: '10', name: 'Centus Assembly T.P. Co.' },
  ],
  'Serpentis': [
    { level: '3', name: 'Serpentis Narcotic Warehouses' },
    { level: '4', name: 'Serpentis Phi-Outpost' },
    { level: '5', name: 'Serpentis Corporation Hydroponics Site' },
    { level: '6', name: 'Serpentis Logistical Outpost' },
    { level: '7', name: 'Serpentis Paramilitary Complex' },
    { level: '8', name: 'Serpentis Prison Camp' },
    { level: '10', name: 'Serpentis Fleet Shipyard' },
  ],
  'Rogue Drones': [
    { level: '3', name: 'Rogue Drone Asteroid Infestation' },
    { level: '5', name: 'Outgrowth Rogue Drone Hive' },
    { level: '10', name: 'Outgrowth Rogue Drone Hive' },
  ],
}

const SUGGESTED_PRICES: Record<string, number[]> = {
  '3': [10, 15, 20, 30],
  '4': [15, 25, 35, 50],
  '5': [20, 30, 50, 80],
  '6': [40, 60, 80, 100],
  '7': [50, 70, 90, 120],
  '8': [60, 80, 100, 130],
  '10': [100, 150, 200, 300],
}

// ========== Filter State ==========

const statusFilter = ref<'active' | 'all'>('active')
const visibilityFilter = ref<'all' | 'perso' | 'corp' | 'public'>('all')
const characterFilter = ref<number | null>(null)
// (no tabs - filter bar only)

// ========== Timer State ==========

const now = ref(Date.now())
let timerInterval: ReturnType<typeof setInterval> | null = null

// ========== Modal State ==========

const showModal = ref(false)
const isSubmitting = ref(false)
const showCharDropdown = ref(false)
const showTypeDropdown = ref(false)
const showSystemDropdown = ref(false)
const showDeleteModal = ref(false)
const deleteTarget = ref<Escalation | null>(null)
const isDeleting = ref(false)

// Form fields
const formCharacterId = ref<number | null>(null)
const formCharacterName = ref('')
const formType = ref('')
const formSystemSearch = ref('')
const formSystemId = ref<number | null>(null)
const formSystemName = ref('')
const formSystemSec = ref(0)
const formSystemRegion = ref('')
const formTimerDays = ref(2)
const formTimerHours = ref(23)
const formTimerMinutes = ref(0)
const formPrice = ref(150)
const formNotes = ref('')

// ========== Toast State ==========

interface Toast {
  id: number
  message: string
  type: 'success' | 'warning' | 'error'
  visible: boolean
}

const toasts = ref<Toast[]>([])
let toastCounter = 0

// ========== Visibility Popover State ==========

const activeVisPopoverId = ref<string | null>(null)

// ========== Characters ==========

const characters = computed(() => authStore.user?.characters ?? [])

// ========== Filtered Escalations ==========

const filteredEscalations = computed(() => {
  let list = [...escalationStore.escalations]

  // Status filter
  if (statusFilter.value === 'active') {
    list = list.filter(e => {
      const expired = new Date(e.expiresAt).getTime() <= now.value
      return e.saleStatus !== 'vendu' && !expired
    })
  }

  // Visibility filter
  if (visibilityFilter.value !== 'all') {
    list = list.filter(e => e.visibility === visibilityFilter.value)
  }

  // Character filter
  if (characterFilter.value !== null) {
    list = list.filter(e => e.characterId === characterFilter.value)
  }

  // Sort: expired at bottom, then by expiry ascending (most urgent first)
  list.sort((a, b) => {
    const aExpired = new Date(a.expiresAt).getTime() <= now.value || a.saleStatus === 'vendu'
    const bExpired = new Date(b.expiresAt).getTime() <= now.value || b.saleStatus === 'vendu'
    if (aExpired !== bExpired) return aExpired ? 1 : -1
    return new Date(a.expiresAt).getTime() - new Date(b.expiresAt).getTime()
  })

  return list
})

// ========== KPI Computed ==========

const kpiTotal = computed(() => filteredEscalations.value.length)

const kpiNewBm = computed(() => {
  const nouveau = filteredEscalations.value.filter(e => e.bmStatus === 'nouveau').length
  const bm = filteredEscalations.value.filter(e => e.bmStatus === 'bm').length
  return `${nouveau} / ${bm}`
})

const kpiEnvente = computed(() => {
  const list = filteredEscalations.value.filter(e => e.saleStatus === 'envente')
  return {
    count: list.length,
    total: list.reduce((sum, e) => sum + e.price, 0),
  }
})

const kpiVendu = computed(() => {
  const list = filteredEscalations.value.filter(e => e.saleStatus === 'vendu')
  return {
    count: list.length,
    total: list.reduce((sum, e) => sum + e.price, 0),
  }
})

// ========== Timer Helpers ==========

function getTimerInfo(expiresAt: string) {
  const remaining = new Date(expiresAt).getTime() - now.value
  if (remaining <= 0) {
    return { text: 'Expire', percent: 0, zone: 'expired' as const }
  }

  const totalSeconds = Math.floor(remaining / 1000)
  const days = Math.floor(totalSeconds / 86400)
  const hours = Math.floor((totalSeconds % 86400) / 3600)
  const minutes = Math.floor((totalSeconds % 3600) / 60)
  const seconds = totalSeconds % 60

  const pad = (n: number) => String(n).padStart(2, '0')
  const text = days > 0
    ? `${days}j ${pad(hours)}h ${pad(minutes)}m`
    : `${pad(hours)}h ${pad(minutes)}m ${pad(seconds)}s`

  const percent = Math.max(0, Math.min(100, (remaining / (TOTAL_HOURS * 3600000)) * 100))
  const zone = percent > 50 ? 'safe' : percent > 20 ? 'warning' : 'danger'

  return { text, percent, zone: zone as 'safe' | 'warning' | 'danger' }
}

function timerBarColor(zone: string) {
  if (zone === 'safe') return 'bg-cyan-500'
  if (zone === 'warning') return 'bg-amber-500'
  return 'bg-red-500'
}

function timerTextColor(zone: string) {
  if (zone === 'safe') return 'text-cyan-400'
  if (zone === 'warning') return 'text-amber-400'
  return 'text-red-400'
}

// ========== Security Status Helpers ==========

function secStatusColor(sec: number) {
  if (sec >= 0.9) return 'text-emerald-400'
  if (sec >= 0.5) return 'text-green-400'
  if (sec > 0.0) return 'text-amber-400'
  return 'text-red-400'
}

function secBadgeClasses(sec: number) {
  if (sec >= 0.9) return 'bg-emerald-500/20 text-emerald-400'
  if (sec >= 0.5) return 'bg-green-500/20 text-green-400'
  if (sec > 0.0) return 'bg-amber-500/20 text-amber-400'
  return 'bg-red-500/20 text-red-400'
}

// ========== Visibility Helpers ==========

function visibilityBadgeClasses(vis: string) {
  if (vis === 'perso') return 'bg-slate-500/20 text-slate-400 hover:ring-slate-400/30'
  if (vis === 'corp') return 'bg-indigo-500/20 text-indigo-400 hover:ring-indigo-400/30'
  return 'bg-purple-500/20 text-purple-400 hover:ring-purple-400/30'
}

function visibilityLabel(vis: string) {
  if (vis === 'corp') return 'Corp'
  if (vis === 'public') return 'Public'
  return ''
}

function visibilityTitle(vis: string) {
  if (vis === 'perso') return 'Perso'
  if (vis === 'corp') return 'Corp'
  return 'Public'
}

function visibilityDescription(vis: string) {
  if (vis === 'perso') return 'Visible que par moi'
  if (vis === 'corp') return 'Membres de la corp'
  return 'Visible par tous'
}

// ========== Price Display ==========

function priceColor(escalation: Escalation) {
  const timer = getTimerInfo(escalation.expiresAt)
  if (escalation.saleStatus === 'vendu') return 'text-emerald-400'
  if (timer.zone === 'danger') return 'text-red-400'
  if (timer.zone === 'warning') return 'text-amber-400'
  return 'text-slate-200'
}

function priceSubColor(escalation: Escalation) {
  const timer = getTimerInfo(escalation.expiresAt)
  if (escalation.saleStatus === 'vendu') return 'text-emerald-500/60'
  if (timer.zone === 'danger') return 'text-red-500/60'
  if (timer.zone === 'warning') return 'text-amber-500/60'
  return 'text-slate-500'
}

// ========== Share System ==========

function shouldShowShareButtons(escalation: Escalation) {
  return (
    escalation.visibility !== 'perso' &&
    escalation.saleStatus === 'envente'
  )
}

function shareWts(escalation: Escalation) {
  if (escalation.bmStatus === 'nouveau') {
    showToast('Impossible de partager : cette escalation n\'est pas encore bookmarkee.', 'warning')
    return
  }

  const remaining = new Date(escalation.expiresAt).getTime() - now.value
  const hours = Math.max(0, Math.round(remaining / 3600000))

  const text = `WTS ${escalation.type} <url=showinfo:5//${escalation.solarSystemId}>${escalation.solarSystemName}</url> ${hours}h ${escalation.price}m`
  navigator.clipboard.writeText(text).then(() => {
    showToast('Message WTS copie dans le presse-papier', 'success')
  }).catch(() => {
    showToast('Erreur lors de la copie', 'error')
  })
}

function shareDiscord(escalation: Escalation) {
  if (escalation.bmStatus === 'nouveau') {
    showToast('Impossible de partager : cette escalation n\'est pas encore bookmarkee.', 'warning')
    return
  }

  const expiresUnix = Math.floor(new Date(escalation.expiresAt).getTime() / 1000)

  const text = [
    `**WTS ${escalation.type}**`,
    `> Systeme : **${escalation.solarSystemName}** (${escalation.securityStatus.toFixed(1)}) | Expire <t:${expiresUnix}:R> | Prix : **${escalation.price}M ISK**`,
    `> Contact : ${escalation.characterName}`,
    `Partagé avec [Evetools](<https://evetools.srekcud.be/escalations>)`,
  ].join('\n')

  navigator.clipboard.writeText(text).then(() => {
    showToast('Message Discord copie dans le presse-papier', 'success')
  }).catch(() => {
    showToast('Erreur lors de la copie', 'error')
  })
}

// ========== Toast System ==========

function showToast(message: string, type: 'success' | 'warning' | 'error' = 'success') {
  const id = ++toastCounter
  const toast: Toast = { id, message, type, visible: false }
  toasts.value.push(toast)

  // Animate in
  requestAnimationFrame(() => {
    const t = toasts.value.find(t => t.id === id)
    if (t) t.visible = true
  })

  // Auto dismiss
  setTimeout(() => {
    const t = toasts.value.find(t => t.id === id)
    if (t) t.visible = false
    setTimeout(() => {
      toasts.value = toasts.value.filter(t => t.id !== id)
    }, 300)
  }, 3500)
}

// ========== Actions ==========

async function toggleBmStatus(escalation: Escalation) {
  const newStatus = escalation.bmStatus === 'nouveau' ? 'bm' : 'nouveau'
  try {
    await escalationStore.updateEscalation(escalation.id, { bmStatus: newStatus })
  } catch {
    showToast('Erreur lors de la mise a jour du statut BM', 'error')
  }
}

async function toggleSaleStatus(escalation: Escalation) {
  const newStatus = escalation.saleStatus === 'envente' ? 'vendu' : 'envente'
  try {
    await escalationStore.updateEscalation(escalation.id, { saleStatus: newStatus })
  } catch {
    showToast('Erreur lors de la mise a jour du statut de vente', 'error')
  }
}

async function changeVisibility(escalation: Escalation, newVis: 'perso' | 'corp' | 'public') {
  activeVisPopoverId.value = null
  try {
    await escalationStore.updateEscalation(escalation.id, { visibility: newVis })
  } catch {
    showToast('Erreur lors du changement de visibilite', 'error')
  }
}

function askDeleteEscalation(escalation: Escalation) {
  deleteTarget.value = escalation
  showDeleteModal.value = true
}

async function confirmDelete() {
  if (!deleteTarget.value || isDeleting.value) return
  isDeleting.value = true
  try {
    await escalationStore.deleteEscalation(deleteTarget.value.id)
    showToast('Escalation supprimee', 'success')
  } catch {
    showToast('Erreur lors de la suppression', 'error')
  } finally {
    isDeleting.value = false
    showDeleteModal.value = false
    deleteTarget.value = null
  }
}

function cancelDelete() {
  showDeleteModal.value = false
  deleteTarget.value = null
}

function toggleVisPopover(id: string, event: Event) {
  event.stopPropagation()
  activeVisPopoverId.value = activeVisPopoverId.value === id ? null : id
}

// Close popover on outside click
function handleDocumentClick() {
  activeVisPopoverId.value = null
  showTypeDropdown.value = false
  showCharDropdown.value = false
}

// ========== Modal Helpers ==========

function openModal() {
  // Initialize with first character
  if (characters.value.length > 0 && !formCharacterId.value) {
    formCharacterId.value = characters.value[0].eveCharacterId
    formCharacterName.value = characters.value[0].name
  }
  showModal.value = true
}

function closeModal() {
  showModal.value = false
  showCharDropdown.value = false
  showTypeDropdown.value = false
  showSystemDropdown.value = false
}

function selectFormType(value: string) {
  formType.value = value
  showTypeDropdown.value = false
}

function selectFormCharacter(charId: number, charName: string) {
  formCharacterId.value = charId
  formCharacterName.value = charName
  showCharDropdown.value = false
}

function getCharInitials(name: string) {
  return name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase()
}

// Escalation type options
const escalationOptions = computed(() => {
  return Object.entries(ESCALATION_SITES).map(([faction, sites]) => ({
    label: faction,
    options: sites.map(site => ({
      value: `${site.name} (${site.level}/10)`,
      label: `${site.name} (${site.level}/10)`,
    })),
  }))
})

// Selected level from form type
const selectedLevel = computed(() => {
  if (!formType.value) return null
  const match = formType.value.match(/\((\d+)\/10\)$/)
  return match ? match[1] : null
})

// Suggested prices for current selection
const currentSuggestedPrices = computed(() => {
  if (!selectedLevel.value) return [50, 100, 150, 200]
  return SUGGESTED_PRICES[selectedLevel.value] ?? [50, 100, 150, 200]
})

// Timer preview
const formTotalHours = computed(() => {
  return formTimerDays.value * 24 + formTimerHours.value + formTimerMinutes.value / 60
})

const formTimerPercent = computed(() => {
  return Math.min(100, (formTotalHours.value / 72) * 100)
})

const formTimerPreviewText = computed(() => {
  const h = Math.round(formTotalHours.value)
  return h >= 24 ? `~${Math.floor(h / 24)}j ${h % 24}h` : `~${h}h`
})

const formTimerZone = computed(() => {
  if (formTimerPercent.value > 50) return 'safe'
  if (formTimerPercent.value > 20) return 'warning'
  return 'danger'
})

// Clear system
function clearFormSystem() {
  formSystemId.value = null
  formSystemName.value = ''
  formSystemSec.value = 0
  formSystemRegion.value = ''
  formSystemSearch.value = ''
}

// Set price from suggestion
function setFormPrice(val: number) {
  formPrice.value = val
}

// Update suggestions when type changes
watch(() => formType.value, () => {
  if (selectedLevel.value && SUGGESTED_PRICES[selectedLevel.value]) {
    formPrice.value = SUGGESTED_PRICES[selectedLevel.value][1] ?? 100
  }
})

// Validate form
const isFormValid = computed(() => {
  return (
    formCharacterId.value !== null &&
    formType.value !== '' &&
    formSystemId.value !== null &&
    formTotalHours.value > 0 &&
    formPrice.value >= 0
  )
})

// Preview data
const previewSystem = computed(() => formSystemName.value || '--')
const previewChar = computed(() => formCharacterName.value || '--')
const previewType = computed(() => formType.value || '--')

// Submit form
async function submitEscalation() {
  if (!isFormValid.value || isSubmitting.value) return

  isSubmitting.value = true
  try {
    const input: CreateEscalationInput = {
      characterId: formCharacterId.value!,
      type: formType.value,
      solarSystemId: formSystemId.value!,
      solarSystemName: formSystemName.value,
      securityStatus: formSystemSec.value,
      price: formPrice.value,
      notes: formNotes.value || null,
      timerHours: formTotalHours.value,
    }

    await escalationStore.createEscalation(input)
    showToast('Escalation ajoutee avec succes', 'success')
    closeModal()
    resetForm()
  } catch {
    showToast('Erreur lors de la creation de l\'escalation', 'error')
  } finally {
    isSubmitting.value = false
  }
}

function resetForm() {
  formType.value = ''
  formSystemSearch.value = ''
  formSystemId.value = null
  formSystemName.value = ''
  formSystemSec.value = 0
  formSystemRegion.value = ''
  formTimerDays.value = 2
  formTimerHours.value = 23
  formTimerMinutes.value = 0
  formPrice.value = 150
  formNotes.value = ''
}

// System search - for now just a simple input (will be connected to SDE later)
// Mock data for development
const systemSearchResults = ref<Array<{ name: string; sec: number; region: string; id: number }>>([])
let searchTimeout: ReturnType<typeof setTimeout> | null = null

function onSystemSearch(query: string) {
  formSystemSearch.value = query
  if (searchTimeout) clearTimeout(searchTimeout)

  if (!query || query.length < 2) {
    systemSearchResults.value = []
    showSystemDropdown.value = false
    return
  }

  searchTimeout = setTimeout(async () => {
    try {
      const response = await fetch(`/api/sde/solar-systems?q=${encodeURIComponent(query)}`, {
        headers: {
          'Authorization': `Bearer ${authStore.token}`,
          'Accept': 'application/ld+json',
        },
      })

      if (response.ok) {
        const data = await response.json()
        const items = data['hydra:member'] ?? []
        systemSearchResults.value = items.slice(0, 8).map((s: any) => ({
          name: s.solarSystemName ?? '',
          sec: s.security ?? 0,
          region: s.regionName ?? '',
          id: s.solarSystemId ?? 0,
        }))
        showSystemDropdown.value = systemSearchResults.value.length > 0
      }
    } catch {
      systemSearchResults.value = []
      showSystemDropdown.value = false
    }
  }, 300)
}

function selectSystem(system: { name: string; sec: number; region: string; id: number }) {
  formSystemId.value = system.id
  formSystemName.value = system.name
  formSystemSec.value = system.sec
  formSystemRegion.value = system.region
  formSystemSearch.value = ''
  systemSearchResults.value = []
  showSystemDropdown.value = false
}

// ========== Data Loading ==========

async function loadData() {
  await escalationStore.fetchEscalations()
}

// ========== Keyboard ==========

function handleKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') {
    if (showModal.value) closeModal()
    activeVisPopoverId.value = null
  }
}

// ========== Lifecycle ==========

onMounted(async () => {
  // Start timer
  timerInterval = setInterval(() => {
    now.value = Date.now()
  }, 1000)

  document.addEventListener('click', handleDocumentClick)
  document.addEventListener('keydown', handleKeydown)

  // Init form character
  if (characters.value.length > 0) {
    formCharacterId.value = characters.value[0].eveCharacterId
    formCharacterName.value = characters.value[0].name
  }

  await loadData()
})

onUnmounted(() => {
  if (timerInterval) clearInterval(timerInterval)
  document.removeEventListener('click', handleDocumentClick)
  document.removeEventListener('keydown', handleKeydown)
})
</script>

<template>
  <MainLayout>
    <div class="space-y-6">

      <!-- Header: Filters + Add button -->
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div class="flex items-center gap-3 flex-wrap">
          <!-- Status filter -->
          <div class="flex items-center gap-2 bg-slate-800/50 rounded-lg p-1">
            <button
              class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors"
              :class="statusFilter === 'active' ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700/50'"
              @click="statusFilter = 'active'"
            >Actives</button>
            <button
              class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors"
              :class="statusFilter === 'all' ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700/50'"
              @click="statusFilter = 'all'"
            >Toutes</button>
          </div>

          <!-- Visibility filter -->
          <div class="flex items-center gap-2 bg-slate-800/50 rounded-lg p-1">
            <button
              class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors"
              :class="visibilityFilter === 'all' ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700/50'"
              @click="visibilityFilter = 'all'"
            >Toutes</button>
            <button
              class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors flex items-center gap-1.5"
              :class="visibilityFilter === 'perso' ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700/50'"
              @click="visibilityFilter = 'perso'"
              title="Mes escalations"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
              Perso
            </button>
            <button
              class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors flex items-center gap-1.5"
              :class="visibilityFilter === 'corp' ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700/50'"
              @click="visibilityFilter = 'corp'"
              title="Partagees avec la corporation"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
              Corp
            </button>
            <button
              class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors flex items-center gap-1.5"
              :class="visibilityFilter === 'public' ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700/50'"
              @click="visibilityFilter = 'public'"
              title="Visibles par tous"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"/></svg>
              Public
            </button>
          </div>

          <!-- Character filter -->
          <select
            v-model="characterFilter"
            class="bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-cyan-500 pr-8 appearance-none"
            style="background-image: url(&quot;data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2394a3b8'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E&quot;); background-repeat: no-repeat; background-position: right 0.5rem center; background-size: 1.25rem;"
          >
            <option :value="null">Tous les personnages</option>
            <option v-for="char in characters" :key="char.eveCharacterId" :value="char.eveCharacterId">
              {{ char.name }}
            </option>
          </select>
        </div>

        <!-- Add button -->
        <button
          @click="openModal"
          class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 transition-colors"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
          </svg>
          Ajouter
        </button>
      </div>

      <!-- KPI Cards -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <!-- Total -->
        <div class="group relative bg-gradient-to-br from-slate-800/80 to-slate-900/80 rounded-2xl p-5 border border-slate-700/50 backdrop-blur-sm overflow-hidden transition-all duration-300 hover:border-cyan-500/40 hover:shadow-lg hover:shadow-cyan-500/10 hover:-translate-y-1">
          <div class="absolute inset-0 bg-gradient-to-r from-transparent via-cyan-500/10 to-transparent -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-in-out"></div>
          <div class="relative">
            <p class="text-slate-500 text-sm uppercase tracking-wider mb-1">Total</p>
            <p class="text-3xl font-bold text-cyan-400">{{ kpiTotal }}</p>
          </div>
        </div>

        <!-- Nouveau / BM -->
        <div class="group relative bg-gradient-to-br from-slate-800/80 to-slate-900/80 rounded-2xl p-5 border border-slate-700/50 backdrop-blur-sm overflow-hidden transition-all duration-300 hover:border-cyan-500/40 hover:shadow-lg hover:shadow-cyan-500/10 hover:-translate-y-1">
          <div class="absolute inset-0 bg-gradient-to-r from-transparent via-cyan-500/10 to-transparent -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-in-out"></div>
          <div class="relative">
            <p class="text-slate-500 text-sm uppercase tracking-wider mb-1">Nouveau / BM</p>
            <p class="text-3xl font-bold text-cyan-400">{{ kpiNewBm }}</p>
          </div>
        </div>

        <!-- En vente -->
        <div class="group relative bg-gradient-to-br from-slate-800/80 to-slate-900/80 rounded-2xl p-5 border border-slate-700/50 backdrop-blur-sm overflow-hidden transition-all duration-300 hover:border-amber-500/40 hover:shadow-lg hover:shadow-amber-500/10 hover:-translate-y-1">
          <div class="absolute inset-0 bg-gradient-to-r from-transparent via-amber-500/10 to-transparent -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-in-out"></div>
          <div class="relative">
            <p class="text-slate-500 text-sm uppercase tracking-wider mb-1">En vente</p>
            <p class="text-3xl font-bold text-amber-400">{{ kpiEnvente.count }}</p>
            <p v-if="kpiEnvente.total > 0" class="text-xs text-slate-500 mt-1">{{ kpiEnvente.total }}m ISK</p>
          </div>
        </div>

        <!-- Vendues -->
        <div class="group relative bg-gradient-to-br from-slate-800/80 to-slate-900/80 rounded-2xl p-5 border border-slate-700/50 backdrop-blur-sm overflow-hidden transition-all duration-300 hover:border-emerald-500/40 hover:shadow-lg hover:shadow-emerald-500/10 hover:-translate-y-1">
          <div class="absolute inset-0 bg-gradient-to-r from-transparent via-emerald-500/10 to-transparent -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-in-out"></div>
          <div class="relative">
            <p class="text-slate-500 text-sm uppercase tracking-wider mb-1">Vendues</p>
            <p class="text-3xl font-bold text-emerald-400">{{ kpiVendu.count }}</p>
            <p v-if="kpiVendu.total > 0" class="text-xs text-slate-500 mt-1">{{ kpiVendu.total }}m ISK</p>
          </div>
        </div>
      </div>

      <!-- Loading State -->
      <div v-if="escalationStore.isLoading" class="flex items-center justify-center py-16">
        <div class="flex items-center gap-3">
          <svg class="w-5 h-5 animate-spin text-cyan-400" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <span class="text-slate-400">Chargement des escalations...</span>
        </div>
      </div>

      <!-- Error State -->
      <div v-else-if="escalationStore.error" class="bg-red-500/10 border border-red-500/30 rounded-xl p-6 text-center">
        <svg class="w-8 h-8 text-red-400 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
        </svg>
        <p class="text-red-400 mb-3">{{ escalationStore.error }}</p>
        <button @click="loadData" class="px-4 py-2 bg-red-500/20 hover:bg-red-500/30 text-red-400 rounded-lg text-sm transition-colors">
          Reessayer
        </button>
      </div>

      <!-- Empty State -->
      <div v-else-if="filteredEscalations.length === 0" class="bg-slate-900 rounded-xl border border-slate-800 p-12 text-center">
        <svg class="w-12 h-12 text-slate-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/>
          <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/>
        </svg>
        <p class="text-slate-400 mb-2">Aucune escalation</p>
        <p class="text-sm text-slate-600 mb-4">Ajoutez une escalation pour commencer le suivi</p>
        <button @click="openModal" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium transition-colors">
          Ajouter une escalation
        </button>
      </div>

      <!-- Escalation List -->
      <div v-else class="bg-slate-900 rounded-xl border border-slate-800">
        <div class="px-5 py-4 border-b border-slate-800">
          <h3 class="font-semibold">Escalations DED</h3>
          <p class="text-sm text-slate-500">Gerez vos escalations et exportez-les pour la vente</p>
        </div>

        <div class="divide-y divide-slate-800">
          <div
            v-for="escalation in filteredEscalations"
            :key="escalation.id"
            class="px-5 py-4 hover:bg-slate-800/30 transition-colors"
            :class="{
              'opacity-50': escalation.saleStatus === 'vendu',
              'bg-red-500/5': getTimerInfo(escalation.expiresAt).zone === 'danger' && escalation.saleStatus !== 'vendu',
            }"
          >
            <div class="flex items-center justify-between gap-4">
              <!-- Left: Info -->
              <div class="min-w-0 flex-1">
                <!-- Badges row -->
                <div class="flex items-center gap-2 mb-1 flex-wrap">
                  <!-- Visibility badge (clickable) -->
                  <div class="relative" v-if="escalation.isOwner">
                    <button
                      class="text-xs px-1.5 py-0.5 rounded flex items-center gap-1 hover:ring-1 transition-all cursor-pointer"
                      :class="visibilityBadgeClasses(escalation.visibility)"
                      :title="`${visibilityTitle(escalation.visibility)} — Cliquer pour changer`"
                      @click="toggleVisPopover(escalation.id, $event)"
                    >
                      <!-- Perso icon -->
                      <svg v-if="escalation.visibility === 'perso'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                      <!-- Corp icon -->
                      <svg v-else-if="escalation.visibility === 'corp'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
                      <!-- Public icon -->
                      <svg v-else class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"/></svg>
                      {{ visibilityLabel(escalation.visibility) }}
                    </button>

                    <!-- Visibility popover -->
                    <div
                      v-if="activeVisPopoverId === escalation.id"
                      class="absolute z-50 mt-1 left-0 bg-slate-800 border border-slate-700 rounded-lg shadow-xl overflow-hidden w-48"
                      @click.stop
                    >
                      <button
                        v-for="vis in (['perso', 'corp', 'public'] as const)"
                        :key="vis"
                        class="w-full flex items-center gap-2.5 px-3 py-2 text-left transition-colors"
                        :class="escalation.visibility === vis
                          ? visibilityBadgeClasses(vis).replace('hover:ring-', '').split(' ').slice(0, 2).join(' ')
                          : 'text-slate-400 hover:bg-slate-700/50 hover:text-slate-200'"
                        @click="changeVisibility(escalation, vis)"
                      >
                        <!-- Icons -->
                        <span class="flex-shrink-0">
                          <svg v-if="vis === 'perso'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                          <svg v-else-if="vis === 'corp'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
                          <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"/></svg>
                        </span>
                        <div class="flex-1 min-w-0">
                          <p class="text-sm font-medium">{{ visibilityTitle(vis) }}</p>
                          <p class="text-[10px]" :class="escalation.visibility === vis ? 'opacity-70' : 'text-slate-500'">{{ visibilityDescription(vis) }}</p>
                        </div>
                        <!-- Checkmark for active -->
                        <svg v-if="escalation.visibility === vis" class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                      </button>
                    </div>
                  </div>

                  <!-- Non-owner visibility badge (read-only) -->
                  <span
                    v-else
                    class="text-xs px-1.5 py-0.5 rounded flex items-center gap-1"
                    :class="visibilityBadgeClasses(escalation.visibility).replace('hover:ring-', '').split(' ').slice(0, 2).join(' ')"
                  >
                    <svg v-if="escalation.visibility === 'corp'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
                    <svg v-else class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"/></svg>
                    {{ visibilityLabel(escalation.visibility) }}
                  </span>

                  <!-- BM badge -->
                  <span
                    class="text-xs px-2 py-0.5 rounded"
                    :class="escalation.bmStatus === 'nouveau' ? 'bg-cyan-500/20 text-cyan-400' : 'bg-emerald-500/20 text-emerald-400'"
                  >{{ escalation.bmStatus === 'nouveau' ? 'Nouveau' : 'BM' }}</span>

                  <!-- Sale badge -->
                  <span
                    class="text-xs px-2 py-0.5 rounded"
                    :class="escalation.saleStatus === 'envente' ? 'bg-amber-500/20 text-amber-400' : 'bg-emerald-500/20 text-emerald-400'"
                  >{{ escalation.saleStatus === 'envente' ? 'En vente' : 'Vendu' }}</span>

                  <!-- Urgent badge -->
                  <span
                    v-if="getTimerInfo(escalation.expiresAt).zone === 'danger' && escalation.saleStatus !== 'vendu'"
                    class="text-xs px-2 py-0.5 rounded bg-red-500/20 text-red-400 animate-pulse"
                  >Urgent</span>

                  <!-- Type name -->
                  <span class="text-xs text-slate-500">{{ escalation.type }}</span>
                </div>

                <!-- System + Character -->
                <p class="text-sm text-slate-300 flex items-center gap-3">
                  <span class="flex items-center gap-1">
                    <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                    <span :class="secStatusColor(escalation.securityStatus)">{{ escalation.solarSystemName }}</span>
                  </span>
                  <span class="flex items-center gap-1">
                    <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0"/></svg>
                    {{ escalation.characterName }}
                  </span>
                </p>

                <!-- Notes -->
                <p v-if="escalation.notes && escalation.visibility !== 'public'" class="text-xs text-slate-500 italic mt-1">{{ escalation.notes }}</p>

                <!-- Timer -->
                <div class="flex items-center gap-3 mt-2" v-if="escalation.saleStatus !== 'vendu'">
                  <template v-if="getTimerInfo(escalation.expiresAt).percent > 0">
                    <div class="w-32 h-1.5 bg-slate-700 rounded-full overflow-hidden">
                      <div
                        class="h-full rounded-full transition-[width] duration-1000 linear"
                        :class="timerBarColor(getTimerInfo(escalation.expiresAt).zone)"
                        :style="{ width: getTimerInfo(escalation.expiresAt).percent + '%' }"
                      ></div>
                    </div>
                    <span
                      class="text-xs font-mono"
                      :class="timerTextColor(getTimerInfo(escalation.expiresAt).zone)"
                    >{{ getTimerInfo(escalation.expiresAt).text }}</span>
                  </template>
                  <span v-else class="text-xs font-mono text-slate-600">Expire</span>
                </div>
                <div v-else class="flex items-center gap-3 mt-2">
                  <span class="text-xs font-mono text-slate-600">Termine</span>
                </div>
              </div>

              <!-- Right: Actions -->
              <div class="flex items-center gap-4 flex-shrink-0">
                <!-- Toggle buttons (only for owner) -->
                <div v-if="escalation.isOwner" class="flex items-center gap-2">
                  <button
                    class="px-2.5 py-1 rounded text-xs font-medium border transition-colors"
                    :class="escalation.bmStatus === 'nouveau'
                      ? 'bg-cyan-500/20 text-cyan-400 border-cyan-500/30 hover:bg-cyan-500/30'
                      : 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30 hover:bg-emerald-500/30'"
                    @click="toggleBmStatus(escalation)"
                  >{{ escalation.bmStatus === 'nouveau' ? 'Nouveau' : 'BM' }}</button>
                  <button
                    class="px-2.5 py-1 rounded text-xs font-medium border transition-colors"
                    :class="escalation.saleStatus === 'envente'
                      ? 'bg-amber-500/20 text-amber-400 border-amber-500/30 hover:bg-amber-500/30'
                      : 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30 hover:bg-emerald-500/30'"
                    @click="toggleSaleStatus(escalation)"
                  >{{ escalation.saleStatus === 'envente' ? 'En vente' : 'Vendu' }}</button>
                </div>

                <!-- Price -->
                <div class="text-right min-w-[80px]">
                  <p class="text-lg font-bold font-mono" :class="priceColor(escalation)">
                    {{ escalation.price }}<span class="text-xs ml-0.5" :class="priceSubColor(escalation)">m</span>
                  </p>
                </div>

                <!-- Share buttons -->
                <div v-if="shouldShowShareButtons(escalation)" class="flex items-center gap-1">
                  <!-- WTS copy -->
                  <button
                    class="p-2 rounded-lg text-slate-500 hover:text-cyan-400 hover:bg-slate-800 transition-colors relative"
                    title="Copier pour WTS"
                    @click="shareWts(escalation)"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9.75a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184"/></svg>
                  </button>
                  <!-- Discord copy -->
                  <button
                    class="p-2 rounded-lg text-slate-500 hover:text-indigo-400 hover:bg-slate-800 transition-colors relative"
                    title="Copier pour Discord"
                    @click="shareDiscord(escalation)"
                  >
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M20.317 4.37a19.791 19.791 0 00-4.885-1.515.074.074 0 00-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 00-5.487 0 12.64 12.64 0 00-.617-1.25.077.077 0 00-.079-.037A19.736 19.736 0 003.677 4.37a.07.07 0 00-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 00.031.057 19.9 19.9 0 005.993 3.03.078.078 0 00.084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 00-.041-.106 13.107 13.107 0 01-1.872-.892.077.077 0 01-.008-.128 10.2 10.2 0 00.372-.292.074.074 0 01.077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 01.078.01c.12.098.246.198.373.292a.077.077 0 01-.006.127 12.299 12.299 0 01-1.873.892.077.077 0 00-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 00.084.028 19.839 19.839 0 006.002-3.03.077.077 0 00.032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 00-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.095 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.095 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/></svg>
                  </button>
                </div>

                <!-- Delete button (owner only) -->
                <button
                  v-if="escalation.isOwner"
                  class="p-2 rounded-lg text-slate-600 hover:text-red-400 hover:bg-red-500/10 transition-colors"
                  title="Supprimer"
                  @click="askDeleteEscalation(escalation)"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>

    <!-- Add Modal -->
    <Teleport to="body">
      <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-slate-950/80 backdrop-blur-sm" @click="closeModal"></div>

        <!-- Modal content -->
        <div class="relative bg-slate-900 rounded-2xl border border-cyan-500/30 shadow-2xl shadow-cyan-500/10 w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
          <!-- Header -->
          <div class="flex items-center justify-between px-6 py-4 border-b border-slate-700/50">
            <div class="flex items-center gap-3">
              <div class="w-8 h-8 rounded-lg bg-cyan-500/20 border border-cyan-500/30 flex items-center justify-center">
                <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                </svg>
              </div>
              <div>
                <h3 class="text-lg font-semibold text-slate-100">Nouvelle escalation</h3>
                <p class="text-xs text-slate-500">Enregistrer un DED site pour suivi et vente</p>
              </div>
            </div>
            <button @click="closeModal" class="p-1.5 hover:bg-slate-800 rounded-lg transition-colors">
              <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>

          <!-- Body -->
          <div class="p-6 space-y-5">

            <!-- Row 1: Character + Type -->
            <div class="grid grid-cols-5 gap-4">
              <!-- Character (2 cols) -->
              <div class="col-span-2">
                <label class="block text-sm font-medium text-slate-400 mb-1.5">Personnage</label>
                <div class="relative">
                  <div
                    class="flex items-center gap-2 w-full bg-slate-800/50 border border-slate-700 rounded-lg px-3 py-2 cursor-pointer hover:border-cyan-500/50 transition-colors"
                    @click.stop="showCharDropdown = !showCharDropdown"
                  >
                    <div class="w-6 h-6 rounded bg-slate-700 flex items-center justify-center text-cyan-400 text-xs font-bold flex-shrink-0">
                      {{ formCharacterName ? getCharInitials(formCharacterName) : '?' }}
                    </div>
                    <span class="text-sm truncate">{{ formCharacterName || 'Choisir...' }}</span>
                    <svg class="w-4 h-4 text-slate-500 ml-auto flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                  </div>

                  <!-- Character dropdown -->
                  <div v-if="showCharDropdown" class="absolute z-10 mt-1 w-full bg-slate-800 border border-slate-700 rounded-lg shadow-xl overflow-hidden">
                    <button
                      v-for="char in characters"
                      :key="char.eveCharacterId"
                      class="w-full flex items-center gap-2 px-3 py-2.5 hover:bg-cyan-500/10 transition-colors text-left"
                      @click.stop="selectFormCharacter(char.eveCharacterId, char.name)"
                    >
                      <div class="w-6 h-6 rounded bg-slate-700 flex items-center justify-center text-cyan-400 text-xs font-bold">
                        {{ getCharInitials(char.name) }}
                      </div>
                      <div>
                        <p class="text-sm text-slate-200">{{ char.name }}</p>
                        <p class="text-xs text-slate-500">{{ char.corporationName }}</p>
                      </div>
                    </button>
                  </div>
                </div>
              </div>

              <!-- Escalation type (3 cols) -->
              <div class="col-span-3">
                <label class="block text-sm font-medium text-slate-400 mb-1.5">Type d'escalation</label>
                <div class="relative">
                  <div
                    class="flex items-center justify-between w-full bg-slate-800/50 border border-slate-700 rounded-lg px-3 py-2 cursor-pointer hover:border-cyan-500/50 transition-colors"
                    @click.stop="showTypeDropdown = !showTypeDropdown; showCharDropdown = false"
                  >
                    <span class="text-sm" :class="formType ? 'text-slate-200' : 'text-slate-500'">{{ formType || 'Choisir un type...' }}</span>
                    <svg class="w-4 h-4 text-slate-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                  </div>

                  <div v-if="showTypeDropdown" class="absolute z-10 mt-1 w-full bg-slate-800 border border-slate-700 rounded-lg shadow-xl overflow-hidden max-h-64 overflow-y-auto">
                    <template v-for="group in escalationOptions" :key="group.label">
                      <div class="px-3 py-1.5 text-xs text-slate-500 uppercase tracking-wider bg-slate-900/50 sticky top-0">{{ group.label }}</div>
                      <button
                        v-for="opt in group.options"
                        :key="opt.value"
                        class="w-full px-3 py-2 text-sm text-left transition-colors"
                        :class="formType === opt.value ? 'bg-cyan-500/20 text-cyan-400' : 'text-slate-300 hover:bg-cyan-500/10 hover:text-cyan-300'"
                        @click.stop="selectFormType(opt.value)"
                      >{{ opt.label }}</button>
                    </template>
                  </div>
                </div>
              </div>
            </div>

            <!-- Solar system search -->
            <div>
              <label class="block text-sm font-medium text-slate-400 mb-1.5">Systeme solaire</label>
              <div class="relative">
                <!-- Search input (hidden when system selected) -->
                <template v-if="!formSystemId">
                  <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                  </svg>
                  <input
                    type="text"
                    :value="formSystemSearch"
                    @input="onSystemSearch(($event.target as HTMLInputElement).value)"
                    @focus="onSystemSearch(formSystemSearch)"
                    placeholder="Rechercher un systeme..."
                    autocomplete="off"
                    class="w-full bg-slate-800/50 border border-slate-700 rounded-lg pl-9 pr-3 py-2 text-sm placeholder-slate-500 focus:outline-none focus:border-cyan-500 hover:border-cyan-500/50 transition-colors"
                  >

                  <!-- Autocomplete dropdown -->
                  <div
                    v-if="showSystemDropdown && systemSearchResults.length > 0"
                    class="absolute z-10 mt-1 w-full bg-slate-800 border border-slate-700 rounded-lg shadow-xl overflow-hidden max-h-48 overflow-y-auto"
                  >
                    <button
                      v-for="sys in systemSearchResults"
                      :key="sys.id"
                      class="w-full flex items-center gap-3 px-3 py-2.5 hover:bg-cyan-500/10 transition-colors text-left"
                      @click.stop="selectSystem(sys)"
                    >
                      <span class="text-xs font-mono font-bold px-1.5 py-0.5 rounded" :class="secBadgeClasses(sys.sec)">
                        {{ sys.sec.toFixed(1) }}
                      </span>
                      <span class="text-sm text-slate-200">{{ sys.name }}</span>
                      <span class="text-xs text-slate-500 ml-auto">{{ sys.region }}</span>
                    </button>
                  </div>
                </template>

                <!-- Selected system badge -->
                <div v-else class="inline-flex items-center gap-2 px-3 py-1.5 bg-slate-800/80 rounded-lg border border-slate-700/50">
                  <span class="text-xs font-mono font-bold px-1.5 py-0.5 rounded" :class="secBadgeClasses(formSystemSec)">
                    {{ formSystemSec.toFixed(1) }}
                  </span>
                  <span class="text-sm text-slate-200">{{ formSystemName }}</span>
                  <span class="text-xs text-slate-500">{{ formSystemRegion }}</span>
                  <button class="ml-1 text-slate-500 hover:text-red-400 transition-colors" @click="clearFormSystem">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                  </button>
                </div>
              </div>
            </div>

            <!-- Row 2: Timer + Price -->
            <div class="grid grid-cols-2 gap-4">
              <!-- Timer -->
              <div>
                <label class="block text-sm font-medium text-slate-400 mb-1.5">Temps restant</label>
                <div class="flex items-center gap-2">
                  <div class="flex items-center gap-1.5">
                    <input
                      type="number"
                      v-model.number="formTimerDays"
                      min="0"
                      max="3"
                      class="w-14 bg-slate-800/50 border border-slate-700 rounded-lg px-2 py-2 text-sm text-center focus:outline-none focus:border-cyan-500 font-mono hover:border-cyan-500/50 transition-colors"
                    >
                    <span class="text-xs text-slate-500">j</span>
                  </div>
                  <div class="flex items-center gap-1.5">
                    <input
                      type="number"
                      v-model.number="formTimerHours"
                      min="0"
                      max="23"
                      class="w-14 bg-slate-800/50 border border-slate-700 rounded-lg px-2 py-2 text-sm text-center focus:outline-none focus:border-cyan-500 font-mono hover:border-cyan-500/50 transition-colors"
                    >
                    <span class="text-xs text-slate-500">h</span>
                  </div>
                  <div class="flex items-center gap-1.5">
                    <input
                      type="number"
                      v-model.number="formTimerMinutes"
                      min="0"
                      max="59"
                      class="w-14 bg-slate-800/50 border border-slate-700 rounded-lg px-2 py-2 text-sm text-center focus:outline-none focus:border-cyan-500 font-mono hover:border-cyan-500/50 transition-colors"
                    >
                    <span class="text-xs text-slate-500">m</span>
                  </div>
                </div>
                <!-- Timer preview bar -->
                <div class="flex items-center gap-2 mt-2">
                  <div class="flex-1 h-1.5 bg-slate-700 rounded-full overflow-hidden">
                    <div
                      class="h-full rounded-full transition-all duration-300"
                      :class="timerBarColor(formTimerZone)"
                      :style="{ width: formTimerPercent + '%' }"
                    ></div>
                  </div>
                  <span class="text-xs font-mono" :class="timerTextColor(formTimerZone)">{{ formTimerPreviewText }}</span>
                </div>
              </div>

              <!-- Price -->
              <div>
                <label class="block text-sm font-medium text-slate-400 mb-1.5">Prix demande</label>
                <div class="relative">
                  <input
                    type="number"
                    v-model.number="formPrice"
                    min="0"
                    class="w-full bg-slate-800/50 border border-slate-700 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-cyan-500 font-mono pr-8 hover:border-cyan-500/50 transition-colors"
                  >
                  <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-500">m</span>
                </div>
                <!-- Suggested prices -->
                <div class="flex flex-wrap gap-1.5 mt-2">
                  <button
                    v-for="sp in currentSuggestedPrices"
                    :key="sp"
                    class="px-2 py-0.5 text-xs rounded border transition-colors font-mono"
                    :class="formPrice === sp
                      ? 'bg-cyan-500/10 text-cyan-400 border-cyan-500/30'
                      : 'bg-slate-800 text-slate-400 hover:text-cyan-400 hover:bg-slate-700 border-slate-700/50'"
                    @click="setFormPrice(sp)"
                  >{{ sp }}m</button>
                </div>
              </div>
            </div>

            <!-- Notes -->
            <div>
              <label class="block text-sm font-medium text-slate-400 mb-1.5">
                Notes
                <span class="text-slate-600 font-normal ml-1">(optionnel)</span>
              </label>
              <input
                type="text"
                v-model="formNotes"
                placeholder="Ex: proche de Jita, acheteur trouve..."
                class="w-full bg-slate-800/50 border border-slate-700 rounded-lg px-3 py-2 text-sm placeholder-slate-500 focus:outline-none focus:border-cyan-500 hover:border-cyan-500/50 transition-colors"
              >
            </div>

            <!-- Preview card -->
            <div class="bg-slate-800/30 rounded-xl border border-slate-700/30 p-4">
              <div class="flex items-center gap-2 mb-3">
                <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <span class="text-xs text-slate-500 uppercase tracking-wider">Apercu</span>
              </div>
              <div class="flex items-center justify-between">
                <div>
                  <div class="flex items-center gap-2 mb-1">
                    <span class="text-xs px-2 py-0.5 rounded bg-cyan-500/20 text-cyan-400">Nouveau</span>
                    <span class="text-sm text-slate-300">{{ previewType }}</span>
                  </div>
                  <p class="text-xs text-slate-500 flex items-center gap-3">
                    <span class="flex items-center gap-1">
                      <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                      {{ previewSystem }}
                    </span>
                    <span class="flex items-center gap-1">
                      <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0"/></svg>
                      {{ previewChar }}
                    </span>
                  </p>
                </div>
                <div class="text-right">
                  <p class="text-lg font-bold text-cyan-400 font-mono">{{ formPrice }}<span class="text-xs text-slate-500 ml-0.5">m</span></p>
                  <p class="text-xs font-mono text-slate-500">{{ formTimerPreviewText }} restantes</p>
                </div>
              </div>
            </div>

          </div>

          <!-- Footer -->
          <div class="px-6 py-4 border-t border-slate-700/50 flex items-center justify-between">
            <p class="text-xs text-slate-600">Expire dans 72h maximum</p>
            <div class="flex items-center gap-3">
              <button
                @click="closeModal"
                class="px-4 py-2 rounded-lg text-sm text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
              >Annuler</button>
              <button
                @click="submitEscalation"
                :disabled="!isFormValid || isSubmitting"
                class="px-5 py-2 bg-cyan-600 hover:bg-cyan-500 disabled:bg-slate-700 disabled:text-slate-500 disabled:cursor-not-allowed rounded-lg text-white text-sm font-medium transition-colors flex items-center gap-2 shadow-lg shadow-cyan-500/20 disabled:shadow-none"
              >
                <svg v-if="isSubmitting" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                {{ isSubmitting ? 'Ajout...' : 'Ajouter' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Delete Confirmation Modal -->
    <Teleport to="body">
      <div v-if="showDeleteModal && deleteTarget" class="fixed inset-0 z-50 flex items-center justify-center">
        <div class="absolute inset-0 bg-slate-950/80 backdrop-blur-sm" @click="cancelDelete"></div>
        <div class="relative bg-slate-900 rounded-2xl border border-red-500/30 shadow-2xl shadow-red-500/10 w-full max-w-sm mx-4">
          <div class="p-6 text-center">
            <div class="w-12 h-12 rounded-full bg-red-500/20 border border-red-500/30 flex items-center justify-center mx-auto mb-4">
              <svg class="w-6 h-6 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/>
              </svg>
            </div>
            <h3 class="text-lg font-semibold text-slate-100 mb-2">Supprimer l'escalation ?</h3>
            <p class="text-sm text-slate-400 mb-1">{{ deleteTarget.type }}</p>
            <p class="text-xs text-slate-500">{{ deleteTarget.solarSystemName }} &middot; {{ deleteTarget.characterName }}</p>
          </div>
          <div class="px-6 pb-6 flex items-center gap-3">
            <button
              @click="cancelDelete"
              class="flex-1 px-4 py-2.5 rounded-lg text-sm text-slate-400 hover:text-white hover:bg-slate-800 border border-slate-700 transition-colors"
            >Annuler</button>
            <button
              @click="confirmDelete"
              :disabled="isDeleting"
              class="flex-1 px-4 py-2.5 bg-red-600 hover:bg-red-500 disabled:bg-slate-700 disabled:text-slate-500 rounded-lg text-white text-sm font-medium transition-colors flex items-center justify-center gap-2"
            >
              <svg v-if="isDeleting" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ isDeleting ? 'Suppression...' : 'Supprimer' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Toast Notifications -->
    <Teleport to="body">
      <div class="fixed top-6 right-6 z-[100] flex flex-col gap-3 pointer-events-none">
        <div
          v-for="toast in toasts"
          :key="toast.id"
          class="pointer-events-auto flex items-center gap-3 px-4 py-3 rounded-xl border backdrop-blur-xl shadow-xl max-w-sm transform transition-transform duration-300"
          :class="{
            'bg-emerald-500/20 border-emerald-500/40 text-emerald-400': toast.type === 'success',
            'bg-amber-500/20 border-amber-500/40 text-amber-400': toast.type === 'warning',
            'bg-red-500/20 border-red-500/40 text-red-400': toast.type === 'error',
            'translate-x-0': toast.visible,
            'translate-x-[120%]': !toast.visible,
          }"
        >
          <span class="flex-shrink-0">
            <!-- Success icon -->
            <svg v-if="toast.type === 'success'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <!-- Warning icon -->
            <svg v-else-if="toast.type === 'warning'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
            <!-- Error icon -->
            <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
          </span>
          <p class="text-sm font-medium">{{ toast.message }}</p>
        </div>
      </div>
    </Teleport>
  </MainLayout>
</template>

<style scoped>
/* Hide number input spinners */
input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
input[type=number] {
  -moz-appearance: textfield;
}
</style>
