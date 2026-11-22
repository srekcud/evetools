import { groupActionsByPlace, placeMultibuy, type PlaceGroup } from '@/utils/todoPlaces'
import {
  AZBEL_PLACE,
  ANYWHERE_PLACE,
  EXAMPLE_ACTIONS,
  IBIS_PROJECT_ID,
  JITA_PLACE,
  SOURCE_ISOGEN_10_MARKET_NOT_CHOSEN,
  SOURCE_TRITANIUM_400_AT_JITA,
  SOURCE_ZYDRINE_50_AT_JITA,
  LAUNCH_RAM_AT_AZBEL,
  placedAction,
  stationPlace,
  unknownPlace,
} from '../todoPlaceFixtures'

function groupOf(...actions: Parameters<typeof placedAction>[0][]): PlaceGroup {
  const placed = actions.map(placedAction)
  return { key: `station:${placed[0].place.locationId}`, place: placed[0].place, here: null, actions: placed }
}

describe('groupActionsByPlace', () => {
  it('keeps the server order of the groups and ranks each action under its place key', () => {
    const groups = groupActionsByPlace(EXAMPLE_ACTIONS)

    expect(groups.map(group => ({ key: group.key, place: group.place, ids: group.actions.map(action => action.id) }))).toEqual([
      { key: 'station:1040000000002', place: AZBEL_PLACE, ids: ['a1'] },
      { key: 'station:60003760', place: JITA_PLACE, ids: ['a2', 'a3', 'a4'] },
      { key: 'unknown:market_not_chosen', place: SOURCE_ISOGEN_10_MARKET_NOT_CHOSEN.place, ids: ['a5'] },
      { key: 'anywhere', place: ANYWHERE_PLACE, ids: ['a6'] },
    ])
  })

  it('gathers non consecutive actions of one place, separates stations by location and unknown places by reason', () => {
    const sameNameElsewhere = stationPlace(60_008_494, JITA_PLACE.name as string, 'Amarr')
    const groups = groupActionsByPlace([
      SOURCE_TRITANIUM_400_AT_JITA,
      SOURCE_ISOGEN_10_MARKET_NOT_CHOSEN,
      SOURCE_ZYDRINE_50_AT_JITA,
      placedAction({ id: 'a7', code: 'launch_job', place: unknownPlace('station_unresolved') }),
      placedAction({ id: 'a8', code: 'source_material', place: sameNameElsewhere }),
    ])

    expect(groups.map(group => ({ key: group.key, ids: group.actions.map(action => action.id) }))).toEqual([
      { key: 'station:60003760', ids: ['a2', 'a4'] },
      { key: 'unknown:market_not_chosen', ids: ['a5'] },
      { key: 'unknown:station_unresolved', ids: ['a7'] },
      { key: 'station:60008494', ids: ['a8'] },
    ])
  })

  it('is here when one action is here, not here when one is elsewhere, and unknown otherwise', () => {
    const groups = groupActionsByPlace([
      placedAction({ id: 'b1', code: 'source_material', place: AZBEL_PLACE, here: false }),
      placedAction({ id: 'b2', code: 'source_material', place: AZBEL_PLACE, here: true }),
      placedAction({ id: 'b3', code: 'source_material', place: JITA_PLACE, here: null }),
      placedAction({ id: 'b4', code: 'source_material', place: JITA_PLACE, here: false }),
      placedAction({ id: 'b5', code: 'review_join_requests', place: ANYWHERE_PLACE, here: null }),
    ])

    expect(groups.map(group => [group.key, group.here])).toEqual([
      ['station:1040000000002', true],
      ['station:60003760', false],
      ['anywhere', null],
    ])
  })

  it('gives no group when there is no action', () => {
    expect(groupActionsByPlace([])).toEqual([])
  })
})

describe('placeMultibuy', () => {
  it('adds the Tritanium of two projects into one line and counts the purchase actions and the projects', () => {
    const [, jita] = groupActionsByPlace(EXAMPLE_ACTIONS)

    expect(placeMultibuy(jita)).toEqual({
      lines: [
        { typeName: 'Tritanium', quantity: 520 },
        { typeName: 'Zydrine', quantity: 50 },
      ],
      lineCount: 3,
      projectCount: 2,
      skippedCount: 0,
    })
  })

  it('has no multibuy under two purchase actions, outside a station, nor for another action than a purchase', () => {
    const groups = groupActionsByPlace([
      LAUNCH_RAM_AT_AZBEL,
      SOURCE_TRITANIUM_400_AT_JITA,
      placedAction({ id: 'c1', code: 'launch_job', place: JITA_PLACE, typeName: 'Ishtar', quantity: 3 }),
      SOURCE_ISOGEN_10_MARKET_NOT_CHOSEN,
      placedAction({ id: 'c2', code: 'source_material', place: unknownPlace('market_not_chosen'), typeName: 'Pyerite', typeId: 35, quantity: 70 }),
      placedAction({ id: 'c3', code: 'review_join_requests', place: ANYWHERE_PLACE, quantity: 1 }),
      placedAction({ id: 'c4', code: 'source_material', place: ANYWHERE_PLACE, typeName: 'Mexallon', typeId: 36, quantity: 30 }),
    ])

    expect(groups.map(placeMultibuy)).toEqual([null, null, null, null])
  })

  it('sums by name when there is no type id, keeps the order of first appearance and skips lines without name or quantity', () => {
    const group = groupOf(
      { id: 'd1', code: 'source_material', place: JITA_PLACE, typeName: 'Pyerite', quantity: 70 },
      { id: 'd2', code: 'source_material', place: JITA_PLACE, typeId: 37, typeName: 'Isogen', quantity: 9 },
      { id: 'd3', code: 'source_material', place: JITA_PLACE, typeName: 'Pyerite', quantity: 30, projectId: IBIS_PROJECT_ID },
      { id: 'd4', code: 'source_material', place: JITA_PLACE, typeId: 38, typeName: null, quantity: 5 },
      { id: 'd5', code: 'source_material', place: JITA_PLACE, typeId: 36, typeName: 'Mexallon', quantity: null },
      { id: 'd6', code: 'source_material', place: JITA_PLACE, typeId: 35, typeName: 'Nocxium', quantity: 0 },
    )

    expect(placeMultibuy(group)).toEqual({
      lines: [
        { typeName: 'Pyerite', quantity: 100 },
        { typeName: 'Isogen', quantity: 9 },
      ],
      lineCount: 3,
      projectCount: 2,
      skippedCount: 3,
    })
  })

  it('has no multibuy when skipped lines leave a single one', () => {
    const group = groupOf(
      { id: 'e1', code: 'source_material', place: JITA_PLACE, typeId: 34, typeName: 'Tritanium', quantity: 400 },
      { id: 'e2', code: 'source_material', place: JITA_PLACE, typeId: 39, typeName: null, quantity: 50 },
    )

    expect(placeMultibuy(group)).toBeNull()
  })

  it('counts one project and one line when both purchases are the same type of the same project', () => {
    const group = groupOf(
      { id: 'f1', code: 'source_material', place: JITA_PLACE, typeId: 34, typeName: 'Tritanium', quantity: 400 },
      { id: 'f2', code: 'source_material', place: JITA_PLACE, typeId: 34, typeName: 'Tritanium', quantity: 100 },
    )

    expect(placeMultibuy(group)).toEqual({
      lines: [{ typeName: 'Tritanium', quantity: 500 }],
      lineCount: 2,
      projectCount: 1,
      skippedCount: 0,
    })
  })
})
