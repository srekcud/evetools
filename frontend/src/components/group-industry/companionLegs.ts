import { isAtStationOf } from './companionPositions'
import type { CompanionFlowInput, CompanionLeg } from './companionTasks'
import type { DeclareStationTransferInput, ProjectTransportLeg, ProjectTransportLegLine, ProjectTransportLegPlace } from '@/stores/group-industry/types'

const READY_LINE_STATE = 'ready'
const AWAITING_DEPARTURE_TRANSFER_STATE = 'awaiting_departure'
const UNKNOWN_ACCESS = 'unknown'

type ReadyLine = DeclareStationTransferInput['lines'][number]

export type CompanionLegsView = {
  legs: CompanionLeg[]
  legsUnknown: boolean
  legsUnreadableCount: number
  readyLegCount: number
}

function readyLinesOf(lines: ProjectTransportLegLine[]): ReadyLine[] {
  return lines.flatMap((line): ReadyLine[] => (
    line.state === READY_LINE_STATE && line.quantity !== null && line.quantity > 0
      ? [{ typeId: line.typeId, quantity: line.quantity }]
      : []
  ))
}

function placeNameOf(place: ProjectTransportLegPlace): string | null {
  return place.stationName ?? place.solarSystemName
}

function isDeclared(leg: ProjectTransportLeg, flow: CompanionFlowInput): boolean {
  const departureId = leg.departure.locationId
  const arrivalId = leg.arrival.locationId
  if (departureId === null || arrivalId === null) return false
  return (flow.transfers ?? []).some(transfer => transfer.state === AWAITING_DEPARTURE_TRANSFER_STATE
    && transfer.carrier.characterId !== null
    && flow.myEveCharacterIds.includes(transfer.carrier.characterId)
    && transfer.from.locationId === departureId
    && transfer.to.locationId === arrivalId)
}

function carrierOf(leg: ProjectTransportLeg, flow: CompanionFlowInput): number | null {
  const legCarrier = leg.carrierCharacterIds.at(0)
  if (legCarrier !== undefined) return legCarrier
  if (leg.access !== UNKNOWN_ACCESS) return null
  return flow.myEveCharacterIds.at(0) ?? null
}

function declarationOf(leg: ProjectTransportLeg, flow: CompanionFlowInput, declared: boolean): DeclareStationTransferInput | null {
  const fromSolarSystemId = leg.departure.solarSystemId
  const toSolarSystemId = leg.arrival.solarSystemId
  const carrierCharacterId = carrierOf(leg, flow)
  const lines = readyLinesOf(leg.lines)
  if (declared || fromSolarSystemId === null || toSolarSystemId === null || carrierCharacterId === null || lines.length === 0) return null
  return {
    fromSolarSystemId,
    fromLocationId: leg.departure.locationId,
    toSolarSystemId,
    toLocationId: leg.arrival.locationId,
    lines,
    carrierCharacterId,
    contractId: null,
  }
}

function legOf(leg: ProjectTransportLeg, flow: CompanionFlowInput): CompanionLeg {
  const declared = isDeclared(leg, flow)
  const declaration = declarationOf(leg, flow, declared)
  return {
    key: leg.key,
    from: placeNameOf(leg.departure),
    to: placeNameOf(leg.arrival),
    fromSystem: leg.departure.solarSystemName,
    toSystem: leg.arrival.solarSystemName,
    here: isAtStationOf(flow.positions, leg.departure.locationId),
    accessUnknown: leg.access === UNKNOWN_ACCESS,
    volume: leg.volume,
    lines: leg.lines,
    declared,
    canDeclare: declaration !== null,
    declaration,
  }
}

export function legsViewOf(flow: CompanionFlowInput | undefined): CompanionLegsView {
  if (flow === undefined) return { legs: [], legsUnknown: false, legsUnreadableCount: 0, readyLegCount: 0 }
  if (flow.transportLegs === null) return { legs: [], legsUnknown: true, legsUnreadableCount: 0, readyLegCount: 0 }
  const legs = flow.transportLegs.legs.map(leg => legOf(leg, flow))
  const readyLegCount = legs.filter(leg => !leg.declared && readyLinesOf(leg.lines).length > 0).length
  return {
    legs: [...legs.filter(leg => leg.here === true), ...legs.filter(leg => leg.here !== true)],
    legsUnknown: false,
    legsUnreadableCount: flow.transportLegs.unreadable.length,
    readyLegCount,
  }
}
