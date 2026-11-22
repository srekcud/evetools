import { enableAutoUnmount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import en from '@/i18n/locales/en.json'
import { apiRequest } from '@/services/api'
import {
  callsTo,
  cockpitOf,
  isSectionOpen,
  mountCockpitDetail,
  nextAction,
  nyxProject,
  PROJECT_PATH,
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

function headerButton(wrapper: VueWrapper, label: string) {
  return wrapper.get('[data-testid="project-header"]').findAll('button').find(button => button.text() === label)
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('closed project', () => {
  it('opens the finances section and loads its data right after the closing is confirmed from the header', async () => {
    const completing = await mountCockpitDetail({
      project: nyxProject('selling'),
      cockpit: cockpitOf('selling', nextAction({ code: 'complete_project', targetStatus: 'completed' })),
    })
    const distributionCallsBefore = callsTo(`${PROJECT_PATH}/distribution`)
    const financesBefore = isSectionOpen(completing, 'distribution')

    const closeButton = headerButton(completing, en.groupIndustry.detail.statusActions.completed)
    expect(closeButton).toBeDefined()
    await closeButton?.trigger('click')
    await flushPromises()
    const confirmButton = completing.findAll('button').filter(button => button.text() === en.groupIndustry.detail.completeConfirm.confirm).pop()
    if (confirmButton !== undefined) await confirmButton.trigger('click')
    await flushPromises()

    expect({
      financesBefore,
      patches: sentPatchBodies(),
      financesAfter: isSectionOpen(completing, 'distribution'),
      distributionLoaded: callsTo(`${PROJECT_PATH}/distribution`) > distributionCallsBefore,
    }).toEqual({ financesBefore: false, patches: [{ status: 'completed' }], financesAfter: true, distributionLoaded: true })
  })

  it('keeps the finances section closed when the project moves to a status other than completed', async () => {
    const starting = await mountCockpitDetail({
      project: nyxProject('published'),
      cockpit: cockpitOf('published', nextAction({ code: 'start_project', targetStatus: 'in_progress' })),
    })

    const startButton = headerButton(starting, en.groupIndustry.detail.statusActions.in_progress)
    expect(startButton).toBeDefined()
    await startButton?.trigger('click')
    await flushPromises()

    expect(sentPatchBodies()).toEqual([{ status: 'in_progress' }])
    expect(isSectionOpen(starting, 'distribution')).toBe(false)
  })
})
