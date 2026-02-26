<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEscalationStore, type Escalation, type CreateEscalationInput } from '@/stores/escalation'
import { useAuthStore } from '@/stores/auth'
import { useEscalationTimers } from '@/composables/useEscalationTimers'
import { useEscalationHelpers } from '@/composables/useEscalationHelpers'
import { useToast } from '@/composables/useToast'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import EscalationKpiCards from '@/components/escalation/EscalationKpiCards.vue'
import EscalationFilters from '@/components/escalation/EscalationFilters.vue'
import EscalationRow from '@/components/escalation/EscalationRow.vue'
import EscalationFormModal from '@/components/escalation/EscalationFormModal.vue'

const { t } = useI18n()
const escalationStore = useEscalationStore()
const authStore = useAuthStore()

// ========== Composables ==========

const { now, getTimerInfo, timerBarColor, timerTextColor } = useEscalationTimers()
const { showToast } = useToast()
const {
  secStatusColor,
  visibilityBadgeClasses,
  visibilityLabel,
  visibilityTitle,
  priceColor,
  priceSubColor,
  shouldShowShareButtons,
  shareWts,
  shareDiscord,
  shareAllWts,
  shareAllDiscord,
} = useEscalationHelpers(getTimerInfo, showToast)

// ========== Filter State ==========

const statusFilter = ref<'active' | 'all'>('active')
const visibilityFilter = ref<'all' | 'perso' | 'corp' | 'alliance' | 'public'>('all')
const characterFilter = ref<number | null>(null)
const currentPage = ref(1)
const perPage = 20

// ========== Modal State ==========

const showModal = ref(false)
const editingEscalation = ref<Escalation | null>(null)
const showDeleteModal = ref(false)
const deleteTarget = ref<Escalation | null>(null)
const isDeleting = ref(false)
const showSellModal = ref(false)
const sellTarget = ref<Escalation | null>(null)
const isSelling = ref(false)

// ========== Visibility Popover State ==========

const activeVisPopoverId = ref<string | null>(null)

// ========== Characters ==========

const characters = computed(() => authStore.user?.characters ?? [])

// ========== Filtered Escalations ==========

const allEscalations = computed(() => {
  return [...escalationStore.escalations, ...escalationStore.corpEscalations]
})

const filteredEscalations = computed(() => {
  let list = [...allEscalations.value]

  // Status filter
  if (statusFilter.value === 'active') {
    list = list.filter(e => {
      const expired = new Date(e.expiresAt).getTime() <= now.value
      return e.saleStatus !== 'vendu' && !expired
    })
  }

  // Visibility filter
  const userCorpId = authStore.user?.corporationId
  if (visibilityFilter.value !== 'all') {
    if (visibilityFilter.value === 'corp') {
      list = list.filter(e =>
        e.visibility === 'corp' ||
        (e.visibility === 'alliance' && e.corporationId === userCorpId)
      )
    } else {
      list = list.filter(e => e.visibility === visibilityFilter.value)
    }
  }

  // Character filter
  if (characterFilter.value !== null) {
    list = list.filter(e => e.characterId === characterFilter.value)
  }

  // Sort: expired at bottom, then by expiry ascending
  list.sort((a, b) => {
    const aExpired = new Date(a.expiresAt).getTime() <= now.value || a.saleStatus === 'vendu'
    const bExpired = new Date(b.expiresAt).getTime() <= now.value || b.saleStatus === 'vendu'
    if (aExpired !== bExpired) return aExpired ? 1 : -1
    return new Date(a.expiresAt).getTime() - new Date(b.expiresAt).getTime()
  })

  return list
})

// ========== Pagination ==========

const totalPages = computed(() => Math.max(1, Math.ceil(filteredEscalations.value.length / perPage)))

const paginatedEscalations = computed(() => {
  const start = (currentPage.value - 1) * perPage
  return filteredEscalations.value.slice(start, start + perPage)
})

watch([statusFilter, visibilityFilter, characterFilter], () => {
  currentPage.value = 1
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
  return { count: list.length, total: list.reduce((sum, e) => sum + e.price, 0) }
})

const kpiVendu = computed(() => {
  const list = filteredEscalations.value.filter(e => e.saleStatus === 'vendu')
  return { count: list.length, total: list.reduce((sum, e) => sum + e.price, 0) }
})

// ========== Shareable Escalations ==========

const shareableEscalations = computed(() =>
  filteredEscalations.value.filter(e =>
    e.isOwner &&
    e.visibility !== 'perso' &&
    e.saleStatus === 'envente' &&
    e.bmStatus === 'bm' &&
    new Date(e.expiresAt).getTime() > now.value
  )
)

// ========== Actions ==========

async function toggleBmStatus(escalation: Escalation): Promise<void> {
  const newStatus = escalation.bmStatus === 'nouveau' ? 'bm' : 'nouveau'
  try {
    await escalationStore.updateEscalation(escalation.id, { bmStatus: newStatus })
  } catch {
    showToast(t('escalations.toast.bmStatusError'), 'error')
  }
}

async function toggleSaleStatus(escalation: Escalation): Promise<void> {
  if (escalation.saleStatus === 'envente') {
    sellTarget.value = escalation
    showSellModal.value = true
    return
  }
  try {
    await escalationStore.updateEscalation(escalation.id, { saleStatus: 'envente' })
  } catch {
    showToast(t('escalations.toast.saleStatusError'), 'error')
  }
}

async function confirmSell(): Promise<void> {
  if (!sellTarget.value || isSelling.value) return
  isSelling.value = true
  try {
    await escalationStore.updateEscalation(sellTarget.value.id, { saleStatus: 'vendu' })
    showToast(t('escalations.toast.markedSold'), 'success')
  } catch {
    showToast(t('escalations.toast.saleStatusError'), 'error')
  } finally {
    isSelling.value = false
    showSellModal.value = false
    sellTarget.value = null
  }
}

function cancelSell(): void {
  showSellModal.value = false
  sellTarget.value = null
}

async function changeVisibility(escalation: Escalation, newVis: 'perso' | 'corp' | 'alliance' | 'public'): Promise<void> {
  activeVisPopoverId.value = null
  try {
    await escalationStore.updateEscalation(escalation.id, { visibility: newVis })
  } catch {
    showToast(t('escalations.toast.visibilityError'), 'error')
  }
}

function askDeleteEscalation(escalation: Escalation): void {
  deleteTarget.value = escalation
  showDeleteModal.value = true
}

async function confirmDelete(): Promise<void> {
  if (!deleteTarget.value || isDeleting.value) return
  isDeleting.value = true
  try {
    await escalationStore.deleteEscalation(deleteTarget.value.id)
    showToast(t('escalations.toast.deleted'), 'success')
  } catch {
    showToast(t('escalations.toast.deleteError'), 'error')
  } finally {
    isDeleting.value = false
    showDeleteModal.value = false
    deleteTarget.value = null
  }
}

function cancelDelete(): void {
  showDeleteModal.value = false
  deleteTarget.value = null
}

function toggleVisPopover(id: string, event: Event): void {
  event.stopPropagation()
  activeVisPopoverId.value = activeVisPopoverId.value === id ? null : id
}

// ========== Modal Helpers ==========

function openModal(): void {
  editingEscalation.value = null
  showModal.value = true
}

function openEditModal(escalation: Escalation): void {
  editingEscalation.value = escalation
  showModal.value = true
}

function closeModal(): void {
  showModal.value = false
  editingEscalation.value = null
}

async function handleFormSubmit(payload: CreateEscalationInput | { updates: Partial<Pick<Escalation, 'type' | 'price' | 'notes' | 'visibility'>>; id: string }): Promise<void> {
  try {
    if ('updates' in payload) {
      // Edit mode
      if (Object.keys(payload.updates).length > 0) {
        await escalationStore.updateEscalation(payload.id, payload.updates)
        showToast(t('escalations.toast.updated'), 'success')
      }
    } else {
      // Add mode
      await escalationStore.createEscalation(payload)
      showToast(t('escalations.toast.created'), 'success')
    }
    closeModal()
  } catch {
    const isEdit = 'updates' in payload
    showToast(isEdit ? t('escalations.toast.updateError') : t('escalations.toast.createError'), 'error')
  }
}

// ========== Share handlers ==========

function handleShareWts(escalation: Escalation): void {
  shareWts(escalation, now.value)
}

function handleShareAllWts(): void {
  shareAllWts(shareableEscalations.value, now.value)
}

function handleShareAllDiscord(): void {
  shareAllDiscord(shareableEscalations.value)
}

// ========== Data Loading ==========

async function loadData(): Promise<void> {
  await Promise.all([
    escalationStore.fetchEscalations(),
    escalationStore.fetchCorpEscalations(),
  ])
}

// ========== Keyboard + Document Click ==========

function handleDocumentClick(): void {
  activeVisPopoverId.value = null
}

function handleKeydown(e: KeyboardEvent): void {
  if (e.key === 'Escape') {
    if (showModal.value) closeModal()
    if (showDeleteModal.value) cancelDelete()
    if (showSellModal.value) cancelSell()
    activeVisPopoverId.value = null
  }
}

// ========== Lifecycle ==========

onMounted(async () => {
  document.addEventListener('click', handleDocumentClick)
  document.addEventListener('keydown', handleKeydown)
  await loadData()
})

onUnmounted(() => {
  document.removeEventListener('click', handleDocumentClick)
  document.removeEventListener('keydown', handleKeydown)
})
</script>

<template>
  <div class="space-y-6">

    <!-- Header: Filters + Add button -->
    <EscalationFilters
      :status-filter="statusFilter"
      :visibility-filter="visibilityFilter"
      :character-filter="characterFilter"
      :characters="characters"
      :shareable-count="shareableEscalations.length"
      @update:status-filter="statusFilter = $event"
      @update:visibility-filter="visibilityFilter = $event"
      @update:character-filter="characterFilter = $event"
      @share-wts="handleShareAllWts"
      @share-discord="handleShareAllDiscord"
      @add="openModal"
    />

    <!-- KPI Cards -->
    <EscalationKpiCards
      :total="kpiTotal"
      :new-bm="kpiNewBm"
      :en-vente="kpiEnvente"
      :vendu="kpiVendu"
    />

    <!-- Loading State -->
    <div v-if="escalationStore.isLoading" class="flex items-center justify-center py-16">
      <div class="flex items-center gap-3">
        <LoadingSpinner size="md" class="text-cyan-400" />
        <span class="text-slate-400">{{ t('escalations.loading') }}</span>
      </div>
    </div>

    <!-- Error State -->
    <div v-else-if="escalationStore.error" class="bg-red-500/10 border border-red-500/30 rounded-xl p-6 text-center">
      <svg class="w-8 h-8 text-red-400 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
      </svg>
      <p class="text-red-400 mb-3">{{ escalationStore.error }}</p>
      <button @click="loadData" class="px-4 py-2 bg-red-500/20 hover:bg-red-500/30 text-red-400 rounded-lg text-sm transition-colors">
        {{ t('common.actions.retry') }}
      </button>
    </div>

    <!-- Empty State -->
    <div v-else-if="filteredEscalations.length === 0" class="bg-slate-900 rounded-xl border border-slate-800 p-12 text-center">
      <svg class="w-12 h-12 text-slate-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/>
        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/>
      </svg>
      <p class="text-slate-400 mb-2">{{ t('escalations.noEscalations') }}</p>
      <p class="text-sm text-slate-600 mb-4">{{ t('escalations.noEscalationsHint') }}</p>
      <button @click="openModal" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium transition-colors">
        {{ t('escalations.addEscalation') }}
      </button>
    </div>

    <!-- Escalation List -->
    <div v-else class="bg-slate-900 rounded-xl border border-slate-800">
      <div class="px-5 py-4 border-b border-slate-800">
        <h3 class="font-semibold">{{ t('escalations.listTitle') }}</h3>
        <p class="text-sm text-slate-500">{{ t('escalations.listSubtitle') }}</p>
      </div>

      <div class="divide-y divide-slate-800">
        <EscalationRow
          v-for="escalation in paginatedEscalations"
          :key="escalation.id"
          :escalation="escalation"
          :timer-info="getTimerInfo(escalation.expiresAt)"
          :active-vis-popover-id="activeVisPopoverId"
          :timer-bar-color="timerBarColor(getTimerInfo(escalation.expiresAt).zone)"
          :timer-text-color="timerTextColor(getTimerInfo(escalation.expiresAt).zone)"
          :price-color-class="priceColor(escalation)"
          :price-sub-color-class="priceSubColor(escalation)"
          :sec-status-color-class="secStatusColor(escalation.securityStatus)"
          :visibility-badge-classes="visibilityBadgeClasses(escalation.visibility)"
          :visibility-label="visibilityLabel(escalation.visibility)"
          :visibility-title="visibilityTitle(escalation.visibility)"
          :show-share-buttons="shouldShowShareButtons(escalation)"
          @toggle-bm="toggleBmStatus"
          @toggle-sale="toggleSaleStatus"
          @change-visibility="changeVisibility"
          @toggle-vis-popover="toggleVisPopover"
          @share-wts="handleShareWts"
          @share-discord="shareDiscord"
          @edit="openEditModal"
          @delete="askDeleteEscalation"
        />
      </div>

      <!-- Pagination -->
      <div v-if="totalPages > 1" class="px-5 py-3 border-t border-slate-800 flex items-center justify-between">
        <p class="text-sm text-slate-500">
          {{ t('escalations.pagination.showing', { from: (currentPage - 1) * perPage + 1, to: Math.min(currentPage * perPage, filteredEscalations.length), total: filteredEscalations.length }) }}
        </p>
        <div class="flex items-center gap-1">
          <button
            @click="currentPage = 1"
            :disabled="currentPage === 1"
            class="px-2 py-1 rounded-sm text-sm transition-colors"
            :class="currentPage === 1 ? 'text-slate-600 cursor-not-allowed' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200'"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.75 19.5l-7.5-7.5 7.5-7.5m-6 15L5.25 12l7.5-7.5"/></svg>
          </button>
          <button
            @click="currentPage--"
            :disabled="currentPage === 1"
            class="px-2 py-1 rounded-sm text-sm transition-colors"
            :class="currentPage === 1 ? 'text-slate-600 cursor-not-allowed' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200'"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
          </button>
          <template v-for="page in totalPages" :key="page">
            <button
              v-if="page === 1 || page === totalPages || (page >= currentPage - 1 && page <= currentPage + 1)"
              @click="currentPage = page"
              class="w-8 h-8 rounded-sm text-sm font-medium transition-colors"
              :class="page === currentPage ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200'"
            >
              {{ page }}
            </button>
            <span
              v-else-if="page === currentPage - 2 || page === currentPage + 2"
              class="text-slate-600 px-1"
            >...</span>
          </template>
          <button
            @click="currentPage++"
            :disabled="currentPage === totalPages"
            class="px-2 py-1 rounded-sm text-sm transition-colors"
            :class="currentPage === totalPages ? 'text-slate-600 cursor-not-allowed' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200'"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
          </button>
          <button
            @click="currentPage = totalPages"
            :disabled="currentPage === totalPages"
            class="px-2 py-1 rounded-sm text-sm transition-colors"
            :class="currentPage === totalPages ? 'text-slate-600 cursor-not-allowed' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200'"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 4.5l7.5 7.5-7.5 7.5m6-15l7.5 7.5-7.5 7.5"/></svg>
          </button>
        </div>
      </div>
    </div>

  </div>

  <!-- Add/Edit Modal -->
  <EscalationFormModal
    :visible="showModal"
    :editing-escalation="editingEscalation"
    :characters="characters"
    @close="closeModal"
    @submit="handleFormSubmit"
  />

  <!-- Delete Confirmation Modal -->
  <ConfirmModal
    :show="showDeleteModal && !!deleteTarget"
    :title="t('escalations.deleteModal.title')"
    :subtitle="deleteTarget?.type"
    :message="deleteTarget ? `${deleteTarget.solarSystemName} \u00b7 ${deleteTarget.characterName}` : ''"
    :confirm-label="isDeleting ? t('escalations.deleteModal.deleting') : t('common.actions.delete')"
    confirm-color="red"
    icon="delete"
    :is-loading="isDeleting"
    @confirm="confirmDelete"
    @cancel="cancelDelete"
  />

  <!-- Sell Confirmation Modal -->
  <ConfirmModal
    :show="showSellModal && !!sellTarget"
    :title="t('escalations.sellModal.title')"
    :subtitle="sellTarget?.type"
    :message="sellTarget ? `${sellTarget.solarSystemName} \u00b7 ${sellTarget.price}m ISK` : ''"
    :confirm-label="isSelling ? t('escalations.sellModal.confirming') : t('common.actions.confirm')"
    confirm-color="emerald"
    icon="check"
    :is-loading="isSelling"
    @confirm="confirmSell"
    @cancel="cancelSell"
  />

</template>
