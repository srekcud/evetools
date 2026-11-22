import { canEditStations, sourcingChoiceSummary, stationChoiceLines } from '@/components/group-industry/projectStartupChoices'
import {
  BASE_ROW,
  COMPONENT_ROW,
  componentsType,
  copyType,
  projectWorkStations,
  REACTION_ALTERNATIVE_ID,
  REACTION_RECOMMENDED_ID,
  REACTION_ROW,
  reactionType,
  sourcingOption,
  sourcingRow,
  stationRow,
  unknownAmountsRow,
} from '../../projectSetupFixtures'

describe('station choice lines of the project plan', () => {
  it('puts the recommended station of the startup next to a choice that differs, with the gap, and none next to a choice that follows it', () => {
    const workStations = projectWorkStations([
      reactionType({ chosenStructureId: REACTION_ALTERNATIVE_ID, storedRecommendedStructureId: REACTION_RECOMMENDED_ID }),
      componentsType(),
    ])

    expect(stationChoiceLines(workStations)).toEqual([
      { workType: 'reaction', stationName: 'Athanor Kaimon', recommendedStationName: 'Tatara Isanamo Reactions', differs: true, gap: 200 },
      { workType: 'components', stationName: 'Raitaru Jita Yard', recommendedStationName: null, differs: false, gap: 0 },
    ])
  })

  it('compares with the recommended station kept at startup, not the one recommended today', () => {
    const workStations = projectWorkStations([
      reactionType({
        chosenStructureId: REACTION_ALTERNATIVE_ID,
        recommendedStructureId: REACTION_ALTERNATIVE_ID,
        storedRecommendedStructureId: REACTION_RECOMMENDED_ID,
      }),
    ])

    const [line] = stationChoiceLines(workStations)

    expect({ differs: line.differs, recommended: line.recommendedStationName }).toEqual({ differs: true, recommended: 'Tatara Isanamo Reactions' })
  })

  it('gives an unknown gap, never zero, when a total is unknown or the costs are not compared', () => {
    const unknownTotal = projectWorkStations([
      reactionType({
        chosenStructureId: REACTION_ALTERNATIVE_ID,
        storedRecommendedStructureId: REACTION_RECOMMENDED_ID,
        rows: [stationRow(REACTION_RECOMMENDED_ID), unknownAmountsRow(REACTION_ALTERNATIVE_ID)],
      }),
    ])
    const notCompared = projectWorkStations([copyType({ storedRecommendedStructureId: '3001' })])

    expect([stationChoiceLines(unknownTotal)[0].gap, stationChoiceLines(notCompared)[0].gap]).toEqual([null, null])
  })
})

describe('edit stations availability', () => {
  it('is offered only while no type of work is locked', () => {
    const unlocked = projectWorkStations()
    const oneLocked = projectWorkStations([reactionType({ locked: true, lockReason: 'purchase_made' }), componentsType()])
    const empty = projectWorkStations([])

    expect([canEditStations(unlocked), canEditStations(oneLocked), canEditStations(empty)]).toEqual([true, false, false])
  })
})

describe('sourcing choice summary of the project plan', () => {
  it('counts the intermediates by choice, leaves the raw materials out, and sums the gap of the choices off the recommended', () => {
    const offRecommended = { ...COMPONENT_ROW, chosen: 'jita' as const, recommended: 'build' as const }

    const summary = sourcingChoiceSummary([offRecommended, REACTION_ROW, BASE_ROW])

    expect(summary).toEqual({ build: 0, jita: 2, structure: 0, undecided: 0, offRecommended: 1, gap: 300 })
  })

  it('counts a line without choice as undecided and gives an unknown gap when an off recommended amount is unknown', () => {
    const undecided = sourcingRow(40, { chosen: null, recommended: 'build' })
    const unknownGap = sourcingRow(41, {
      chosen: 'jita',
      recommended: 'build',
      options: { build: sourcingOption({ total: 500 }), jita: sourcingOption({ total: null }) },
    })

    const summary = sourcingChoiceSummary([undecided, unknownGap])

    expect(summary).toEqual({ build: 0, jita: 1, structure: 0, undecided: 1, offRecommended: 1, gap: null })
  })

  it('reports every line on the recommended with a zero gap', () => {
    expect(sourcingChoiceSummary([COMPONENT_ROW, REACTION_ROW])).toEqual({ build: 1, jita: 1, structure: 0, undecided: 0, offRecommended: 0, gap: 0 })
  })
})
