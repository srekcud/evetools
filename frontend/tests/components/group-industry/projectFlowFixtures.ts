import type { FlowCard, FlowCardState, FlowColumn, FlowGrid, FlowRow } from '@/components/group-industry/projectFlow'
import type { BomItem, GroupProject, GroupSale, StationQuantity, TypeStationQuantities } from '@/stores/group-industry/types'

export const STATION_A_ID = 1_040_000_000_002
export const STATION_B_ID = 1_040_000_000_007
export const STATION_C_ID = 1_040_000_000_011
export const JITA_ID = 60_003_760
export const AMARR_ID = 60_008_494

export const JITA_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant'
export const AMARR_NAME = 'Amarr VIII (Oris) - Emperor Family Academy'

export const TRITANIUM = { typeId: 34, typeName: 'Tritanium' }
export const ZYDRINE = { typeId: 39, typeName: 'Zydrine' }
export const MEXALLON = { typeId: 36, typeName: 'Mexallon' }
export const COMPONENT_X = { typeId: 11_540, typeName: 'Capital Armor Plates' }
export const ISHTAR = { typeId: 12_005, typeName: 'Ishtar' }

export function stationQuantity(fields: Pick<StationQuantity, 'locationId' | 'stationName'> & Partial<StationQuantity>): StationQuantity {
  return {
    needed: 0,
    consumed: 0,
    inProduction: 0,
    onSite: 0,
    out: 0,
    toBring: 0,
    inTransit: 0,
    unknownReason: null,
    ...fields,
  }
}

export function typeQuantities(
  type: { typeId: number; typeName: string },
  stations: StationQuantity[] | null,
  unknownReason: string | null = null,
): TypeStationQuantities {
  return { ...type, lineIds: [`line-${type.typeId}`], unknownReason, stations }
}

export function projectOf(fields: Partial<Pick<GroupProject, 'sellStructureId' | 'sellStructureName' | 'personalUse'>> & { items?: { typeId: number; typeName: string }[] } = {}): GroupProject {
  return {
    items: [],
    sellStructureId: null,
    sellStructureName: null,
    personalUse: false,
    ...fields,
  } as unknown as GroupProject
}

export function finalLine(typeId: number, requiredQuantity: number): BomItem {
  return { typeId, requiredQuantity, jobGroup: 'final', isJob: true } as BomItem
}

export function saleOf(typeId: number, typeName: string, quantity: number): GroupSale {
  return { typeId, typeName, quantity } as GroupSale
}

export function flowCard(state: FlowCardState, fields: Partial<FlowCard> & Pick<FlowCard, 'typeName'>): FlowCard {
  return {
    key: `${state}:${fields.typeName}`,
    typeId: null,
    quantity: 1,
    state,
    unknownReason: null,
    ...fields,
  }
}

export function flowRow(fields: Partial<FlowRow> & Pick<FlowRow, 'key'>, cells: Partial<Record<FlowColumn, FlowCard[]>> = {}): FlowRow {
  return {
    kind: 'station',
    locationId: STATION_A_ID,
    name: 'Azbel',
    here: null,
    ...fields,
    cells: { toBring: [], onSite: [], inProduction: [], out: [], done: [], ...cells },
  }
}

export function flowGrid(rows: FlowRow[], unavailableReason: string | null = null): FlowGrid {
  return { unavailableReason, rows }
}
