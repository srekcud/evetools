import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import GroupCompanion from '@/components/group-industry/GroupCompanion.vue'
import GroupCompanionHost from '@/components/group-industry/GroupCompanionHost.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { useGroupCompanionStore } from '@/stores/group-industry/companion'
import { COMPANION_PROJECT_ID, companionProject, companionTasksOf } from './companionFixtures'

const router = vi.hoisted(() => ({ push: vi.fn(), replace: vi.fn() }))

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRouter: (() => router) as never,
}))

enableAutoUnmount(afterEach)

const SWITCH = '[data-testid="companion-project-switch"]'
const CHOICE = '[data-testid="companion-project-choice"]'
const OTHER_PROJECT_ID = 'project-hammerhead'
const LONG_NAME = 'Hammerhead II and Ogre II and Valkyrie II batch for the whole alliance fleet'

const TWO_PROJECTS = [
  { id: COMPANION_PROJECT_ID, name: 'Ishtar batch' },
  { id: OTHER_PROJECT_ID, name: 'Hammerhead batch' },
]

function mountCompanion(options: { size?: 'bar' | 'column'; projects?: { id: string; name: string }[]; locale?: 'en' | 'fr' } = {}): VueWrapper {
  return mount(GroupCompanion, {
    props: {
      tasks: companionTasksOf(),
      projectName: 'Ishtar batch',
      size: options.size ?? 'column',
      errorMessage: null,
      projects: options.projects ?? TWO_PROJECTS,
      currentProjectId: COMPANION_PROJECT_ID,
    },
    global: { plugins: [createI18n({ legacy: false, locale: options.locale ?? 'en', messages: { en, fr } })] },
  })
}

function pictureInPictureWindowStub(): Window {
  return {
    document: document.implementation.createHTMLDocument('companion'),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    resizeTo: vi.fn(),
    close: vi.fn(),
    focus: vi.fn(),
  } as unknown as Window
}

describe('GroupCompanion project switch', () => {
  it('writes one button per project in the column and marks the opened one', () => {
    const wrapper = mountCompanion()

    expect(wrapper.findAll(CHOICE).map(choice => [choice.text(), choice.attributes('aria-pressed')])).toEqual([
      ['Ishtar batch', 'true'],
      ['Hammerhead batch', 'false'],
    ])
  })

  it('labels the group of buttons in the language of the user', () => {
    expect(mountCompanion({ locale: 'fr' }).get(SWITCH).attributes('aria-label')).toBe(fr.groupIndustry.companion.projectSwitch)
  })

  it('emits the id of the project when a button of another project is clicked', async () => {
    const wrapper = mountCompanion()

    await wrapper.findAll(CHOICE)[1].trigger('click')

    expect(wrapper.emitted('switch-project')).toEqual([[OTHER_PROJECT_ID]])
  })

  it('emits nothing when the button of the opened project is clicked', async () => {
    const wrapper = mountCompanion()

    await wrapper.findAll(CHOICE)[0].trigger('click')

    expect(wrapper.emitted('switch-project')).toBeUndefined()
  })

  it('keeps a long project name on one line with the whole name in the tooltip', () => {
    const wrapper = mountCompanion({ projects: [{ id: COMPANION_PROJECT_ID, name: LONG_NAME }, { id: OTHER_PROJECT_ID, name: 'Hammerhead batch' }] })

    const choice = wrapper.findAll(CHOICE)[0]

    expect([choice.attributes('title'), choice.classes().includes('truncate')]).toEqual([LONG_NAME, true])
  })

  it.each([
    ['a single project', 'column', [{ id: COMPANION_PROJECT_ID, name: 'Ishtar batch' }]],
    ['no project', 'column', []],
    ['the bar size', 'bar', TWO_PROJECTS],
  ] as const)('shows no switch with %s', (_label, size, projects) => {
    expect(mountCompanion({ size, projects: [...projects] }).find(SWITCH).exists()).toBe(false)
  })
})

describe('GroupCompanionHost project switch', () => {
  it('feeds the companion with the choices of the store and opens the project picked', async () => {
    Object.defineProperty(window, 'documentPictureInPicture', {
      value: { requestWindow: vi.fn(async () => pictureInPictureWindowStub()) },
      configurable: true,
    })
    setActivePinia(createPinia())
    const companion = useGroupCompanionStore()
    const switchProject = vi.spyOn(companion, 'switchProject').mockResolvedValue(undefined)
    companion.projectId = COMPANION_PROJECT_ID
    companion.project = companionProject()
    companion.myProjects = [companionProject(), companionProject({ id: OTHER_PROJECT_ID, name: 'Hammerhead batch' })]
    const wrapper = mount(GroupCompanionHost, {
      global: {
        plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
        stubs: { GroupCompanion: true },
      },
    })
    await flushPromises()

    const shown = wrapper.getComponent(GroupCompanion)
    shown.vm.$emit('switch-project', OTHER_PROJECT_ID)
    await flushPromises()

    expect({
      choices: shown.props('projects'),
      current: shown.props('currentProjectId'),
      switched: switchProject.mock.calls,
    }).toEqual({
      choices: [{ id: COMPANION_PROJECT_ID, name: 'Ishtar batch' }, { id: OTHER_PROJECT_ID, name: 'Hammerhead batch' }],
      current: COMPANION_PROJECT_ID,
      switched: [[OTHER_PROJECT_ID]],
    })
  })
})
