import { enableAutoUnmount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import type { GroupProject, GroupProjectStatus } from '@/stores/group-industry/types'
import { expectOnlyLocaleTexts, messageOf, TEST_LOCALES, type TestLocale } from '../../localeTexts'
import {
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  NEXT_ACTION_BUTTON,
  NEXT_ACTION_CARD,
  nyxProject,
  route,
  sentPatchBodies,
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

const CONFIRMATION = '[data-testid="start-confirm"]'
const LOCKS = '[data-testid="start-confirm-locks"]'
const CONFIRM_BUTTON = '[data-testid="start-confirm-button"]'
const CANCEL_BUTTON = '[data-testid="start-confirm-cancel"]'
const HEADER = '[data-testid="project-header"]'

const START_ACTION = nextAction({ code: 'start_project', targetStatus: 'in_progress' })

function mountStartable(status: GroupProjectStatus, overrides: Partial<GroupProject> = {}, locale: TestLocale = 'en'): Promise<VueWrapper> {
  return mountCockpitDetail({
    project: nyxProject(status, 'owner', overrides),
    cockpit: cockpitOf(status, START_ACTION),
  }, locale)
}

function mountStartableWithoutCockpit(overrides: Partial<GroupProject> = {}): Promise<VueWrapper> {
  return mountCockpitDetail({
    project: nyxProject('draft', 'owner', overrides),
    cockpitFailure: 'cockpit down',
  })
}

function startButtonOfHeader(wrapper: VueWrapper) {
  return wrapper.get(HEADER).findAll('button').find(button => button.text() === messageOf('en', 'groupIndustry.detail.statusActions.in_progress'))
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('group project detail start confirmation', () => {
  it('asks before starting a draft from the next action card and lists the blacklist and the invention it locks, in both languages', async () => {
    for (const locale of TEST_LOCALES) {
      const wrapper = await mountStartable('draft', { blacklistGroupIds: [25, 26, 27], blacklistTypeIds: [11_399], excludesInvention: true }, locale)

      await wrapper.get(NEXT_ACTION_BUTTON).trigger('click')
      await flushPromises()

      expect({
        patches: sentPatchBodies(),
        asked: wrapper.find(CONFIRMATION).exists(),
        blacklist: textOf(wrapper, LOCKS).includes('3') && textOf(wrapper, LOCKS).includes('1'),
      }, locale).toEqual({ patches: [], asked: true, blacklist: true })
      expectOnlyLocaleTexts(textOf(wrapper, CONFIRMATION), locale, [
        'groupIndustry.detail.startConfirm.message',
        'groupIndustry.detail.startConfirm.inventionExcluded',
      ])
      wrapper.unmount()
      setActivePinia(createPinia())
    }
  })

  it('says nothing is excluded and the invention is included when the project has neither', async () => {
    const wrapper = await mountStartable('draft')

    await wrapper.get(NEXT_ACTION_BUTTON).trigger('click')
    await flushPromises()

    expect({ patches: sentPatchBodies(), asked: wrapper.find(CONFIRMATION).exists() }).toEqual({ patches: [], asked: true })
    expect(textOf(wrapper, LOCKS)).toContain(messageOf('en', 'groupIndustry.detail.startConfirm.inventionIncluded'))
    expectOnlyLocaleTexts(textOf(wrapper, LOCKS), 'en', ['groupIndustry.detail.startConfirm.blacklistNone'])
  })

  it('asks the same confirmation from the header button when the cockpit is unavailable', async () => {
    const wrapper = await mountStartableWithoutCockpit({ excludesInvention: true })

    await startButtonOfHeader(wrapper)?.trigger('click')
    await flushPromises()

    expect({ patches: sentPatchBodies(), asked: wrapper.find(CONFIRMATION).exists() }).toEqual({ patches: [], asked: true })
    expect(textOf(wrapper, LOCKS)).toContain(messageOf('en', 'groupIndustry.detail.startConfirm.inventionExcluded'))
  })

  it('starts the project only once the confirmation is accepted', async () => {
    const wrapper = await mountStartable('draft')

    await wrapper.get(NEXT_ACTION_BUTTON).trigger('click')
    await flushPromises()
    await wrapper.get(CONFIRM_BUTTON).trigger('click')
    await flushPromises()

    expect({ patches: sentPatchBodies(), asked: wrapper.find(CONFIRMATION).exists() }).toEqual({
      patches: [{ status: 'in_progress' }],
      asked: false,
    })
  })

  it('keeps the project in draft when the confirmation is cancelled', async () => {
    const wrapper = await mountStartable('draft')

    await wrapper.get(NEXT_ACTION_BUTTON).trigger('click')
    await flushPromises()
    await wrapper.get(CANCEL_BUTTON).trigger('click')
    await flushPromises()

    expect({ patches: sentPatchBodies(), asked: wrapper.find(CONFIRMATION).exists() }).toEqual({ patches: [], asked: false })
  })

  it('names the Prepare block and what starting locks in the card description of a draft, in both languages', async () => {
    for (const locale of TEST_LOCALES) {
      const wrapper = await mountStartable('draft', {}, locale)

      expectOnlyLocaleTexts(textOf(wrapper, NEXT_ACTION_CARD), locale, ['groupIndustry.cockpit.nextAction.start_project.descriptionLocks'])
      wrapper.unmount()
      setActivePinia(createPinia())
    }
  })

  it('keeps the plain card description and starts at once when the project is already published', async () => {
    const wrapper = await mountStartable('published')

    expectOnlyLocaleTexts(textOf(wrapper, NEXT_ACTION_CARD), 'en', ['groupIndustry.cockpit.nextAction.start_project.description'])

    await wrapper.get(NEXT_ACTION_BUTTON).trigger('click')
    await flushPromises()

    expect({ patches: sentPatchBodies(), asked: wrapper.find(CONFIRMATION).exists() }).toEqual({
      patches: [{ status: 'in_progress' }],
      asked: false,
    })
    expect(textOf(wrapper, NEXT_ACTION_CARD)).not.toContain('lock')
  })
})
