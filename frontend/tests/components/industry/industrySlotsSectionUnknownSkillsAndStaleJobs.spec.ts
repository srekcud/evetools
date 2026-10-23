import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import IndustrySlotsSection from '@/components/industry/IndustrySlotsSection.vue'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })

const aurora = {
  characterId: 3001, characterName: 'Aurora', isMain: true,
  slots: {
    manufacturing: { used: 1, max: null },
    reaction: { used: 0, max: null },
    science: { used: 0, max: null },
  },
  jobs: [], freeSlots: [], skillsUnknown: true, jobsStale: false,
}

const borealis = {
  characterId: 3002, characterName: 'Borealis', isMain: false,
  slots: {
    manufacturing: { used: 3, max: 7 },
    reaction: { used: 0, max: 1 },
    science: { used: 0, max: 4 },
  },
  jobs: [], freeSlots: [], skillsUnknown: false, jobsStale: true,
}

function slotTrackerResponse(unknownSkillsCharacterCount: number) {
  return {
    globalKpis: {
      manufacturing: { used: 3, max: 7, percent: 42.9 },
      reaction: { used: 0, max: 1, percent: 0.0 },
      science: { used: 0, max: 4, percent: 0.0 },
    },
    characters: unknownSkillsCharacterCount > 0 ? [aurora, borealis] : [borealis],
    timeline: [],
    skillsMayBeStale: unknownSkillsCharacterCount > 0,
    unknownSkillsCharacterCount,
  }
}

async function mountSlotsSection(unknownSkillsCharacterCount: number) {
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === '/industry/slots') return Promise.resolve(slotTrackerResponse(unknownSkillsCharacterCount))
    return new Promise(() => {})
  })
  setActivePinia(createPinia())
  const wrapper = mount(IndustrySlotsSection, { global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function characterCard(wrapper: Awaited<ReturnType<typeof mountSlotsSection>>, characterName: string) {
  const card = wrapper.findAll('div.eve-card').find(div => div.find('h2').exists() && div.get('h2').text() === characterName)
  if (!card) throw new Error(`No card for ${characterName}`)
  return card
}

function characterHeader(wrapper: Awaited<ReturnType<typeof mountSlotsSection>>, characterName: string) {
  return characterCard(wrapper, characterName).get('h2').element.parentElement as HTMLElement
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('IndustrySlotsSection with unknown skills and stale jobs', () => {
  it('shows each unknown max slot of a character as — with the unknown-value tooltip instead of a number', async () => {
    const wrapper = await mountSlotsSection(1)

    const header = characterHeader(wrapper, 'Aurora')
    const unknownMaxSlots = Array.from(header.querySelectorAll('[title]'))
    expect(unknownMaxSlots.map(element => [element.getAttribute('title'), element.textContent?.trim()])).toEqual([
      ['Unknown value', '—'],
      ['Unknown value', '—'],
      ['Unknown value', '—'],
    ])
    expect(header.textContent).toContain('1/—')
    expect(header.textContent).not.toMatch(/\d+\/\d+/)
  })

  it('keeps the known max slots of a character with known skills as numbers', async () => {
    const wrapper = await mountSlotsSection(1)

    const header = characterHeader(wrapper, 'Borealis')
    expect(header.textContent).toMatch(/3\/7\s/)
    expect(header.textContent).toMatch(/0\/1\s/)
    expect(header.textContent).toMatch(/0\/4\s/)
    expect(header.querySelectorAll('[title]')).toHaveLength(0)
  })

  it('shows the partial mention on the global KPIs with the count of characters with unknown skills', async () => {
    const wrapper = await mountSlotsSection(1)

    expect(wrapper.text()).toContain(i18n.global.t('industry.slots.partialKpis', { count: 1 }))
  })

  it('shows no partial mention when every character has known skills', async () => {
    const wrapper = await mountSlotsSection(0)

    expect(wrapper.text()).not.toContain(i18n.global.t('industry.slots.partialKpis', { count: 0 }))
  })

  it('shows a stale-data badge on the card of a character whose jobs are stale only', async () => {
    const wrapper = await mountSlotsSection(1)

    expect(characterCard(wrapper, 'Borealis').text()).toContain(en.industry.slots.jobsStale)
    expect(characterCard(wrapper, 'Aurora').text()).not.toContain(en.industry.slots.jobsStale)
  })
})
