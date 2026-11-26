export type PendingDestination = 'colony' | 'home'

export interface PendingConsumer {
  colonyId: string
  planetName: string
}

export interface PendingOutput {
  colonyId: string
  planetId: number
  planetName: string | null
  solarSystemName: string | null
  characterId: number
  characterName: string | null
  typeId: number
  typeName: string | null
  quantity: number | null
  volumePerUnit: number | null
  volumeM3: number | null
  pinIds: number[]
  consumers: PendingConsumer[]
  destination: PendingDestination
  lastViewAt: string
  dataAgeSeconds: number
  dataStale: boolean
}

export interface CustomsOfficeStock {
  typeId: number
  typeName: string | null
  quantity: number
  volumePerUnit: number | null
  volumeM3: number | null
  probablePlanet: PendingConsumer | null
}

export interface PendingStockResponse {
  pendingOutputs: PendingOutput[]
  customsOfficeStocks: CustomsOfficeStock[]
  complete: boolean
}

export type PickupSourceKind = 'colony' | 'customs'

export interface PickupSource {
  kind: PickupSourceKind
  probable: boolean
  colonyId: string
  planetName: string | null
  quantity: number | null
  volumeM3: number | null
  dataStale: boolean
  dataAgeSeconds: number
}

export interface PickupRow {
  typeId: number
  typeName: string | null
  needed: number
  pending: number
  pickup: number
  remaining: number
  sources: PickupSource[]
  unknownSources: PickupSource[]
}

function isBoundForAnotherColony(output: PendingOutput): boolean {
  return output.destination === 'colony' && output.consumers.length > 0
}

function toPickupSource(output: PendingOutput): PickupSource {
  return {
    kind: 'colony',
    probable: false,
    colonyId: output.colonyId,
    planetName: output.planetName,
    quantity: output.quantity,
    volumeM3: output.volumeM3,
    dataStale: output.dataStale,
    dataAgeSeconds: output.dataAgeSeconds,
  }
}

function toCustomsPickupSource(stock: CustomsOfficeStock): PickupSource {
  return {
    kind: 'customs',
    probable: stock.probablePlanet !== null,
    colonyId: stock.probablePlanet?.colonyId ?? '',
    planetName: stock.probablePlanet?.planetName ?? null,
    quantity: stock.quantity,
    volumeM3: stock.volumeM3,
    dataStale: false,
    dataAgeSeconds: 0,
  }
}

export function buildPickupPlan(
  totals: readonly { typeId: number; quantity: number }[],
  typeNames: ReadonlyMap<number, string | null>,
  outputs: readonly PendingOutput[],
  customsOfficeStocks: readonly CustomsOfficeStock[],
): PickupRow[] {
  const bound = outputs.filter(isBoundForAnotherColony)

  return totals
    .filter(total => total.quantity > 0)
    .map(total => {
      const sources = [
        ...bound.filter(output => output.typeId === total.typeId).map(toPickupSource),
        ...customsOfficeStocks.filter(stock => stock.typeId === total.typeId).map(toCustomsPickupSource),
      ]
      const known = sources.filter(source => source.quantity !== null)
      const pending = sources.reduce((sum, source) => (source.quantity === null ? sum : sum + source.quantity), 0)
      const pickup = Math.min(total.quantity, pending)

      return {
        typeId: total.typeId,
        typeName: typeNames.get(total.typeId) ?? null,
        needed: total.quantity,
        pending,
        pickup,
        remaining: total.quantity - pickup,
        sources: known,
        unknownSources: sources.filter(source => source.quantity === null),
      }
    })
}

function comparePlanetNames(first: string | null, second: string | null): number {
  if (first === second) return 0
  if (first === null) return 1
  if (second === null) return -1
  return first.localeCompare(second)
}

function destinationRank(output: PendingOutput): number {
  return output.destination === 'colony' ? 0 : 1
}

export function sortBringBack(outputs: readonly PendingOutput[]): PendingOutput[] {
  return [...outputs].sort((first, second) =>
    destinationRank(first) - destinationRank(second)
    || comparePlanetNames(first.planetName, second.planetName)
    || first.typeId - second.typeId,
  )
}
