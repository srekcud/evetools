import { enableAutoUnmount, flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MiningOperationDetail from '@/views/MiningOperation/MiningOperationDetail.vue'
import MiningOperationList from '@/views/MiningOperation/MiningOperationList.vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useMiningOperationStore, type MiningOperation, type MiningOperationLedger, type MiningOperationValuation } from '@/stores/miningOperation'
import { useSyncStore } from '@/stores/sync'
import type { User } from '@/types'
import {
  BOOST_SHIPS_PATH,
  DETAIL_PATH,
  ENDED_AT,
  LEDGER_PATH,
  MEMBERS_PATH,
  OPERATION_O,
  ORCA_TYPE_ID,
  PORPOISE_TYPE_ID,
  RORQUAL_TYPE_ID,
  boostShipCatalogue,
  compression,
  ledger,
  ledgerCharacter,
  participant,
  spodumainOperation,
  startedSpodumainOperation,
  valuationSettings,
} from './miningOperationLedgerFixtures'
import { answerWithoutFleetStorage } from './miningOperationFleetStorageFixtures'
import { REPROCESSING_PROFILE_PATH, MEMBERS_WITH_CYRA, VALUATION_PATH, e1Valuation } from './miningOperationValuationFixtures'
import { PAYMENTS_PATH } from './miningOperationPaymentFixtures'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: { id: OPERATION_O }, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const CREATE_PATH = '/mining-operations'
const SPODUMAIN_TYPE_ID = 19
const ZEOLITES_TYPE_ID = 45_490
const TYPE_ABSENT_FROM_SDE = 999_999_311

const ARIA_WITH_HER_MAIN = {
  id: '00000000-0000-4000-8000-000000000311',
  authStatus: 'valid',
  mainCharacter: null,
  characters: [{ id: 'character-aria', name: 'Aria Solberg', isMain: true, hasValidToken: true }],
  corporationId: 98_262_101,
  corporationName: 'Corporation 98262101',
} as unknown as User

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

function mountOptions(locale: 'en' | 'fr') {
  return {
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, LoadingSpinner: true, teleport: true, 'router-link': { template: '<a><slot /></a>' } },
    },
  }
}

async function openCreation(): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (endpoint === BOOST_SHIPS_PATH) return boostShipCatalogue() as never
    if (endpoint === CREATE_PATH && options?.method === 'POST') return spodumainOperation() as never
    return [] as never
  })
  useAuthStore().user = ARIA_WITH_HER_MAIN
  const wrapper = mount(MiningOperationList, mountOptions('en'))
  await flushPromises()
  await wrapper.findAll('button').find(button => button.text().includes('New operation'))?.trigger('click')
  await flushPromises()
  return wrapper
}

function boostShipRadio(wrapper: VueWrapper, typeId: number): DOMWrapper<HTMLInputElement> {
  return wrapper.find(`input[type="radio"][value="${typeId}"]`)
}

function compressorBox(wrapper: VueWrapper, kind: string): DOMWrapper<HTMLInputElement> {
  return wrapper.find(`input[type="checkbox"][value="${kind}"]`)
}

function compressorBoxes(wrapper: VueWrapper): Record<string, boolean | null> {
  return Object.fromEntries(['asteroid', 'gas', 'ice', 'mercoxit', 'moon'].map(kind => {
    const box = compressorBox(wrapper, kind)
    return [kind, box.exists() ? box.element.checked : null]
  }))
}

async function submitCreation(wrapper: VueWrapper): Promise<Record<string, unknown>> {
  await wrapper.find('input[type="text"]').setValue('Ice belt — dimanche')
  await wrapper.find('form').trigger('submit')
  await flushPromises()
  const post = vi.mocked(apiRequest).mock.calls.find(call => call[0] === CREATE_PATH && call[1]?.method === 'POST')
  expect(post, 'creation request').toBeDefined()
  return JSON.parse(String(post?.[1]?.body)) as Record<string, unknown>
}

describe('NewMiningOperationModal, boost ship and mounted compressors', () => {
  it('offers the Porpoise, the Orca and the Rorqual read from the catalogue as radio buttons, none chosen', async () => {
    const wrapper = await openCreation()

    expect([PORPOISE_TYPE_ID, ORCA_TYPE_ID, RORQUAL_TYPE_ID].map(typeId => boostShipRadio(wrapper, typeId).exists())).toEqual([true, true, true])
    expect([PORPOISE_TYPE_ID, ORCA_TYPE_ID, RORQUAL_TYPE_ID].map(typeId => boostShipRadio(wrapper, typeId).element.checked)).toEqual([false, false, false])
    expect(compressorBoxes(wrapper)).toEqual({ asteroid: null, gas: null, ice: null, mercoxit: null, moon: null })
  })

  it('shows only the compressors of the chosen hull, every one ticked', async () => {
    const wrapper = await openCreation()

    await boostShipRadio(wrapper, PORPOISE_TYPE_ID).setValue(true)
    const onThePorpoise = compressorBoxes(wrapper)
    await boostShipRadio(wrapper, ORCA_TYPE_ID).setValue(true)
    const onTheOrca = compressorBoxes(wrapper)

    expect([onThePorpoise, onTheOrca]).toEqual([
      { asteroid: true, gas: true, ice: null, mercoxit: null, moon: null },
      { asteroid: true, gas: true, ice: true, mercoxit: true, moon: true },
    ])
  })

  it('sends the chosen boost ship with the explicit list of the compressors left ticked', async () => {
    const wrapper = await openCreation()
    await boostShipRadio(wrapper, ORCA_TYPE_ID).setValue(true)
    await compressorBox(wrapper, 'ice').setValue(false)

    const body = await submitCreation(wrapper)

    expect([body.boostShipTypeId, body.mountedCompressorKinds]).toEqual([ORCA_TYPE_ID, ['asteroid', 'gas', 'mercoxit', 'moon']])
  })

  it('sends no boost ship and no compressor when no hull is chosen', async () => {
    const wrapper = await openCreation()

    const body = await submitCreation(wrapper)

    expect([body.boostShipTypeId, body.mountedCompressorKinds]).toEqual([null, []])
  })
})

const PORPOISE_COMPRESSION = compression({
  boostShipTypeId: PORPOISE_TYPE_ID,
  boostShipName: 'Porpoise',
  mountedCompressors: [
    { kind: 'asteroid', typeId: 62_622, typeName: 'Medium Asteroid Ore Compressor I' },
    { kind: 'gas', typeId: 62_624, typeName: 'Medium Gas Compressor I' },
  ],
  types: [
    { typeId: SPODUMAIN_TYPE_ID, compressible: true, status: 'on_site' },
    { typeId: ZEOLITES_TYPE_ID, compressible: false, status: 'no_hull_compressor' },
    { typeId: TYPE_ABSENT_FROM_SDE, compressible: null, status: 'unknown_ore_kind' },
  ],
  gaps: { noHull: ['moon'], notMounted: [] },
})

function minedLedger(overrides: Partial<MiningOperationLedger>): MiningOperationLedger {
  const brannHolt = ledgerCharacter('Brann Holt', {
    state: 'known',
    totalQuantity: 7_700,
    quantities: [
      { typeId: SPODUMAIN_TYPE_ID, typeName: 'Spodumain', quantity: 5_000, measuredQuantity: 5_000, correction: null },
      { typeId: ZEOLITES_TYPE_ID, typeName: 'Zeolites', quantity: 2_000, measuredQuantity: 2_000, correction: null },
      { typeId: TYPE_ABSENT_FROM_SDE, typeName: `Type ${TYPE_ABSENT_FROM_SDE}`, quantity: 700, measuredQuantity: 700, correction: null },
    ],
  })
  return ledger({
    status: 'closing',
    endedAt: ENDED_AT,
    participants: [participant('member-brann', 'Brann Holt', [brannHolt], { state: 'known', totalQuantity: 7_700 })],
    ...overrides,
  })
}

async function mountLedgerPage(operationLedger: MiningOperationLedger): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    const fleetStorageAnswer = answerWithoutFleetStorage(endpoint)
    if (fleetStorageAnswer !== undefined) return fleetStorageAnswer as never
    if (endpoint === MEMBERS_PATH) return MEMBERS_WITH_CYRA as never
    if (endpoint === LEDGER_PATH) return operationLedger as never
    if (endpoint === VALUATION_PATH) return e1Valuation() as never
    return startedSpodumainOperation({ status: 'closing', endedAt: ENDED_AT, participantsCount: 3, charactersCount: 5 }) as never
  })
  vi.spyOn(useSyncStore(), 'getMiningOperationEvent').mockReturnValue(null)
  const wrapper = mount(MiningOperationDetail, mountOptions('fr'))
  await flushPromises()
  return wrapper
}

function minedTypeRow(wrapper: VueWrapper, typeName: string): string {
  const row = wrapper.findAll('li').find(item => !item.find('li').exists() && item.text().includes(typeName))
  expect(row, `row of ${typeName}`).toBeDefined()
  return (row?.text() ?? '').toLowerCase()
}

function alertsAbout(wrapper: VueWrapper, phrase: string): string[] {
  return wrapper.findAll('[role="alert"]').map(alert => alert.text()).filter(text => text.includes(phrase))
}

describe('MiningOperationDetail, compression of the mined types', () => {
  it('badges each mined type: on site, none, unknown', async () => {
    const wrapper = await mountLedgerPage(minedLedger({ compression: PORPOISE_COMPRESSION }))

    const badges = ['Spodumain', 'Zeolites', `Type ${TYPE_ABSENT_FROM_SDE}`].map(typeName => {
      const row = minedTypeRow(wrapper, typeName)
      return ['sur place', 'aucun', 'inconnu'].filter(badge => row.includes(badge))
    })

    expect(badges).toEqual([['sur place'], ['aucun'], ['inconnu']])
  })

  it('warns in amber that the Porpoise cannot compress the moon ore and that the gross volume is hauled', async () => {
    const wrapper = await mountLedgerPage(minedLedger({ compression: PORPOISE_COMPRESSION }))

    const banners = alertsAbout(wrapper, 'ne peut pas compresser')

    expect(banners).toHaveLength(1)
    expect([banners[0]?.includes('Porpoise'), banners[0]?.includes('volume brut à transporter')]).toEqual([true, true])
  })

  it('warns that no compressor is mounted for the ice on an Orca with only an asteroid compressor', async () => {
    const orcaWithoutIceCompressor = compression({
      boostShipTypeId: ORCA_TYPE_ID,
      boostShipName: 'Orca',
      mountedCompressors: [{ kind: 'asteroid', typeId: 62_625, typeName: 'Large Asteroid Ore Compressor I' }],
      types: [{ typeId: 16_264, compressible: false, status: 'not_mounted' }],
      gaps: { noHull: [], notMounted: ['ice'] },
    })

    const wrapper = await mountLedgerPage(minedLedger({ compression: orcaWithoutIceCompressor }))

    expect([alertsAbout(wrapper, 'Aucun compresseur monté pour').length, alertsAbout(wrapper, 'ne peut pas compresser').length]).toEqual([1, 0])
  })

  it('shows no compression banner without a boost ship', async () => {
    const wrapper = await mountLedgerPage(minedLedger({ compression: compression({ types: [{ typeId: SPODUMAIN_TYPE_ID, compressible: null, status: 'no_boost_ship' }] }) }))

    expect([alertsAbout(wrapper, 'ne peut pas compresser').length, alertsAbout(wrapper, 'Aucun compresseur monté pour').length]).toEqual([0, 0])
  })
})

describe('MiningValuationSettings, boost ship and mounted compressors', () => {
  it('shows the declared hull and compressors and saves the compressors ticked afterwards', async () => {
    const declared = valuationSettings({ boostShipTypeId: ORCA_TYPE_ID, mountedCompressorKinds: ['asteroid'] })
    const operation: MiningOperation = spodumainOperation({ valuationSettings: declared })
    const valuation: MiningOperationValuation = e1Valuation({ status: 'planned', settings: declared })
    vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
      const fleetStorageAnswer = answerWithoutFleetStorage(endpoint)
      if (fleetStorageAnswer !== undefined) return fleetStorageAnswer as never
      if (endpoint === DETAIL_PATH) return operation as never
      if (endpoint === MEMBERS_PATH) return MEMBERS_WITH_CYRA as never
      if (endpoint === LEDGER_PATH) return ledger({ status: 'planned' }) as never
      if (endpoint === VALUATION_PATH) return valuation as never
      if (endpoint === PAYMENTS_PATH) return [] as never
      if (endpoint === REPROCESSING_PROFILE_PATH) return { configured: false, reprocessYield: null, oreProcessingSkills: [] } as never
      return operation as never
    })
    vi.spyOn(useSyncStore(), 'getMiningOperationEvent').mockReturnValue(null)
    const updateValuation = vi.spyOn(useMiningOperationStore(), 'updateValuation').mockResolvedValue(null)
    const wrapper = mount(MiningOperationDetail, mountOptions('en'))
    await flushPromises()

    const before = [boostShipRadio(wrapper, ORCA_TYPE_ID).element.checked, boostShipRadio(wrapper, PORPOISE_TYPE_ID).element.checked, compressorBoxes(wrapper)]
    await compressorBox(wrapper, 'ice').setValue(true)
    await wrapper.findAll('button').find(button => button.text().trim() === 'Save settings')?.trigger('click')
    await flushPromises()

    expect(before).toEqual([true, false, { asteroid: true, gas: false, ice: false, mercoxit: false, moon: false }])
    expect(updateValuation.mock.calls).toHaveLength(1)
    expect(updateValuation.mock.calls[0]?.[0]).toBe(OPERATION_O)
    expect(updateValuation.mock.calls[0]?.[1]).toMatchObject({ mountedCompressorKinds: ['asteroid', 'ice'] })
  })
})
