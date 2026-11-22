import { enableAutoUnmount, flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectInvention from '@/components/group-industry/ProjectInvention.vue'
import { apiRequest } from '@/services/api'
import { useGroupInventionStore } from '@/stores/group-industry/invention'
import type { ProjectInvention as ProjectInventionPayload, ProjectInventionOption, ProjectInventionProduct } from '@/stores/group-industry/types'
import { useAuthStore } from '@/stores/auth'
import type { Character } from '@/types'
import { deferred } from '../../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-vexor-navy-batch'
const PROJECT_PATH = `/industry/projects/${PROJECT_ID}`
const INVENTION_PATH = `${PROJECT_PATH}/invention`
const INVENTOR_CHARACTER_ID = '0192f5a0-7c1e-7d2a-9b4e-3a5c6d7e8fbb'
const VEXOR_NAVY_TYPE_ID = 17_843
const PROCESS_DECRYPTOR_TYPE_ID = 34_204
const SYMMETRY_DECRYPTOR_TYPE_ID = 34_208
const BLANK = /[\u00a0\u202f]/g

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
const frenchI18n = createI18n({ legacy: false, locale: 'fr', messages: { en, fr } })

function option(overrides: Partial<ProjectInventionOption>): ProjectInventionOption {
  return {
    decryptorTypeId: null,
    decryptorName: 'No decryptor',
    me: 2,
    te: 4,
    runs: 8,
    probability: 0.3,
    inventionCost: 1_400_000,
    totalProductionCost: 41_000_000,
    expectedAttempts: 16.6,
    manufacturingJobs: 4,
    decryptorCost: 0,
    costPerUnit: 820_000,
    costPerUnitDelta: 0,
    ...overrides,
  }
}

const NO_DECRYPTOR = option({})
const PROCESS = option({
  decryptorTypeId: PROCESS_DECRYPTOR_TYPE_ID,
  decryptorName: 'Process Decryptor',
  probability: 0.45,
  runs: 9,
  expectedAttempts: 11.1,
  decryptorCost: 700_000,
  inventionCost: 1_050_000,
  totalProductionCost: 39_500_000,
  costPerUnit: 790_000,
  costPerUnitDelta: -30_000,
})
const SYMMETRY = option({
  decryptorTypeId: SYMMETRY_DECRYPTOR_TYPE_ID,
  decryptorName: 'Symmetry Decryptor',
  probability: 0.27,
  runs: 12,
  expectedAttempts: 18.5,
  manufacturingJobs: 3,
  decryptorCost: 1_900_000,
  inventionCost: 2_700_000,
  totalProductionCost: 44_000_000,
  costPerUnit: 880_000,
  costPerUnitDelta: 60_000,
})
const SYMMETRY_WITH_UNKNOWN_COST = option({
  decryptorTypeId: SYMMETRY_DECRYPTOR_TYPE_ID,
  decryptorName: 'Symmetry Decryptor',
  probability: 0.27,
  runs: 12,
  expectedAttempts: 18.5,
  decryptorCost: null,
  inventionCost: null,
  totalProductionCost: null,
  costPerUnit: null,
  costPerUnitDelta: null,
})

function product(selectedDecryptorTypeId: number | null, options: ProjectInventionOption[] = [NO_DECRYPTOR, PROCESS, SYMMETRY]): ProjectInventionProduct {
  return {
    typeId: VEXOR_NAVY_TYPE_ID,
    typeName: 'Vexor Navy Issue',
    runs: 30,
    me: 2,
    te: 4,
    selectedDecryptorTypeId,
    invention: {
      baseProbability: 0.3,
      unknownReason: null,
      datacores: ['Datacore - Mechanical Engineering', 'Datacore - Gallentean Starship Engineering'],
      datacoresCostPerAttempt: 66_000,
      inventor: null,
      options,
    },
  }
}

function payload(products: ProjectInventionProduct[]): ProjectInventionPayload {
  return { id: PROJECT_ID, products }
}

function inventor(): Character {
  return {
    id: INVENTOR_CHARACTER_ID,
    eveCharacterId: 2_113_000_001,
    name: 'Pilot Vexor',
    corporationId: 98_000_011,
    corporationName: 'Navy Issue Works',
    allianceId: null,
    allianceName: null,
    isMain: true,
    hasValidToken: true,
    lastSyncAt: null,
  }
}

function setupAuthenticatedUser(): void {
  setActivePinia(createPinia())
  const character = inventor()
  useAuthStore().user = { id: 'user-2', authStatus: 'authenticated', mainCharacter: character, characters: [character], corporationId: 98_000_011, corporationName: 'Navy Issue Works' }
}

function answerRequests(answers: { selectedAfterReload: number | null, initial: ProjectInventionProduct, patch?: () => Promise<unknown> }): void {
  vi.mocked(apiRequest).mockImplementation((_endpoint: string, options?: RequestInit) => {
    if (options?.method === 'PATCH') return (answers.patch ?? (() => Promise.resolve({})))()
    const reloaded = vi.mocked(apiRequest).mock.calls.some(([, callOptions]) => callOptions?.method === 'PATCH')
    return Promise.resolve(payload([reloaded ? product(answers.selectedAfterReload, answers.initial.invention.options) : answers.initial]))
  })
}

async function mountInvention(canEdit: boolean, answers: Parameters<typeof answerRequests>[0], plugin = i18n) {
  answerRequests(answers)
  setupAuthenticatedUser()
  const wrapper = mount(ProjectInvention, { props: { projectId: PROJECT_ID, canEdit }, global: { plugins: [plugin] } })
  await flushPromises()
  return wrapper
}

function requests(): Array<[string, string, string | null]> {
  return vi.mocked(apiRequest).mock.calls.map(([endpoint, options]) => [options?.method ?? 'GET', String(endpoint), typeof options?.body === 'string' ? options.body : null])
}

function useButton(wrapper: VueWrapper, optionKey: number | 'none') {
  return wrapper.find(`[data-testid="invention-use-${VEXOR_NAVY_TYPE_ID}-${optionKey}"]`)
}

function textOf(element: DOMWrapper<Element>): string {
  return element.exists() ? element.text() : 'absent'
}

async function clickUse(wrapper: VueWrapper, optionKey: number | 'none'): Promise<void> {
  const button = useButton(wrapper, optionKey)
  if (button.exists()) await button.trigger('click')
}

function usedBadge(wrapper: VueWrapper, optionKey: number | 'none') {
  return wrapper.find(`[data-testid="invention-used-${VEXOR_NAVY_TYPE_ID}-${optionKey}"]`)
}

function allUseButtons(wrapper: VueWrapper) {
  return wrapper.findAll('[data-testid^="invention-use-"]')
}

function allUsedBadges(wrapper: VueWrapper) {
  return wrapper.findAll('[data-testid^="invention-used-"]')
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ProjectInvention applying a decryptor to the project', () => {
  it('shows no use button without edit rights but still marks the registered decryptor as used in the plan', async () => {
    const wrapper = await mountInvention(false, { initial: product(PROCESS_DECRYPTOR_TYPE_ID), selectedAfterReload: PROCESS_DECRYPTOR_TYPE_ID })

    expect({
      useButtons: allUseButtons(wrapper).length,
      badgeRows: allUsedBadges(wrapper).map(badge => badge.attributes('data-testid')),
      badgeText: textOf(usedBadge(wrapper, PROCESS_DECRYPTOR_TYPE_ID)),
    }).toEqual({
      useButtons: 0,
      badgeRows: [`invention-used-${VEXOR_NAVY_TYPE_ID}-${PROCESS_DECRYPTOR_TYPE_ID}`],
      badgeText: en.groupIndustry.invention.usedInPlan,
    })
  })

  it('puts the used in the plan badge on the registered decryptor only and offers the use button on every other row', async () => {
    const wrapper = await mountInvention(true, { initial: product(PROCESS_DECRYPTOR_TYPE_ID), selectedAfterReload: PROCESS_DECRYPTOR_TYPE_ID })

    expect({
      badgeRows: allUsedBadges(wrapper).map(badge => badge.attributes('data-testid')),
      badgeText: textOf(usedBadge(wrapper, PROCESS_DECRYPTOR_TYPE_ID)),
      buttonRows: allUseButtons(wrapper).map(button => button.attributes('data-testid')),
      symmetryButtonText: textOf(useButton(wrapper, SYMMETRY_DECRYPTOR_TYPE_ID)),
      noDecryptorButtonText: textOf(useButton(wrapper, 'none')),
    }).toEqual({
      badgeRows: [`invention-used-${VEXOR_NAVY_TYPE_ID}-${PROCESS_DECRYPTOR_TYPE_ID}`],
      badgeText: en.groupIndustry.invention.usedInPlan,
      buttonRows: [`invention-use-${VEXOR_NAVY_TYPE_ID}-none`, `invention-use-${VEXOR_NAVY_TYPE_ID}-${SYMMETRY_DECRYPTOR_TYPE_ID}`],
      symmetryButtonText: en.groupIndustry.invention.useDecryptor,
      noDecryptorButtonText: en.groupIndustry.invention.useNoDecryptor,
    })
  })

  it('puts the badge on the no decryptor row when no choice is registered', async () => {
    const wrapper = await mountInvention(true, { initial: product(null), selectedAfterReload: null })

    expect({
      badgeRows: allUsedBadges(wrapper).map(badge => badge.attributes('data-testid')),
      badgeText: textOf(usedBadge(wrapper, 'none')),
      buttonRows: allUseButtons(wrapper).map(button => button.attributes('data-testid')),
      processButtonText: textOf(useButton(wrapper, PROCESS_DECRYPTOR_TYPE_ID)),
    }).toEqual({
      badgeRows: [`invention-used-${VEXOR_NAVY_TYPE_ID}-none`],
      badgeText: en.groupIndustry.invention.usedInPlan,
      buttonRows: [`invention-use-${VEXOR_NAVY_TYPE_ID}-${PROCESS_DECRYPTOR_TYPE_ID}`, `invention-use-${VEXOR_NAVY_TYPE_ID}-${SYMMETRY_DECRYPTOR_TYPE_ID}`],
      processButtonText: en.groupIndustry.invention.useDecryptor,
    })
  })

  it('sends the decryptor type id keyed by the product type id when a decryptor is chosen', async () => {
    const wrapper = await mountInvention(true, { initial: product(null), selectedAfterReload: PROCESS_DECRYPTOR_TYPE_ID })

    await clickUse(wrapper, PROCESS_DECRYPTOR_TYPE_ID)
    await flushPromises()

    expect(requests().filter(([method]) => method === 'PATCH')).toEqual([
      ['PATCH', PROJECT_PATH, JSON.stringify({ inventionDecryptors: { [VEXOR_NAVY_TYPE_ID]: PROCESS_DECRYPTOR_TYPE_ID } })],
    ])
  })

  it('sends null for the product when the no decryptor row is chosen', async () => {
    const wrapper = await mountInvention(true, { initial: product(SYMMETRY_DECRYPTOR_TYPE_ID), selectedAfterReload: null })

    await clickUse(wrapper, 'none')
    await flushPromises()

    expect(requests().filter(([method]) => method === 'PATCH')).toEqual([
      ['PATCH', PROJECT_PATH, JSON.stringify({ inventionDecryptors: { [VEXOR_NAVY_TYPE_ID]: null } })],
    ])
  })

  it('reads the comparison again with the selected inventor after the patch, moves the badge to the new choice and emits applied once', async () => {
    const wrapper = await mountInvention(true, { initial: product(null), selectedAfterReload: SYMMETRY_DECRYPTOR_TYPE_ID })

    await clickUse(wrapper, SYMMETRY_DECRYPTOR_TYPE_ID)
    await flushPromises()

    expect({
      requests: requests().map(([method, endpoint]) => `${method} ${endpoint}`),
      applied: wrapper.emitted('applied')?.length ?? 0,
      badgeRows: allUsedBadges(wrapper).map(badge => badge.attributes('data-testid')),
    }).toEqual({
      requests: [
        `GET ${INVENTION_PATH}?inventorCharacterId=${INVENTOR_CHARACTER_ID}`,
        `PATCH ${PROJECT_PATH}`,
        `GET ${INVENTION_PATH}?inventorCharacterId=${INVENTOR_CHARACTER_ID}`,
      ],
      applied: 1,
      badgeRows: [`invention-used-${VEXOR_NAVY_TYPE_ID}-${SYMMETRY_DECRYPTOR_TYPE_ID}`],
    })
  })

  it('shows the message of the failed patch in an alert, emits no applied and keeps the registered choice displayed', async () => {
    const wrapper = await mountInvention(true, {
      initial: product(PROCESS_DECRYPTOR_TYPE_ID),
      selectedAfterReload: SYMMETRY_DECRYPTOR_TYPE_ID,
      patch: () => Promise.reject(new Error('The invention decryptor of a completed project cannot be changed')),
    })

    await clickUse(wrapper, SYMMETRY_DECRYPTOR_TYPE_ID)
    await flushPromises()

    const alert = wrapper.find('[data-testid="invention-apply-error"]')
    expect({
      alertRole: alert.exists() ? alert.attributes('role') : 'absent',
      alertText: textOf(alert),
      applied: wrapper.emitted('applied')?.length ?? 0,
      badgeRows: allUsedBadges(wrapper).map(badge => badge.attributes('data-testid')),
      invention: requests().filter(([method, endpoint]) => method === 'GET' && endpoint.startsWith(INVENTION_PATH)).length,
    }).toEqual({
      alertRole: 'alert',
      alertText: expect.stringContaining('The invention decryptor of a completed project cannot be changed'),
      applied: 0,
      badgeRows: [`invention-used-${VEXOR_NAVY_TYPE_ID}-${PROCESS_DECRYPTOR_TYPE_ID}`],
      invention: 1,
    })
  })

  it('disables the use buttons while the patch is running and sends one request only', async () => {
    const pendingPatch = deferred<unknown>()
    const wrapper = await mountInvention(true, { initial: product(null), selectedAfterReload: PROCESS_DECRYPTOR_TYPE_ID, patch: () => pendingPatch.promise })

    await clickUse(wrapper, PROCESS_DECRYPTOR_TYPE_ID)
    await flushPromises()
    await clickUse(wrapper, SYMMETRY_DECRYPTOR_TYPE_ID)
    await flushPromises()

    const whilePending = {
      disabled: allUseButtons(wrapper).map(button => button.attributes('disabled') !== undefined),
      patches: requests().filter(([method]) => method === 'PATCH').length,
    }
    pendingPatch.resolve({})
    await flushPromises()

    expect(whilePending).toEqual({ disabled: [true, true], patches: 1 })
  })

  it('lets the user choose an option whose cost per unit is unknown and still sends the decryptor', async () => {
    const wrapper = await mountInvention(true, { initial: product(null, [NO_DECRYPTOR, PROCESS, SYMMETRY_WITH_UNKNOWN_COST]), selectedAfterReload: SYMMETRY_DECRYPTOR_TYPE_ID })

    const button = useButton(wrapper, SYMMETRY_DECRYPTOR_TYPE_ID)
    const enabledBeforeClick = button.exists() && button.attributes('disabled') === undefined
    await clickUse(wrapper, SYMMETRY_DECRYPTOR_TYPE_ID)
    await flushPromises()

    expect({
      enabledBeforeClick,
      patches: requests().filter(([method]) => method === 'PATCH'),
    }).toEqual({
      enabledBeforeClick: true,
      patches: [['PATCH', PROJECT_PATH, JSON.stringify({ inventionDecryptors: { [VEXOR_NAVY_TYPE_ID]: SYMMETRY_DECRYPTOR_TYPE_ID } })]],
    })
  })

  it('shows the English footnote of the translation file and no longer says the tab is read only', async () => {
    const wrapper = await mountInvention(true, { initial: product(null), selectedAfterReload: null })

    const paragraphs = wrapper.findAll('p').map(paragraph => paragraph.text().replace(BLANK, ' '))
    expect({
      footnoteShown: paragraphs.includes(en.groupIndustry.invention.footnote.replace(BLANK, ' ')),
      readOnlyWording: wrapper.text().includes('Read only'),
      newWording: paragraphs.some(paragraph => paragraph.includes('saved on the project')),
    }).toEqual({ footnoteShown: true, readOnlyWording: false, newWording: true })
  })

  it('shows the French footnote of the translation file and no longer says the tab is read only', async () => {
    const wrapper = await mountInvention(true, { initial: product(null), selectedAfterReload: null }, frenchI18n)

    const paragraphs = wrapper.findAll('p').map(paragraph => paragraph.text().replace(BLANK, ' '))
    expect({
      footnoteShown: paragraphs.includes(fr.groupIndustry.invention.footnote.replace(BLANK, ' ')),
      readOnlyWording: wrapper.text().includes('Lecture seule'),
      newWording: paragraphs.some(paragraph => paragraph.includes('enregistré sur le projet')),
    }).toEqual({ footnoteShown: true, readOnlyWording: false, newWording: true })
  })

  it('has the labels of the apply action in English and in French', () => {
    const invention = (messages: typeof en | typeof fr): Record<string, unknown> => messages.groupIndustry.invention
    const keys = ['useDecryptor', 'useNoDecryptor', 'usedInPlan', 'applyError']
    expect({
      english: keys.map(key => typeof invention(en)[key]),
      french: keys.map(key => typeof invention(fr)[key]),
    }).toEqual({
      english: ['string', 'string', 'string', 'string'],
      french: ['string', 'string', 'string', 'string'],
    })
  })
})

describe('useGroupInventionStore applyDecryptor', () => {
  it('patches the project with the decryptor of the product then reads the comparison again', async () => {
    setActivePinia(createPinia())
    vi.mocked(apiRequest).mockResolvedValue(payload([product(PROCESS_DECRYPTOR_TYPE_ID)]))
    const store = useGroupInventionStore()

    await store.applyDecryptor(PROJECT_ID, VEXOR_NAVY_TYPE_ID, PROCESS_DECRYPTOR_TYPE_ID)

    expect({
      requests: requests().map(([method, endpoint, body]) => [method, endpoint.split('?')[0], body]),
      selected: store.products?.map(entry => entry.selectedDecryptorTypeId),
    }).toEqual({
      requests: [
        ['PATCH', PROJECT_PATH, JSON.stringify({ inventionDecryptors: { [VEXOR_NAVY_TYPE_ID]: PROCESS_DECRYPTOR_TYPE_ID } })],
        ['GET', INVENTION_PATH, null],
      ],
      selected: [PROCESS_DECRYPTOR_TYPE_ID],
    })
  })

  it('forgets the error of a failed application once the next one succeeds', async () => {
    setActivePinia(createPinia())
    vi.mocked(apiRequest).mockRejectedValueOnce(new Error('Refused.')).mockResolvedValue(payload([product(PROCESS_DECRYPTOR_TYPE_ID)]))
    const store = useGroupInventionStore()

    const failed = await store.applyDecryptor(PROJECT_ID, VEXOR_NAVY_TYPE_ID, PROCESS_DECRYPTOR_TYPE_ID)
    const errorAfterFailure = store.applyError
    const applied = await store.applyDecryptor(PROJECT_ID, VEXOR_NAVY_TYPE_ID, PROCESS_DECRYPTOR_TYPE_ID)

    expect({ failed, errorAfterFailure, applied, errorAfterSuccess: store.applyError }).toEqual({
      failed: false,
      errorAfterFailure: 'Refused.',
      applied: true,
      errorAfterSuccess: null,
    })
  })
})
