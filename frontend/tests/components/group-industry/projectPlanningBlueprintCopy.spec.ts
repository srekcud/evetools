import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectPlanning from '@/components/group-industry/ProjectPlanning.vue'
import { apiRequest } from '@/services/api'
import type { ProjectPlanning as ProjectPlanningData } from '@/stores/group-industry/types'
import {
  ARIA_SOLBERG,
  HULL_LINE_ID,
  ISHTAR_LINE_ID,
  PLANNING_PROJECT_ID,
  planningCharacter,
  planningJob,
  projectPlanning,
  RAM_LINE_ID,
} from '../../projectPlanningData'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const COPY_BUTTON = '[data-testid="planning-copy-blueprint-name"]'
const ISHTAR_BLUEPRINT = 'Ishtar Blueprint'
const FORMULA = 'Nocxium Atoms Reaction Formula'
const HULL_BLUEPRINT = 'Hull Rework Blueprint'

const ARIA_WITH_TWO_JOBS = planningCharacter({
  characterName: ARIA_SOLBERG,
  jobs: [
    planningJob({ lineId: RAM_LINE_ID, typeName: 'R.A.M.- Starship Tech', blueprintName: FORMULA, activity: 'reaction' }),
    planningJob({ lineId: ISHTAR_LINE_ID, typeName: 'Ishtar', blueprintName: ISHTAR_BLUEPRINT }),
    planningJob({ lineId: HULL_LINE_ID, typeName: 'Hull Rework', blueprintName: null }),
  ],
})

const writeText = vi.fn<(text: string) => Promise<void>>()

async function mountPlanning(planning: ProjectPlanningData): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockResolvedValue(planning)
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(ProjectPlanning, { props: { projectId: PLANNING_PROJECT_ID }, global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function assignmentRow(wrapper: VueWrapper) {
  return wrapper.get(`[data-testid="planning-assignment-${ARIA_SOLBERG}"]`)
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  writeText.mockReset()
  writeText.mockResolvedValue(undefined)
  Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
})

describe('ProjectPlanning copy of the blueprint name', () => {
  it('copies the blueprint name of an unscheduled job, not the name of its product', async () => {
    const wrapper = await mountPlanning(projectPlanning({
      characters: [ARIA_WITH_TWO_JOBS],
      unscheduled: [{ lineId: HULL_LINE_ID, typeName: 'Hull Rework', blueprintName: HULL_BLUEPRINT, reason: 'no_eligible_worker' }],
    }))

    await wrapper.get(`[data-testid="planning-unscheduled-${HULL_LINE_ID}"] ${COPY_BUTTON}`).trigger('click')

    expect(writeText.mock.calls).toEqual([[HULL_BLUEPRINT]])
  })

  it('copies the blueprint name or the formula name of the job it sits next to in the assignments', async () => {
    const wrapper = await mountPlanning(projectPlanning({ characters: [ARIA_WITH_TWO_JOBS] }))

    const buttons = assignmentRow(wrapper).findAll(COPY_BUTTON)
    await buttons[1].trigger('click')
    await buttons[0].trigger('click')

    expect(writeText.mock.calls).toEqual([[ISHTAR_BLUEPRINT], [FORMULA]])
  })

  it('offers no button for a job whose blueprint name is unknown, rather than copying the product name', async () => {
    const wrapper = await mountPlanning(projectPlanning({
      characters: [ARIA_WITH_TWO_JOBS],
      unscheduled: [{ lineId: HULL_LINE_ID, typeName: 'Hull Rework', blueprintName: null, reason: 'duration_unknown' }],
    }))

    expect(assignmentRow(wrapper).findAll(COPY_BUTTON)).toHaveLength(2)
    expect(wrapper.find(`[data-testid="planning-unscheduled-${HULL_LINE_ID}"] ${COPY_BUTTON}`).exists()).toBe(false)
  })

  it('confirms the copy on the button, and only once the clipboard accepted it', async () => {
    const wrapper = await mountPlanning(projectPlanning({ characters: [ARIA_WITH_TWO_JOBS] }))
    const button = assignmentRow(wrapper).get(COPY_BUTTON)

    expect(button.attributes('data-state')).toBe('idle')
    await button.trigger('click')
    await flushPromises()

    expect(button.attributes('data-state')).toBe('copied')
  })

  it('reports a clipboard refusal on the button instead of confirming the copy', async () => {
    writeText.mockRejectedValue(new Error('denied'))
    const wrapper = await mountPlanning(projectPlanning({ characters: [ARIA_WITH_TWO_JOBS] }))
    const button = assignmentRow(wrapper).get(COPY_BUTTON)

    await button.trigger('click')
    await flushPromises()

    expect(button.attributes('data-state')).toBe('failed')
  })

  it('leaves the gantt bars without any copy button', async () => {
    const wrapper = await mountPlanning(projectPlanning({ characters: [ARIA_WITH_TWO_JOBS] }))

    expect(wrapper.get(`[data-testid="planning-lane-${ARIA_SOLBERG}"]`).findAll(COPY_BUTTON)).toHaveLength(0)
  })
})
