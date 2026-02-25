import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { authFetch, safeJsonParse } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import type {
  ContractScanResult,
  DetectedExpense,
  DetectedLootSale,
  LootSaleScanResult,
} from '@/types/pve'

export function usePveExpenseScanning(
  onDataRefresh: () => Promise<void>,
  onError: (message: string) => void,
  onOpenLootConfig: () => void,
) {
  const { t } = useI18n()
  const authStore = useAuthStore()

  // Contract scanning state
  const showScanResults = ref(false)
  const scanResults = ref<ContractScanResult | null>(null)
  const isScanning = ref(false)
  const isImporting = ref(false)

  // Loot sales scanning state
  const showLootScanResults = ref(false)
  const lootScanResults = ref<LootSaleScanResult | null>(null)
  const isScanningLoot = ref(false)
  const isImportingLoot = ref(false)

  // Track seen contracts to avoid duplicates
  const seenContractIds = ref<Set<number>>(new Set())
  const seenTransactionIds = ref<Set<number>>(new Set())

  // Declined counts from backend (persisted)
  const declinedContractsCount = ref(0)
  const declinedTransactionsCount = ref(0)

  function setDeclinedCounts(contracts: number, transactions: number) {
    declinedContractsCount.value = contracts
    declinedTransactionsCount.value = transactions
  }

  async function scanContracts(selectedDays: number) {
    isScanning.value = true

    try {
      const response = await authFetch(`/api/pve/scan-contracts?days=${selectedDays}`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${authStore.token}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({})
      })

      if (!response.ok) {
        const data = await safeJsonParse<{ error?: string }>(response)
        throw new Error(data.error || 'Failed to scan contracts')
      }

      const data = await safeJsonParse<ContractScanResult>(response)
      data.detectedExpenses = data.detectedExpenses.map((e: DetectedExpense) => ({ ...e, selected: true }))
      scanResults.value = data
      showScanResults.value = true
    } catch (e: unknown) {
      onError(e instanceof Error ? e.message : t('pve.errors.scanFailed'))
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

      await onDataRefresh()
    } catch (e) {
      onError(t('pve.errors.importFailed'))
      console.error(e)
    } finally {
      isImporting.value = false
    }
  }

  async function scanLootSales(selectedDays: number) {
    isScanningLoot.value = true

    try {
      const [lootSalesResponse, lootContractsResponse] = await Promise.all([
        authFetch(`/api/pve/scan-loot-sales?days=${selectedDays}`, {
          method: 'POST',
          headers: {
            'Authorization': `Bearer ${authStore.token}`,
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({})
        }),
        authFetch(`/api/pve/scan-loot-contracts?days=${selectedDays}`, {
          method: 'POST',
          headers: {
            'Authorization': `Bearer ${authStore.token}`,
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({})
        })
      ])

      const lootSalesData = lootSalesResponse.ok ? await lootSalesResponse.json() : { detectedSales: [] }
      const lootContractsData = lootContractsResponse.ok ? await lootContractsResponse.json() : { detectedContracts: [] }

      const lootSales: DetectedLootSale[] = (lootSalesData.detectedSales || [])
        .filter((s: DetectedLootSale) => s.source !== 'contract')
        .filter((s: DetectedLootSale) => !seenTransactionIds.value.has(s.transactionId))
        .map((s: DetectedLootSale) => ({
          ...s,
          selected: true,
          needsPriceInput: s.source === 'corp_project' && s.price === 0
        }))

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
          needsPriceInput: c.contractPrice === 0
        }))

      const allSales = [...lootSales, ...lootContracts]

      if (allSales.length === 0 && lootSalesData.noLootTypesConfigured) {
        onOpenLootConfig()
        return
      }

      lootScanResults.value = {
        scannedTransactions: lootSalesData.scannedTransactions || 0,
        scannedContracts: (lootSalesData.scannedContracts || 0) + (lootContractsData.scannedContracts || 0),
        scannedProjects: lootSalesData.scannedProjects || 0,
        detectedSales: allSales
      }
      showLootScanResults.value = true
    } catch (e: unknown) {
      onError(e instanceof Error ? e.message : t('pve.errors.scanLootFailed'))
      console.error(e)
    } finally {
      isScanningLoot.value = false
    }
  }

  async function importSingleLootSale(sale: DetectedLootSale) {
    if (!lootScanResults.value) return

    isImportingLoot.value = true

    try {
      if (sale.source === 'contract') {
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

        const data = await safeJsonParse<{ rejectedZeroPrice?: number }>(response)
        if (data.rejectedZeroPrice && data.rejectedZeroPrice > 0) {
          onError(t('pve.errors.contractZeroPrice'))
          return
        }

        if (sale.contractId) {
          seenContractIds.value.add(sale.contractId)
        }
      } else if (sale.source === 'corp_project') {
        const response = await authFetch('/api/pve/import-loot-sales', {
          method: 'POST',
          headers: {
            'Authorization': `Bearer ${authStore.token}`,
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            sales: [{
              type: 'corp_project',
              typeId: sale.typeId,
              typeName: `${sale.projectName || 'Projet corp'}: ${sale.typeName}`,
              quantity: sale.quantity,
              price: sale.price,
              dateIssued: sale.dateIssued,
              transactionId: sale.transactionId,
            }],
            declined: []
          }),
        })

        if (!response.ok) throw new Error('Failed to import contribution')

        if (sale.transactionId) {
          seenTransactionIds.value.add(sale.transactionId)
        }
      } else {
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

        if (sale.transactionId) {
          seenTransactionIds.value.add(sale.transactionId)
        }
      }

      lootScanResults.value.detectedSales = lootScanResults.value.detectedSales.filter(s => s !== sale)

      await onDataRefresh()
    } catch (e) {
      onError(t('pve.errors.importFailed'))
      console.error(e)
    } finally {
      isImportingLoot.value = false
    }
  }

  async function ignoreLootSale(sale: DetectedLootSale) {
    if (!lootScanResults.value) return

    try {
      if (sale.source === 'contract') {
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

      lootScanResults.value.detectedSales = lootScanResults.value.detectedSales.filter(s => s !== sale)
    } catch (e) {
      onError(t('pve.errors.ignoreFailed'))
      console.error(e)
    }
  }

  async function resetSeenContracts() {
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
      declinedContractsCount.value = keepContractIds.length
      declinedTransactionsCount.value = keepTransactionIds.length
    } catch (e) {
      console.error('Failed to reset declined:', e)
    }
    seenContractIds.value.clear()
    seenTransactionIds.value.clear()
  }

  return {
    // Contract scanning
    showScanResults,
    scanResults,
    isScanning,
    isImporting,
    scanContracts,
    toggleExpenseSelection,
    selectAllExpenses,
    deselectAllExpenses,
    importSelectedExpenses,

    // Loot scanning
    showLootScanResults,
    lootScanResults,
    isScanningLoot,
    isImportingLoot,
    scanLootSales,
    importSingleLootSale,
    ignoreLootSale,

    // Declined management
    declinedContractsCount,
    declinedTransactionsCount,
    setDeclinedCounts,
    resetSeenContracts,
  }
}
