import type { CharacterLocationRead } from '@/stores/group-industry/types'

export function isAtStationOf(positions: CharacterLocationRead[] | null, locationId: number | null): boolean | null {
  if (positions === null || locationId === null) return null
  return positions.some(position => (position.stationId ?? position.structureId) === locationId)
}
