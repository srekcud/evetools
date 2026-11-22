import { describe, expect, it } from 'vitest'
import { buildFlowGrid, type FlowCard, type FlowColumn } from '@/components/group-industry/projectFlow'
import type { BomItem, JobMatch, StationQuantity } from '@/stores/group-industry/types'
import {
  COMPONENT_X,
  STATION_A_ID,
  TRITANIUM,
  ZYDRINE,
  projectOf,
  stationQuantity,
  typeQuantities,
} from './projectFlowFixtures'

const NOW = Date.parse('2026-11-22T12:00:00Z')
const THREE_HOURS_MS = 3 * 60 * 60 * 1000
const AZBEL = { locationId: STATION_A_ID, stationName: 'Azbel' }
const INVENTED_BLUEPRINT = { typeId: 11_541, typeName: 'Capital Armor Plates Blueprint' }
const COPIED_BLUEPRINT = { typeId: 11_542, typeName: 'Ishtar Blueprint' }
const UNLISTED_TYPE = { typeId: 99_999, typeName: 'Mystery Part' }

function line(fields: Partial<BomItem> & Pick<BomItem, 'typeId'>): BomItem {
  return {
    isJob: false,
    activityType: null,
    runs: null,
    fulfilledQuantity: 0,
    purchasedQuantity: 0,
    jobMatches: [],
    ...fields,
  } as BomItem
}

function jobLine(typeId: number, activityType: string, runs: number | null, jobMatches: JobMatch[] = []): BomItem {
  return line({ typeId, isJob: true, activityType, runs, jobMatches })
}

function linkedJob(status: string, runs: number, endDate: string | null = null): JobMatch {
  return { status, runs, endDate } as JobMatch
}

function cardsOf(
  type: { typeId: number; typeName: string },
  station: Partial<StationQuantity>,
  bomItems: BomItem[],
  column: FlowColumn,
): FlowCard[] {
  const grid = buildFlowGrid({
    quantities: [typeQuantities(type, [stationQuantity({ ...AZBEL, ...station })])],
    actions: [],
    sales: [],
    project: projectOf(),
    bomItems,
    now: NOW,
  })
  return grid.rows.flatMap(row => row.cells[column])
}

function onlyCardOf(
  type: { typeId: number; typeName: string },
  station: Partial<StationQuantity>,
  bomItems: BomItem[],
  column: FlowColumn,
): FlowCard {
  const cards = cardsOf(type, station, bomItems, column)
  expect(cards).toHaveLength(1)
  return cards[0]
}

describe('flow card activity', () => {
  it('colours a line without job as a material', () => {
    const card = onlyCardOf(ZYDRINE, { onSite: 60 }, [line({ typeId: ZYDRINE.typeId })], 'onSite')

    expect(card.activity).toBe('material')
    expect(card.bpc).toBe(false)
    expect(card.progress).toBeNull()
  })

  it.each(['reaction', 'manufacturing', 'copy', 'invention'])('colours a %s job line by its activity', (activity) => {
    const card = onlyCardOf(COMPONENT_X, { inProduction: 4 }, [jobLine(COMPONENT_X.typeId, activity, 10)], 'inProduction')

    expect(card.activity).toBe(activity)
  })

  it('colours a job line with an unrecognised activity as unknown, never as a material', () => {
    const card = onlyCardOf(COMPONENT_X, { onSite: 4 }, [jobLine(COMPONENT_X.typeId, 'research', 10)], 'onSite')

    expect(card.activity).toBe('unknown')
  })

  it('colours a type missing from the BOM as unknown with no source, bar or time', () => {
    const card = onlyCardOf(UNLISTED_TYPE, { onSite: 3 }, [], 'onSite')

    expect(card).toMatchObject({ activity: 'unknown', source: null, bpc: false, progress: null, ready: false, remainingMs: null })
  })

  it('keeps the activity of an unreadable card but gives it no bar', () => {
    const card = onlyCardOf(
      COMPONENT_X,
      { onSite: null, out: null, toBring: null, unknownReason: 'corporation_assets_not_synced' },
      [jobLine(COMPONENT_X.typeId, 'reaction', 10)],
      'onSite',
    )

    expect(card).toMatchObject({ state: 'unknown', activity: 'reaction', progress: null, ready: false })
  })

  it('prefers the job line when two lines share a type', () => {
    const card = onlyCardOf(
      COMPONENT_X,
      { onSite: 4 },
      [line({ typeId: COMPONENT_X.typeId }), jobLine(COMPONENT_X.typeId, 'reaction', 10)],
      'onSite',
    )

    expect(card.activity).toBe('reaction')
  })
})

describe('flow card blueprint frame and source', () => {
  it('frames an invention card as blueprint copies sourced from invention', () => {
    const card = onlyCardOf(INVENTED_BLUEPRINT, { onSite: 20 }, [jobLine(INVENTED_BLUEPRINT.typeId, 'invention', 20)], 'onSite')

    expect(card).toMatchObject({ activity: 'invention', bpc: true, source: 'invention' })
  })

  it('frames a copy card as blueprint copies sourced from a blueprint', () => {
    const card = onlyCardOf(COPIED_BLUEPRINT, { onSite: 59 }, [jobLine(COPIED_BLUEPRINT.typeId, 'copy', 59)], 'onSite')

    expect(card).toMatchObject({ activity: 'copy', bpc: true, source: 'blueprint' })
  })

  it('does not frame a manufacturing or reaction card', () => {
    const card = onlyCardOf(COMPONENT_X, { onSite: 4 }, [jobLine(COMPONENT_X.typeId, 'manufacturing', 10)], 'onSite')

    expect(card.bpc).toBe(false)
  })

  it('tags a material fulfilled without any purchase as stock', () => {
    const card = onlyCardOf(TRITANIUM, { onSite: 400 }, [line({ typeId: TRITANIUM.typeId, fulfilledQuantity: 400, purchasedQuantity: 0 })], 'onSite')

    expect(card.source).toBe('stock')
  })

  it('tags a consumed material fulfilled beyond its purchases as stock', () => {
    const card = onlyCardOf(TRITANIUM, { consumed: 300, onSite: 0 }, [line({ typeId: TRITANIUM.typeId, fulfilledQuantity: 400, purchasedQuantity: 100 })], 'onSite')

    expect(card.source).toBe('stock')
  })

  it('gives no source to a material bought through the project', () => {
    const card = onlyCardOf(TRITANIUM, { onSite: 400 }, [line({ typeId: TRITANIUM.typeId, fulfilledQuantity: 400, purchasedQuantity: 400 })], 'onSite')

    expect(card.source).toBeNull()
  })

  it('gives no stock source to a card that is not on site or consumed', () => {
    const card = onlyCardOf(TRITANIUM, { toBring: 400, onSite: 0 }, [line({ typeId: TRITANIUM.typeId, fulfilledQuantity: 400, purchasedQuantity: 0 })], 'toBring')

    expect(card.source).toBeNull()
  })

  it('gives no stock source to a job line', () => {
    const card = onlyCardOf(COMPONENT_X, { onSite: 4 }, [jobLine(COMPONENT_X.typeId, 'manufacturing', 10)], 'onSite')

    expect(card.source).toBeNull()
  })
})

describe('flow card progress', () => {
  it('measures a batch in production by the share of finished runs', () => {
    const matches = [linkedJob('delivered', 3), linkedJob('ready', 2)]

    const card = onlyCardOf(COMPONENT_X, { inProduction: 40 }, [jobLine(COMPONENT_X.typeId, 'reaction', 10, matches)], 'inProduction')

    expect(card).toMatchObject({ progress: 50, ready: false })
  })

  it('is ready when every run is finished', () => {
    const matches = [linkedJob('delivered', 6), linkedJob('delivered', 4)]

    const card = onlyCardOf(COMPONENT_X, { inProduction: 40 }, [jobLine(COMPONENT_X.typeId, 'reaction', 10, matches)], 'inProduction')

    expect(card).toMatchObject({ progress: 100, ready: true })
  })

  it('treats an active job whose end date has passed as finished', () => {
    const matches = [linkedJob('active', 10, new Date(NOW - 60_000).toISOString())]

    const card = onlyCardOf(COMPONENT_X, { inProduction: 40 }, [jobLine(COMPONENT_X.typeId, 'reaction', 10, matches)], 'inProduction')

    expect(card).toMatchObject({ progress: 100, ready: true })
  })

  it('caps the progress at 100 when more runs finished than planned', () => {
    const matches = [linkedJob('delivered', 12)]

    const card = onlyCardOf(COMPONENT_X, { inProduction: 40 }, [jobLine(COMPONENT_X.typeId, 'reaction', 10, matches)], 'inProduction')

    expect(card.progress).toBe(100)
  })

  it('shows an empty bar, not an absent one, while no run is finished', () => {
    const matches = [linkedJob('active', 10, new Date(NOW + THREE_HOURS_MS).toISOString())]

    const card = onlyCardOf(COMPONENT_X, { inProduction: 40 }, [jobLine(COMPONENT_X.typeId, 'reaction', 10, matches)], 'inProduction')

    expect(card).toMatchObject({ progress: 0, ready: false })
  })

  it.each(['cancelled', 'reverted', 'unknown'])('ignores a %s job in the finished runs', (status) => {
    const matches = [linkedJob(status, 10)]

    const card = onlyCardOf(COMPONENT_X, { inProduction: 40 }, [jobLine(COMPONENT_X.typeId, 'reaction', 10, matches)], 'inProduction')

    expect(card.progress).toBe(0)
  })

  it('has no progress when the job line has no runs', () => {
    const card = onlyCardOf(COMPONENT_X, { inProduction: 40 }, [jobLine(COMPONENT_X.typeId, 'reaction', null)], 'inProduction')

    expect(card.progress).toBeNull()
  })

  it('shows a finished product that is out as a full, ready bar', () => {
    const card = onlyCardOf(COMPONENT_X, { out: 40, onSite: 0 }, [jobLine(COMPONENT_X.typeId, 'reaction', 10)], 'out')

    expect(card).toMatchObject({ progress: 100, ready: true })
  })

  it('shows no bar on a material that is out', () => {
    const card = onlyCardOf(TRITANIUM, { out: 40, onSite: 0 }, [line({ typeId: TRITANIUM.typeId })], 'out')

    expect(card).toMatchObject({ progress: null, ready: false })
  })

  it('shows no bar on a card still to launch', () => {
    const grid = buildFlowGrid({
      quantities: [],
      actions: [{
        id: 'launch-1',
        projectId: 'p1',
        projectName: null,
        code: 'launch_job',
        bomLineId: 'line-1',
        typeId: COMPONENT_X.typeId,
        typeName: COMPONENT_X.typeName,
        quantity: 5,
        place: { kind: 'station', locationId: STATION_A_ID, name: 'Azbel', solarSystemId: null, solarSystemName: null, unknownReason: null },
        here: null,
        access: null,
      }],
      sales: [],
      project: projectOf(),
      bomItems: [jobLine(COMPONENT_X.typeId, 'manufacturing', 10)],
      now: NOW,
    })

    const card = grid.rows[0].cells.inProduction[0]

    expect(card).toMatchObject({ state: 'toLaunch', activity: 'manufacturing', progress: null, ready: false, remainingMs: null })
  })
})

describe('flow card remaining time', () => {
  it('takes the longest remaining time of the running jobs', () => {
    const matches = [
      linkedJob('active', 5, new Date(NOW + THREE_HOURS_MS).toISOString()),
      linkedJob('active', 5, new Date(NOW + THREE_HOURS_MS / 3).toISOString()),
    ]

    const card = onlyCardOf(COMPONENT_X, { inProduction: 40 }, [jobLine(COMPONENT_X.typeId, 'reaction', 10, matches)], 'inProduction')

    expect(card.remainingMs).toBe(THREE_HOURS_MS)
  })

  it('counts a paused job and ignores a cancelled one', () => {
    const matches = [
      linkedJob('paused', 5, new Date(NOW + THREE_HOURS_MS).toISOString()),
      linkedJob('cancelled', 5, new Date(NOW + THREE_HOURS_MS * 2).toISOString()),
    ]

    const card = onlyCardOf(COMPONENT_X, { inProduction: 40 }, [jobLine(COMPONENT_X.typeId, 'reaction', 10, matches)], 'inProduction')

    expect(card.remainingMs).toBe(THREE_HOURS_MS)
  })

  it('has no remaining time without a running job', () => {
    const matches = [linkedJob('delivered', 10)]

    const card = onlyCardOf(COMPONENT_X, { inProduction: 40 }, [jobLine(COMPONENT_X.typeId, 'reaction', 10, matches)], 'inProduction')

    expect(card.remainingMs).toBeNull()
  })

  it('has no remaining time when the running job has no end date', () => {
    const matches = [linkedJob('active', 10, null)]

    const card = onlyCardOf(COMPONENT_X, { inProduction: 40 }, [jobLine(COMPONENT_X.typeId, 'reaction', 10, matches)], 'inProduction')

    expect(card.remainingMs).toBeNull()
  })

  it('shows no remaining time on a card that is not in production', () => {
    const matches = [linkedJob('active', 10, new Date(NOW + THREE_HOURS_MS).toISOString())]

    const card = onlyCardOf(COMPONENT_X, { onSite: 4 }, [jobLine(COMPONENT_X.typeId, 'reaction', 10, matches)], 'onSite')

    expect(card.remainingMs).toBeNull()
  })
})
