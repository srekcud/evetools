import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectBlacklistEditor from '@/components/group-industry/ProjectBlacklistEditor.vue'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const PROJECT_ID = 'project-ishtar-batch'
const PROJECT_PATH = `/industry/projects/${PROJECT_ID}`
const BLACKLIST_PATH = '/industry/blacklist'
const FUEL_GROUP = 1136
const ADVANCED_GROUP = 334
const BIOCHEM_GROUPS = [661, 662]
const GROUP_OUTSIDE_EVERY_CATEGORY = 999
const KEPT_TYPE = 34

const BLACKLIST_CONFIG = {
  categories: [
    { key: 'fuel_blocks', label: 'Fuel Blocks', groupIds: [FUEL_GROUP], blacklisted: false, partiallyBlacklisted: false },
    { key: 'advanced_components', label: 'Advanced Components', groupIds: [ADVANCED_GROUP], blacklisted: false, partiallyBlacklisted: false },
    { key: 'biochem_reactions', label: 'Biochemical Reactions', groupIds: BIOCHEM_GROUPS, blacklisted: false, partiallyBlacklisted: false },
  ],
  groupIds: [],
  items: [],
}

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

function text(key: string): string {
  return i18n.global.t(key)
}

type Overrides = Partial<{ status: string, canEdit: boolean, blacklistGroupIds: number[], blacklistTypeIds: number[] }>

async function mountEditor(overrides: Overrides = {}): Promise<VueWrapper> {
  setActivePinia(createPinia())
  const wrapper = mount(ProjectBlacklistEditor, {
    props: {
      projectId: PROJECT_ID,
      status: 'draft',
      canEdit: true,
      blacklistGroupIds: [ADVANCED_GROUP, ...BIOCHEM_GROUPS, GROUP_OUTSIDE_EVERY_CATEGORY],
      blacklistTypeIds: [KEPT_TYPE],
      ...overrides,
    },
    global: { plugins: [i18n] },
  })
  await flushPromises()
  return wrapper
}

async function openEditor(wrapper: VueWrapper): Promise<void> {
  await wrapper.find('[data-testid="project-blacklist-edit"]').trigger('click')
  await flushPromises()
}

function isChecked(wrapper: VueWrapper, key: string): boolean | 'absent' {
  const box = wrapper.find(`[data-testid="project-blacklist-category-${key}"]`)
  return box.exists() ? (box.element as HTMLInputElement).checked : 'absent'
}

function patchBody(): Record<string, unknown> {
  const call = vi.mocked(apiRequest).mock.calls.find(([path, options]) => path === PROJECT_PATH && options?.method === 'PATCH')
  return call === undefined ? { missing: true } : JSON.parse(String(call[1]?.body))
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (path: string) => {
    if (path === BLACKLIST_PATH) return BLACKLIST_CONFIG
    return { id: PROJECT_ID }
  })
})

describe('project blacklist editor', () => {
  it('offers the edition of a draft project to its owner or an admin', async () => {
    const wrapper = await mountEditor()

    expect(wrapper.find('[data-testid="project-blacklist-edit"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="project-blacklist-edit"]').text()).toBe(text('groupIndustry.blacklistEditor.edit'))
  })

  it.each([
    ['a published project', { status: 'published' }],
    ['a project in progress', { status: 'in_progress' }],
    ['a participant who is neither owner nor admin', { canEdit: false }],
  ])('does not offer the edition on %s', async (_label, overrides) => {
    const wrapper = await mountEditor(overrides)

    expect(wrapper.find('[data-testid="project-blacklist-edit"]').exists()).toBe(false)
  })

  it('checks a category only when every group of the category is in the blacklist of the project', async () => {
    const wrapper = await mountEditor({ blacklistGroupIds: [ADVANCED_GROUP, BIOCHEM_GROUPS[0]] })

    await openEditor(wrapper)

    expect(['fuel_blocks', 'advanced_components', 'biochem_reactions'].map(key => isChecked(wrapper, key)))
      .toEqual([false, true, false])
  })

  it('saves the checked categories, keeps the groups outside every category and the types, then tells the page', async () => {
    const wrapper = await mountEditor()
    await openEditor(wrapper)

    await wrapper.find('[data-testid="project-blacklist-category-fuel_blocks"]').setValue(true)
    await wrapper.find('[data-testid="project-blacklist-category-advanced_components"]').setValue(false)
    await wrapper.find('[data-testid="project-blacklist-save"]').trigger('click')
    await flushPromises()

    const body = patchBody()
    expect({ ...body, blacklistCategoryKeys: [...(body.blacklistCategoryKeys as string[] ?? [])].sort() }).toEqual({
      blacklistCategoryKeys: ['biochem_reactions', 'fuel_blocks'],
      blacklistGroupIds: [GROUP_OUTSIDE_EVERY_CATEGORY],
      blacklistTypeIds: [KEPT_TYPE],
    })
    expect(wrapper.emitted('saved')).toHaveLength(1)
    expect(wrapper.find('[data-testid="project-blacklist-editor"]').exists()).toBe(false)
  })

  it('shows the refusal of the API and stays open without telling the page', async () => {
    vi.mocked(apiRequest).mockImplementation(async (path: string, options?: RequestInit) => {
      if (path === BLACKLIST_PATH) return BLACKLIST_CONFIG
      if (options?.method === 'PATCH') throw new Error('The blacklist of a project can only be changed while it is a draft')
      return { id: PROJECT_ID }
    })
    const wrapper = await mountEditor()
    await openEditor(wrapper)

    await wrapper.find('[data-testid="project-blacklist-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="project-blacklist-error"]').text()).toContain('The blacklist of a project can only be changed while it is a draft')
    expect(wrapper.find('[data-testid="project-blacklist-editor"]').exists()).toBe(true)
    expect(wrapper.emitted('saved')).toBeUndefined()
  })

  it('closes without any request when the edition is cancelled', async () => {
    const wrapper = await mountEditor()
    await openEditor(wrapper)

    await wrapper.find('[data-testid="project-blacklist-cancel"]').trigger('click')

    expect(wrapper.find('[data-testid="project-blacklist-editor"]').exists()).toBe(false)
    expect(patchBody()).toEqual({ missing: true })
  })
})
