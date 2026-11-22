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

const FERNITE_CARBIDE_TYPE_ID = 16_673
const TOGGLE = '[data-testid="free-slot-stock-toggle"]'
const STATIONS = '[data-testid="free-slot-stock-stations"]'

const STATION_STOCK = [
  { locationId: 60_003_760, locationName: 'Jita IV - Moon 4', systemName: 'Jita', quantity: 120 },
  { locationId: 1_035_466_617_946, locationName: 'Ishtar Keepstar', systemName: null, quantity: 45 },
]

function stockpileSuggestion(stockByStation: typeof STATION_STOCK): FreeSlotEntry {
  return {
    activityType: 'reaction',
    count: 1,
    suggestion: {
      typeId: FERNITE_CARBIDE_TYPE_ID,
      typeName: 'Fernite Carbide',
      reason: 'stockpile at 10%',
      project: null,
      runs: null,
      stockByStation,
    },
  }
}

const STEP_SUGGESTION: FreeSlotEntry = {
  activityType: 'manufacturing',
  count: 2,
  suggestion: {
    typeId: 1_207_101,
    typeName: 'Rifter Hull',
    reason: null,
    project: { id: '0194f2a0-6b1c-7d3e-8a4f-1c2b3d4e5f70', name: 'Rifter wave' },
    runs: 4,
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

describe('IndustrySlotsSection free slot stock by station', () => {
  it('folds the stock by station of a stockpile suggestion behind a toggle', async () => {
    const wrapper = await mountSlotsSection([stockpileSuggestion(STATION_STOCK)])

    expect(wrapper.find(TOGGLE).exists()).toBe(true)
    expect(wrapper.find(TOGGLE).attributes('aria-expanded')).toBe('false')
    expect(wrapper.find(STATIONS).exists()).toBe(false)
  })

  it('lists each station with its system and quantity once the toggle is opened', async () => {
    const wrapper = await mountSlotsSection([stockpileSuggestion(STATION_STOCK)])

    await wrapper.find(TOGGLE).trigger('click')

    expect(wrapper.find(TOGGLE).attributes('aria-expanded')).toBe('true')
    const text = wrapper.find(STATIONS).text()
    expect(text).toContain('Jita IV - Moon 4')
    expect(text).toContain('(Jita)')
    expect(text).toContain('120')
    expect(text).toContain('Ishtar Keepstar')
    expect(text).toContain('45')
    expect(text).not.toContain('(null)')
  })

  it('folds the list again on a second click', async () => {
    const wrapper = await mountSlotsSection([stockpileSuggestion(STATION_STOCK)])

    await wrapper.find(TOGGLE).trigger('click')
    await wrapper.find(TOGGLE).trigger('click')

    expect(wrapper.find(TOGGLE).attributes('aria-expanded')).toBe('false')
    expect(wrapper.find(STATIONS).exists()).toBe(false)
  })

  it('shows neither toggle nor list for a stockpile suggestion without stock', async () => {
    const wrapper = await mountSlotsSection([stockpileSuggestion([])])

    expect(wrapper.find(TOGGLE).exists()).toBe(false)
    expect(wrapper.find(STATIONS).exists()).toBe(false)
    expect(wrapper.text()).toContain('(stockpile at 10%)')
  })

  it('shows neither toggle nor list for a project step suggestion', async () => {
    const wrapper = await mountSlotsSection([STEP_SUGGESTION])

    expect(wrapper.find(TOGGLE).exists()).toBe(false)
    expect(wrapper.find(STATIONS).exists()).toBe(false)
  })
})
