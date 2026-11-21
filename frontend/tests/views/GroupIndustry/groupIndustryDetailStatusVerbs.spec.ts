import { enableAutoUnmount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import type { GroupProjectStatus } from '@/stores/group-industry/types'
import { TEST_LOCALES, type TestLocale } from '../../localeTexts'
import {
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  NEXT_ACTION_BUTTON,
  nyxProject,
  route,
  sentPatchBodies,
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

const HEADER = '[data-testid="project-header"]'
const PUBLISH_HELP = '[data-testid="publish-help"]'
const MODAL_TITLE = 'h2'

const VERBS: Record<TestLocale, { publish: string; start: string; sell: string; close: string }> = {
  en: { publish: 'Publish', start: 'Start the project', sell: 'Move to selling', close: 'Close the project' },
  fr: { publish: 'Publier', start: 'Démarrer le projet', sell: 'Passer à la vente', close: 'Clôturer le projet' },
}

const STATUS_LABELS: Record<TestLocale, { published: string; completed: string }> = {
  en: { published: 'Published', completed: 'Completed' },
  fr: { published: 'Publié', completed: 'Terminé' },
}

function headerButtonTexts(wrapper: VueWrapper): string[] {
  return wrapper.get(HEADER).findAll('button').map(button => button.text()).filter(text => text !== '')
}

function buttonTextsOf(wrapper: VueWrapper): string[] {
  return wrapper.findAll('button').map(button => button.text())
}

function cardButtonText(wrapper: VueWrapper): string {
  const button = wrapper.find(NEXT_ACTION_BUTTON)
  return button.exists() ? button.text() : ''
}

function headerTransitionButton(wrapper: VueWrapper, label: string) {
  return wrapper.get(HEADER).findAll('button').find(button => button.text() === label)
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('group project detail draft transitions', () => {
  it('moves a draft to in progress from the next action card only, and offers Publish with a help line on a shared project only', async () => {
    const observed: Record<string, unknown> = {}

    for (const locale of TEST_LOCALES) {
      const startCard = nextAction({ code: 'start_project', targetStatus: 'in_progress' })
      const shared = await mountCockpitDetail({
        project: nyxProject('draft', 'owner', { private: false }),
        cockpit: cockpitOf('draft', startCard),
      }, locale)
      const sharedObservation = {
        sharedHeader: headerButtonTexts(shared).filter(text => Object.values(VERBS[locale]).includes(text)),
        sharedCard: cardButtonText(shared),
        sharedStartButtons: buttonTextsOf(shared).filter(text => text === VERBS[locale].start).length,
        sharedHelp: shared.find(PUBLISH_HELP).exists(),
      }
      shared.unmount()
      setActivePinia(createPinia())
      const privateDraft = await mountCockpitDetail({
        project: nyxProject('draft', 'owner', { private: true }),
        cockpit: cockpitOf('draft', startCard),
      }, locale)
      observed[locale] = {
        ...sharedObservation,
        privateHeader: headerButtonTexts(privateDraft).filter(text => Object.values(VERBS[locale]).includes(text)),
        privateCard: cardButtonText(privateDraft),
        privateHelp: privateDraft.find(PUBLISH_HELP).exists(),
      }
      privateDraft.unmount()
      setActivePinia(createPinia())
    }

    const expectedFor = (locale: TestLocale) => ({
      sharedHeader: [VERBS[locale].publish],
      sharedCard: VERBS[locale].start,
      sharedStartButtons: 1,
      sharedHelp: true,
      privateHeader: [],
      privateCard: VERBS[locale].start,
      privateHelp: false,
    })
    expect(observed).toEqual({ fr: expectedFor('fr'), en: expectedFor('en') })
  })

  it('starts a draft from the header when the next action card is unavailable', async () => {
    const wrapper = await mountCockpitDetail({
      project: nyxProject('draft', 'owner', { private: true }),
      cockpitFailure: 'cockpit unavailable',
    })

    const start = headerTransitionButton(wrapper, VERBS.en.start)
    await start?.trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="start-confirm-button"]').trigger('click')
    await flushPromises()

    expect({ offered: start !== undefined, patches: sentPatchBodies() }).toEqual({ offered: true, patches: [{ status: 'in_progress' }] })
  })
})

describe('group project detail one verb per transition', () => {
  it('names each transition the same in the header and in the next action card', async () => {
    const observed: Record<string, unknown> = {}

    for (const locale of TEST_LOCALES) {
      const header = async (status: GroupProjectStatus) => headerTransitionButton(
        await mountCockpitDetail({
          project: nyxProject(status),
          cockpit: cockpitOf(status, nextAction({ code: 'source_material', tab: 'materials' })),
        }, locale),
        status === 'published' ? VERBS[locale].start : status === 'in_progress' ? VERBS[locale].sell : VERBS[locale].close,
      )?.text()
      const card = async (status: GroupProjectStatus, code: string, targetStatus: GroupProjectStatus) => cardButtonText(
        await mountCockpitDetail({
          project: nyxProject(status),
          cockpit: cockpitOf(status, nextAction({ code: code as never, targetStatus })),
        }, locale),
      )
      observed[locale] = {
        headerStart: await header('published'),
        headerSell: await header('in_progress'),
        headerClose: await header('selling'),
        cardStart: await card('published', 'start_project', 'in_progress'),
        cardSell: await card('in_progress', 'start_selling', 'selling'),
        cardClose: await card('selling', 'complete_project', 'completed'),
      }
    }

    const expectedFor = (locale: TestLocale) => ({
      headerStart: VERBS[locale].start,
      headerSell: VERBS[locale].sell,
      headerClose: VERBS[locale].close,
      cardStart: VERBS[locale].start,
      cardSell: VERBS[locale].sell,
      cardClose: VERBS[locale].close,
    })
    expect(observed).toEqual({ fr: expectedFor('fr'), en: expectedFor('en') })
  })

  it('hides the header button of the transition the next action card carries', async () => {
    const cases: Array<{ status: GroupProjectStatus; code: 'start_project' | 'start_selling' | 'complete_project'; target: GroupProjectStatus }> = [
      { status: 'published', code: 'start_project', target: 'in_progress' },
      { status: 'in_progress', code: 'start_selling', target: 'selling' },
      { status: 'selling', code: 'complete_project', target: 'completed' },
    ]
    const labels = [VERBS.en.start, VERBS.en.sell, VERBS.en.close]
    const observed: Array<{ header: string[]; cardButtons: number }> = []

    for (const { status, code, target } of cases) {
      const wrapper = await mountCockpitDetail({
        project: nyxProject(status),
        cockpit: cockpitOf(status, nextAction({ code, targetStatus: target })),
      })
      observed.push({
        header: headerButtonTexts(wrapper).filter(text => labels.includes(text)),
        cardButtons: buttonTextsOf(wrapper).filter(text => labels.includes(text)).length,
      })
      wrapper.unmount()
    }

    expect(observed).toEqual([
      { header: [], cardButtons: 1 },
      { header: [], cardButtons: 1 },
      { header: [], cardButtons: 1 },
    ])
  })

  it('closes the project with the same verb in the header, the card and the confirmation, then moves it to completed', async () => {
    const observed: Record<string, unknown> = {}

    for (const locale of TEST_LOCALES) {
      vi.mocked(apiRequest).mockReset()
      const wrapper = await mountCockpitDetail({
        project: nyxProject('selling'),
        cockpit: cockpitOf('selling', nextAction({ code: 'record_sale', tab: 'sales', quantity: 3 })),
      }, locale)
      await headerTransitionButton(wrapper, VERBS[locale].close)?.trigger('click')
      await flushPromises()
      const confirmation = wrapper.findAll('button').filter(button => button.text() === VERBS[locale].close)
      const modalTitle = wrapper.findAll(MODAL_TITLE).map(title => title.text()).filter(text => text === VERBS[locale].close)
      const patchesBeforeConfirmation = sentPatchBodies()
      await confirmation[confirmation.length - 1]?.trigger('click')
      await flushPromises()
      observed[locale] = { modalTitle, confirmationButtons: confirmation.length, patchesBeforeConfirmation, patches: sentPatchBodies() }
      wrapper.unmount()
    }

    const expectedFor = (locale: TestLocale) => ({
      modalTitle: [VERBS[locale].close],
      confirmationButtons: 2,
      patchesBeforeConfirmation: [],
      patches: [{ status: 'completed' }],
    })
    expect(observed).toEqual({ fr: expectedFor('fr'), en: expectedFor('en') })
  })

  it('writes the status names with their accents in French', async () => {
    const observed: Record<string, { published: string; completed: string }> = {}

    for (const locale of TEST_LOCALES) {
      const published = await mountCockpitDetail({
        project: nyxProject('published'),
        cockpit: cockpitOf('published', nextAction({ code: 'nothing_to_do' })),
      }, locale)
      const publishedBadge = published.get('[data-testid="project-header-title"]').element.parentElement?.textContent ?? ''
      published.unmount()
      setActivePinia(createPinia())
      const completed = await mountCockpitDetail({
        project: nyxProject('completed'),
        cockpit: cockpitOf('completed', nextAction({ code: 'project_completed' })),
      }, locale)
      const completedBadge = completed.get('[data-testid="project-header-title"]').element.parentElement?.textContent ?? ''
      completed.unmount()
      setActivePinia(createPinia())
      observed[locale] = {
        published: publishedBadge.replace('Nyx batch', ''),
        completed: completedBadge.replace('Nyx batch', ''),
      }
    }

    expect(observed).toEqual(STATUS_LABELS)
  })
})
