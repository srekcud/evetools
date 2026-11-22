import type { ActionPlace, PlacedAction, PlacedActionReservation } from '@/stores/group-industry/types'

export interface PlaceGroup {
  key: string
  place: ActionPlace
  here: boolean | null
  actions: PlacedAction[]
}

export interface MultibuyLine {
  typeName: string
  quantity: number
}

export interface PlaceMultibuy {
  lines: MultibuyLine[]
  lineCount: number
  projectCount: number
  skippedCount: number
}

const MIN_MULTIBUY_LINES = 2

function placeKey(place: ActionPlace): string {
  switch (place.kind) {
    case 'station':
      return `station:${place.locationId}`
    case 'anywhere':
      return 'anywhere'
    case 'unknown':
      return `unknown:${place.unknownReason}`
  }
}

function hereOfGroup(actions: readonly PlacedAction[]): boolean | null {
  if (actions.some(action => action.here === true)) {
    return true
  }
  return actions.some(action => action.here === false) ? false : null
}

export function groupActionsByPlace(actions: readonly PlacedAction[]): PlaceGroup[] {
  const actionsByKey = new Map<string, PlacedAction[]>()
  for (const action of actions) {
    const key = placeKey(action.place)
    const placed = actionsByKey.get(key)
    if (placed === undefined) {
      actionsByKey.set(key, [action])
    } else {
      placed.push(action)
    }
  }
  return [...actionsByKey.entries()].map(([key, placed]) => ({
    key,
    place: placed[0].place,
    here: hereOfGroup(placed),
    actions: placed,
  }))
}

function isSourcingWithNameAndQuantity(action: PlacedAction): action is PlacedAction & { typeName: string; quantity: number } {
  return action.typeName !== null && action.quantity !== null && action.quantity > 0
}

function lineKey(action: PlacedAction & { typeName: string }): string {
  return action.typeId === null ? `name:${action.typeName}` : `type:${action.typeId}`
}

export function placeMultibuy(group: PlaceGroup): PlaceMultibuy | null {
  if (group.place.kind !== 'station') {
    return null
  }
  const sourcingActions = group.actions.filter(action => action.code === 'source_material')
  const retained = sourcingActions.filter(isSourcingWithNameAndQuantity)
  if (retained.length < MIN_MULTIBUY_LINES) {
    return null
  }
  const linesByKey = new Map<string, MultibuyLine>()
  for (const action of retained) {
    const key = lineKey(action)
    const line = linesByKey.get(key)
    if (line === undefined) {
      linesByKey.set(key, { typeName: action.typeName, quantity: action.quantity })
    } else {
      line.quantity += action.quantity
    }
  }
  return {
    lines: [...linesByKey.values()],
    lineCount: retained.length,
    projectCount: new Set(retained.map(action => action.projectId)).size,
    skippedCount: sourcingActions.length - retained.length,
  }
}

export function reservedHoldersText(
  reservations: PlacedActionReservation[],
  unknownMemberLabel: string,
  formatQuantity: (quantity: number) => string,
): string {
  return reservations.map((reservation) => `${reservation.characterName ?? unknownMemberLabel} ${formatQuantity(reservation.quantity)}`).join(', ')
}
