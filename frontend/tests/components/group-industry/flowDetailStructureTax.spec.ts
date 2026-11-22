import { enableAutoUnmount, flushPromises, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectFlowCardDetail from '@/components/group-industry/ProjectFlowCardDetail.vue'
import { apiRequest } from '@/services/api'
import type { BomItem, JobMatch, StructureHealthRow } from '@/stores/group-industry/types'
import type { TestLocale } from '../../localeTexts'
import { bomLineOf, flowCard, ISHTAR, projectOf } from './projectFlowFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const PROJECT_ID = 'project-ishtar-batch'
const FACILITY_NAME = 'Azbel'
const TAX_PERCENT = 3.5
const STRUCTURE_ITEM = '[data-testid="flow-detail-structure"]'
const SETTINGS_LOCATION = { name: 'settings', params: { section: 'industry' } }
const MESSAGES: Record<TestLocale, unknown> = { en, fr }

function stationText(locale: TestLocale, key: string): string {
  const message = `groupIndustry.setup.stations.${key}`.split('.').reduce<unknown>((node, part) => (node as Record<string, unknown> | undefined)?.[part], MESSAGES[locale])
  return typeof message === 'string' ? message : `missing ${locale} message ${key}`
}

function squashed(text: string): string {
  return text.replace(/[\u00a0\u202f]/g, ' ').replace(/\s+/g, ' ').trim()
}

function healthRow(facilityTaxPercent: number | null): StructureHealthRow {
  return { structureId: 'structure-azbel', name: FACILITY_NAME, kind: 'raitaru', state: 'ok', fuelDays: 12, offlineServices: [], facilityTaxPercent }
}

function lineRunAtAzbel(): BomItem {
  const jobMatch: JobMatch = {
    id: 'match-aria', bomLineId: 'line-ishtar', esiJobId: 480_012, cost: 1_250_000, status: 'active', endDate: '2026-11-23T10:00:00+00:00',
    runs: 7, characterName: 'Aria Solberg', matchedAt: '2026-11-20T08:00:00+00:00', facilityId: 1_040_000_000_002, facilityName: FACILITY_NAME, manual: false,
  }
  return bomLineOf({ id: 'line-ishtar', ...ISHTAR, isJob: true, activityType: 'manufacturing', runs: 10, jobMatches: [jobMatch] })
}

async function mountStructureBlock(rows: StructureHealthRow[], locale: TestLocale = 'en'): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    const resource = endpoint.split('?')[0].replace(`/industry/projects/${PROJECT_ID}/`, '')
    if (resource === 'structure-health') return { id: PROJECT_ID, lowFuelThresholdDays: 3, rows }
    if (resource === 'planning') return { id: PROJECT_ID, anchor: '2026-11-20T08:00:00+00:00', structureTimeBonusIncluded: false, plannedEnd: null, expectedEnd: null, delaySeconds: null, delayCause: null, lanesShown: true, characters: [], unscheduled: [] }
    return []
  })
  setActivePinia(createPinia())
  const wrapper = mount(ProjectFlowCardDetail, {
    props: {
      projectId: PROJECT_ID,
      project: projectOf(),
      canLinkJobs: false,
      canRecordSales: false,
      card: flowCard('inProduction', { ...ISHTAR, quantity: 12, activity: 'manufacturing', key: 'inProduction:12005:azbel' }),
      line: lineRunAtAzbel(),
    },
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: { RouterLink: RouterLinkStub, JobLinkModal: true, teleport: true },
    },
  })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('card detail structure block installation tax', () => {
  it('shows the installation tax rate of the structure in French and in English', async () => {
    for (const locale of ['fr', 'en'] as const) {
      const wrapper = await mountStructureBlock([healthRow(TAX_PERCENT)], locale)
      const expected = squashed(stationText(locale, 'taxRate').replace('{percent}', `${TAX_PERCENT.toLocaleString(locale)} %`))

      expect(squashed(wrapper.get(STRUCTURE_ITEM).text()), locale).toContain(expected)
      expect(squashed(wrapper.get(STRUCTURE_ITEM).text()), locale).not.toContain(stationText(locale, 'taxNotSet'))
    }
  })

  it('shows the tax not set warning with the link to the structure settings, never a 0 % rate', async () => {
    for (const locale of ['fr', 'en'] as const) {
      const wrapper = await mountStructureBlock([healthRow(null)], locale)
      const block = wrapper.get(STRUCTURE_ITEM)
      const link = block.findComponent(RouterLinkStub)

      expect(squashed(block.text()), locale).toContain(stationText(locale, 'taxNotSet'))
      expect(link.props('to'), locale).toEqual(SETTINGS_LOCATION)
      expect(squashed(link.text()), locale).toBe(stationText(locale, 'taxNotSetLink'))
      expect(squashed(block.text()), locale).not.toContain(stationText(locale, 'taxRate').replace('{percent}', ''))
      expect(squashed(block.text()), locale).not.toMatch(/(^|\D)0 %/)
    }
  })

  it('says nothing about the tax of a structure whose health could not be matched', async () => {
    const wrapper = await mountStructureBlock([])
    const text = squashed(wrapper.get(STRUCTURE_ITEM).text())

    expect(text).not.toContain(stationText('en', 'taxNotSet'))
    expect(text).not.toContain(stationText('en', 'taxRate').replace('{percent}', ''))
  })
})
