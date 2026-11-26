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

export interface PendingStockResponse {
  pendingOutputs: PendingOutput[]
  complete: boolean
}

export interface PickupSource {
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

export function buildPickupPlan(
  _totals: readonly { typeId: number; quantity: number }[],
  _typeNames: ReadonlyMap<number, string | null>,
  _outputs: readonly PendingOutput[],
): PickupRow[] {
  return []
}

export function sortBringBack(_outputs: readonly PendingOutput[]): PendingOutput[] {
  return []
}
