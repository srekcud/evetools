import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { RANKING_INPUT_A, RANKING_INPUT_C, RANKING_PRODUCT_B, withSellPrices, withTypeVolume } from '../../planetaryPlannerFixtures'
import {
  TEMPLATE_B_AND_M,
  TEMPLATE_B_LINE,
  TEMPLATE_CATALOG,
  TEMPLATE_PRODUCT_M,
  makeTemplate,
} from '../../planetaryTemplatesFixtures'
import { cellOfTemplate, mountTemplates, rowOfTemplate, type TemplatesWrapper } from '../../planetaryTemplatesHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const PROFIT_CELLS = ['template-profit-day', 'template-profit-week', 'template-profit-month']
const B_THEN_M = makeTemplate('tpl-b-then-m', 'B then M', [{ typeId: RANKING_PRODUCT_B, targetPerDay: 120 }, { typeId: TEMPLATE_PRODUCT_M, targetPerDay: 18 }])
const M_THEN_B = makeTemplate('tpl-m-then-b', 'M then B', [{ typeId: TEMPLATE_PRODUCT_M, targetPerDay: 18 }, { typeId: RANKING_PRODUCT_B, targetPerDay: 120 }])
const EARLY_TEMPLATE = makeTemplate('tpl-early', 'Early B', [{ typeId: RANKING_PRODUCT_B, targetPerDay: 120 }], {}, undefined, '2026-11-05T12:00:00+00:00')
const FRACTIONAL_TEMPLATE = makeTemplate('tpl-fractional', 'Fractional B', [{ typeId: RANKING_PRODUCT_B, targetPerDay: 36.5 }])

function headerLabels(wrapper: TemplatesWrapper): string[] {
  return wrapper.get('.overflow-x-auto > div').findAll('span').map(label => label.text())
}

function productionLines(wrapper: TemplatesWrapper, templateId: string): string[] {
  return Array.from(cellOfTemplate(wrapper, templateId, 'template-productions').element.children).map(line => (line.textContent ?? '').replace(/\s+/g, ' ').trim())
}

async function click(wrapper: TemplatesWrapper, templateId: string, testId: string) {
  await rowOfTemplate(wrapper, templateId).get(`[data-testid="${testId}"]`).trigger('click')
  await flushPromises()
}

describe('PiPlannerTemplates headers', () => {
  it('labels the columns in English, with a last column left blank for the buttons', async () => {
    const { wrapper } = await mountTemplates({ templates: [TEMPLATE_B_LINE] })

    expect(headerLabels(wrapper)).toEqual(['Name', 'Productions', 'Planets', 'Profit / day', 'Profit / week', 'Profit / month', 'Purchases / week', 'Saved on', ''])
  })

  it('labels the columns in French', async () => {
    const { wrapper } = await mountTemplates({ templates: [TEMPLATE_B_LINE], locale: 'fr' })

    expect(headerLabels(wrapper)).toEqual(['Nom', 'Productions', 'Planètes', 'Profit / jour', 'Profit / semaine', 'Profit / mois', 'Achats / semaine', 'Enregistré le', ''])
  })
})

describe('PiPlannerTemplates incomplete flags', () => {
  it('flags nothing as incomplete when every price and volume is known', async () => {
    const { wrapper } = await mountTemplates({ templates: [TEMPLATE_B_LINE] })

    for (const testId of ['template-planets', ...PROFIT_CELLS, 'template-purchases']) {
      expect(cellOfTemplate(wrapper, 'tpl-b-line', testId).attributes('data-incomplete')).toBe('false')
    }
  })

  it('flags only the planets as incomplete when the volume of a type is unknown', async () => {
    const { wrapper } = await mountTemplates({ templates: [TEMPLATE_B_LINE], catalog: withTypeVolume(TEMPLATE_CATALOG, RANKING_INPUT_A, null) })

    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-planets').attributes('data-incomplete')).toBe('true')
    for (const testId of [...PROFIT_CELLS, 'template-purchases']) {
      expect(cellOfTemplate(wrapper, 'tpl-b-line', testId).attributes('data-incomplete')).toBe('false')
    }
  })

  it('flags the three profits and the purchases but not the planets when the price of a bought input is missing', async () => {
    const { wrapper } = await mountTemplates({ templates: [TEMPLATE_B_LINE], catalog: withSellPrices(TEMPLATE_CATALOG, { [RANKING_INPUT_C]: null }) })

    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-planets').attributes('data-incomplete')).toBe('false')
    for (const testId of [...PROFIT_CELLS, 'template-purchases']) {
      expect(cellOfTemplate(wrapper, 'tpl-b-line', testId).attributes('data-incomplete')).toBe('true')
    }
  })
})

describe('PiPlannerTemplates productions and figures', () => {
  it('lists the productions in the order of the template, whichever the catalog order: B x 120 then M x 18, and M x 18 then B x 120', async () => {
    const { wrapper } = await mountTemplates({ templates: [B_THEN_M, M_THEN_B] })

    expect(productionLines(wrapper, 'tpl-b-then-m')).toEqual(['Ranking Product B× 120 / day', 'Ranking Product M× 18 / day'])
    expect(productionLines(wrapper, 'tpl-m-then-b')).toEqual(['Ranking Product M× 18 / day', 'Ranking Product B× 120 / day'])
  })

  it('shows a fractional target of 36.5 in English and 36,5 in French', async () => {
    const english = await mountTemplates({ templates: [FRACTIONAL_TEMPLATE] })
    expect(productionLines(english.wrapper, 'tpl-fractional')).toEqual(['Ranking Product B× 36.5 / day'])

    const french = await mountTemplates({ templates: [FRACTIONAL_TEMPLATE], locale: 'fr' })
    expect(productionLines(french.wrapper, 'tpl-fractional')).toEqual(['Ranking Product B× 36,5 / jour'])
  })

  it('colors a negative profit red and a positive one green: B at 300 gives -13.44 K, -94.08 K and -403.20 K while M keeps 8.05 K', async () => {
    const mTemplate = makeTemplate('tpl-m-line', 'M line', [{ typeId: TEMPLATE_PRODUCT_M, targetPerDay: 18 }])
    const { wrapper } = await mountTemplates({ templates: [TEMPLATE_B_LINE, mTemplate], catalog: withSellPrices(TEMPLATE_CATALOG, { [RANKING_PRODUCT_B]: 300 }) })

    const negatives = PROFIT_CELLS.map(testId => cellOfTemplate(wrapper, 'tpl-b-line', testId))
    expect(negatives.map(cell => cell.text())).toEqual(['-13.44 K', '-94.08 K', '-403.20 K'])
    for (const cell of negatives) expect(cell.classes()).toContain('text-red-400')
    expect(cellOfTemplate(wrapper, 'tpl-m-line', 'template-profit-day').text()).toBe('8.05 K')
    expect(cellOfTemplate(wrapper, 'tpl-m-line', 'template-profit-day').classes()).not.toContain('text-red-400')
    expect(cellOfTemplate(wrapper, 'tpl-m-line', 'template-profit-day').classes()).toContain('text-emerald-400')
  })

  it('shows a profit of exactly 0 as 0, known and not red', async () => {
    const untaxed = makeTemplate('tpl-untaxed', 'Untaxed B', [{ typeId: RANKING_PRODUCT_B, targetPerDay: 120 }], {}, { priceSource: 'jita-sell', taxPercent: 0, daysBetweenRounds: 4 })
    const { wrapper } = await mountTemplates({ templates: [untaxed], catalog: withSellPrices(TEMPLATE_CATALOG, { [RANKING_PRODUCT_B]: 400 }) })

    for (const testId of PROFIT_CELLS) {
      const cell = cellOfTemplate(wrapper, 'tpl-untaxed', testId)
      expect(cell.text()).toBe('0')
      expect(cell.attributes('data-state')).toBe('known')
      expect(cell.classes()).not.toContain('text-red-400')
    }
  })
})

describe('PiPlannerTemplates saved date', () => {
  it('shows the month first in English: 11/20/2026 and 11/05/2026', async () => {
    const { wrapper } = await mountTemplates({ templates: [TEMPLATE_B_LINE, EARLY_TEMPLATE] })

    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-date').text()).toBe('11/20/2026')
    expect(cellOfTemplate(wrapper, 'tpl-early', 'template-date').text()).toBe('11/05/2026')
  })

  it('shows the day first in French: 20/11/2026 and 05/11/2026', async () => {
    const { wrapper } = await mountTemplates({ templates: [TEMPLATE_B_LINE, EARLY_TEMPLATE], locale: 'fr' })

    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-date').text()).toBe('20/11/2026')
    expect(cellOfTemplate(wrapper, 'tpl-early', 'template-date').text()).toBe('05/11/2026')
  })
})

describe('PiPlannerTemplates confirmation text', () => {
  it('names the template in the confirmation and words Load and Delete differently', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [TEMPLATE_B_AND_M, TEMPLATE_B_LINE] })
    store.addProduction(RANKING_PRODUCT_B, 120)

    await click(wrapper, 'tpl-b-and-m', 'template-load')
    const loadMessage = wrapper.get('[data-testid="template-confirm"] span').text()
    await wrapper.get('[data-testid="template-confirm-cancel"]').trigger('click')
    await click(wrapper, 'tpl-b-line', 'template-delete')
    const deleteMessage = wrapper.get('[data-testid="template-confirm"] span').text()

    expect(loadMessage).toBe('Replace the current plan with "B and M mix"?')
    expect(deleteMessage).toBe('Delete the template "Barren B line"?')
  })
})
