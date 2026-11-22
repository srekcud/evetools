import {
  blacklistAfter,
  marketsToWrite,
  sourcingQuery,
  sourcingSummary,
  stationsPayload,
  stationsSummary,
} from '@/components/group-industry/projectSetup'
import {
  BASE_ROW,
  BASE_TYPE_ID,
  COMPONENT_ROW,
  COMPONENT_TYPE_ID,
  COMPONENTS_ALTERNATIVE_ID,
  componentsType,
  copyType,
  COPY_CHOSEN_ID,
  projectWorkStations,
  REACTION_ALTERNATIVE_ID,
  REACTION_ROW,
  REACTION_TYPE_ID,
  reactionType,
  sourcingDecisions,
  sourcingOption,
  sourcingRow,
  stationRow,
  unknownAmountsRow,
} from '../../projectSetupFixtures'

describe('project setup stations summary', () => {
  it('sums the chosen candidates with the gap to the recommended ones, and gives an unknown total and gap, never zero, when a chosen total is unknown', () => {
    const picks = { reaction: REACTION_ALTERNATIVE_ID, components: COMPONENTS_ALTERNATIVE_ID }
    const withUnknownReaction = projectWorkStations([
      reactionType({ rows: [stationRow('1001'), unknownAmountsRow(REACTION_ALTERNATIVE_ID)] }),
      componentsType(),
    ])

    const known = stationsSummary(projectWorkStations(), picks)
    const unknown = stationsSummary(withUnknownReaction, picks)

    expect({ known, unknown }).toEqual({
      known: {
        totalCost: 1650,
        gapToRecommended: 150,
        transportCost: 50,
        stationIds: [REACTION_ALTERNATIVE_ID, COMPONENTS_ALTERNATIVE_ID],
      },
      unknown: {
        totalCost: null,
        gapToRecommended: null,
        transportCost: null,
        stationIds: [REACTION_ALTERNATIVE_ID, COMPONENTS_ALTERNATIVE_ID],
      },
    })
  })
})

describe('project setup stations payload', () => {
  it('keeps only the unlocked types that have a chosen station, recommended ones included', () => {
    const locked = reactionType({ locked: true, lockReason: 'job_launched' })
    const withoutStation = { ...componentsType(), workType: 'hulls' as const, recommendedStructureId: null, chosenStructureId: null, rows: [] }

    const payload = stationsPayload([locked, withoutStation, componentsType(), copyType()], { components: COMPONENTS_ALTERNATIVE_ID })

    expect(payload).toEqual({ components: COMPONENTS_ALTERNATIVE_ID, blueprints: COPY_CHOSEN_ID })
  })
})

describe('project setup sourcing query', () => {
  it('sends the built lines as build and the bought ones as buy, base lines left out', () => {
    const rows = [COMPONENT_ROW, REACTION_ROW, BASE_ROW]

    const query = sourcingQuery(rows, { [COMPONENT_TYPE_ID]: 'build', [REACTION_TYPE_ID]: 'jita', [BASE_TYPE_ID]: 'structure' })

    expect(query).toEqual({ build: [COMPONENT_TYPE_ID], buy: [REACTION_TYPE_ID] })
  })
})

describe('project setup blacklist and markets', () => {
  it('adds the lines that are no longer built, removes the ones that are built again and gives null when nothing changes', () => {
    const rows = [sourcingRow(7), sourcingRow(9), BASE_ROW]
    const picks = { 7: 'build', 9: 'jita' } as const

    const changed = blacklistAfter([5, 7], rows, picks)
    const unchanged = blacklistAfter([5, 9], rows, picks)

    expect({ changed, unchanged }).toEqual({ changed: [5, 9], unchanged: null })
  })

  it('writes only the markets the user picked for bought lines', () => {
    const boughtOnCapital = sourcingRow(44, { options: { build: sourcingOption(), jita: sourcingOption(), structure: sourcingOption() }, chosen: 'jita', bought: true })
    const rows = [boughtOnCapital, BASE_ROW]

    const writes = marketsToWrite(rows, { 44: 'structure' })

    expect(writes).toEqual([{ typeId: 44, market: 'structure' }])
  })
})

describe('project setup sourcing summary', () => {
  it('moves the delivered cost by the difference of the picked market and compares it to the first answer', () => {
    const decisions = sourcingDecisions(1000)

    const summary = sourcingSummary(decisions, { [REACTION_TYPE_ID]: 'structure' }, 1000)

    expect(summary).toEqual({
      total: 950,
      gapToBaseline: -50,
      jita: { amount: 80, types: 1, importCost: 5 },
      structure: { amount: 150, types: 1 },
    })
  })
})
