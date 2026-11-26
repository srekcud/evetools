import { enableAutoUnmount } from '@vue/test-utils'
import en from '@/i18n/locales/en.json'
import { RANKING_PRODUCT_B } from '../../planetaryPlannerFixtures'
import { TEMPLATE_PRODUCT_M, TEMPLATE_VANISHED, VANISHED_PRODUCT, makeTemplate } from '../../planetaryTemplatesFixtures'
import { cellOfTemplate, mountTemplates, rowOfTemplate } from '../../planetaryTemplatesHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const FIGURE_CELLS = ['template-planets', 'template-profit-day', 'template-profit-week', 'template-profit-month', 'template-purchases']
const ONE_MISSING = makeTemplate('tpl-one-missing', 'One missing', [
  { typeId: RANKING_PRODUCT_B, targetPerDay: 120 },
  { typeId: VANISHED_PRODUCT, targetPerDay: 12 },
  { typeId: TEMPLATE_PRODUCT_M, targetPerDay: 18 },
])

describe('PiPlannerTemplates row of a template with a missing recipe', () => {
  it('shows every figure as unknown and incomplete, the recipe not found label and the product as #99999 x 12 a day', async () => {
    const { wrapper } = await mountTemplates({ templates: [TEMPLATE_VANISHED] })

    for (const testId of FIGURE_CELLS) {
      const cell = cellOfTemplate(wrapper, 'tpl-vanished', testId)
      expect(cell.text()).toBe(en.pi.planner.unknown)
      expect(cell.attributes('data-state')).toBe('unknown')
      expect(cell.attributes('data-incomplete')).toBe('true')
    }
    expect(cellOfTemplate(wrapper, 'tpl-vanished', 'template-productions').text()).toMatch(/#99999\s*×\s*12\s*\/\s*day/)
    expect(cellOfTemplate(wrapper, 'tpl-vanished', 'template-missing').text()).toBe(en.pi.planner.templates.missing)
  })

  it('shows every figure as unknown for a missing product among valid ones while naming the valid products and keeping Delete enabled', async () => {
    const { wrapper } = await mountTemplates({ templates: [ONE_MISSING] })
    const productions = cellOfTemplate(wrapper, 'tpl-one-missing', 'template-productions').text()

    for (const testId of FIGURE_CELLS) {
      const cell = cellOfTemplate(wrapper, 'tpl-one-missing', testId)
      expect(cell.text()).toBe(en.pi.planner.unknown)
      expect(cell.attributes('data-state')).toBe('unknown')
    }
    expect(productions).toMatch(/Ranking Product B\s*×\s*120\s*\/\s*day/)
    expect(productions).toMatch(/#99999\s*×\s*12\s*\/\s*day/)
    expect(productions).toMatch(/Ranking Product M\s*×\s*18\s*\/\s*day/)
    expect(rowOfTemplate(wrapper, 'tpl-one-missing').get('[data-testid="template-load"]').attributes('disabled')).toBeDefined()
    expect(rowOfTemplate(wrapper, 'tpl-one-missing').get('[data-testid="template-delete"]').attributes('disabled')).toBeUndefined()
  })

  it('names the vanished product by its type id, also in the title attribute', async () => {
    const { wrapper } = await mountTemplates({ templates: [TEMPLATE_VANISHED] })

    expect(cellOfTemplate(wrapper, 'tpl-vanished', 'template-productions').find('[title="#99999"]').exists()).toBe(true)
  })
})
