import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ProjectDedicatedCharacters from '@/components/group-industry/ProjectDedicatedCharacters.vue'
import { apiRequest } from '@/services/api'
import type { GroupProject } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const CHARACTERS_ENDPOINT = '/me/characters'
const PANEL = '[data-testid="project-dedicated-characters"]'

const ORCA = { id: 'project-orca', name: 'Orca batch', status: 'in_progress' }
const HECATE = { id: 'project-hecate', name: 'Hecate batch', status: 'selling' }

const ARIA = { id: 'uuid-aria', eveCharacterId: 2_112_000_001, name: 'Aria Solberg', activeProjectId: ORCA.id, activeProjectName: ORCA.name }
const BRIN = { id: 'uuid-brin', eveCharacterId: 2_112_000_002, name: 'Brin Solberg', activeProjectId: null, activeProjectName: null }
const CORIN = { id: 'uuid-corin', eveCharacterId: 2_112_000_003, name: 'Corin Vale', activeProjectId: HECATE.id, activeProjectName: HECATE.name }

type Patch = (characterId: string, body: { projectId: string | null }) => unknown

async function mountPanel(project: { id: string; name: string; status: string }, patch: Patch = () => { throw new Error('Unexpected patch') }) {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (endpoint === CHARACTERS_ENDPOINT) return [ARIA, BRIN, CORIN]
    const patched = /^\/me\/characters\/([^/]+)\/active-project$/.exec(endpoint)
    if (patched !== null && options?.method === 'PATCH') return patch(patched[1], JSON.parse(String(options.body)))
    throw new Error(`Unexpected request ${endpoint}`)
  })
  const wrapper = mount(ProjectDedicatedCharacters, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] },
    props: { project: project as unknown as GroupProject },
  })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountPanel>>

function box(wrapper: Wrapper, characterId: string) {
  return wrapper.get(`[data-testid="dedicate-character-${characterId}"]`)
}

function isTicked(wrapper: Wrapper, characterId: string): boolean {
  return (box(wrapper, characterId).element as HTMLInputElement).checked
}

function otherProjectHint(wrapper: Wrapper, characterId: string) {
  return wrapper.find(`[data-testid="dedicate-character-other-${characterId}"]`)
}

enableAutoUnmount(afterEach)

describe('ProjectDedicatedCharacters', () => {
  it('ticks the characters dedicated to this project and names the other project of a character dedicated elsewhere', async () => {
    const wrapper = await mountPanel(ORCA)

    expect(wrapper.find(PANEL).exists()).toBe(true)
    expect(wrapper.text()).toContain('Dedicate a character to this project')
    expect([ARIA, BRIN, CORIN].map(character => [character.name, isTicked(wrapper, character.id)])).toEqual([
      ['Aria Solberg', true],
      ['Brin Solberg', false],
      ['Corin Vale', false],
    ])
    expect(otherProjectHint(wrapper, ARIA.id).exists()).toBe(false)
    expect(otherProjectHint(wrapper, BRIN.id).exists()).toBe(false)
    expect(otherProjectHint(wrapper, CORIN.id).text()).toContain('Hecate batch')
  })

  it('patches this project on the character that is ticked, even when it was dedicated elsewhere, and drops the other project hint', async () => {
    const wrapper = await mountPanel(ORCA, (characterId, body) => ({ id: characterId, activeProjectId: body.projectId, activeProjectName: ORCA.name }))
    expect(wrapper.find(PANEL).exists()).toBe(true)

    await box(wrapper, CORIN.id).setValue(true)
    await flushPromises()

    expect(vi.mocked(apiRequest)).toHaveBeenCalledWith(`/me/characters/${CORIN.id}/active-project`, expect.objectContaining({ method: 'PATCH', body: JSON.stringify({ projectId: ORCA.id }) }))
    expect(isTicked(wrapper, CORIN.id)).toBe(true)
    expect(otherProjectHint(wrapper, CORIN.id).exists()).toBe(false)
  })

  it('patches a null project id on the character that is unticked', async () => {
    const wrapper = await mountPanel(ORCA, characterId => ({ id: characterId, activeProjectId: null, activeProjectName: null }))
    expect(wrapper.find(PANEL).exists()).toBe(true)

    await box(wrapper, ARIA.id).setValue(false)
    await flushPromises()

    expect(vi.mocked(apiRequest)).toHaveBeenCalledWith(`/me/characters/${ARIA.id}/active-project`, expect.objectContaining({ method: 'PATCH', body: JSON.stringify({ projectId: null }) }))
    expect(isTicked(wrapper, ARIA.id)).toBe(false)
  })

  it('is hidden on a completed project', async () => {
    const open = await mountPanel(ORCA)
    expect(open.find(PANEL).exists()).toBe(true)

    const wrapper = await mountPanel({ ...ORCA, status: 'completed' })

    expect(wrapper.find(PANEL).exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Aria Solberg')
  })
})
