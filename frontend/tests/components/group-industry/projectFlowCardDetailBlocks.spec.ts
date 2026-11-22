import type { FlowCard, FlowCardState } from '@/components/group-industry/projectFlow'
import { DETAIL_BLOCK_ORIGINS, detailBlockKindsOf, lineOfCard, type DetailBlockKind } from '@/components/group-industry/projectFlowCardDetail'
import type { BomItem, PendingContribution } from '@/stores/group-industry/types'
import { bomLineOf, flowCard, ISHTAR, TRITANIUM, ZYDRINE } from './projectFlowFixtures'

const ALL_KINDS: DetailBlockKind[] = [
  'whereToBuy', 'courier', 'contributions', 'useMyStock', 'depositToCheck', 'blueprints', 'research', 'jobs',
  'reservation', 'planning', 'structure', 'decryptors', 'whereToSell', 'sellOrders', 'salesToMatch',
]

const JOB_BLOCKS: DetailBlockKind[] = ['jobs', 'reservation', 'planning', 'structure']
const SALES_BLOCKS: DetailBlockKind[] = ['whereToSell', 'sellOrders', 'salesToMatch']
const SUPPLY_BLOCKS: DetailBlockKind[] = ['whereToBuy', 'courier', 'contributions']

const PENDING_DEPOSIT: PendingContribution = { participantId: 'participant-aria', characterName: 'Aria Solberg', quantity: 250, isMine: true }

const MATERIAL_LINE = bomLineOf({ id: 'line-tritanium', ...TRITANIUM })
const MATERIAL_LINE_WITH_DEPOSIT = bomLineOf({ id: 'line-tritanium', ...TRITANIUM, pendingContributions: [PENDING_DEPOSIT] })
const JOB_LINE = bomLineOf({ id: 'line-ishtar', ...ISHTAR, isJob: true, activityType: 'manufacturing', runs: 10 })
const INVENTION_LINE = bomLineOf({ id: 'line-ishtar-invention', ...ISHTAR, isJob: true, activityType: 'invention', runs: 4 })

interface DetailCase {
  name: string
  state: FlowCardState
  fields?: Partial<Pick<FlowCard, 'source' | 'bpc' | 'activity'>>
  line: BomItem | undefined
  isFinalProduct?: boolean
  personalUse?: boolean
  expected: DetailBlockKind[]
}

const CASES: DetailCase[] = [
  { name: 'no block without a BOM line', state: 'toBuy', line: undefined, expected: [] },
  { name: 'a material to buy', state: 'toBuy', line: MATERIAL_LINE, expected: SUPPLY_BLOCKS },
  { name: 'a material to bring', state: 'toBring', line: MATERIAL_LINE, expected: SUPPLY_BLOCKS },
  { name: 'a material in transit', state: 'inTransit', line: MATERIAL_LINE, expected: SUPPLY_BLOCKS },
  { name: 'a material taken from stock', state: 'onSite', fields: { source: 'stock' }, line: MATERIAL_LINE, expected: ['useMyStock'] },
  { name: 'a material with a deposit awaiting check', state: 'onSite', line: MATERIAL_LINE_WITH_DEPOSIT, expected: ['useMyStock', 'depositToCheck'] },
  { name: 'a stock material with a deposit awaiting check has no contributions block', state: 'onSite', fields: { source: 'stock' }, line: MATERIAL_LINE_WITH_DEPOSIT, expected: ['useMyStock', 'depositToCheck'] },
  { name: 'any other material', state: 'onSite', line: MATERIAL_LINE, expected: ['contributions'] },
  { name: 'a manufacturing job', state: 'inProduction', fields: { activity: 'manufacturing' }, line: JOB_LINE, expected: JOB_BLOCKS },
  { name: 'a blueprint copy job', state: 'inProduction', fields: { activity: 'copy', bpc: true }, line: { ...JOB_LINE, activityType: 'copy' }, expected: ['blueprints', 'research', ...JOB_BLOCKS] },
  { name: 'an invention job', state: 'inProduction', fields: { activity: 'invention', bpc: true }, line: INVENTION_LINE, expected: ['blueprints', 'research', ...JOB_BLOCKS, 'decryptors'] },
  { name: 'a final product that is not a job line', state: 'onSite', line: MATERIAL_LINE, isFinalProduct: true, expected: ['contributions', ...SALES_BLOCKS] },
  { name: 'a final product of a personal use project', state: 'onSite', line: MATERIAL_LINE, isFinalProduct: true, personalUse: true, expected: ['contributions'] },
  { name: 'a final product job, sales blocks last', state: 'out', fields: { activity: 'manufacturing' }, line: JOB_LINE, isFinalProduct: true, expected: [...JOB_BLOCKS, ...SALES_BLOCKS] },
  { name: 'a final product job of a personal use project', state: 'out', fields: { activity: 'manufacturing' }, line: JOB_LINE, isFinalProduct: true, personalUse: true, expected: JOB_BLOCKS },
]

describe('detailBlockKindsOf', () => {
  it.each(CASES)('lists the blocks of $name', ({ state, fields, line, isFinalProduct = false, personalUse = false, expected }) => {
    const card = flowCard(state, { typeName: line?.typeName ?? TRITANIUM.typeName, typeId: line?.typeId ?? TRITANIUM.typeId, ...fields })

    expect(detailBlockKindsOf({ card, line, isFinalProduct, personalUse })).toEqual(expected)
  })
})

describe('DETAIL_BLOCK_ORIGINS', () => {
  it('maps each block kind to its origin label key under groupIndustry.flow.detail.origins', () => {
    expect(Object.keys(DETAIL_BLOCK_ORIGINS).sort()).toEqual([...ALL_KINDS].sort())
    expect(Object.fromEntries(ALL_KINDS.map(kind => [kind, DETAIL_BLOCK_ORIGINS[kind]]))).toEqual(
      Object.fromEntries(ALL_KINDS.map(kind => [kind, `groupIndustry.flow.detail.origins.${kind}`])),
    )
  })
})

describe('lineOfCard', () => {
  it('takes the job line of the type first, else its first line, and nothing for an unknown type', () => {
    const materialLine = bomLineOf({ id: 'line-zydrine-material', ...ZYDRINE })
    const otherMaterialLine = bomLineOf({ id: 'line-zydrine-other', ...ZYDRINE })
    const jobLine = bomLineOf({ id: 'line-zydrine-job', ...ZYDRINE, isJob: true })
    const bomItems = [materialLine, otherMaterialLine, jobLine, MATERIAL_LINE]

    const zydrineCard = flowCard('onSite', { typeName: 'Zydrine', typeId: ZYDRINE.typeId })
    const tritaniumCard = flowCard('onSite', { typeName: 'Tritanium', typeId: TRITANIUM.typeId })
    const ishtarCard = flowCard('onSite', { typeName: 'Ishtar', typeId: ISHTAR.typeId })
    const untypedCard = flowCard('unknown', { typeName: 'Unnamed' })

    expect(lineOfCard(zydrineCard, bomItems)?.id).toBe('line-zydrine-job')
    expect(lineOfCard(zydrineCard, [materialLine, otherMaterialLine])?.id).toBe('line-zydrine-material')
    expect(lineOfCard(tritaniumCard, bomItems)?.id).toBe('line-tritanium')
    expect(lineOfCard(ishtarCard, bomItems)).toBeUndefined()
    expect(lineOfCard(untypedCard, bomItems)).toBeUndefined()
  })
})
