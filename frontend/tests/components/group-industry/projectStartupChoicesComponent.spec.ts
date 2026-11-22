import { enableAutoUnmount, flushPromises, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import ProjectStartupChoices from '@/components/group-industry/ProjectStartupChoices.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { apiRequest } from '@/services/api'
import type { ProjectWorkStations, SourcingDecisions } from '@/stores/group-industry/types'
import {
  COMPONENT_ROW,
  componentsType,
  projectWorkStations,
  REACTION_ALTERNATIVE_ID,
  REACTION_RECOMMENDED_ID,
  REACTION_ROW,
  reactionType,
  requestsOf,
  sourcingDecisions,
} from '../../projectSetupFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-nyx-batch'

const OFF_RECOMMENDED_STATIONS = projectWorkStations([
  reactionType({ chosenStructureId: REACTION_ALTERNATIVE_ID, storedRecommendedStructureId: REACTION_RECOMMENDED_ID }),
  componentsType(),
])

const OFF_RECOMMENDED_SOURCING = sourcingDecisions(1000, [{ ...COMPONENT_ROW, chosen: 'jita', recommended: 'build' }, REACTION_ROW])

function answer(workStations: ProjectWorkStations | Error, decisions: SourcingDecisions | Error = OFF_RECOMMENDED_SOURCING): void {
  vi.mocked(apiRequest).mockImplementation((async (path: string) => {
    const answerOf = path.endsWith('/work-stations') ? workStations : decisions
    if (answerOf instanceof Error) throw answerOf
    return answerOf
  }) as never)
}

async function mountChoices(props: { status?: string; canEdit?: boolean } = {}): Promise<VueWrapper> {
  const wrapper = mount(ProjectStartupChoices, {
    props: { projectId: PROJECT_ID, status: props.status ?? 'in_progress', canEdit: props.canEdit ?? true },
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
      stubs: { RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  return wrapper
}

function textOf(wrapper: VueWrapper, testId: string): string | null {
  const element = wrapper.find(`[data-testid="${testId}"]`)
  return element.exists() ? element.text() : null
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ProjectStartupChoices', () => {
  it('reads the stations and the sourcing decisions of the project', async () => {
    answer(OFF_RECOMMENDED_STATIONS)

    await mountChoices()

    expect(requestsOf(vi.mocked(apiRequest)).map(request => request.path).sort()).toEqual([
      `/industry/projects/${PROJECT_ID}/sourcing-decisions`,
      `/industry/projects/${PROJECT_ID}/work-stations`,
    ])
  })

  it('shows each station choice, the recommended one and the gap next to a choice that differs only', async () => {
    answer(OFF_RECOMMENDED_STATIONS)

    const wrapper = await mountChoices()

    expect({
      reactionStation: textOf(wrapper, 'startup-station-reaction'),
      reactionRecommended: textOf(wrapper, 'startup-station-recommended-reaction'),
      reactionGap: textOf(wrapper, 'startup-station-gap-reaction')?.includes('200'),
      componentsStation: textOf(wrapper, 'startup-station-components'),
      componentsRecommended: textOf(wrapper, 'startup-station-recommended-components'),
    }).toEqual({
      reactionStation: expect.stringContaining('Athanor Kaimon'),
      reactionRecommended: expect.stringContaining('Tatara Isanamo Reactions'),
      reactionGap: true,
      componentsStation: expect.stringContaining('Raitaru Jita Yard'),
      componentsRecommended: null,
    })
  })

  it('summarises the buy or build choices with the number off the recommended and their gap', async () => {
    answer(OFF_RECOMMENDED_STATIONS)

    const wrapper = await mountChoices()

    expect({
      offRecommended: textOf(wrapper, 'startup-sourcing-off')?.includes('1'),
      gap: textOf(wrapper, 'startup-sourcing-off')?.includes('300'),
      allOnRecommended: textOf(wrapper, 'startup-sourcing-on-recommended'),
    }).toEqual({ offRecommended: true, gap: true, allOnRecommended: null })
  })

  it('says every buy or build choice follows the recommended when none differs', async () => {
    answer(OFF_RECOMMENDED_STATIONS, sourcingDecisions(1000, [COMPONENT_ROW, REACTION_ROW]))

    const wrapper = await mountChoices()

    expect({ off: textOf(wrapper, 'startup-sourcing-off'), onRecommended: textOf(wrapper, 'startup-sourcing-on-recommended') !== null }).toEqual({
      off: null,
      onRecommended: true,
    })
  })

  it('links Edit stations to the stations only setup while nothing is locked', async () => {
    answer(OFF_RECOMMENDED_STATIONS)

    const wrapper = await mountChoices()

    expect(wrapper.findComponent(RouterLinkStub).props('to')).toEqual({
      name: 'industry-project-setup',
      params: { id: PROJECT_ID },
      query: { edit: 'stations' },
    })
  })

  it('hides Edit stations once a type of work is locked, for a reader who cannot edit, and when the plan has no station', async () => {
    const locked = projectWorkStations([reactionType({ locked: true, lockReason: 'job_launched' }), componentsType()])
    answer(locked)
    const lockedWrapper = await mountChoices()
    answer(OFF_RECOMMENDED_STATIONS)
    const readerWrapper = await mountChoices({ canEdit: false })
    answer(projectWorkStations([]))
    const emptyWrapper = await mountChoices()

    expect([lockedWrapper, readerWrapper, emptyWrapper].map(wrapper => wrapper.findComponent(RouterLinkStub).exists())).toEqual([false, false, false])
  })

  it('shows the error of a failed read and no invented station line', async () => {
    answer(new Error('The plan of the project cannot be computed'))

    const wrapper = await mountChoices()

    expect({
      error: textOf(wrapper, 'startup-choices-error'),
      stationLines: wrapper.findAll('[data-testid^="startup-station-"]').length,
      edit: wrapper.findComponent(RouterLinkStub).exists(),
    }).toEqual({ error: expect.stringContaining('The plan of the project cannot be computed'), stationLines: 0, edit: false })
  })
})
