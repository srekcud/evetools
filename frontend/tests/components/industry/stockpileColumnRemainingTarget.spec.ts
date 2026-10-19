import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import StockpileColumn from '@/components/industry/StockpileColumn.vue'
import type { StockpileItemStatus, StockpileStage } from '@/stores/industry/types'

type ItemWithRemainingTarget = StockpileItemStatus & { remainingTargetQuantity: number }

const ORE_TYPE_ID = 3003
const FRAME_TYPE_ID = 3002
const ORE_TARGET_QUANTITY = 700
const ORE_REMAINING_TARGET_QUANTITY = 350
const ORE_STOCK = 0
const ORE_REMAINING_TARGET_QUANTITY_WITH_FRAMES_IN_STOCK = 0
const FRAME_TARGET_QUANTITY = 10
const FRAME_REMAINING_TARGET_QUANTITY = 10
const FRAME_STOCK = 5

const oreItem: ItemWithRemainingTarget = {
  id: '00000000-0000-4000-8000-000000003003',
  typeId: ORE_TYPE_ID,
  typeName: 'Ore',
  targetQuantity: ORE_TARGET_QUANTITY,
  remainingTargetQuantity: ORE_REMAINING_TARGET_QUANTITY,
  stock: ORE_STOCK,
  percent: 0,
  status: 'critical',
  unitPrice: 2,
  stockValue: 0,
  deficitCost: 700,
  sourceProductTypeId: 3001,
  locations: [],
}

const frameItem: ItemWithRemainingTarget = {
  id: '00000000-0000-4000-8000-000000003002',
  typeId: FRAME_TYPE_ID,
  typeName: 'Frame',
  targetQuantity: FRAME_TARGET_QUANTITY,
  remainingTargetQuantity: FRAME_REMAINING_TARGET_QUANTITY,
  stock: FRAME_STOCK,
  percent: 50,
  status: 'partial',
  unitPrice: 50,
  stockValue: 250,
  deficitCost: 250,
  sourceProductTypeId: 3001,
  locations: [],
}

function columnText(stage: StockpileStage, item: ItemWithRemainingTarget): string {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(StockpileColumn, {
    global: { plugins: [i18n] },
    props: {
      stage,
      stageData: { items: [item], totalValue: item.stockValue, healthPercent: 0 },
      isLastColumn: false,
      stageColor: 'cyan',
      stageName: stage,
    },
  })
  return wrapper.text().replace(/\s+/g, ' ')
}

describe('StockpileColumn remaining target', () => {
  it('shows the stock against the remaining target, with the target quantity in parentheses when they differ', () => {
    expect(columnText('raw_material', oreItem)).toContain('0 / 350 (700)')
  })

  it('shows the stock against the remaining target without parentheses when it equals the target quantity', () => {
    const text = columnText('component', frameItem)

    expect(text).toContain('5 / 10')
    expect(text).not.toMatch(/5 \/ 10 \(/)
  })

  it('shows a remaining target of zero against the target quantity in parentheses', () => {
    const oreCoveredByFrames = { ...oreItem, remainingTargetQuantity: ORE_REMAINING_TARGET_QUANTITY_WITH_FRAMES_IN_STOCK, percent: 100, status: 'met' as const, deficitCost: 0 }

    expect(columnText('raw_material', oreCoveredByFrames)).toContain('0 / 0 (700)')
  })
})
