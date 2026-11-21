import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import en from '@/i18n/locales/en.json'
import { apiRequest } from '@/services/api'
import { TEST_LOCALES } from '../../localeTexts'
import {
  callsTo,
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  NEXT_ACTION_BUTTON,
  NEXT_ACTION_TITLE,
  nyxProject,
  PROJECT_PATH,
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

const SETTLE_TRANSFERS = nextAction({ code: 'settle_transfers', tab: 'finances', quantity: 2 })
const COPY_RECAP = nextAction({ code: 'copy_recap', tab: 'finances' })

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('closed project', () => {
  it('titles the settle transfers and copy recap actions in every locale and opens the finances tab from their button', async () => {
    const observed: Record<string, Array<{ titled: boolean; rawKey: boolean; finances: string | undefined }>> = {}

    for (const locale of TEST_LOCALES) {
      observed[locale] = []
      for (const action of [SETTLE_TRANSFERS, COPY_RECAP]) {
        const wrapper = await mountCockpitDetail({
          project: nyxProject('completed'),
          cockpit: cockpitOf('completed', action),
        }, locale)
        const title = textOf(wrapper, NEXT_ACTION_TITLE)
        await wrapper.get(NEXT_ACTION_BUTTON).trigger('click')
        await flushPromises()
        observed[locale].push({
          titled: title !== '',
          rawKey: title.includes('groupIndustry.'),
          finances: wrapper.get(tabSelector('distribution')).attributes('aria-selected'),
        })
        wrapper.unmount()
      }
    }

    const expected = [
      { titled: true, rawKey: false, finances: 'true' },
      { titled: true, rawKey: false, finances: 'true' },
    ]
    expect(observed).toEqual(Object.fromEntries(TEST_LOCALES.map(locale => [locale, expected])))
  })

  it('names the transfers to settle with their count in the description', async () => {
    const wrapper = await mountCockpitDetail({
      project: nyxProject('completed'),
      cockpit: cockpitOf('completed', SETTLE_TRANSFERS),
    })

    expect(wrapper.get('[data-testid="next-action-card"]').text()).toContain('2')
  })

  it('opens the finances tab and loads its data right after the closing is confirmed', async () => {
    const completing = await mountCockpitDetail({
      project: nyxProject('selling'),
      cockpit: cockpitOf('selling', nextAction({ code: 'complete_project', targetStatus: 'completed' })),
    })
    const distributionCallsBefore = callsTo(`${PROJECT_PATH}/distribution`)
    const financesBefore = completing.get(tabSelector('distribution')).attributes('aria-selected')

    await completing.get(NEXT_ACTION_BUTTON).trigger('click')
    await flushPromises()
    const confirmButton = completing.findAll('button').filter(button => button.text() === en.groupIndustry.detail.completeConfirm.confirm).pop()
    if (confirmButton !== undefined) await confirmButton.trigger('click')
    await flushPromises()

    expect({
      financesBefore,
      financesAfter: completing.get(tabSelector('distribution')).attributes('aria-selected'),
      distributionLoaded: callsTo(`${PROJECT_PATH}/distribution`) > distributionCallsBefore,
    }).toEqual({ financesBefore: 'false', financesAfter: 'true', distributionLoaded: true })
  })

  it('keeps the current tab when the project moves to a status other than completed', async () => {
    const starting = await mountCockpitDetail({
      project: nyxProject('published'),
      cockpit: cockpitOf('published', nextAction({ code: 'start_project', targetStatus: 'in_progress' })),
    })

    await starting.get(NEXT_ACTION_BUTTON).trigger('click')
    await flushPromises()

    expect(starting.get(tabSelector('distribution')).attributes('aria-selected')).toBe('false')
  })
})
