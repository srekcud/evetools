export type BountyEntry = {
  id: number
  date: string
  refType: string
  refTypeLabel: string
  amount: number
  description: string
  characterName: string
}

export type Expense = {
  id: string
  type: string
  description: string
  amount: number
  date: string
}

export type LootSaleEntry = {
  id: string
  type: string
  description: string
  amount: number
  date: string
}

export type PveData = {
  period: { from: string; to: string; days: number }
  lastSyncAt: string | null
  bounties: { total: number; count: number; entries: BountyEntry[] }
  lootSales: { total: number; count: number; entries: LootSaleEntry[] }
  expenses: { total: number; byType: Record<string, number> }
  profit: number
}

export type AmmoType = {
  typeId: number
  typeName: string
}

export type DetectedExpense = {
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

export type ContractScanResult = {
  scannedContracts: number
  scannedTransactions: number
  detectedExpenses: DetectedExpense[]
}

export type DetectedLootSale = {
  transactionId: number
  contractId?: number
  projectId?: string | null
  projectName?: string
  type: string
  typeId: number
  typeName: string
  quantity: number
  price: number
  dateIssued: string
  characterName: string
  source?: 'market' | 'contract' | 'corp_project'
  selected: boolean
  needsPriceInput?: boolean
}

export type LootSaleScanResult = {
  scannedTransactions: number
  scannedContracts?: number
  scannedProjects?: number
  detectedSales: DetectedLootSale[]
}
