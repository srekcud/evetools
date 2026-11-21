import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ProductionChain from '@/components/group-industry/ProductionChain.vue'
import { apiRequest } from '@/services/api'
import { TEST_LOCALES } from '../../localeTexts'
import {
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  NEXT_ACTION_BUTTON,
  NEXT_ACTION_CARD,
  NEXT_ACTION_TITLE,
  nyxProject,
  route,
  tabSelector,
  textOf,
} from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const JOBS_SECTION = '[data-testid="project-jobs-section"]'
const ENDS_AT = '2026-11-23T10:30:00+00:00'

const AWAIT_JOBS = nextAction({ code: 'await_jobs', tab: 'materials', endsAt: ENDS_AT })
const AWAIT_JOBS_UNKNOWN_END = nextAction({ code: 'await_jobs', tab: 'materials', endsAt: null })

function formattedEnd(locale: string): string {
  return new Date(ENDS_AT).toLocaleString(locale, { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

const scrollIntoView = vi.fn()

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
  scrollIntoView.mockReset()
  Element.prototype.scrollIntoView = scrollIntoView
})

describe('project cockpit await jobs action', () => {
  it('tells the jobs are running until the latest end date instead of asking to launch runs, in both languages', async () => {
    const observed: Record<string, { title: string; rawKeys: boolean; asksToLaunch: boolean; showsEnd: boolean; button: boolean }> = {}

    for (const locale of TEST_LOCALES) {
      const wrapper = await mountCockpitDetail({
        project: nyxProject('in_progress'),
        cockpit: cockpitOf('in_progress', AWAIT_JOBS),
      }, locale)
      const card = textOf(wrapper, NEXT_ACTION_CARD)
      observed[locale] = {
        title: textOf(wrapper, NEXT_ACTION_TITLE),
        rawKeys: card.includes('groupIndustry.'),
        asksToLaunch: /\b0\b/.test(card),
        showsEnd: card.includes(formattedEnd('fr-FR')) || card.includes(formattedEnd('en-US')),
        button: wrapper.find(NEXT_ACTION_BUTTON).exists(),
      }
      wrapper.unmount()
    }

    expect(observed.fr.title).not.toBe(observed.en.title)
    expect(observed).toEqual({
      fr: { title: observed.fr.title, rawKeys: false, asksToLaunch: false, showsEnd: true, button: true },
      en: { title: observed.en.title, rawKeys: false, asksToLaunch: false, showsEnd: true, button: true },
    })
  })

  it('says the end date is unknown instead of showing a date when the API gives none', async () => {
    const known = await mountCockpitDetail({
      project: nyxProject('in_progress'),
      cockpit: cockpitOf('in_progress', AWAIT_JOBS),
    })
    const unknown = await mountCockpitDetail({
      project: nyxProject('in_progress'),
      cockpit: cockpitOf('in_progress', AWAIT_JOBS_UNKNOWN_END),
    })
    const knownText = textOf(known, NEXT_ACTION_CARD)
    const unknownText = textOf(unknown, NEXT_ACTION_CARD)

    expect(unknownText).not.toBe(knownText)
    expect(unknownText).not.toContain('Invalid')
    expect(unknownText).not.toContain('groupIndustry.')
    expect(unknownText.includes(formattedEnd('fr-FR')) || unknownText.includes(formattedEnd('en-US'))).toBe(false)
  })

  it('opens the production tab and scrolls to the jobs section when the button is used', async () => {
    const wrapper = await mountCockpitDetail({
      project: nyxProject('in_progress'),
      cockpit: cockpitOf('in_progress', AWAIT_JOBS),
    })

    await wrapper.get(NEXT_ACTION_BUTTON).trigger('click')
    await flushPromises()

    const section = wrapper.find(JOBS_SECTION)
    expect({
      chainTabSelected: wrapper.get(tabSelector('chain')).attributes('aria-selected'),
      scrolled: scrollIntoView.mock.contexts as Element[],
      containsProductionChain: section.exists() && wrapper.findComponent(ProductionChain).exists(),
    }).toEqual({ chainTabSelected: 'true', scrolled: section.exists() ? [section.element] : [], containsProductionChain: true })
  })
})
