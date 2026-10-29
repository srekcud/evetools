import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import SkillsConfig from '@/components/industry/SkillsConfig.vue'
import { useStructuresStore } from '@/stores/industry/structures'
import type { CharacterSkill, CharacterSyncFailure } from '@/stores/industry/types'

const BANNER = '[data-testid="skills-sync-failures"]'
const EmptyView = { render: () => null }

const CACHED_SKILLS: CharacterSkill[] = [
  { characterId: 2112000001, characterName: 'Pilot One', industry: 5, advancedIndustry: 4, reactions: 3, source: 'esi', lastSyncAt: null },
  { characterId: 2112000002, characterName: 'Pilot Two', industry: 3, advancedIndustry: 1, reactions: 0, source: 'manual', lastSyncAt: null },
  { characterId: 2112000003, characterName: 'Pilot Three', industry: 4, advancedIndustry: 2, reactions: 5, source: 'esi', lastSyncAt: null },
]

async function mountAndSyncSkills(failedCharacters: CharacterSyncFailure[], locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  const structuresStore = useStructuresStore()
  structuresStore.characterSkills = CACHED_SKILLS
  vi.spyOn(structuresStore, 'fetchCharacterSkills').mockResolvedValue(undefined)
  vi.spyOn(structuresStore, 'syncCharacterSkills').mockResolvedValue(failedCharacters as never)
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: EmptyView },
      { path: '/characters', component: EmptyView },
    ],
  })
  const wrapper = mount(SkillsConfig, { global: { plugins: [i18n, router] } })
  const syncLabel = locale === 'en' ? 'Sync all' : fr.industry.skills.syncAll
  const syncButton = wrapper.findAll('button').find(button => button.text() === syncLabel)
  if (!syncButton) throw new Error('No sync all button')
  await syncButton.trigger('click')
  await flushPromises()
  return wrapper
}

function bannerLines(wrapper: VueWrapper): string[] {
  return wrapper.find(BANNER).findAll('li').map(line => line.text())
}

function linkTargets(wrapper: VueWrapper, label: string): (string | undefined)[] {
  return wrapper.find(BANNER).findAll('a').filter(link => link.text() === label).map(link => link.attributes('href'))
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('SkillsConfig skill sync failures', () => {
  it('names a character whose token lacks the skills scope with a link to reconnect it and keeps the skills', async () => {
    const wrapper = await mountAndSyncSkills([{ characterName: 'Pilot Two', reason: 'missing_scope', statusCode: null }])

    expect(wrapper.findAll(BANNER)).toHaveLength(1)
    expect(wrapper.find(BANNER).text()).toContain('Skills not synced for 1 character:')
    expect(bannerLines(wrapper)).toEqual(['Pilot Two: the token lacks a required ESI permission, reconnect the character'])
    expect(linkTargets(wrapper, 'Reconnect characters')).toEqual(['/characters'])
    expect(wrapper.findAll('tbody tr')).toHaveLength(3)
  })

  it('names a rate limited character in french without link', async () => {
    const wrapper = await mountAndSyncSkills([{ characterName: 'Pilot Three', reason: 'esi_rate_limited', statusCode: 420 }], 'fr')

    expect(wrapper.findAll(BANNER)).toHaveLength(1)
    expect(wrapper.find(BANNER).text()).toContain('Compétences non synchronisées pour 1 personnage :')
    expect(bannerLines(wrapper)).toEqual(['Pilot Three : limite de requêtes ESI atteinte, réessayez plus tard'])
    expect(wrapper.find(BANNER).findAll('a')).toHaveLength(0)
  })

  it('names every failed character with the plural title', async () => {
    const wrapper = await mountAndSyncSkills([
      { characterName: 'Pilot One', reason: 'esi_unavailable', statusCode: 502 },
      { characterName: 'Pilot Three', reason: 'missing_token', statusCode: null },
    ])

    expect(wrapper.findAll(BANNER)).toHaveLength(1)
    expect(wrapper.find(BANNER).text()).toContain('Skills not synced for 2 characters:')
    expect(bannerLines(wrapper)).toEqual([
      'Pilot One: ESI unavailable (maintenance or server error), retry later',
      'Pilot Three: no ESI token, reconnect the character',
    ])
  })

  it('names two failed characters with the french plural title', async () => {
    const wrapper = await mountAndSyncSkills([
      { characterName: 'Pilot One', reason: 'authentication_required', statusCode: null },
      { characterName: 'Pilot Two', reason: 'esi_error', statusCode: 403 },
    ], 'fr')

    expect(wrapper.find(BANNER).text()).toContain('Compétences non synchronisées pour 2 personnages :')
    expect(bannerLines(wrapper)).toEqual([
      'Pilot One : autorisation révoquée ou expirée, reconnectez le personnage',
      'Pilot Two : erreur ESI 403',
    ])
  })

  it('removes the banner of a previous sync when the next sync fails', async () => {
    const wrapper = await mountAndSyncSkills([{ characterName: 'Pilot Three', reason: 'esi_unavailable', statusCode: 503 }])
    expect(bannerLines(wrapper)).toEqual(['Pilot Three: ESI unavailable (maintenance or server error), retry later'])
    vi.spyOn(useStructuresStore(), 'syncCharacterSkills').mockRejectedValue(new Error('Gateway timeout'))

    const syncButton = wrapper.findAll('button').find(button => button.text() === 'Sync all')
    if (!syncButton) throw new Error('No sync all button')
    await syncButton.trigger('click')
    await flushPromises()

    expect(wrapper.find(BANNER).exists()).toBe(false)
    expect(wrapper.findAll('tbody tr')).toHaveLength(3)
  })

  it('shows no banner when every character was synced', async () => {
    const wrapper = await mountAndSyncSkills([])

    expect(wrapper.find(BANNER).exists()).toBe(false)
    expect(wrapper.findAll('tbody tr')).toHaveLength(3)
  })

  it('removes the banner from its close button', async () => {
    const wrapper = await mountAndSyncSkills([{ characterName: 'Pilot Two', reason: 'esi_error', statusCode: 404 }])

    expect(wrapper.findAll(BANNER)).toHaveLength(1)
    const closeButton = wrapper.find(BANNER).findAll('button').find(button => button.attributes('aria-label') === 'Close')
    if (!closeButton) throw new Error('No close button with an accessible name')
    await closeButton.trigger('click')

    expect(wrapper.find(BANNER).exists()).toBe(false)
  })
})
