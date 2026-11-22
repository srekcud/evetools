import { stationBannerOf } from '@/components/group-industry/projectSetup'
import {
  componentsType,
  copyType,
  REACTION_ALTERNATIVE_ID,
  REACTION_RECOMMENDED_ID,
  reactionType,
  stationRow,
  unknownAmountsRow,
} from '../../projectSetupFixtures'
import type { WorkStationRow } from '@/stores/group-industry/types'

const CHEAPEST_ID = REACTION_RECOMMENDED_ID
const RETAINED_ID = REACTION_ALTERNATIVE_ID
const CHEAPEST_TOTAL = 900
const RETAINED_TOTAL = 1000
const RETAINED_PICK = { reaction: RETAINED_ID }

function reactionWith(cheapest: Partial<WorkStationRow>, retained: Partial<WorkStationRow>) {
  return reactionType({
    recommendedStructureId: CHEAPEST_ID,
    rows: [
      stationRow(CHEAPEST_ID, { totalCost: CHEAPEST_TOTAL, inFavoriteSystem: false, ...cheapest }),
      stationRow(RETAINED_ID, { totalCost: RETAINED_TOTAL, inFavoriteSystem: true, ...retained }),
    ],
  })
}

const policy = (acceptsOutsideFavoriteSystem: boolean, minimumOutsideSaving: number) => ({ acceptsOutsideFavoriteSystem, minimumOutsideSaving })

describe('stationBannerOf', () => {
  it('names the reason the cheapest station is not the retained one: favorite system only, saving under the threshold, or simply not retained', () => {
    const type = reactionWith({}, {})

    const banners = {
      policyOff: stationBannerOf(type, RETAINED_PICK, policy(false, 500)),
      underThreshold: stationBannerOf(type, RETAINED_PICK, policy(true, 500)),
      savingEqualsThreshold: stationBannerOf(type, RETAINED_PICK, policy(true, 100)),
      thresholdWithinACent: stationBannerOf(type, RETAINED_PICK, policy(true, 100.005)),
      thresholdBeyondACent: stationBannerOf(type, RETAINED_PICK, policy(true, 100.02)),
      cheapestInFavoriteSystemWithPolicyOff: stationBannerOf(
        reactionWith({ inFavoriteSystem: true }, { inFavoriteSystem: false }),
        RETAINED_PICK,
        policy(false, 5),
      ),
    }

    expect(banners).toEqual({
      policyOff: { kind: 'onlyFavoriteSystem', structureId: CHEAPEST_ID, saving: 100 },
      underThreshold: { kind: 'belowThreshold', structureId: CHEAPEST_ID, saving: 100 },
      savingEqualsThreshold: { kind: 'notRetained', structureId: CHEAPEST_ID, saving: 100 },
      thresholdWithinACent: { kind: 'notRetained', structureId: CHEAPEST_ID, saving: 100 },
      thresholdBeyondACent: { kind: 'belowThreshold', structureId: CHEAPEST_ID, saving: 100 },
      cheapestInFavoriteSystemWithPolicyOff: { kind: 'notRetained', structureId: CHEAPEST_ID, saving: 100 },
    })
  })

  it('gives no banner when nothing is cheaper or when a cost is unknown, never a banner on a guessed saving', () => {
    const open = policy(false, 5)

    const banners = {
      retainedIsCheapest: stationBannerOf(reactionWith({}, {}), { reaction: CHEAPEST_ID }, open),
      cheapestTotalUnknown: stationBannerOf(reactionWith({ totalCost: null }, {}), RETAINED_PICK, open),
      retainedTotalUnknown: stationBannerOf(reactionWith({}, { totalCost: null }), RETAINED_PICK, open),
      savingUnderACent: stationBannerOf(reactionWith({}, { totalCost: CHEAPEST_TOTAL + 0.005 }), RETAINED_PICK, open),
      savingJustAboveACent: stationBannerOf(reactionWith({}, { totalCost: CHEAPEST_TOTAL + 0.02 }), RETAINED_PICK, open),
      retainedCostsMore: stationBannerOf(reactionWith({ totalCost: RETAINED_TOTAL + 50 }, {}), RETAINED_PICK, open),
      noRecommendedStation: stationBannerOf(reactionType({ recommendedStructureId: null }), RETAINED_PICK, open),
      pickedStationAbsentFromTheRows: stationBannerOf(reactionWith({}, {}), { reaction: '9999' }, open),
      costsNotCompared: stationBannerOf(copyType({ costsCompared: false, rows: [unknownAmountsRow('3001'), unknownAmountsRow('3002')] }), { blueprints: '3002' }, open),
      unlockedTypeWithoutDifference: stationBannerOf(componentsType(), {}, open),
    }

    expect(banners).toEqual({
      retainedIsCheapest: null,
      cheapestTotalUnknown: null,
      retainedTotalUnknown: null,
      savingUnderACent: null,
      savingJustAboveACent: { kind: 'onlyFavoriteSystem', structureId: CHEAPEST_ID, saving: expect.closeTo(0.02, 6) },
      retainedCostsMore: null,
      noRecommendedStation: null,
      pickedStationAbsentFromTheRows: null,
      costsNotCompared: null,
      unlockedTypeWithoutDifference: null,
    })
  })

  it('keeps the boundaries exact: a threshold one cent above the saving is not below it, a cheapest station in the favorite system is never blamed on the threshold, and a one cent gap is not a saving', () => {
    const type = reactionWith({}, {})
    const inFavorite = reactionWith({ inFavoriteSystem: true }, { inFavoriteSystem: false })
    const oneCentGap = reactionWith({ totalCost: 1234567.88 }, { totalCost: 1234567.89 })

    expect({
      thresholdOneCentAboveSaving: stationBannerOf(type, RETAINED_PICK, policy(true, 100 + 0.01))?.kind,
      policyOnCheapestInFavoriteBelowThreshold: stationBannerOf(inFavorite, RETAINED_PICK, policy(true, 500))?.kind,
      oneCentGap: stationBannerOf(oneCentGap, RETAINED_PICK, policy(false, 5)),
    }).toEqual({
      thresholdOneCentAboveSaving: 'notRetained',
      policyOnCheapestInFavoriteBelowThreshold: 'notRetained',
      oneCentGap: null,
    })
  })
})
