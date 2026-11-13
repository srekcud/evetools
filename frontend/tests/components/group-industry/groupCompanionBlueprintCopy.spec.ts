import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import GroupCompanion from '@/components/group-industry/GroupCompanion.vue'
import type { CompanionTasks } from '@/components/group-industry/companionTasks'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { bomLine, companionTasksOf, jobMatch } from './companionFixtures'

const COPY_BUTTON = '[data-testid="planning-copy-blueprint-name"]'
const FREE_LINE = '[data-testid="companion-free-line"]'
const MINE_LINE = '[data-testid="companion-mine-line"]'
const JOB_LINE = '[data-testid="companion-job"]'
const FEROX_BLUEPRINT = 'Ferox Blueprint'
const NOCXIUM_FORMULA = 'Nocxium Atoms Reaction Formula'
const ISHTAR_BLUEPRINT = 'Ishtar Blueprint'

const writeText = vi.fn<(text: string) => Promise<void>>()

function mountCompanion(tasks: CompanionTasks): VueWrapper {
  return mount(GroupCompanion, {
    props: { tasks, projectName: 'Ferox batch', size: 'column', errorMessage: null },
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
}

const ferox = bomLine({
  id: 'bom-ferox',
  typeId: 16_227,
  typeName: 'Ferox',
  isJob: true,
  activityType: 'manufacturing',
  runs: 4,
  freeQuantity: 4,
  blueprintName: FEROX_BLUEPRINT,
})
const nocxiumAtoms = bomLine({
  id: 'bom-nocxium-atoms',
  typeId: 16_637,
  typeName: 'Nocxium Atoms',
  isJob: true,
  activityType: 'reaction',
  runs: 3,
  freeQuantity: 3,
  blueprintName: NOCXIUM_FORMULA,
})
const tritanium = bomLine({ id: 'bom-tritanium', typeId: 34, typeName: 'Tritanium', freeQuantity: 1_000, blueprintName: null })
const mysteryHull = bomLine({
  id: 'bom-mystery',
  typeId: 99_002,
  typeName: 'Mystery Hull',
  isJob: true,
  activityType: 'manufacturing',
  runs: 1,
  freeQuantity: 1,
  blueprintName: null,
})

enableAutoUnmount(afterEach)

beforeEach(() => {
  writeText.mockReset()
  writeText.mockResolvedValue(undefined)
  Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
})

describe('GroupCompanion copy of the blueprint name', () => {
  it('copies the blueprint or formula name, not the product name, from a line to assign', async () => {
    const wrapper = mountCompanion(companionTasksOf({ free: [ferox, nocxiumAtoms] }))

    for (const line of wrapper.findAll(FREE_LINE)) await line.get(COPY_BUTTON).trigger('click')

    expect(writeText.mock.calls).toEqual([[FEROX_BLUEPRINT], [NOCXIUM_FORMULA]])
  })

  it('copies the blueprint name from a line to deliver', async () => {
    const wrapper = mountCompanion(companionTasksOf({ mine: [{ item: ferox, quantity: 4 }] }))

    await wrapper.get(`${MINE_LINE} ${COPY_BUTTON}`).trigger('click')

    expect(writeText.mock.calls).toEqual([[FEROX_BLUEPRINT]])
  })

  it('copies the blueprint name from one of my ESI jobs', async () => {
    const wrapper = mountCompanion(companionTasksOf({
      jobs: [{ match: jobMatch({ id: 'match-ishtar', status: 'active', esiJobId: 910_001 }), item: { ...ferox, blueprintName: ISHTAR_BLUEPRINT } }],
    }))

    await wrapper.get(`${JOB_LINE} ${COPY_BUTTON}`).trigger('click')

    expect(writeText.mock.calls).toEqual([[ISHTAR_BLUEPRINT]])
  })

  it('offers no button on a material, on a line whose blueprint name is unknown or on a line without the field', () => {
    const withoutField = bomLine({ id: 'bom-legacy', typeId: 37, typeName: 'Isogen', freeQuantity: 5 })
    const wrapper = mountCompanion(companionTasksOf({
      free: [tritanium, mysteryHull, withoutField],
      mine: [{ item: tritanium, quantity: 10 }],
      jobs: [{ match: jobMatch({ id: 'match-mystery', status: 'active', esiJobId: 910_002 }), item: mysteryHull }],
    }))

    expect(wrapper.findAll(COPY_BUTTON)).toHaveLength(0)
  })

  it('keeps the claim button and the truncated name on a line that carries the copy button', async () => {
    const wrapper = mountCompanion(companionTasksOf({ free: [ferox] }))
    const line = wrapper.get(FREE_LINE)

    await line.get('[data-testid="companion-claim"]').trigger('click')

    expect({
      emitted: wrapper.emitted('claim'),
      copied: writeText.mock.calls,
      nameTruncated: line.get('[title="Ferox"]').classes().includes('truncate'),
      buttonKeepsItsWidth: line.get(COPY_BUTTON).classes().includes('shrink-0'),
    }).toEqual({ emitted: [['bom-ferox']], copied: [], nameTruncated: true, buttonKeepsItsWidth: true })
  })

  it('confirms the copy on the button once the clipboard accepted it', async () => {
    const wrapper = mountCompanion(companionTasksOf({ free: [ferox] }))
    const button = wrapper.get(COPY_BUTTON)

    await button.trigger('click')
    await flushPromises()

    expect(button.attributes('data-state')).toBe('copied')
  })
})
