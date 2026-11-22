import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import IndustrySlotsSection from '@/components/industry/IndustrySlotsSection.vue'
import { apiRequest } from '@/services/api'
import type { FreeSlotEntry, SlotTrackerData } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const EmptyView = { render: () => null }
const SUGGESTION_LINK = '[data-testid="free-slot-suggestion-link"]'

const RIFTER_WAVE_PROJECT_ID = '0194f2a0-6b1c-7d3e-8a4f-1c2b3d4e5f70'
const RIFTER_WAVE_PROJECT_NAME = 'Rifter wave of the corporation hangar restock'
const RIFTER_HULL_TYPE_ID = 1_207_101
const FERNITE_CARBIDE_TYPE_ID = 16_673

const STEP_SUGGESTION: FreeSlotEntry = {
  activityType: 'manufacturing',
  count: 2,
  suggestion: {
    typeId: RIFTER_HULL_TYPE_ID,
    typeName: 'Rifter Hull',
    reason: null,
    project: { id: RIFTER_WAVE_PROJECT_ID, name: RIFTER_WAVE_PROJECT_NAME },
    runs: 4,
  },
}

const STOCKPILE_SUGGESTION: FreeSlotEntry = {
  activityType: 'reaction',
  count: 1,
  suggestion: {
    typeId: FERNITE_CARBIDE_TYPE_ID,
    typeName: 'Fernite Carbide',
    reason: 'stockpile at 10%',
    project: null,
    runs: null,
  },
}

function slotsWith(freeSlots: FreeSlotEntry[]): SlotTrackerData {
  return {
    jobsSyncedAt: null,
    jobsSyncIntervalSeconds: 1800,
    globalKpis: {
      manufacturing: { used: 8, max: 10, percent: 80 },
      reaction: { used: 4, max: 5, percent: 80 },
      science: { used: 0, max: 8, percent: 0 },
    },
    characters: [{
      characterId: 2_150_001,
      characterName: 'Ilyra Castell',
      isMain: true,
      slots: {
        manufacturing: { used: 8, max: 10 },
        reaction: { used: 4, max: 5 },
        science: { used: 0, max: 8 },
      },
      skillsUnknown: false,
      jobsStale: false,
      jobs: [],
      freeSlots,
    }],
    timeline: [],
    skillsMayBeStale: false,
    unknownSkillsCharacterCount: 0,
  }
}

async function mountSlotsSection(freeSlots: FreeSlotEntry[]): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === '/industry/slots') return Promise.resolve(slotsWith(freeSlots))
    return new Promise(() => {})
  })
  setActivePinia(createPinia())
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/industry/projects/:id', name: 'industry-project', component: EmptyView }],
  })
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(IndustrySlotsSection, { global: { plugins: [i18n, router] } })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('IndustrySlotsSection free slot suggestion', () => {
  it('links the suggested step to the production tab of its project', async () => {
    const wrapper = await mountSlotsSection([STEP_SUGGESTION])

    const links = wrapper.findAll(SUGGESTION_LINK).map(link => ({ href: link.attributes('href'), text: link.text() }))
    expect(links).toEqual([{ href: `/industry/projects/${RIFTER_WAVE_PROJECT_ID}?tab=chain`, text: 'Rifter Hull' }])
  })

  it('shows the runs and the project name of the suggested step and no reason', async () => {
    const wrapper = await mountSlotsSection([STEP_SUGGESTION])

    const footerText = wrapper.text()
    expect(footerText).toContain(`4 runs · ${RIFTER_WAVE_PROJECT_NAME}`)
    expect(footerText).not.toContain('(null)')
  })

  it('shows a stockpile suggestion as plain text with its reason and no link style', async () => {
    const wrapper = await mountSlotsSection([STOCKPILE_SUGGESTION])

    const nameElement = wrapper.findAll('span').find(span => span.text() === 'Fernite Carbide')
    expect(nameElement).toBeDefined()
    expect(wrapper.findAll(SUGGESTION_LINK)).toHaveLength(0)
    expect(nameElement?.classes() ?? []).not.toContain('cursor-pointer')
    expect(nameElement?.classes() ?? []).not.toContain('hover:underline')
    expect(wrapper.text()).toContain('(stockpile at 10%)')
  })
})
