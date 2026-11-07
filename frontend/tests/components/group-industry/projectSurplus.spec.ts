import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectSurplus from '@/components/group-industry/ProjectSurplus.vue'
import { apiRequest } from '@/services/api'
import type { ProjectSurplus as ProjectSurplusResource, ProjectSurplusLine } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const PROJECT_ID = 'project-ishtar-batch'
const OTHER_PROJECT_ID = 'project-hecate-batch'
const SURPLUS_PATH = `/industry/projects/${PROJECT_ID}/surplus`
const BLANK = /[\u00a0\u202f]/g

const TRITANIUM: ProjectSurplusLine = {
  lineId: 'line-tritanium',
  typeId: 34,
  typeName: 'Tritanium',
  requiredQuantity: 12_000,
  contributedQuantity: 12_500,
  surplusQuantity: 500,
  unitPrice: 4.25,
  surplusValue: 2_125,
}
const MORPHITE: ProjectSurplusLine = {
  lineId: 'line-morphite',
  typeId: 11_399,
  typeName: 'Morphite',
  requiredQuantity: 150,
  contributedQuantity: 160,
  surplusQuantity: 10,
  unitPrice: 18_500,
  surplusValue: 185_000,
}
const ISOGEN_WITHOUT_VALUE: ProjectSurplusLine = {
  lineId: 'line-isogen',
  typeId: 37,
  typeName: 'Isogen',
  requiredQuantity: 200,
  contributedQuantity: 250,
  surplusQuantity: 50,
  unitPrice: null,
  surplusValue: null,
}

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

function text(key: string, params: Record<string, unknown> = {}): string {
  return i18n.global.t(key, params).replace(BLANK, ' ')
}

function resource(lines: ProjectSurplusLine[], totalValue: number | null, projectId = PROJECT_ID): ProjectSurplusResource {
  return { id: projectId, totalValue, lines }
}

async function mountSurplus(lines: ProjectSurplusLine[], totalValue: number | null): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockResolvedValue(resource(lines, totalValue))
  setActivePinia(createPinia())
  const wrapper = mount(ProjectSurplus, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function rowText(wrapper: VueWrapper, line: ProjectSurplusLine): string {
  const found = wrapper.find(`[data-testid="project-surplus-row-${line.lineId}"]`)
  return found.exists() ? found.text().replace(BLANK, ' ') : 'absent'
}

function totalText(wrapper: VueWrapper): string {
  const found = wrapper.find('[data-testid="project-surplus-total"]')
  return found.exists() ? found.text().replace(BLANK, ' ') : 'absent'
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('project surplus card', () => {
  it('reads the surplus of the project when it opens', async () => {
    await mountSurplus([TRITANIUM], 2_125)

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(SURPLUS_PATH)
  })

  it('reads again when the project changes', async () => {
    const wrapper = await mountSurplus([TRITANIUM], 2_125)
    vi.mocked(apiRequest).mockResolvedValue(resource([MORPHITE], 185_000, OTHER_PROJECT_ID))

    await wrapper.setProps({ projectId: OTHER_PROJECT_ID })
    await flushPromises()

    expect(vi.mocked(apiRequest).mock.calls.map(([path]) => path)).toEqual([SURPLUS_PATH, `/industry/projects/${OTHER_PROJECT_ID}/surplus`])
    expect(rowText(wrapper, MORPHITE)).toContain('Morphite')
    expect(rowText(wrapper, TRITANIUM)).toBe('absent')
  })

  it('shows the required, contributed and surplus quantities with the contribution price and the value of the surplus', async () => {
    const wrapper = await mountSurplus([TRITANIUM], 2_125)

    const shown = rowText(wrapper, TRITANIUM)
    expect(shown).toContain('Tritanium')
    expect(shown).toContain('12,000')
    expect(shown).toContain('12,500')
    expect(shown).toContain('500')
    expect(shown).toContain('4.25')
    expect(shown).toContain('2,125 ISK')
  })

  it('shows the total value under the lines', async () => {
    const wrapper = await mountSurplus([TRITANIUM, MORPHITE], 187_125)

    expect(totalText(wrapper)).toContain('187,125 ISK')
  })

  it('shows an unknown value as unknown, never as zero', async () => {
    const wrapper = await mountSurplus([ISOGEN_WITHOUT_VALUE], null)

    const shown = rowText(wrapper, ISOGEN_WITHOUT_VALUE)
    expect(shown).toContain(text('groupIndustry.surplus.unknown'))
    expect(shown).not.toContain('0 ISK')
    expect(totalText(wrapper)).toContain(text('groupIndustry.surplus.unknown'))
    expect(totalText(wrapper)).not.toContain('0 ISK')
  })

  it('lists the lines in the order of the response under a titled card', async () => {
    const wrapper = await mountSurplus([MORPHITE, TRITANIUM], 187_125)

    expect(wrapper.find('[data-testid="project-surplus"] h2').text()).toBe(text('groupIndustry.surplus.title'))
    expect(wrapper.findAll('[data-testid^="project-surplus-row-"]').map(row => row.attributes('data-testid'))).toEqual([
      'project-surplus-row-line-morphite',
      'project-surplus-row-line-tritanium',
    ])
  })

  it('states that the card is read only: nothing goes to the Stockpile and the profit is unchanged', async () => {
    const wrapper = await mountSurplus([TRITANIUM], 2_125)

    expect(wrapper.find('[data-testid="project-surplus-readonly"]').text()).toBe(text('groupIndustry.surplus.readOnly'))
    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('says there is no surplus when no line exceeds its required quantity', async () => {
    const wrapper = await mountSurplus([], 0)

    expect(wrapper.find('[data-testid="project-surplus-empty"]').text()).toBe(text('groupIndustry.surplus.empty'))
    expect(wrapper.find('[data-testid="project-surplus-total"]').exists()).toBe(false)
  })

  it('shows the error when the surplus cannot be read', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Server unreachable'))
    setActivePinia(createPinia())
    const wrapper = mount(ProjectSurplus, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
    await flushPromises()

    const alert = wrapper.find('[data-testid="project-surplus-error"]')
    expect(alert.text()).toContain(text('groupIndustry.surplus.loadError'))
    expect(alert.text()).toContain('Server unreachable')
    expect(wrapper.find('[data-testid="project-surplus-empty"]').exists()).toBe(false)
  })

  it('has the labels in English and French', () => {
    const keys = ['title', 'loading', 'loadError', 'empty', 'readOnly', 'unknown', 'total', 'columns.type', 'columns.required', 'columns.contributed', 'columns.surplus', 'columns.unitPrice', 'columns.value']
    const missing = (['en', 'fr'] as const).flatMap(locale => keys.filter(key => i18n.global.te(`groupIndustry.surplus.${key}`, locale) === false))

    expect(missing).toEqual([])
  })
})
