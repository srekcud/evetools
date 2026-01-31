<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { authFetch } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { usePveStore } from '@/stores/pve'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import MainLayout from '@/layouts/MainLayout.vue'
import PveDashboard from '@/components/pve/PveDashboard.vue'
import IncomeChart from '@/components/pve/IncomeChart.vue'
import ProfitTrendChart from '@/components/pve/ProfitTrendChart.vue'
import ExpenseBreakdownChart from '@/components/pve/ExpenseBreakdownChart.vue'

const authStore = useAuthStore()
const pveStore = usePveStore()
const { formatIskFull, formatDate, formatDateTime } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

interface BountyEntry {
  id: number
  date: string
  refType: string
  refTypeLabel: string
  amount: number
  description: string
  characterName: string
}

interface Expense {
  id: string
  type: string
  description: string
  amount: number
  date: string
}

interface LootSaleEntry {
  id: string
  type: string
  description: string
  amount: number
  date: string
}

interface PveData {
  period: { from: string; to: string; days: number }
  lastSyncAt: string | null
  bounties: { total: number; count: number; entries: BountyEntry[] }
  lootSales: { total: number; count: number; entries: LootSaleEntry[] }
  expenses: { total: number; byType: Record<string, number> }
  profit: number
}

interface AmmoType {
  typeId: number
  typeName: string
}

interface DetectedExpense {
  contractId: number
  transactionId: number
  type: string
  typeId: number
  typeName: string
  quantity: number
  price: number
  dateIssued: string
  source: 'contract' | 'market'
  selected: boolean
}

interface ContractScanResult {
  scannedContracts: number
  scannedTransactions: number
  detectedExpenses: DetectedExpense[]
}

interface DetectedLootSale {
  transactionId: number
  contractId?: number
  type: string
  typeId: number
  typeName: string
  quantity: number
  price: number
  dateIssued: string
  characterName: string
  source?: 'market' | 'contract'
  selected: boolean
  needsPriceInput?: boolean  // True for 0 ISK contracts that need price input
}

interface LootSaleScanResult {
  scannedTransactions: number
  scannedContracts?: number
  detectedSales: DetectedLootSale[]
}

// State
const pveData = ref<PveData | null>(null)
const expenses = ref<Expense[]>([])
const isLoading = ref(false)
const isLoadingExpenses = ref(false)
const isSyncing = ref(false)
const error = ref('')
const selectedDays = ref(30)

// Calculate YTD days (from Jan 1st to today)
const ytdDays = computed(() => {
  const now = new Date()
  const startOfYear = new Date(now.getFullYear(), 0, 1)
  const diffTime = now.getTime() - startOfYear.getTime()
  return Math.ceil(diffTime / (1000 * 60 * 60 * 24))
})

// Add expense form
const showAddForm = ref(false)
const newExpense = ref({
  type: 'fuel',
  description: '',
  amount: '',
  date: new Date().toISOString().split('T')[0],
})
const isSubmitting = ref(false)
const formErrors = ref<{ description?: string; amount?: string }>({})

// Ammo settings
const ammoTypes = ref<AmmoType[]>([])
const showAmmoConfig = ref(false)
const ammoSearchQuery = ref('')
const ammoSearchResults = ref<AmmoType[]>([])
const isSearchingAmmo = ref(false)
const isAddingAmmo = ref(false)

// Loot types settings
const lootTypes = ref<AmmoType[]>([])
const showLootConfig = ref(false)
const lootSearchQuery = ref('')
const lootSearchResults = ref<AmmoType[]>([])
const isSearchingLoot = ref(false)
const isAddingLootType = ref(false)

// Contract scanning
const showScanResults = ref(false)
const scanResults = ref<ContractScanResult | null>(null)
const isScanning = ref(false)
const isImporting = ref(false)

// Loot sales
const showAddLootSaleForm = ref(false)
const newLootSale = ref({
  description: '',
  amount: '',
  date: new Date().toISOString().split('T')[0],
})
const lootSaleFormErrors = ref<{ description?: string; amount?: string }>({})
const isSubmittingLootSale = ref(false)

// Loot sales scanning
const showLootScanResults = ref(false)
const lootScanResults = ref<LootSaleScanResult | null>(null)
const isScanningLoot = ref(false)
const isImportingLoot = ref(false)

// Track seen contracts to avoid duplicates
const seenContractIds = ref<Set<number>>(new Set())
const seenTransactionIds = ref<Set<number>>(new Set())

const expenseTypes = [
  { value: 'fuel', label: 'Fuel' },
  { value: 'ammo', label: 'Consommables' },
  { value: 'crab_beacon', label: 'Crab Beacon' },
  { value: 'other', label: 'Autre' },
]

async function fetchPveData() {
  isLoading.value = true
  error.value = ''

  try {
    const response = await authFetch(`/api/pve/income?days=${selectedDays.value}`, {
      headers: { 'Authorization': `Bearer ${authStore.token}` }
    })

    if (!response.ok) throw new Error('Failed to fetch PVE data')

    pveData.value = await response.json()
  } catch (e) {
    error.value = 'Erreur lors du chargement des données PVE'
    console.error(e)
  } finally {
    isLoading.value = false
  }
}

async function fetchExpenses() {
  isLoadingExpenses.value = true

  try {
    const response = await authFetch(`/api/pve/expenses?days=${selectedDays.value}`, {
      headers: { 'Authorization': `Bearer ${authStore.token}` }
    })

    if (response.ok) {
      const data = await response.json()
      expenses.value = data.expenses
    }
  } catch (e) {
    console.error('Failed to fetch expenses:', e)
  } finally {
    isLoadingExpenses.value = false
  }
}

async function syncPveData() {
  isSyncing.value = true
  error.value = ''

  try {
    const response = await authFetch('/api/pve/sync', {
      method: 'POST',
      headers: { 'Authorization': `Bearer ${authStore.token}` }
    })

    if (!response.ok) {
      const data = await response.json()
      throw new Error(data.error || 'Failed to sync')
    }

    // Refresh data after sync
    await Promise.all([fetchPveData(), fetchExpenses()])
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Erreur lors de la synchronisation'
    console.error(e)
  } finally {
    isSyncing.value = false
  }
}

async function addExpense() {
  // Validate form
  formErrors.value = {}

  if (!newExpense.value.description.trim()) {
    formErrors.value.description = 'Description requise'
  }
  if (!newExpense.value.amount || parseFloat(newExpense.value.amount) <= 0) {
    formErrors.value.amount = 'Montant requis'
  }

  if (Object.keys(formErrors.value).length > 0) return

  isSubmitting.value = true

  try {
    const response = await authFetch('/api/pve/expenses', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${authStore.token}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        type: newExpense.value.type,
        description: newExpense.value.description,
        amount: parseFloat(newExpense.value.amount),
        date: newExpense.value.date,
      }),
    })

    if (!response.ok) throw new Error('Failed to add expense')

    // Reset form and refresh data
    newExpense.value = {
      type: 'fuel',
      description: '',
      amount: '',
      date: new Date().toISOString().split('T')[0],
    }
    showAddForm.value = false

    await Promise.all([fetchPveData(), fetchExpenses()])
  } catch (e) {
    error.value = 'Erreur lors de l\'ajout de la dépense'
    console.error(e)
  } finally {
    isSubmitting.value = false
  }
}

async function deleteExpense(id: string) {
  try {
    const response = await authFetch(`/api/pve/expenses/${id}`, {
      method: 'DELETE',
      headers: { 'Authorization': `Bearer ${authStore.token}` }
    })

    if (!response.ok) throw new Error('Failed to delete expense')

    await Promise.all([fetchPveData(), fetchExpenses()])
  } catch (e) {
    error.value = 'Erreur lors de la suppression'
    console.error(e)
  }
}

function getExpenseTypeLabel(type: string): string {
  return expenseTypes.find(t => t.value === type)?.label || type
}

function getExpenseTypeColor(type: string): string {
  switch (type) {
    case 'fuel': return 'bg-orange-500/20 text-orange-400'
    case 'ammo': return 'bg-red-500/20 text-red-400'
    case 'crab_beacon': return 'bg-purple-500/20 text-purple-400'
    default: return 'bg-slate-500/20 text-slate-400'
  }
}

onMounted(() => {
  fetchPveData()
  fetchExpenses()
  fetchAmmoSettings()
})

function onDaysChange() {
  fetchPveData()
  fetchExpenses()
}

function openAddExpenseForm() {
  formErrors.value = {}
  showAddForm.value = true
}

// Ammo settings functions
async function fetchAmmoSettings() {
  try {
    const response = await authFetch('/api/pve/settings', {
      headers: { 'Authorization': `Bearer ${authStore.token}` }
    })
    if (response.ok) {
      const data = await response.json()
      ammoTypes.value = data.ammoTypes
      lootTypes.value = data.lootTypes || []
    }
  } catch (e) {
    console.error('Failed to fetch ammo settings:', e)
  }
}

let searchTimeout: ReturnType<typeof setTimeout> | null = null
function onAmmoSearchInput() {
  if (searchTimeout) clearTimeout(searchTimeout)
  if (ammoSearchQuery.value.length < 2) {
    ammoSearchResults.value = []
    return
  }
  searchTimeout = setTimeout(() => searchAmmoTypes(), 300)
}

async function searchAmmoTypes() {
  if (ammoSearchQuery.value.length < 2) return

  isSearchingAmmo.value = true
  try {
    const response = await authFetch(`/api/pve/search-types?query=${encodeURIComponent(ammoSearchQuery.value)}`, {
      headers: { 'Authorization': `Bearer ${authStore.token}` }
    })
    if (response.ok) {
      const data = await response.json()
      // Filter out already added ammo types
      const existingIds = ammoTypes.value.map(a => a.typeId)
      ammoSearchResults.value = data.types.filter((t: AmmoType) => !existingIds.includes(t.typeId))
    }
  } catch (e) {
    console.error('Failed to search ammo types:', e)
  } finally {
    isSearchingAmmo.value = false
  }
}

async function addAmmoType(typeId: number) {
  isAddingAmmo.value = true
  try {
    const response = await authFetch('/api/pve/settings/ammo', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${authStore.token}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ typeId }),
    })
    if (response.ok) {
      await fetchAmmoSettings()
      ammoSearchQuery.value = ''
      ammoSearchResults.value = []
    }
  } catch (e) {
    console.error('Failed to add ammo type:', e)
  } finally {
    isAddingAmmo.value = false
  }
}

async function removeAmmoType(typeId: number) {
  try {
    const response = await authFetch(`/api/pve/settings/ammo/${typeId}`, {
      method: 'DELETE',
      headers: { 'Authorization': `Bearer ${authStore.token}` }
    })
    if (response.ok) {
      await fetchAmmoSettings()
    }
  } catch (e) {
    console.error('Failed to remove ammo type:', e)
  }
}

// Loot types settings functions
let lootSearchTimeout: ReturnType<typeof setTimeout> | null = null
function onLootSearchInput() {
  if (lootSearchTimeout) clearTimeout(lootSearchTimeout)
  if (lootSearchQuery.value.length < 2) {
    lootSearchResults.value = []
    return
  }
  lootSearchTimeout = setTimeout(() => searchLootTypes(), 300)
}

async function searchLootTypes() {
  if (lootSearchQuery.value.length < 2) return

  isSearchingLoot.value = true
  try {
    const response = await authFetch(`/api/pve/search-types?query=${encodeURIComponent(lootSearchQuery.value)}`, {
      headers: { 'Authorization': `Bearer ${authStore.token}` }
    })
    if (response.ok) {
      const data = await response.json()
      // Filter out already added loot types
      const existingIds = lootTypes.value.map(l => l.typeId)
      lootSearchResults.value = data.types.filter((t: AmmoType) => !existingIds.includes(t.typeId))
    }
  } catch (e) {
    console.error('Failed to search loot types:', e)
  } finally {
    isSearchingLoot.value = false
  }
}

async function addLootType(typeId: number) {
  isAddingLootType.value = true
  try {
    const response = await authFetch('/api/pve/settings/loot', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${authStore.token}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ typeId }),
    })
    if (response.ok) {
      await fetchAmmoSettings()
      lootSearchQuery.value = ''
      lootSearchResults.value = []
    }
  } catch (e) {
    console.error('Failed to add loot type:', e)
  } finally {
    isAddingLootType.value = false
  }
}

async function removeLootType(typeId: number) {
  try {
    const response = await authFetch(`/api/pve/settings/loot/${typeId}`, {
      method: 'DELETE',
      headers: { 'Authorization': `Bearer ${authStore.token}` }
    })
    if (response.ok) {
      await fetchAmmoSettings()
    }
  } catch (e) {
    console.error('Failed to remove loot type:', e)
  }
}

// Contract scanning functions
async function scanContracts() {
  isScanning.value = true
  error.value = ''

  try {
    const response = await authFetch(`/api/pve/scan-contracts?days=${selectedDays.value}`, {
      method: 'POST',
      headers: { 'Authorization': `Bearer ${authStore.token}` }
    })

    if (!response.ok) {
      const data = await response.json()
      throw new Error(data.error || 'Failed to scan contracts')
    }

    const data = await response.json()
    // Add selected property to each expense
    data.detectedExpenses = data.detectedExpenses.map((e: DetectedExpense) => ({ ...e, selected: true }))
    scanResults.value = data
    showScanResults.value = true
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Erreur lors du scan des contrats'
    console.error(e)
  } finally {
    isScanning.value = false
  }
}

function toggleExpenseSelection(expense: DetectedExpense) {
  expense.selected = !expense.selected
}

function selectAllExpenses() {
  if (scanResults.value) {
    scanResults.value.detectedExpenses.forEach(e => e.selected = true)
  }
}

function deselectAllExpenses() {
  if (scanResults.value) {
    scanResults.value.detectedExpenses.forEach(e => e.selected = false)
  }
}

async function importSelectedExpenses() {
  if (!scanResults.value) return

  const selectedExpenses = scanResults.value.detectedExpenses.filter(e => e.selected)
  const declinedExpenses = scanResults.value.detectedExpenses.filter(e => !e.selected)

  isImporting.value = true
  error.value = ''

  try {
    const response = await authFetch('/api/pve/import-expenses', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${authStore.token}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        expenses: selectedExpenses.map(e => ({
          type: e.type,
          typeId: e.typeId,
          typeName: e.typeName,
          quantity: e.quantity,
          price: e.price,
          dateIssued: e.dateIssued,
          contractId: e.contractId,
          transactionId: e.transactionId,
        })),
        declined: declinedExpenses.map(e => ({
          contractId: e.contractId,
          transactionId: e.transactionId,
        }))
      }),
    })

    if (!response.ok) throw new Error('Failed to import expenses')

    showScanResults.value = false
    scanResults.value = null

    // Refresh expenses
    await Promise.all([fetchPveData(), fetchExpenses()])
  } catch (e) {
    error.value = 'Erreur lors de l\'import des dépenses'
    console.error(e)
  } finally {
    isImporting.value = false
  }
}

// Loot sale functions
function openAddLootSaleForm() {
  lootSaleFormErrors.value = {}
  showAddLootSaleForm.value = true
}

async function addLootSale() {
  // Validate form
  lootSaleFormErrors.value = {}

  if (!newLootSale.value.description.trim()) {
    lootSaleFormErrors.value.description = 'Description requise'
  }
  if (!newLootSale.value.amount || parseFloat(newLootSale.value.amount) <= 0) {
    lootSaleFormErrors.value.amount = 'Montant requis'
  }

  if (Object.keys(lootSaleFormErrors.value).length > 0) return

  isSubmittingLootSale.value = true

  try {
    const response = await authFetch('/api/pve/loot-sales', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${authStore.token}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        description: newLootSale.value.description,
        amount: parseFloat(newLootSale.value.amount),
        date: newLootSale.value.date,
      }),
    })

    if (!response.ok) throw new Error('Failed to add loot sale')

    // Reset form and refresh data
    newLootSale.value = {
      description: '',
      amount: '',
      date: new Date().toISOString().split('T')[0],
    }
    showAddLootSaleForm.value = false

    await fetchPveData()
  } catch (e) {
    error.value = 'Erreur lors de l\'ajout de la vente'
    console.error(e)
  } finally {
    isSubmittingLootSale.value = false
  }
}

async function deleteLootSale(id: string) {
  try {
    const response = await authFetch(`/api/pve/loot-sales/${id}`, {
      method: 'DELETE',
      headers: { 'Authorization': `Bearer ${authStore.token}` }
    })

    if (!response.ok) throw new Error('Failed to delete loot sale')

    await fetchPveData()
  } catch (e) {
    error.value = 'Erreur lors de la suppression'
    console.error(e)
  }
}

async function scanLootSales() {
  isScanningLoot.value = true
  error.value = ''

  try {
    // Call both endpoints in parallel
    const [lootSalesResponse, lootContractsResponse] = await Promise.all([
      authFetch(`/api/pve/scan-loot-sales?days=${selectedDays.value}`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${authStore.token}` }
      }),
      authFetch(`/api/pve/scan-loot-contracts?days=${selectedDays.value}`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${authStore.token}` }
      })
    ])

    const lootSalesData = lootSalesResponse.ok ? await lootSalesResponse.json() : { detectedSales: [] }
    const lootContractsData = lootContractsResponse.ok ? await lootContractsResponse.json() : { detectedContracts: [] }

    // Transform loot sales - only market transactions (contracts are handled separately)
    const lootSales: DetectedLootSale[] = (lootSalesData.detectedSales || [])
      .filter((s: DetectedLootSale) => s.source !== 'contract') // Exclude contracts, they come from scan-loot-contracts
      .filter((s: DetectedLootSale) => !seenTransactionIds.value.has(s.transactionId))
      .map((s: DetectedLootSale) => ({
        ...s,
        selected: true,
        needsPriceInput: false
      }))

    // Transform loot contracts to DetectedLootSale format (filter out already seen)
    const lootContracts: DetectedLootSale[] = (lootContractsData.detectedContracts || [])
      .filter((c: { contractId: number }) => !seenContractIds.value.has(c.contractId))
      .map((c: {
        contractId: number
        description: string
        items: { typeId: number; typeName: string; quantity: number }[]
        totalQuantity: number
        contractPrice: number
        suggestedPrice: number
        date: string
        characterName: string
      }) => ({
        transactionId: 0,
        contractId: c.contractId,
        type: 'loot_contract',
        typeId: c.items[0]?.typeId || 0,
        typeName: c.description,
        quantity: c.totalQuantity,
        price: c.contractPrice,
        dateIssued: c.date,
        characterName: c.characterName,
        source: 'contract' as const,
        selected: true,
        needsPriceInput: c.contractPrice === 0 // Only editable if contract was at 0 ISK
      }))

    // Merge results
    const allSales = [...lootSales, ...lootContracts]

    // If no results and no loot types configured, show config modal
    if (allSales.length === 0 && lootSalesData.noLootTypesConfigured) {
      showLootConfig.value = true
      return
    }

    lootScanResults.value = {
      scannedTransactions: lootSalesData.scannedTransactions || 0,
      scannedContracts: (lootSalesData.scannedContracts || 0) + (lootContractsData.scannedContracts || 0),
      detectedSales: allSales
    }
    showLootScanResults.value = true
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Erreur lors du scan des ventes'
    console.error(e)
  } finally {
    isScanningLoot.value = false
  }
}

async function importSingleLootSale(sale: DetectedLootSale) {
  if (!lootScanResults.value) return

  isImportingLoot.value = true
  error.value = ''

  try {
    if (sale.source === 'contract') {
      // Import contract
      const response = await authFetch('/api/pve/import-loot-contracts', {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${authStore.token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          contracts: [{
            contractId: sale.contractId,
            price: sale.price,
            description: sale.typeName,
            date: sale.dateIssued,
          }],
          declined: []
        }),
      })

      if (!response.ok) throw new Error('Failed to import contract')

      const data = await response.json()
      if (data.rejectedZeroPrice > 0) {
        error.value = 'Contrat rejeté : le prix doit être supérieur à 0 ISK'
        return
      }

      // Add to seen and remove from list
      if (sale.contractId) {
        seenContractIds.value.add(sale.contractId)
      }
    } else {
      // Import market sale
      const response = await authFetch('/api/pve/import-loot-sales', {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${authStore.token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          sales: [{
            type: sale.type,
            typeId: sale.typeId,
            typeName: sale.typeName,
            quantity: sale.quantity,
            price: sale.price,
            dateIssued: sale.dateIssued,
            transactionId: sale.transactionId,
          }],
          declined: []
        }),
      })

      if (!response.ok) throw new Error('Failed to import sale')

      // Add to seen
      if (sale.transactionId) {
        seenTransactionIds.value.add(sale.transactionId)
      }
    }

    // Remove from list
    lootScanResults.value.detectedSales = lootScanResults.value.detectedSales.filter(s => s !== sale)

    // Refresh data
    await fetchPveData()
  } catch (e) {
    error.value = 'Erreur lors de l\'import'
    console.error(e)
  } finally {
    isImportingLoot.value = false
  }
}

async function ignoreLootSale(sale: DetectedLootSale) {
  if (!lootScanResults.value) return

  try {
    if (sale.source === 'contract') {
      // Add to declined contracts
      await authFetch('/api/pve/import-loot-contracts', {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${authStore.token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          contracts: [],
          declined: [{ contractId: sale.contractId }]
        }),
      })

      if (sale.contractId) {
        seenContractIds.value.add(sale.contractId)
      }
    } else {
      // Add to declined transactions
      await authFetch('/api/pve/import-loot-sales', {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${authStore.token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          sales: [],
          declined: [{ transactionId: sale.transactionId }]
        }),
      })

      if (sale.transactionId) {
        seenTransactionIds.value.add(sale.transactionId)
      }
    }

    // Remove from list
    lootScanResults.value.detectedSales = lootScanResults.value.detectedSales.filter(s => s !== sale)
  } catch (e) {
    error.value = 'Erreur lors de l\'ignorement'
    console.error(e)
  }
}

async function resetSeenContracts() {
  // Collect IDs currently visible in scan results - these should NOT be removed from declined
  const keepContractIds: number[] = []
  const keepTransactionIds: number[] = []

  if (lootScanResults.value) {
    for (const sale of lootScanResults.value.detectedSales) {
      if (sale.source === 'contract' && sale.contractId) {
        keepContractIds.push(sale.contractId)
      } else if (sale.transactionId) {
        keepTransactionIds.push(sale.transactionId)
      }
    }
  }

  try {
    // Clear declined lists in database, except for currently visible items
    await authFetch('/api/pve/settings/reset-declined', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${authStore.token}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        keepContractIds,
        keepTransactionIds,
      })
    })
  } catch (e) {
    console.error('Failed to reset declined:', e)
  }
  // Also clear local seen sets
  seenContractIds.value.clear()
  seenTransactionIds.value.clear()
}

</script>

<template>
  <MainLayout>
      <!-- Header -->
      <div class="flex items-center justify-between mb-6">
        <div>
          <p class="text-slate-400">Suivi des revenus et dépenses PVE</p>
          <p v-if="pveData?.lastSyncAt" class="text-xs text-slate-500 mt-1">
            Dernière sync : {{ formatDateTime(pveData.lastSyncAt) }}
          </p>
          <p v-else class="text-xs text-amber-500 mt-1">
            Aucune synchronisation effectuée
          </p>
        </div>
        <div class="flex items-center gap-3">
          <!-- Period selector -->
          <select
            v-model="selectedDays"
            @change="onDaysChange"
            class="bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-cyan-500"
          >
            <option :value="7">7 jours</option>
            <option :value="14">14 jours</option>
            <option :value="30">30 jours</option>
            <option :value="60">60 jours</option>
            <option :value="90">90 jours</option>
            <option :value="ytdDays">YTD ({{ ytdDays }} jours)</option>
          </select>
          <!-- Ammo config button -->
          <button
            @click="showAmmoConfig = true"
            class="px-4 py-2 bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-lg text-slate-300 text-sm font-medium flex items-center gap-2"
            title="Configurer les consommables"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            Consommables
          </button>
          <!-- Sync button -->
          <button
            @click="syncPveData"
            :disabled="isSyncing"
            class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 disabled:opacity-50"
            title="Synchroniser les données depuis EVE"
          >
            <svg :class="['w-4 h-4', isSyncing ? 'animate-spin' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            {{ isSyncing ? 'Sync...' : 'Rafraîchir' }}
          </button>
          <!-- Scan contracts button -->
          <button
            @click="scanContracts"
            :disabled="isScanning"
            class="px-4 py-2 bg-purple-600 hover:bg-purple-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 disabled:opacity-50"
          >
            <svg v-if="isScanning" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
            </svg>
            {{ isScanning ? 'Scan en cours...' : 'Scanner achats' }}
          </button>
          <!-- Add expense button -->
          <button
            type="button"
            @click="openAddExpenseForm"
            class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Ajouter dépense
          </button>
        </div>
      </div>

      <!-- Error -->
      <div v-if="error" class="mb-6 p-4 bg-red-500/20 border border-red-500/50 rounded-lg text-red-400 flex items-center justify-between">
        <span>{{ error }}</span>
        <button @click="error = ''" class="text-red-400 hover:text-red-300">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>

      <!-- Loading -->
      <div v-if="isLoading" class="flex flex-col items-center justify-center py-20">
        <svg class="w-10 h-10 animate-spin text-cyan-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        <p class="text-slate-400">Chargement des données...</p>
      </div>

      <template v-else-if="pveData">
        <!-- Dashboard with Charts -->
        <div class="mb-8">
          <PveDashboard :days="selectedDays" />
        </div>

        <!-- Three columns: Bounties, Loot Sales, and Expenses -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <!-- Bounties list -->
          <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-800">
              <h3 class="font-semibold">Bounties récentes</h3>
              <p class="text-sm text-slate-500">Revenus NPC des {{ pveData.period.days }} derniers jours</p>
            </div>
            <div v-if="pveData.bounties.entries.length > 0" class="divide-y divide-slate-800 max-h-96 overflow-y-auto">
              <div
                v-for="entry in pveData.bounties.entries"
                :key="entry.id"
                class="px-5 py-3 flex items-center justify-between"
              >
                <div class="flex items-center gap-2">
                  <span :class="[
                    'text-xs px-2 py-0.5 rounded',
                    entry.refType === 'ess' ? 'bg-violet-500/20 text-violet-400' :
                    entry.refType === 'mission' ? 'bg-sky-500/20 text-sky-400' :
                    'bg-emerald-500/20 text-emerald-400'
                  ]">{{ entry.refTypeLabel }}</span>
                  <div>
                    <p class="text-sm text-slate-200">{{ entry.characterName }}</p>
                    <p class="text-xs text-slate-500">{{ formatDateTime(entry.date) }}</p>
                  </div>
                </div>
                <span class="text-emerald-400 font-mono text-sm">+{{ formatIskFull(entry.amount) }}</span>
              </div>
            </div>
            <div v-else class="px-5 py-8 text-center text-slate-500">
              Aucune bounty sur cette période
            </div>
          </div>

          <!-- Loot Sales list -->
          <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
              <div>
                <h3 class="font-semibold">Ventes de loot</h3>
              </div>
              <div class="flex gap-2">
                <button
                  @click="showLootConfig = true"
                  class="p-1.5 bg-slate-700 hover:bg-slate-600 rounded text-white"
                  title="Configurer les types de loot"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                  </svg>
                </button>
                <button
                  @click="scanLootSales"
                  :disabled="isScanningLoot"
                  class="p-1.5 bg-amber-600 hover:bg-amber-500 rounded text-white disabled:opacity-50"
                  title="Scanner les ventes"
                >
                  <svg v-if="isScanningLoot" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                  </svg>
                  <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                  </svg>
                </button>
                <button
                  v-if="seenContractIds.size > 0 || seenTransactionIds.size > 0"
                  @click="resetSeenContracts"
                  class="p-1.5 bg-slate-700 hover:bg-red-600 rounded text-slate-400 hover:text-white"
                  title="Réinitialiser le filtre (réafficher les contrats déjà vus)"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                  </svg>
                </button>
                <button
                  type="button"
                  @click="openAddLootSaleForm"
                  class="p-1.5 bg-slate-700 hover:bg-slate-600 rounded text-white"
                  title="Ajouter manuellement"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                  </svg>
                </button>
              </div>
            </div>
            <div v-if="pveData.lootSales.entries.length > 0" class="divide-y divide-slate-800 max-h-96 overflow-y-auto">
              <div
                v-for="sale in pveData.lootSales.entries"
                :key="sale.id"
                class="px-5 py-3 flex items-center justify-between group"
              >
                <div class="flex items-center gap-3">
                  <span class="text-xs px-2 py-1 rounded bg-amber-500/20 text-amber-400">Loot</span>
                  <div>
                    <p class="text-sm text-slate-200">{{ sale.description }}</p>
                    <p class="text-xs text-slate-500">{{ formatDate(sale.date) }}</p>
                  </div>
                </div>
                <div class="flex items-center gap-3">
                  <span class="text-amber-400 font-mono text-sm">+{{ formatIskFull(sale.amount) }}</span>
                  <button
                    @click="deleteLootSale(sale.id)"
                    class="opacity-0 group-hover:opacity-100 p-1 hover:bg-red-500/20 rounded text-slate-400 hover:text-red-400 transition-all"
                    title="Supprimer"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                  </button>
                </div>
              </div>
            </div>
            <div v-else class="px-5 py-8 text-center text-slate-500">
              Aucune vente enregistrée
            </div>
          </div>

          <!-- Expenses list -->
          <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-800">
              <h3 class="font-semibold">Dépenses</h3>
              <p class="text-sm text-slate-500">Fuel, consommables, beacons...</p>
            </div>
            <div v-if="expenses.length > 0" class="divide-y divide-slate-800 max-h-96 overflow-y-auto">
              <div
                v-for="expense in expenses"
                :key="expense.id"
                class="px-5 py-3 flex items-center justify-between group"
              >
                <div class="flex items-center gap-3">
                  <span :class="['text-xs px-2 py-1 rounded', getExpenseTypeColor(expense.type)]">
                    {{ getExpenseTypeLabel(expense.type) }}
                  </span>
                  <div>
                    <p class="text-sm text-slate-200">{{ expense.description }}</p>
                    <p class="text-xs text-slate-500">{{ formatDate(expense.date) }}</p>
                  </div>
                </div>
                <div class="flex items-center gap-3">
                  <span class="text-red-400 font-mono text-sm">-{{ formatIskFull(expense.amount) }}</span>
                  <button
                    @click="deleteExpense(expense.id)"
                    class="opacity-0 group-hover:opacity-100 p-1 hover:bg-red-500/20 rounded text-slate-400 hover:text-red-400 transition-all"
                    title="Supprimer"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                  </button>
                </div>
              </div>
            </div>
            <div v-else class="px-5 py-8 text-center text-slate-500">
              Aucune dépense enregistrée
            </div>
          </div>
        </div>

        <!-- Charts Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
          <!-- Income Chart -->
          <IncomeChart
            v-if="pveStore.dailyStats.length > 0"
            :data="pveStore.dailyStats"
          />

          <!-- Profit Trend Chart -->
          <ProfitTrendChart
            v-if="pveStore.dailyStats.length > 0"
            :data="pveStore.dailyStats"
          />

          <!-- Expense Breakdown Chart -->
          <ExpenseBreakdownChart
            v-if="pveStore.stats && pveStore.stats.totals.expenses > 0"
            :data="{
              fuel: pveStore.stats.expensesByType?.fuel ?? 0,
              ammo: pveStore.stats.expensesByType?.ammo ?? 0,
              crab_beacon: pveStore.stats.expensesByType?.crab_beacon ?? 0,
              other: pveStore.stats.expensesByType?.other ?? 0,
            }"
          />
        </div>
      </template>

    <!-- Add Expense Modal -->
    <Teleport to="body">
      <div v-if="showAddForm" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" @click.self="showAddForm = false">
        <div class="bg-slate-900 rounded-xl border border-slate-700 max-w-md w-full p-6">
          <h3 class="text-lg font-semibold mb-4">Ajouter une dépense</h3>

          <div class="space-y-4">
            <!-- Type -->
            <div>
              <label class="block text-sm text-slate-400 mb-1">Type</label>
              <select
                v-model="newExpense.type"
                class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-cyan-500"
              >
                <option v-for="t in expenseTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
              </select>
            </div>

            <!-- Description -->
            <div>
              <label class="block text-sm text-slate-400 mb-1">Description <span class="text-red-400">*</span></label>
              <input
                v-model="newExpense.description"
                type="text"
                placeholder="Ex: 500 Helium Isotopes"
                :class="[
                  'w-full bg-slate-800 border rounded-lg px-4 py-2 text-sm focus:outline-none',
                  formErrors.description ? 'border-red-500 focus:border-red-500' : 'border-slate-700 focus:border-cyan-500'
                ]"
              />
              <p v-if="formErrors.description" class="text-red-400 text-xs mt-1">{{ formErrors.description }}</p>
            </div>

            <!-- Amount -->
            <div>
              <label class="block text-sm text-slate-400 mb-1">Montant (ISK) <span class="text-red-400">*</span></label>
              <input
                v-model="newExpense.amount"
                type="number"
                placeholder="150000000"
                :class="[
                  'w-full bg-slate-800 border rounded-lg px-4 py-2 text-sm focus:outline-none',
                  formErrors.amount ? 'border-red-500 focus:border-red-500' : 'border-slate-700 focus:border-cyan-500'
                ]"
              />
              <p v-if="formErrors.amount" class="text-red-400 text-xs mt-1">{{ formErrors.amount }}</p>
            </div>

            <!-- Date -->
            <div>
              <label class="block text-sm text-slate-400 mb-1">Date</label>
              <input
                v-model="newExpense.date"
                type="date"
                class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-cyan-500"
              />
            </div>
          </div>

          <div class="flex gap-3 mt-6">
            <button
              @click="showAddForm = false"
              :disabled="isSubmitting"
              class="flex-1 py-2 bg-slate-800 hover:bg-slate-700 rounded-lg disabled:opacity-50"
            >
              Annuler
            </button>
            <button
              type="button"
              @click="addExpense"
              :disabled="isSubmitting"
              class="flex-1 py-2 bg-cyan-600 hover:bg-cyan-500 text-white font-medium rounded-lg disabled:opacity-50 flex items-center justify-center gap-2"
            >
              <svg v-if="isSubmitting" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
              {{ isSubmitting ? 'Ajout...' : 'Ajouter' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Ammo Config Modal -->
    <Teleport to="body">
      <div v-if="showAmmoConfig" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" @click.self="showAmmoConfig = false">
        <div class="bg-slate-900 rounded-xl border border-slate-700 max-w-lg w-full p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold">Consommables PVE</h3>
            <button @click="showAmmoConfig = false" class="text-slate-400 hover:text-slate-300">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>

          <p class="text-sm text-slate-400 mb-4">
            Ajoutez les consommables que vous utilisez pour le PVE : drogues, munitions, fuel (isotopes), etc. Ils seront détectés lors du scan des contrats et transactions.
          </p>

          <!-- Search input -->
          <div class="relative mb-4">
            <input
              v-model="ammoSearchQuery"
              @input="onAmmoSearchInput"
              type="text"
              placeholder="Rechercher un consommable (drogue, munition, isotope...)"
              class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-cyan-500 pr-10"
            />
            <div v-if="isSearchingAmmo" class="absolute right-3 top-2.5">
              <svg class="w-5 h-5 animate-spin text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
            </div>
          </div>

          <!-- Search results -->
          <div v-if="ammoSearchResults.length > 0" class="mb-4 bg-slate-800 rounded-lg border border-slate-700 max-h-48 overflow-y-auto">
            <button
              v-for="result in ammoSearchResults"
              :key="result.typeId"
              @click="addAmmoType(result.typeId)"
              :disabled="isAddingAmmo"
              class="w-full px-4 py-2 text-left hover:bg-slate-700 text-sm flex items-center justify-between group disabled:opacity-50"
            >
              <span>{{ result.typeName }}</span>
              <svg class="w-4 h-4 text-cyan-500 opacity-0 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
              </svg>
            </button>
          </div>

          <!-- Configured ammo types -->
          <div class="mb-2 text-sm text-slate-400">Items configurés :</div>
          <div v-if="ammoTypes.length > 0" class="space-y-2 max-h-48 overflow-y-auto">
            <div
              v-for="ammo in ammoTypes"
              :key="ammo.typeId"
              class="flex items-center justify-between bg-slate-800 rounded-lg px-4 py-2"
            >
              <div class="flex items-center gap-3">
                <img
                  :src="getTypeIconUrl(ammo.typeId, 32)"
                  :alt="ammo.typeName"
                  class="w-6 h-6 rounded"
                  @error="onImageError"
                />
                <span class="text-sm">{{ ammo.typeName }}</span>
              </div>
              <button
                @click="removeAmmoType(ammo.typeId)"
                class="p-1 hover:bg-red-500/20 rounded text-slate-400 hover:text-red-400 transition-colors"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>
          </div>
          <div v-else class="text-center py-6 text-slate-500">
            Aucun item configuré
          </div>

          <div class="mt-6 pt-4 border-t border-slate-800">
            <button
              @click="showAmmoConfig = false"
              class="w-full py-2 bg-slate-800 hover:bg-slate-700 rounded-lg text-white font-medium"
            >
              Fermer
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Loot Config Modal -->
    <Teleport to="body">
      <div v-if="showLootConfig" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" @click.self="showLootConfig = false">
        <div class="bg-slate-900 rounded-xl border border-slate-700 max-w-lg w-full p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold">Types de loot</h3>
            <button @click="showLootConfig = false" class="text-slate-400 hover:text-slate-300">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>

          <p class="text-sm text-slate-400 mb-4">
            Ajoutez les types de loot que vous vendez (OPE, blue loot, etc.). Seuls ces items seront detectes lors du scan des ventes.
          </p>

          <!-- Search input -->
          <div class="relative mb-4">
            <input
              v-model="lootSearchQuery"
              @input="onLootSearchInput"
              type="text"
              placeholder="Rechercher un item (ex: Rogue Drone Infestation Data)"
              class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-amber-500 pr-10"
            />
            <div v-if="isSearchingLoot" class="absolute right-3 top-2.5">
              <svg class="w-5 h-5 animate-spin text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
            </div>
          </div>

          <!-- Search results -->
          <div v-if="lootSearchResults.length > 0" class="mb-4 bg-slate-800 rounded-lg border border-slate-700 max-h-48 overflow-y-auto">
            <button
              v-for="result in lootSearchResults"
              :key="result.typeId"
              @click="addLootType(result.typeId)"
              :disabled="isAddingLootType"
              class="w-full px-4 py-2 text-left hover:bg-slate-700 text-sm flex items-center justify-between group disabled:opacity-50"
            >
              <span>{{ result.typeName }}</span>
              <svg class="w-4 h-4 text-amber-500 opacity-0 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
              </svg>
            </button>
          </div>

          <!-- Configured loot types -->
          <div class="mb-2 text-sm text-slate-400">Items configures :</div>
          <div v-if="lootTypes.length > 0" class="space-y-2 max-h-48 overflow-y-auto">
            <div
              v-for="loot in lootTypes"
              :key="loot.typeId"
              class="flex items-center justify-between bg-slate-800 rounded-lg px-4 py-2"
            >
              <div class="flex items-center gap-3">
                <img
                  :src="getTypeIconUrl(loot.typeId, 32)"
                  :alt="loot.typeName"
                  class="w-6 h-6 rounded"
                  @error="onImageError"
                />
                <span class="text-sm">{{ loot.typeName }}</span>
              </div>
              <button
                @click="removeLootType(loot.typeId)"
                class="p-1 hover:bg-red-500/20 rounded text-slate-400 hover:text-red-400 transition-colors"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>
          </div>
          <div v-else class="text-center py-6 text-slate-500">
            Aucun type de loot configure
          </div>

          <div class="mt-6 pt-4 border-t border-slate-800">
            <button
              @click="showLootConfig = false"
              class="w-full py-2 bg-slate-800 hover:bg-slate-700 rounded-lg text-white font-medium"
            >
              Fermer
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Contract Scan Results Modal -->
    <Teleport to="body">
      <div v-if="showScanResults && scanResults" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" @click.self="showScanResults = false">
        <div class="bg-slate-900 rounded-xl border border-slate-700 max-w-2xl w-full max-h-[80vh] flex flex-col">
          <div class="p-6 border-b border-slate-800">
            <div class="flex items-center justify-between">
              <div>
                <h3 class="text-lg font-semibold">Résultats du scan</h3>
                <p class="text-sm text-slate-400 mt-1">
                  {{ scanResults.scannedContracts }} contrats, {{ scanResults.scannedTransactions }} transactions marché - {{ scanResults.detectedExpenses.length }} dépenses détectées
                </p>
              </div>
              <button @click="showScanResults = false" class="text-slate-400 hover:text-slate-300">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>
          </div>

          <div v-if="scanResults.detectedExpenses.length > 0" class="flex-1 overflow-y-auto p-6">
            <!-- Selection controls -->
            <div class="flex items-center gap-3 mb-4">
              <button @click="selectAllExpenses" class="text-sm text-cyan-400 hover:text-cyan-300">Tout sélectionner</button>
              <span class="text-slate-600">|</span>
              <button @click="deselectAllExpenses" class="text-sm text-slate-400 hover:text-slate-300">Tout désélectionner</button>
            </div>

            <!-- Expenses list -->
            <div class="space-y-3">
              <div
                v-for="expense in scanResults.detectedExpenses"
                :key="`${expense.contractId}-${expense.typeId}`"
                @click="toggleExpenseSelection(expense)"
                :class="[
                  'p-4 rounded-lg border cursor-pointer transition-colors',
                  expense.selected
                    ? 'bg-slate-800 border-cyan-500/50'
                    : 'bg-slate-800/50 border-slate-700 opacity-60'
                ]"
              >
                <div class="flex items-start gap-4">
                  <div class="flex-shrink-0 pt-1">
                    <div :class="[
                      'w-5 h-5 rounded border-2 flex items-center justify-center',
                      expense.selected ? 'border-cyan-500 bg-cyan-500' : 'border-slate-600'
                    ]">
                      <svg v-if="expense.selected" class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                      </svg>
                    </div>
                  </div>
                  <img
                    :src="getTypeIconUrl(expense.typeId, 64)"
                    :alt="expense.typeName"
                    class="w-12 h-12 rounded"
                    @error="onImageError"
                  />
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                      <span :class="['text-xs px-2 py-0.5 rounded', getExpenseTypeColor(expense.type)]">
                        {{ getExpenseTypeLabel(expense.type) }}
                      </span>
                      <span v-if="expense.source === 'contract'" class="text-xs text-slate-500">
                        Contrat #{{ expense.contractId }}
                      </span>
                      <span v-else class="text-xs text-blue-400">
                        Marché
                      </span>
                    </div>
                    <p class="text-sm text-slate-300">{{ expense.typeName }}</p>
                  </div>
                  <div class="text-right">
                    <p class="text-red-400 font-mono">{{ formatIskFull(expense.price) }}</p>
                    <p class="text-xs text-slate-500">{{ formatDate(expense.dateIssued) }}</p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div v-else class="flex-1 flex items-center justify-center p-6">
            <div class="text-center text-slate-500">
              <svg class="w-12 h-12 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M12 12h.01M12 12h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              <p>Aucune dépense détectée dans vos contrats</p>
              <p class="text-sm mt-1">Vérifiez la configuration des consommables ou la période</p>
            </div>
          </div>

          <div v-if="scanResults.detectedExpenses.length > 0" class="p-6 border-t border-slate-800">
            <div class="flex items-center justify-between mb-4">
              <span class="text-sm text-slate-400">
                {{ scanResults.detectedExpenses.filter(e => e.selected).length }} dépense(s) sélectionnée(s)
              </span>
              <span class="text-red-400 font-mono">
                Total: {{ formatIskFull(scanResults.detectedExpenses.filter(e => e.selected).reduce((sum, e) => sum + e.price, 0)) }}
              </span>
            </div>
            <div class="flex gap-3">
              <button
                @click="showScanResults = false"
                class="flex-1 py-2 bg-slate-800 hover:bg-slate-700 rounded-lg"
              >
                Annuler
              </button>
              <button
                @click="importSelectedExpenses"
                :disabled="isImporting || scanResults.detectedExpenses.filter(e => e.selected).length === 0"
                class="flex-1 py-2 bg-cyan-600 hover:bg-cyan-500 text-white font-medium rounded-lg disabled:opacity-50 flex items-center justify-center gap-2"
              >
                <svg v-if="isImporting" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                {{ isImporting ? 'Import...' : 'Importer' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Add Loot Sale Modal -->
    <Teleport to="body">
      <div v-if="showAddLootSaleForm" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" @click.self="showAddLootSaleForm = false">
        <div class="bg-slate-900 rounded-xl border border-slate-700 max-w-md w-full p-6">
          <h3 class="text-lg font-semibold mb-4">Ajouter une vente de loot</h3>

          <div class="space-y-4">
            <!-- Description -->
            <div>
              <label class="block text-sm text-slate-400 mb-1">Description <span class="text-red-400">*</span></label>
              <input
                v-model="newLootSale.description"
                type="text"
                placeholder="Ex: 100x Overseer's Personal Effects"
                :class="[
                  'w-full bg-slate-800 border rounded-lg px-4 py-2 text-sm focus:outline-none',
                  lootSaleFormErrors.description ? 'border-red-500 focus:border-red-500' : 'border-slate-700 focus:border-amber-500'
                ]"
              />
              <p v-if="lootSaleFormErrors.description" class="text-red-400 text-xs mt-1">{{ lootSaleFormErrors.description }}</p>
            </div>

            <!-- Amount -->
            <div>
              <label class="block text-sm text-slate-400 mb-1">Montant (ISK) <span class="text-red-400">*</span></label>
              <input
                v-model="newLootSale.amount"
                type="number"
                placeholder="500000000"
                :class="[
                  'w-full bg-slate-800 border rounded-lg px-4 py-2 text-sm focus:outline-none',
                  lootSaleFormErrors.amount ? 'border-red-500 focus:border-red-500' : 'border-slate-700 focus:border-amber-500'
                ]"
              />
              <p v-if="lootSaleFormErrors.amount" class="text-red-400 text-xs mt-1">{{ lootSaleFormErrors.amount }}</p>
            </div>

            <!-- Date -->
            <div>
              <label class="block text-sm text-slate-400 mb-1">Date</label>
              <input
                v-model="newLootSale.date"
                type="date"
                class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-amber-500"
              />
            </div>
          </div>

          <div class="flex gap-3 mt-6">
            <button
              @click="showAddLootSaleForm = false"
              :disabled="isSubmittingLootSale"
              class="flex-1 py-2 bg-slate-800 hover:bg-slate-700 rounded-lg disabled:opacity-50"
            >
              Annuler
            </button>
            <button
              type="button"
              @click="addLootSale"
              :disabled="isSubmittingLootSale"
              class="flex-1 py-2 bg-amber-600 hover:bg-amber-500 text-white font-medium rounded-lg disabled:opacity-50 flex items-center justify-center gap-2"
            >
              <svg v-if="isSubmittingLootSale" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
              {{ isSubmittingLootSale ? 'Ajout...' : 'Ajouter' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Loot Sale Scan Results Modal -->
    <Teleport to="body">
      <div v-if="showLootScanResults && lootScanResults" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" @click.self="showLootScanResults = false">
        <div class="bg-slate-900 rounded-xl border border-slate-700 max-w-2xl w-full max-h-[80vh] flex flex-col">
          <div class="p-6 border-b border-slate-800">
            <div class="flex items-center justify-between">
              <div>
                <h3 class="text-lg font-semibold">Ventes de loot détectées</h3>
                <p class="text-sm text-slate-400 mt-1">
                  {{ lootScanResults.scannedTransactions }} transactions, {{ lootScanResults.scannedContracts || 0 }} contrats - {{ lootScanResults.detectedSales.length }} ventes détectées
                </p>
              </div>
              <button @click="showLootScanResults = false" class="text-slate-400 hover:text-slate-300">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>
          </div>

          <div v-if="lootScanResults.detectedSales.length > 0" class="flex-1 overflow-y-auto p-6">
            <!-- Sales list -->
            <div class="space-y-3">
              <div
                v-for="sale in lootScanResults.detectedSales"
                :key="sale.source === 'contract' ? `contract-${sale.contractId}` : `tx-${sale.transactionId}`"
                class="p-4 rounded-lg border bg-slate-800 border-slate-700"
              >
                <div class="flex items-start gap-4">
                  <img
                    :src="getTypeIconUrl(sale.typeId, 64)"
                    :alt="sale.typeName"
                    class="w-12 h-12 rounded"
                    @error="onImageError"
                  />
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                      <span v-if="sale.source === 'contract'" class="text-xs px-2 py-0.5 rounded bg-amber-500/20 text-amber-400">
                        Contrat #{{ sale.contractId }}
                      </span>
                      <span v-else class="text-xs px-2 py-0.5 rounded bg-blue-500/20 text-blue-400">
                        Marché
                      </span>
                      <span class="text-xs text-slate-500">{{ sale.characterName }}</span>
                    </div>
                    <p class="text-sm text-slate-300">{{ sale.typeName }}</p>
                    <p class="text-xs text-slate-500">{{ formatDate(sale.dateIssued) }}</p>
                  </div>
                  <div class="flex flex-col items-end gap-2">
                    <!-- Editable price for 0 ISK contracts -->
                    <div v-if="sale.source === 'contract' && sale.price === 0" class="flex items-center gap-1">
                      <input
                        type="number"
                        v-model.number="sale.price"
                        class="w-28 px-2 py-1 text-right bg-slate-700 border border-slate-600 rounded text-amber-400 font-mono text-sm focus:outline-none focus:border-amber-500"
                        min="0"
                        step="100000"
                        placeholder="Prix"
                      />
                      <span class="text-xs text-slate-500">ISK</span>
                    </div>
                    <!-- Fixed price display -->
                    <p v-else class="text-amber-400 font-mono text-sm">+{{ formatIskFull(sale.price) }}</p>
                    <!-- Action buttons -->
                    <div class="flex gap-2">
                      <button
                        @click="importSingleLootSale(sale)"
                        :disabled="isImportingLoot || (sale.source === 'contract' && sale.price <= 0)"
                        class="px-3 py-1 text-xs bg-emerald-600 hover:bg-emerald-500 disabled:bg-slate-600 disabled:cursor-not-allowed rounded text-white font-medium"
                      >
                        Ajouter
                      </button>
                      <button
                        @click="ignoreLootSale(sale)"
                        :disabled="isImportingLoot"
                        class="px-3 py-1 text-xs bg-slate-600 hover:bg-slate-500 disabled:opacity-50 rounded text-slate-300 font-medium"
                      >
                        Ignorer
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div v-else class="flex-1 flex items-center justify-center p-6">
            <div class="text-center text-slate-500">
              <svg class="w-12 h-12 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
              </svg>
              <p>Aucune vente de loot détectée</p>
              <p class="text-sm mt-1">Vendez du loot sur le marché pour le voir apparaitre ici</p>
            </div>
          </div>

          <div v-if="lootScanResults.detectedSales.length > 0" class="p-4 border-t border-slate-800">
            <div class="flex items-center justify-between">
              <span class="text-sm text-slate-400">
                {{ lootScanResults.detectedSales.length }} contrat(s) en attente
              </span>
              <button
                @click="showLootScanResults = false"
                class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-sm"
              >
                Fermer
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </MainLayout>
</template>
