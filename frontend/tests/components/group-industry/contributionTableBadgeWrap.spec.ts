import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContributionTable from '@/components/group-industry/ContributionTable.vue'
import { apiRequest } from '@/services/api'
import type { GroupContribution } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-1098'
const METHOD_CELL_INDEX = 5
const STATUS_CELL_INDEX = 6

function contribution(overrides: Partial<GroupContribution>): GroupContribution {
  return {
    id: 'contribution',
    memberCharacterName: 'Aria Solberg',
    memberId: 'member-aria',
    bomItemId: 'bom-line',
    bomItemTypeName: 'Tritanium',
    type: 'material',
    quantity: 100,
    estimatedValue: 700,
    status: 'approved',
    isAutoDetected: false,
    isVerified: true,
    valuedAtJitaPrice: false,
    valueSource: 'wallet_purchase',
    awaitingQuantity: 0,
    reopenable: false,
    duplicatesDerived: false,
    reviewedByCharacterName: null,
    reviewedAt: null,
    note: null,
    createdAt: '2026-11-19T09:00:00+00:00',
    ...overrides,
  }
}

async function mountTable(contributions: GroupContribution[], locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockResolvedValueOnce(contributions)
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(ContributionTable, { global: { plugins: [i18n] }, props: { projectId: PROJECT_ID, isAdmin: true } })
  await flushPromises()
  return wrapper
}

function cellsOf(wrapper: VueWrapper): DOMWrapper<Element>[] {
  const row = wrapper.find('tbody tr')
  return row.findAll('td')
}

const CROWDED_STATUS: Partial<GroupContribution> = {
  status: 'pending',
  isAutoDetected: true,
  isVerified: false,
  duplicatesDerived: true,
  walletBackedQuantity: 40,
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ContributionTable badges never break inside themselves', () => {
  it.each(['en', 'fr'] as const)('keeps every badge of the Status column on one line in %s', async locale => {
    const wrapper = await mountTable([contribution(CROWDED_STATUS)], locale)
    const badges = cellsOf(wrapper)[STATUS_CELL_INDEX].findAll('span')

    expect(badges.length).toBeGreaterThanOrEqual(4)
    for (const badge of badges) {
      expect(badge.classes(), badge.text()).toContain('whitespace-nowrap')
    }
  })

  it.each(['en', 'fr'] as const)('keeps the Method label and the ESI badge on one line in %s', async locale => {
    const wrapper = await mountTable([contribution({ isAutoDetected: true })], locale)
    const spans = cellsOf(wrapper)[METHOD_CELL_INDEX].findAll('span')

    expect(spans).toHaveLength(2)
    for (const span of spans) {
      expect(span.classes(), span.text()).toContain('whitespace-nowrap')
    }
  })

  it('lets whole badges flow to the next line in the Status column', async () => {
    const wrapper = await mountTable([contribution(CROWDED_STATUS)])
    const container = cellsOf(wrapper)[STATUS_CELL_INDEX].find('[data-testid="status-badges"]')

    expect(container.exists()).toBe(true)
    expect(container.classes()).toEqual(expect.arrayContaining(['flex', 'flex-wrap', 'justify-center']))
    expect(container.classes().some(name => name.startsWith('gap-'))).toBe(true)
  })

  it('lets whole badges flow to the next line in the Method column', async () => {
    const wrapper = await mountTable([contribution({ isAutoDetected: true })])
    const container = cellsOf(wrapper)[METHOD_CELL_INDEX].find('[data-testid="method-badges"]')

    expect(container.exists()).toBe(true)
    expect(container.classes()).toEqual(expect.arrayContaining(['flex', 'flex-wrap']))
    expect(container.classes().some(name => name.startsWith('gap-'))).toBe(true)
  })

  it('no longer spaces badges with a left margin', async () => {
    const wrapper = await mountTable([contribution(CROWDED_STATUS)])
    const cells = cellsOf(wrapper)

    for (const index of [METHOD_CELL_INDEX, STATUS_CELL_INDEX]) {
      for (const span of cells[index].findAll('span')) {
        expect(span.classes(), span.text()).not.toContain('ml-1.5')
      }
    }
  })

  it('still renders the approved status badge alone when nothing else applies', async () => {
    const wrapper = await mountTable([contribution({ valueSource: 'jita_price', valuedAtJitaPrice: true })])
    const badges = cellsOf(wrapper)[STATUS_CELL_INDEX].findAll('span')

    expect(badges).toHaveLength(1)
    expect(badges[0].text()).toBe('Approved')
  })
})
