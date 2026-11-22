import { enableAutoUnmount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { expectOnlyLocaleTexts, messageOf, TEST_LOCALES, type TestLocale } from '../../localeTexts'
import {
  bomLine,
  cockpitOf,
  mountCockpitDetail,
  nextAction,
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

const CONFIRMATION = '[data-testid="selling-confirm"]'
const UNCOVERED = '[data-testid="selling-confirm-uncovered"]'
const UNDELIVERED = '[data-testid="selling-confirm-undelivered"]'
const CONFIRM_BUTTON = '[data-testid="selling-confirm-button"]'
const CANCEL_BUTTON = '[data-testid="selling-confirm-cancel"]'
const HEADER = '[data-testid="project-header"]'

const OPEN_LINES = [
  bomLine({ id: 'tritanium', typeId: 34, typeName: 'Tritanium', remainingQuantity: 400 }),
  bomLine({ id: 'pyerite', typeId: 35, typeName: 'Pyerite', remainingQuantity: 0, isFulfilled: true }),
  bomLine({ id: 'isogen', typeId: 37, typeName: 'Isogen', remainingQuantity: 75 }),
  bomLine({ id: 'hull', typeId: 23_913, typeName: 'Nyx hull', isJob: true, remainingQuantity: 2 }),
  bomLine({ id: 'plating', typeId: 11_000, typeName: 'Armor plating', isJob: true, remainingQuantity: 0, isFulfilled: true }),
]

const COVERED_LINES = [
  bomLine({ id: 'pyerite', typeId: 35, typeName: 'Pyerite', remainingQuantity: 0, isFulfilled: true }),
  bomLine({ id: 'plating', typeId: 11_000, typeName: 'Armor plating', isJob: true, remainingQuantity: 0, isFulfilled: true }),
]

function sellButtonOf(wrapper: VueWrapper, locale: TestLocale) {
  return wrapper.get(HEADER).findAll('button').find(button => button.text() === messageOf(locale, 'groupIndustry.detail.statusActions.selling'))
}

async function mountInProgress(lines = OPEN_LINES, locale: TestLocale = 'en', cardCode: 'source_material' | 'start_selling' = 'source_material') {
  return mountCockpitDetail({
    project: nyxProject('in_progress'),
    bom: lines,
    cockpit: cockpitOf('in_progress', cardCode === 'start_selling'
      ? nextAction({ code: 'start_selling', targetStatus: 'selling' })
      : nextAction({ code: 'source_material', tab: 'materials' })),
  }, locale)
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('group project detail move to selling confirmation', () => {
  it('asks before moving to selling, lists the open lines, says it cannot be undone, in both languages', async () => {
    const observed: Record<string, unknown> = {}

    for (const locale of TEST_LOCALES) {
      const wrapper = await mountInProgress(OPEN_LINES, locale)
      await sellButtonOf(wrapper, locale)?.trigger('click')
      await flushPromises()
      observed[locale] = {
        patches: sentPatchBodies(),
        asked: wrapper.find(CONFIRMATION).exists(),
        uncovered: textOf(wrapper, UNCOVERED),
        undelivered: textOf(wrapper, UNDELIVERED),
        message: textOf(wrapper, CONFIRMATION),
      }
      expectOnlyLocaleTexts(textOf(wrapper, CONFIRMATION), locale, ['groupIndustry.detail.sellingConfirm.message'])
      wrapper.unmount()
      setActivePinia(createPinia())
    }

    const open = observed as Record<TestLocale, { patches: unknown[]; asked: boolean; uncovered: string; undelivered: string }>
    for (const locale of TEST_LOCALES) {
      expect({
        patches: open[locale].patches,
        asked: open[locale].asked,
        uncoveredNames: ['Tritanium', 'Isogen', 'Pyerite'].map(name => open[locale].uncovered.includes(name)),
        uncoveredQuantities: ['400', '75'].map(quantity => open[locale].uncovered.includes(quantity)),
        undeliveredNames: ['Nyx hull', 'Armor plating'].map(name => open[locale].undelivered.includes(name)),
      }, locale).toEqual({
        patches: [],
        asked: true,
        uncoveredNames: [true, true, false],
        uncoveredQuantities: [true, true],
        undeliveredNames: [true, false],
      })
    }
  })

  it('asks the same confirmation from the header button when the cockpit points to the move to selling', async () => {
    const wrapper = await mountInProgress(OPEN_LINES, 'en', 'start_selling')

    expect(sellButtonOf(wrapper, 'en')).toBeDefined()
    await sellButtonOf(wrapper, 'en')?.trigger('click')
    await flushPromises()

    expect({
      patches: sentPatchBodies(),
      asked: wrapper.find(CONFIRMATION).exists(),
      uncovered: textOf(wrapper, UNCOVERED).includes('Tritanium'),
      undelivered: textOf(wrapper, UNDELIVERED).includes('Nyx hull'),
    }).toEqual({ patches: [], asked: true, uncovered: true, undelivered: true })
  })

  it('moves the project to selling only once the confirmation is accepted', async () => {
    const wrapper = await mountInProgress()

    await sellButtonOf(wrapper, 'en')?.trigger('click')
    await flushPromises()
    await wrapper.get(CONFIRM_BUTTON).trigger('click')
    await flushPromises()

    expect({ patches: sentPatchBodies(), asked: wrapper.find(CONFIRMATION).exists() }).toEqual({
      patches: [{ status: 'selling' }],
      asked: false,
    })
  })

  it('keeps the project in progress when the confirmation is cancelled', async () => {
    const wrapper = await mountInProgress()

    await sellButtonOf(wrapper, 'en')?.trigger('click')
    await flushPromises()
    await wrapper.get(CANCEL_BUTTON).trigger('click')
    await flushPromises()

    expect({ patches: sentPatchBodies(), asked: wrapper.find(CONFIRMATION).exists() }).toEqual({ patches: [], asked: false })
  })

  it('still asks, without any list, when every line is covered and delivered', async () => {
    const wrapper = await mountInProgress(COVERED_LINES)

    await sellButtonOf(wrapper, 'en')?.trigger('click')
    await flushPromises()

    expect({
      patches: sentPatchBodies(),
      asked: wrapper.find(CONFIRMATION).exists(),
      uncovered: wrapper.find(UNCOVERED).exists(),
      undelivered: wrapper.find(UNDELIVERED).exists(),
      cannotBeUndone: textOf(wrapper, CONFIRMATION).includes(messageOf('en', 'groupIndustry.detail.sellingConfirm.message')),
    }).toEqual({ patches: [], asked: true, uncovered: false, undelivered: false, cannotBeUndone: true })
  })

  it('keeps starting a published project without a confirmation', async () => {
    const wrapper = await mountCockpitDetail({
      project: nyxProject('published'),
      bom: OPEN_LINES,
      cockpit: cockpitOf('published', nextAction({ code: 'start_project', targetStatus: 'in_progress' })),
    })

    const startButton = wrapper.get(HEADER).findAll('button').find(button => button.text() === messageOf('en', 'groupIndustry.detail.statusActions.in_progress'))
    expect(startButton).toBeDefined()
    await startButton?.trigger('click')
    await flushPromises()

    expect({ patches: sentPatchBodies(), asked: wrapper.find(CONFIRMATION).exists() }).toEqual({
      patches: [{ status: 'in_progress' }],
      asked: false,
    })
  })
})
