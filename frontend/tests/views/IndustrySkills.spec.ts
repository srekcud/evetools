import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import fr from '@/i18n/locales/fr.json'
import IndustrySkills from '@/views/IndustrySkills.vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import type { SkillGainLine, SkillGains } from '@/stores/industry/skillGains'
import type { Character, User } from '@/types'
import { deferred } from '../deferred'

const routerPush = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => ({ query: {}, params: {} })) as never,
  useRouter: (() => ({ push: routerPush, replace: vi.fn(), resolve: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const MAIN_ID = '00000000-0000-4000-8000-0000000000a1'
const ALT_ID = '00000000-0000-4000-8000-0000000000a2'
const SELECT = '[data-testid="skills-character-select"]'
const ROW = '[data-testid="skill-gain-row"]'
const EMPTY = '[data-testid="skills-empty"]'
const ISK_PER_HOUR_CHIP = '[data-testid="skills-isk-per-hour"]'
const BACK = '[data-testid="skills-back"]'

function character(id: string, name: string, isMain: boolean): Character {
  return {
    id,
    eveCharacterId: 2_112_000_000 + Number(id.slice(-1)),
    name,
    corporationId: 98_000_001,
    corporationName: 'Test Corp',
    allianceId: null,
    allianceName: null,
    isMain,
    hasValidToken: true,
    lastSyncAt: null,
  }
}

function skillLine(name: string): SkillGainLine {
  return {
    skillId: 3380,
    skillName: name,
    currentLevel: 2,
    targetLevel: 3,
    effect: 'manufacturing_time',
    hoursSavedPerMonth: 4.35,
    monthlyGain: 5_217_391,
    gainUnknownReason: null,
    trainingSeconds: 345_600,
    trainingUnknownReason: null,
    gainPerTrainingDay: 1_304_348,
  }
}

function skillGains(characterId: string, lines: SkillGainLine[], iskPerHour: number | null = 1_200_000): SkillGains {
  return { characterId, period: { from: '2026-10-10', to: '2026-11-09', days: 30 }, iskPerHour, lines }
}

async function mountView(characters: Character[] = [character(ALT_ID, 'Alt Pilot', false), character(MAIN_ID, 'Main Pilot', true)]): Promise<VueWrapper> {
  const pinia = createPinia()
  setActivePinia(pinia)
  const authStore = useAuthStore()
  authStore.token = 'test-token'
  authStore.user = { id: 'user-1', authStatus: 'valid', mainCharacter: null, characters, corporationId: 98_000_001, corporationName: 'Test Corp' } as User
  const wrapper = mount(IndustrySkills, {
    global: {
      plugins: [pinia, createI18n({ legacy: false, locale: 'fr', messages: { fr } })],
      stubs: { MainLayout: { template: '<div><slot /></div>' } },
    },
  })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  routerPush.mockClear()
})

describe('IndustrySkills', () => {
  it('loads the skill gains of the main character on mount and lists them', async () => {
    vi.mocked(apiRequest).mockResolvedValue(skillGains(MAIN_ID, [skillLine('Industry')]))

    const wrapper = await mountView()

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(`/me/characters/${MAIN_ID}/skill-gains`)
    expect(wrapper.findAll(ROW)).toHaveLength(1)
    expect(wrapper.text()).toContain('Production · Main Pilot')
    expect((wrapper.get(SELECT).element as HTMLSelectElement).value).toBe(MAIN_ID)
    expect(wrapper.get(SELECT).findAll('option').map(option => option.text())).toEqual(['Alt Pilot', 'Main Pilot'])
  })

  it('loads the skill gains of the character picked in the selector', async () => {
    vi.mocked(apiRequest).mockImplementation(async url => skillGains(String(url).includes(ALT_ID) ? ALT_ID : MAIN_ID, [skillLine(String(url).includes(ALT_ID) ? 'Alt skill' : 'Main skill')]))
    const wrapper = await mountView()

    await wrapper.get(SELECT).setValue(ALT_ID)
    await flushPromises()

    expect(apiRequest).toHaveBeenLastCalledWith(`/me/characters/${ALT_ID}/skill-gains`)
    expect(wrapper.get(ROW).text()).toContain('Alt skill')
    expect(wrapper.text()).toContain('Production · Alt Pilot')
  })

  it('ignores the answer of a character no longer selected', async () => {
    const mainAnswer = deferred<SkillGains>()
    vi.mocked(apiRequest).mockImplementation(url => (String(url).includes(MAIN_ID) ? mainAnswer.promise : Promise.resolve(skillGains(ALT_ID, [skillLine('Alt skill')]))))
    const wrapper = await mountView()

    await wrapper.get(SELECT).setValue(ALT_ID)
    await flushPromises()
    mainAnswer.resolve(skillGains(MAIN_ID, [skillLine('Main skill')]))
    await flushPromises()

    expect(wrapper.findAll(ROW)).toHaveLength(1)
    expect(wrapper.get(ROW).text()).toContain('Alt skill')
    expect(wrapper.text()).not.toContain('Main skill')
  })

  it('shows the ISK per hour the gains are valued at, or says it is unknown', async () => {
    vi.mocked(apiRequest).mockResolvedValue(skillGains(MAIN_ID, [skillLine('Industry')]))
    const known = await mountView()
    vi.mocked(apiRequest).mockResolvedValue(skillGains(MAIN_ID, [skillLine('Industry')], null))
    const unknown = await mountView()

    expect(known.get(ISK_PER_HOUR_CHIP).text()).toBe('valorisé à 1,20 M ISK/h sur 30 jours')
    expect(unknown.get(ISK_PER_HOUR_CHIP).text()).toBe('ISK/h inconnu')
  })

  it('shows the failure instead of an empty table when the gains cannot be loaded', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Service unavailable'))

    const wrapper = await mountView()

    expect(wrapper.text()).toContain('Service unavailable')
    expect(wrapper.findAll(ROW)).toHaveLength(0)
    expect(wrapper.find(EMPTY).exists()).toBe(false)
  })

  it('shows the empty state when the character has no skill to train', async () => {
    vi.mocked(apiRequest).mockResolvedValue(skillGains(MAIN_ID, []))

    const wrapper = await mountView()

    expect(wrapper.get(EMPTY).text()).toBe('Aucune compétence à entraîner pour ce personnage')
  })

  it('asks for nothing and says so when the user has no character', async () => {
    const wrapper = await mountView([])

    expect(apiRequest).not.toHaveBeenCalled()
    expect(wrapper.get(EMPTY).text()).toBe('Aucun personnage')
  })

  it('goes back to the workshop', async () => {
    vi.mocked(apiRequest).mockResolvedValue(skillGains(MAIN_ID, []))
    const wrapper = await mountView()

    await wrapper.get(BACK).trigger('click')

    expect(routerPush).toHaveBeenCalledExactlyOnceWith({ name: 'industry' })
  })
})
