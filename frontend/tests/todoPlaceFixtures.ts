import type { ActionPlace, PlacedAction } from '@/stores/group-industry/types'

export const ISHTAR_PROJECT_ID = 'project-ishtar-batch'
export const IBIS_PROJECT_ID = 'project-ibis-run'

export const AZBEL_PLACE: ActionPlace = {
  kind: 'station',
  locationId: 1_040_000_000_002,
  name: 'Azbel',
  solarSystemId: 30_000_144,
  solarSystemName: 'Perimeter',
  unknownReason: null,
}

export const JITA_PLACE: ActionPlace = {
  kind: 'station',
  locationId: 60_003_760,
  name: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
  solarSystemId: 30_000_142,
  solarSystemName: 'Jita',
  unknownReason: null,
}

export const ANYWHERE_PLACE: ActionPlace = {
  kind: 'anywhere',
  locationId: null,
  name: null,
  solarSystemId: null,
  solarSystemName: null,
  unknownReason: null,
}

export function unknownPlace(unknownReason: string): ActionPlace {
  return {
    kind: 'unknown',
    locationId: null,
    name: null,
    solarSystemId: null,
    solarSystemName: null,
    unknownReason,
  }
}

export function stationPlace(locationId: number, name: string, solarSystemName: string | null): ActionPlace {
  return {
    kind: 'station',
    locationId,
    name,
    solarSystemId: solarSystemName === null ? null : 30_000_001,
    solarSystemName,
    unknownReason: null,
  }
}

export function placedAction(
  fields: Pick<PlacedAction, 'id' | 'code' | 'place'> & Partial<PlacedAction>,
): PlacedAction {
  return {
    projectId: ISHTAR_PROJECT_ID,
    projectName: 'Ishtar batch',
    bomLineId: null,
    typeId: null,
    typeName: null,
    quantity: null,
    here: null,
    access: null,
    ...fields,
  }
}

export const LAUNCH_RAM_AT_AZBEL = placedAction({
  id: 'a1',
  code: 'launch_job',
  place: AZBEL_PLACE,
  typeId: 11478,
  typeName: 'R.A.M.- Starship Tech',
  quantity: 5,
  here: true,
})

export const SOURCE_TRITANIUM_400_AT_JITA = placedAction({
  id: 'a2',
  code: 'source_material',
  place: JITA_PLACE,
  typeId: 34,
  typeName: 'Tritanium',
  quantity: 400,
  here: false,
})

export const SOURCE_TRITANIUM_120_AT_JITA_FOR_IBIS = placedAction({
  id: 'a3',
  code: 'source_material',
  place: JITA_PLACE,
  projectId: IBIS_PROJECT_ID,
  projectName: 'Ibis run',
  typeId: 34,
  typeName: 'Tritanium',
  quantity: 120,
})

export const SOURCE_ZYDRINE_50_AT_JITA = placedAction({
  id: 'a4',
  code: 'source_material',
  place: JITA_PLACE,
  typeId: 39,
  typeName: 'Zydrine',
  quantity: 50,
})

export const SOURCE_ISOGEN_10_MARKET_NOT_CHOSEN = placedAction({
  id: 'a5',
  code: 'source_material',
  place: unknownPlace('market_not_chosen'),
  typeId: 37,
  typeName: 'Isogen',
  quantity: 10,
})

export const REVIEW_JOIN_REQUESTS_2_ANYWHERE = placedAction({
  id: 'a6',
  code: 'review_join_requests',
  place: ANYWHERE_PLACE,
  quantity: 2,
})

export const EXAMPLE_ACTIONS: PlacedAction[] = [
  LAUNCH_RAM_AT_AZBEL,
  SOURCE_TRITANIUM_400_AT_JITA,
  SOURCE_TRITANIUM_120_AT_JITA_FOR_IBIS,
  SOURCE_ZYDRINE_50_AT_JITA,
  SOURCE_ISOGEN_10_MARKET_NOT_CHOSEN,
  REVIEW_JOIN_REQUESTS_2_ANYWHERE,
]
