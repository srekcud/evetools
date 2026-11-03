import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MiningOperationJoin from '@/views/MiningOperation/MiningOperationJoin.vue'
import { ApiError, apiRequest } from '@/services/api'
import type { MiningOperationInvitation } from '@/stores/miningOperation'
import { expectOnlyLocaleTexts, TEST_LOCALES, type TestLocale } from '../../localeTexts'

const OPERATION_O = '4d2f8a61-3b7c-4e95-a0d8-6c1e9f2b7a53'
const JOIN_CODE = '7f3a9c2e1b'
const INVITATION_PATH = `/mining-operations/join/${JOIN_CODE}`
const BRANN_HOLT_ID = '9c1b4e7a-2d58-4f36-b0a9-3e6d8f2c1a47'
const BRANN_II_ID = '2f7a9d3c-6e14-4b82-a5c0-8d1e4b7f9a36'
const BRANN_III_ID = '5b8d2e7f-4a19-4c63-9e05-7d2a6c1f8b43'
const BRANN_IV_ID = 'e1c7a3f9-8b52-4d06-a4e7-3f9b5d2c6a18'

const route = reactive<{ params: Record<string, string> }>({ params: { code: JOIN_CODE } })
const push = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push, replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const BRANN_PREVIEW: MiningOperationInvitation = {
  code: JOIN_CODE,
  operationId: OPERATION_O,
  label: 'Anomalie Spodumain — 20:00',
  ownerCharacterName: 'Aria Solberg',
  plannedStartAt: '2026-11-05T20:00:00+00:00',
  status: 'planned',
  visibility: 'corporation',
  splitMode: 'value_prorata',
  participantsCount: 1,
  autoAccepted: true,
  myRole: null,
  myStatus: null,
  characters: [
    { id: BRANN_HOLT_ID, eveCharacterId: 2_112_262_201, name: 'Brann Holt', isMain: true, eligibility: 'eligible', busyOperationLabel: null },
    { id: BRANN_II_ID, eveCharacterId: 2_112_262_202, name: 'Brann II', isMain: false, eligibility: 'eligible', busyOperationLabel: null },
    { id: BRANN_III_ID, eveCharacterId: 2_112_262_203, name: 'Brann III', isMain: false, eligibility: 'reconnect_required', busyOperationLabel: null },
    { id: BRANN_IV_ID, eveCharacterId: 2_112_262_204, name: 'Brann IV', isMain: false, eligibility: 'in_another_operation', busyOperationLabel: 'Ice belt — dimanche' },
  ],
  valuationSettings: {
    valuationForm: 'compressed',
    valuationMarket: 'jita',
    valuationSide: 'buy',
    structureId: null,
    structureName: null,
    reprocessYield: null,
    boosterCutPercent: 0,
    corporationCutPercent: 0,
    boosterCharacterName: null,
  },
  fleetStorageRecipientName: null,
}

function answerInvitation(preview: MiningOperationInvitation | ApiError, joined?: MiningOperationInvitation): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (endpoint === INVITATION_PATH && options?.method === 'POST') return joined as never
    if (preview instanceof ApiError) throw preview
    return preview as never
  })
}

async function mountJoin(locale: TestLocale = 'en'): Promise<VueWrapper> {
  const wrapper = mount(MiningOperationJoin, {
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, LoadingSpinner: true, 'router-link': { template: '<a><slot /></a>' } },
    },
  })
  await flushPromises()
  return wrapper
}

function checkboxOf(wrapper: VueWrapper, characterId: string) {
  const checkbox = wrapper.find(`input[type="checkbox"][value="${characterId}"]`)
  expect(checkbox.exists(), `checkbox of ${characterId}`).toBe(true)
  return checkbox
}

function joinButton(wrapper: VueWrapper) {
  return wrapper.findAll('button').find(button => /Join with|Rejoindre avec/.test(button.text()))
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  push.mockReset()
  route.params = { code: JOIN_CODE }
})

enableAutoUnmount(afterEach)

describe('MiningOperationJoin', () => {
  it('keeps the join button disabled while no character is ticked and disables the characters that cannot mine', async () => {
    answerInvitation(BRANN_PREVIEW)

    const wrapper = await mountJoin()

    expect(joinButton(wrapper)?.attributes('disabled')).toBeDefined()
    expect(checkboxOf(wrapper, BRANN_HOLT_ID).attributes('disabled')).toBeUndefined()
    expect(checkboxOf(wrapper, BRANN_III_ID).attributes('disabled')).toBeDefined()
    expect(checkboxOf(wrapper, BRANN_IV_ID).attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('reconnect needed')
    expect(wrapper.text()).toContain('already in “Ice belt — dimanche”')
  })

  it('joins with the two ticked characters and opens the operation once accepted', async () => {
    answerInvitation(BRANN_PREVIEW, { ...BRANN_PREVIEW, participantsCount: 2, myRole: 'member', myStatus: 'accepted' })
    const wrapper = await mountJoin('fr')

    await checkboxOf(wrapper, BRANN_HOLT_ID).setValue(true)
    await checkboxOf(wrapper, BRANN_II_ID).setValue(true)

    expect(joinButton(wrapper)?.text()).toBe('Rejoindre avec 2 personnages')
    await joinButton(wrapper)?.trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledWith(INVITATION_PATH, {
      method: 'POST',
      body: JSON.stringify({ characterIds: [BRANN_HOLT_ID, BRANN_II_ID] }),
    })
    expect(push).toHaveBeenCalledWith(expect.objectContaining({ name: 'mining-operation-detail', params: { id: OPERATION_O } }))
  })

  it('shows the request as sent when the join waits for approval', async () => {
    answerInvitation({ ...BRANN_PREVIEW, autoAccepted: false }, { ...BRANN_PREVIEW, autoAccepted: false, myRole: 'member', myStatus: 'pending' })
    const wrapper = await mountJoin()

    await checkboxOf(wrapper, BRANN_HOLT_ID).setValue(true)
    await joinButton(wrapper)?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Request sent')
    expect(push).not.toHaveBeenCalled()
  })

  it('tells an accepted participant he already takes part without offering the characters again', async () => {
    answerInvitation({ ...BRANN_PREVIEW, myRole: 'member', myStatus: 'accepted' })

    const wrapper = await mountJoin()

    expect(wrapper.text()).toContain('You already take part in this operation.')
    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(0)
  })

  it('tells that a closing operation no longer accepts participants and offers no join button', async () => {
    answerInvitation({ ...BRANN_PREVIEW, status: 'closing' })

    const wrapper = await mountJoin()

    expect(wrapper.text()).toContain('This operation no longer accepts participants.')
    expect(joinButton(wrapper)).toBeUndefined()
  })

  it('shows the valuation basis, the booster cut and the booster in the operation rules', async () => {
    answerInvitation({ ...BRANN_PREVIEW, valuationSettings: { ...BRANN_PREVIEW.valuationSettings, valuationSide: 'sell', boosterCutPercent: 10, boosterCharacterName: 'Cyra Lune' } })

    const wrapper = await mountJoin()

    const rules = wrapper.findAll('section').find(section => section.text().includes('Operation rules'))?.text() ?? ''
    expect(['Compressed, Jita Sell', 'Booster cut', 'Cyra Lune'].every(text => rules.includes(text))).toBe(true)
  })

  it('names the fleet storage recipient in the operation rules', async () => {
    answerInvitation({ ...BRANN_PREVIEW, fleetStorageRecipientName: 'Brann Holt' })

    const wrapper = await mountJoin()

    const rules = wrapper.findAll('section').find(section => section.text().includes('Operation rules'))?.text() ?? ''
    expect(['Fleet storage', 'Brann Holt'].every(text => rules.includes(text))).toBe(true)
  })

  it('says the operation has no fleet storage recipient in the operation rules', async () => {
    answerInvitation(BRANN_PREVIEW)

    const wrapper = await mountJoin()

    const rules = wrapper.findAll('section').find(section => section.text().includes('Operation rules'))?.text() ?? ''
    expect(rules.includes('Fleet storage') && rules.includes('none')).toBe(true)
  })

  it('tells that the link is invalid or renewed when the code is not found', async () => {
    answerInvitation(new ApiError('Mining operation not found', 404))

    const wrapper = await mountJoin()

    expect(wrapper.text()).toContain('Invalid or renewed link.')
  })

  it.each(TEST_LOCALES)('shows only %s texts on the invitation', async (locale) => {
    answerInvitation(BRANN_PREVIEW)

    const wrapper = await mountJoin(locale)

    expectOnlyLocaleTexts(wrapper.text(), locale, [
      'miningOperation.join.whichCharacters',
      'miningOperation.join.oneOpenOperation',
      'miningOperation.join.rules',
      'miningOperation.join.autoAccepted',
      'miningOperation.join.eligibility.eligible',
      'miningOperation.join.eligibility.reconnect_required',
      'miningOperation.join.reconnect',
      'miningOperation.splitMode.value_prorata',
    ])
  })
})
