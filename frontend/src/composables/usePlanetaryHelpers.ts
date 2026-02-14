import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { usePlanetaryStore, type Colony, type Pin } from '@/stores/planetary'

// ========== Constants ==========

export const PLANET_TYPE_CONFIG: Record<string, { typeId: number; badgeBg: string; badgeText: string; badgeBorder: string }> = {
  temperate: { typeId: 11, badgeBg: 'bg-green-500/15', badgeText: 'text-green-400', badgeBorder: 'border-green-500/20' },
  barren: { typeId: 2016, badgeBg: 'bg-yellow-500/15', badgeText: 'text-yellow-400', badgeBorder: 'border-yellow-500/20' },
  lava: { typeId: 2015, badgeBg: 'bg-red-500/15', badgeText: 'text-red-400', badgeBorder: 'border-red-500/20' },
  ice: { typeId: 12, badgeBg: 'bg-cyan-500/15', badgeText: 'text-cyan-400', badgeBorder: 'border-cyan-500/20' },
  gas: { typeId: 13, badgeBg: 'bg-purple-500/15', badgeText: 'text-purple-400', badgeBorder: 'border-purple-500/20' },
  oceanic: { typeId: 2014, badgeBg: 'bg-blue-500/15', badgeText: 'text-blue-400', badgeBorder: 'border-blue-500/20' },
  plasma: { typeId: 2063, badgeBg: 'bg-orange-500/15', badgeText: 'text-orange-400', badgeBorder: 'border-orange-500/20' },
  storm: { typeId: 2017, badgeBg: 'bg-gray-500/15', badgeText: 'text-gray-400', badgeBorder: 'border-gray-500/20' },
}

export const TIER_CONFIG: Record<string, { labelKey: string; badgeBg: string; badgeText: string; badgeBorder: string }> = {
  P0: { labelKey: 'pi.tiers.P0', badgeBg: 'bg-slate-600/50', badgeText: 'text-slate-300', badgeBorder: 'border-slate-600/50' },
  P1: { labelKey: 'pi.tiers.P1', badgeBg: 'bg-cyan-500/15', badgeText: 'text-cyan-400', badgeBorder: 'border-cyan-500/20' },
  P2: { labelKey: 'pi.tiers.P2', badgeBg: 'bg-indigo-500/15', badgeText: 'text-indigo-400', badgeBorder: 'border-indigo-500/20' },
  P3: { labelKey: 'pi.tiers.P3', badgeBg: 'bg-amber-500/15', badgeText: 'text-amber-400', badgeBorder: 'border-amber-500/20' },
  P4: { labelKey: 'pi.tiers.P4', badgeBg: 'bg-rose-500/15', badgeText: 'text-rose-400', badgeBorder: 'border-rose-500/20' },
}

export const VOLUME_BY_TIER: Record<string, number> = {
  P0: 0.01,
  P1: 0.38,
  P2: 1.50,
  P3: 6.00,
  P4: 100.00,
}

// ========== Composable ==========

export function usePlanetaryHelpers() {
  const { t } = useI18n()
  const { formatNumber } = useFormatters()

  // Security helpers
  function getSecurityColorClass(security: number | null): string {
    if (security === null) return 'text-slate-500'
    if (security >= 0.5) return 'text-emerald-400'
    if (security >= 0.1) return 'text-yellow-400'
    return 'text-red-400'
  }

  function formatSecurity(security: number | null): string {
    if (security === null) return '?'
    return security.toFixed(1)
  }

  // Planet helpers
  function getPlanetConfig(planetType: string) {
    const key = planetType.toLowerCase()
    return PLANET_TYPE_CONFIG[key] || PLANET_TYPE_CONFIG.barren
  }

  function getPlanetIconUrl(planetType: string, size: number = 64): string {
    const config = getPlanetConfig(planetType)
    return `https://images.evetech.net/types/${config.typeId}/icon?size=${size}`
  }

  // Pin classification helpers
  function getExtractorPins(colony: Colony): Pin[] {
    return colony.pins.filter(p => p.pinCategory === 'extractor' || p.extractorProductTypeId !== null)
  }

  function getFactoryPins(colony: Colony): Pin[] {
    return colony.pins.filter(p => p.pinCategory === 'factory' || (p.schematicId !== null && p.pinCategory !== 'extractor'))
  }

  function getStoragePins(colony: Colony): Pin[] {
    return colony.pins.filter(p =>
      p.pinCategory === 'storage' || p.pinCategory === 'launchpad' || p.pinCategory === 'command_center'
    )
  }

  // Colony border class
  function getColonyBorderClass(colony: Colony): string {
    if (colony.status === 'expired') return 'border-red-500/20'
    if (colony.status === 'expiring') return 'border-amber-500/15'
    return 'border-slate-700/40'
  }

  // Net flow helpers
  function getNetFlowColorClass(net: number | null): string {
    if (net === null) return 'text-slate-500'
    if (net > 0) return 'text-emerald-400'
    if (net < 0) return 'text-red-400'
    return 'text-slate-500'
  }

  function formatNetFlow(net: number | null): string {
    if (net === null) return '-'
    const sign = net > 0 ? '+' : ''
    const unit = t('common.time.perDay').replace('/ ', '/')
    return `${sign}${formatNumber(net, 1)} m3${unit}`
  }

  // Supply delta helpers
  function getSupplyDeltaColor(delta: number): { dot: string; text: string } {
    if (delta > 0) return { dot: 'bg-blue-400', text: 'text-blue-400' }
    if (delta === 0) return { dot: 'bg-emerald-400', text: 'text-emerald-400' }
    return { dot: 'bg-red-400', text: 'text-red-400 font-bold' }
  }

  function formatDelta(delta: number): string {
    if (delta === 0) return '0'
    const sign = delta > 0 ? '+' : ''
    return sign + formatNumber(delta, 0)
  }

  function getWorstDelta(inputs: { delta: number }[]): number {
    if (!inputs.length) return 0
    return inputs.reduce((worst, i) => Math.min(worst, i.delta), inputs[0].delta)
  }

  // Tier helpers
  function getTierTotalVolume(tier: { items: { dailyQuantity: number }[] }): number {
    return tier.items.reduce((sum, item) => sum + item.dailyQuantity, 0)
  }

  function getTierPercentage(tierIsk: number, totalIsk: number): string {
    if (totalIsk <= 0) return '-'
    return ((tierIsk / totalIsk) * 100).toFixed(1) + '%'
  }

  function getTierIskColor(tier: string): string {
    const config = TIER_CONFIG[tier]
    return config?.badgeText || 'text-slate-400'
  }

  // Storage flow computation
  interface FlowLine {
    contentTypeName: string
    contentTypeId: number
    quantityPerCycle: number
    cycleTimeSeconds: number | null
    dailyQuantity: number | null
  }

  interface StorageFlowData {
    incoming: FlowLine[]
    outgoing: FlowLine[]
    netDailyVolume: number | null
    fillDays: number | null
    capacity: number | null
  }

  function getSourcePinCycleTime(colony: Colony, sourcePinId: number): number | null {
    const pin = colony.pins.find(p => p.pinId === sourcePinId)
    if (!pin) return null

    if (pin.extractorCycleTime && pin.extractorCycleTime > 0) {
      return pin.extractorCycleTime
    }
    if (pin.schematicCycleTime && pin.schematicCycleTime > 0) {
      return pin.schematicCycleTime
    }

    return null
  }

  function guessItemTier(contentTypeId: number): string {
    const planetaryStore = usePlanetaryStore()
    for (const tier of planetaryStore.production) {
      if (tier.items.some(i => i.typeId === contentTypeId)) {
        return tier.tier
      }
    }
    return 'P0'
  }

  function getStorageFlowData(colony: Colony, storagePin: Pin): StorageFlowData {
    interface RouteEntry { quantity: number; cycleTime: number | null }
    const inMap = new Map<number, { name: string; routes: RouteEntry[] }>()
    const outMap = new Map<number, { name: string; routes: RouteEntry[] }>()

    for (const route of colony.routes) {
      if (route.destinationPinId === storagePin.pinId) {
        const entry: RouteEntry = { quantity: route.quantity, cycleTime: getSourcePinCycleTime(colony, route.sourcePinId) }
        const existing = inMap.get(route.contentTypeId)
        if (existing) {
          existing.routes.push(entry)
        } else {
          inMap.set(route.contentTypeId, { name: route.contentTypeName, routes: [entry] })
        }
      }
      if (route.sourcePinId === storagePin.pinId) {
        const entry: RouteEntry = { quantity: route.quantity, cycleTime: getSourcePinCycleTime(colony, route.destinationPinId) }
        const existing = outMap.get(route.contentTypeId)
        if (existing) {
          existing.routes.push(entry)
        } else {
          outMap.set(route.contentTypeId, { name: route.contentTypeName, routes: [entry] })
        }
      }
    }

    function buildFlowLines(map: Map<number, { name: string; routes: RouteEntry[] }>): FlowLine[] {
      const lines: FlowLine[] = []
      for (const [typeId, data] of map) {
        const totalQty = data.routes.reduce((sum, r) => sum + r.quantity, 0)
        const allCyclesKnown = data.routes.every(r => r.cycleTime !== null && r.cycleTime > 0)
        const dailyQuantity = allCyclesKnown
          ? Math.round(data.routes.reduce((sum, r) => sum + r.quantity * (86400 / r.cycleTime!), 0))
          : null
        const firstCycle = data.routes.find(r => r.cycleTime !== null)?.cycleTime ?? null

        lines.push({
          contentTypeName: data.name,
          contentTypeId: typeId,
          quantityPerCycle: totalQty,
          cycleTimeSeconds: firstCycle,
          dailyQuantity,
        })
      }
      return lines
    }

    const incoming = buildFlowLines(inMap)
    const outgoing = buildFlowLines(outMap)

    let netDailyVolume: number | null = null
    const allInKnown = incoming.every(f => f.dailyQuantity !== null)
    const allOutKnown = outgoing.every(f => f.dailyQuantity !== null)

    if (allInKnown && allOutKnown && (incoming.length > 0 || outgoing.length > 0)) {
      const computeVolume = (flows: FlowLine[]) =>
        flows.reduce((sum, f) => {
          const vol = VOLUME_BY_TIER[guessItemTier(f.contentTypeId)] ?? 0.38
          return sum + (f.dailyQuantity ?? 0) * vol
        }, 0)

      netDailyVolume = Math.round((computeVolume(incoming) - computeVolume(outgoing)) * 100) / 100
    }

    let fillDays: number | null = null
    if (netDailyVolume !== null && netDailyVolume > 0 && storagePin.capacity && storagePin.capacity > 0) {
      fillDays = Math.round((storagePin.capacity / netDailyVolume) * 10) / 10
    }

    return { incoming, outgoing, netDailyVolume, fillDays, capacity: storagePin.capacity }
  }

  // Colony production computation
  interface ColonyProductionItem {
    typeName: string
    dailyQuantity: number
    outputTier: string | null
    unitPrice: number
    dailyIskValue: number
  }

  function findUnitPrice(typeId: number): number {
    const planetaryStore = usePlanetaryStore()
    for (const tier of planetaryStore.production) {
      const item = tier.items.find(i => i.typeId === typeId)
      if (item && item.unitPrice) return item.unitPrice
    }
    return 0
  }

  function getColonyProduction(colony: Colony): ColonyProductionItem[] {
    const factories = getFactoryPins(colony)
    const items: ColonyProductionItem[] = []

    for (const pin of factories) {
      if (pin.schematicOutput && pin.schematicCycleTime && pin.schematicCycleTime > 0) {
        const cyclesPerDay = (3600 / pin.schematicCycleTime) * 24
        const dailyQty = Math.round(pin.schematicOutput.quantity * cyclesPerDay)
        const unitPrice = findUnitPrice(pin.schematicOutput.typeId)
        const existing = items.find(i => i.typeName === pin.schematicOutput!.typeName)
        if (existing) {
          existing.dailyQuantity += dailyQty
          existing.dailyIskValue += dailyQty * unitPrice
        } else {
          items.push({
            typeName: pin.schematicOutput.typeName,
            dailyQuantity: dailyQty,
            outputTier: pin.outputTier,
            unitPrice,
            dailyIskValue: dailyQty * unitPrice,
          })
        }
      }
    }

    return items
  }

  return {
    // Security
    getSecurityColorClass,
    formatSecurity,
    // Planet
    getPlanetConfig,
    getPlanetIconUrl,
    // Pins
    getExtractorPins,
    getFactoryPins,
    getStoragePins,
    // Colony
    getColonyBorderClass,
    // Net flow
    getNetFlowColorClass,
    formatNetFlow,
    // Supply delta
    getSupplyDeltaColor,
    formatDelta,
    getWorstDelta,
    // Tier
    getTierTotalVolume,
    getTierPercentage,
    getTierIskColor,
    // Storage flow
    getStorageFlowData,
    // Colony production
    getColonyProduction,
  }
}
