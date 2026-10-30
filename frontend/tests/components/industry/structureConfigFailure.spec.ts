import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import StructureConfig from '@/components/industry/StructureConfig.vue'
import { ApiError, apiRequest } from '@/services/api'
import { useStructuresStore } from '@/stores/industry/structures'
import type { StructureConfig as StructureConfigRecord } from '@/stores/industry/types'
import { apiOutcomesByPath, awaiting, deferred, rejectWith, respondWith } from '../../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

type Locale = 'en' | 'fr'

const STRUCTURES_PATH = '/industry/structures'
const CORPORATION_STRUCTURES_PATH = '/industry/corporation-structures'
const SOTIYO_HEK_LOCATION_ID = 1_035_000_000_042
const LOAD_ERROR = { en: 'Your structures could not be loaded.', fr: 'Vos structures n\'ont pas pu être chargées.' } as const
const SAVE_ERROR = { en: 'The structure was not saved.', fr: 'La structure n\'a pas été enregistrée.' } as const
const DELETE_ERROR_EN = 'The structure was not deleted.'
const RIG_OPTIONS = { manufacturing: [], reaction: [] }

const RAITARU_PERIMETER: StructureConfigRecord = {
  id: 'struct-1',
  name: 'Raitaru Perimeter',
  locationId: 1_035_000_000_011,
  solarSystemId: 30_000_144,
  securityType: 'highsec',
  structureType: 'raitaru',
  rigs: [],
  isDefault: false,
  isCorporationStructure: false,
  manufacturingMaterialBonus: 1,
  reactionMaterialBonus: 0,
  manufacturingTimeBonus: 15,
  reactionTimeBonus: 0,
  facilityTaxRate: 0.5,
  createdAt: '2026-10-20T09:00:00+00:00',
}

const SOTIYO_HEK: StructureConfigRecord = {
  ...RAITARU_PERIMETER,
  id: 'struct-2',
  name: 'Sotiyo Hek',
  locationId: SOTIYO_HEK_LOCATION_ID,
  solarSystemId: 30_002_053,
  securityType: 'lowsec',
  structureType: 'sotiyo',
  manufacturingMaterialBonus: 1,
  manufacturingTimeBonus: 30,
  facilityTaxRate: 1.25,
  createdAt: '2026-10-22T14:30:00+00:00',
}

const reads = apiOutcomesByPath()
const writes = apiOutcomesByPath()
let requestedPaths: string[] = []
let handlerErrors: unknown[] = []

async function mountStructureConfig(locale: Locale = 'en'): Promise<VueWrapper> {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(StructureConfig, {
    global: {
      plugins: [i18n],
      stubs: { teleport: true, FavoriteSystemsConfig: true },
      config: { errorHandler: error => { handlerErrors.push(error) } },
    },
  })
  await flushPromises()
  return wrapper
}

async function clickButton(wrapper: VueWrapper, label: string): Promise<void> {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === label)
  expect(button, `button ${label}`).toBeDefined()
  await button!.trigger('click')
  await flushPromises()
}

async function openDeleteModalOfRaitaruPerimeter(wrapper: VueWrapper): Promise<void> {
  await wrapper.get(`button[aria-label="${en.common.actions.delete}"]`).trigger('click')
  await flushPromises()
}

function deleteModalOpen(wrapper: VueWrapper): boolean {
  return wrapper.find('[role="dialog"]').exists()
}

function alertText(wrapper: VueWrapper, testId: string): string | null {
  const alert = wrapper.find(`[data-testid="${testId}"]`)
  return alert.exists() ? alert.text() : null
}

function listedStructureNames(wrapper: VueWrapper): string[] {
  return wrapper.findAll('h4').map(title => title.text())
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  requestedPaths = []
  handlerErrors = []
  reads.clear()
  writes.clear()
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    requestedPaths.push(endpoint)
    if (endpoint === CORPORATION_STRUCTURES_PATH) return { structures: [] }
    if (endpoint === `${STRUCTURES_PATH}/bonus-preview`) return {}
    const answer = options?.method ? writes.answer(`${options.method} ${endpoint}`) : reads.answer(endpoint)
    if (answer === undefined) throw new Error(`Unexpected request ${options?.method ?? 'GET'} ${endpoint}`)
    return answer
  })
})

describe('StructureConfig load failure (issue 189)', () => {
  it.each([
    ['en', LOAD_ERROR.en, en.industry.structures.noStructures],
    ['fr', LOAD_ERROR.fr, fr.industry.structures.noStructures],
  ] as const)('shows the load failure instead of claiming no structure is configured (%s)', async (locale, loadError, noStructures) => {
    reads.respondTo(STRUCTURES_PATH, rejectWith(new ApiError('API error: 503', 503)))

    const wrapper = await mountStructureConfig(locale)

    const alert = wrapper.find('[data-testid="structures-load-error"]')
    expect({
      loadError: alert.exists() ? alert.text() : null,
      role: alert.exists() ? alert.attributes('role') : null,
      red: alert.exists() ? alert.classes('text-red-400') : null,
      noStructuresShown: wrapper.text().includes(noStructures),
      corporationStructuresRequested: requestedPaths.includes(CORPORATION_STRUCTURES_PATH),
      handlerErrors,
    }).toEqual({ loadError, role: 'alert', red: true, noStructuresShown: false, corporationStructuresRequested: true, handlerErrors: [] })
  })

  it('shows the load failure above the structures already loaded and keeps them listed', async () => {
    useStructuresStore().structures = [RAITARU_PERIMETER]
    reads.respondTo(STRUCTURES_PATH, rejectWith(new ApiError('API error: 503', 503)))

    const wrapper = await mountStructureConfig()

    expect({ loadError: alertText(wrapper, 'structures-load-error'), listed: listedStructureNames(wrapper) })
      .toEqual({ loadError: LOAD_ERROR.en, listed: ['Raitaru Perimeter'] })
  })
})

describe('StructureConfig delete failure (issue 189)', () => {
  async function mountWithAFailedDeletionOfRaitaruPerimeter(): Promise<VueWrapper> {
    reads.respondTo(STRUCTURES_PATH, respondWith({ structures: [RAITARU_PERIMETER], rigOptions: RIG_OPTIONS }))
    writes.respondTo(`DELETE ${STRUCTURES_PATH}/struct-1`, rejectWith(new ApiError('API error: 500', 500)))
    const wrapper = await mountStructureConfig()
    await openDeleteModalOfRaitaruPerimeter(wrapper)
    await clickButton(wrapper, en.common.actions.delete)
    return wrapper
  }

  it('keeps the modal open with the delete failure and the structure listed, without an unhandled error', async () => {
    const wrapper = await mountWithAFailedDeletionOfRaitaruPerimeter()

    const alert = wrapper.find('[data-testid="structure-delete-error"]')
    expect({
      modalOpen: deleteModalOpen(wrapper),
      deleteError: alert.exists() ? alert.text() : null,
      role: alert.exists() ? alert.attributes('role') : null,
      red: alert.exists() ? alert.classes('text-red-400') : null,
      listed: listedStructureNames(wrapper),
      handlerErrors,
    }).toEqual({
      modalOpen: true,
      deleteError: DELETE_ERROR_EN,
      role: 'alert',
      red: true,
      listed: ['Raitaru Perimeter'],
      handlerErrors: [],
    })
  })

  it('shows the delete failure inside the delete modal, under the confirmation text', async () => {
    const wrapper = await mountWithAFailedDeletionOfRaitaruPerimeter()

    const modalParagraphs = wrapper.get('[role="dialog"]').findAll('p').map(paragraph => paragraph.text())

    expect({ deleteErrorInModal: wrapper.get('[role="dialog"] [data-testid="structure-delete-error"]').text(), modalParagraphs })
      .toEqual({
        deleteErrorInModal: DELETE_ERROR_EN,
        modalParagraphs: ['Are you sure you want to delete Raitaru Perimeter?', DELETE_ERROR_EN],
      })
  })

  it('closes the modal on cancel and shows no delete failure when it is opened again', async () => {
    const wrapper = await mountWithAFailedDeletionOfRaitaruPerimeter()

    await clickButton(wrapper, en.common.actions.cancel)
    const modalOpenAfterCancel = deleteModalOpen(wrapper)
    await openDeleteModalOfRaitaruPerimeter(wrapper)

    expect({ modalOpenAfterCancel, deleteError: alertText(wrapper, 'structure-delete-error') })
      .toEqual({ modalOpenAfterCancel: false, deleteError: null })
  })

  it('clears the delete failure while retrying and closes the modal once the structure is deleted', async () => {
    const wrapper = await mountWithAFailedDeletionOfRaitaruPerimeter()
    const retriedDeletion = deferred<null>()
    writes.respondTo(`DELETE ${STRUCTURES_PATH}/struct-1`, awaiting(retriedDeletion))

    await clickButton(wrapper, en.common.actions.delete)
    const deleteErrorWhileRetrying = alertText(wrapper, 'structure-delete-error')
    retriedDeletion.resolve(null)
    await flushPromises()

    expect({ deleteErrorWhileRetrying, modalOpen: deleteModalOpen(wrapper), listed: listedStructureNames(wrapper) })
      .toEqual({ deleteErrorWhileRetrying: null, modalOpen: false, listed: [] })
  })
})

describe('StructureConfig save failure (issue 189)', () => {
  it.each([
    ['en', SAVE_ERROR.en, en.common.actions.add, en.industry.structures.namePlaceholder],
    ['fr', SAVE_ERROR.fr, fr.common.actions.add, fr.industry.structures.namePlaceholder],
  ] as const)('keeps the form open with the typed name and shows the save failure (%s)', async (locale, saveError, addLabel, namePlaceholder) => {
    reads.respondTo(STRUCTURES_PATH, respondWith({ structures: [], rigOptions: RIG_OPTIONS }))
    writes.respondTo(`POST ${STRUCTURES_PATH}`, rejectWith(new ApiError('API error: 500', 500)))
    const wrapper = await mountStructureConfig(locale)
    await clickButton(wrapper, addLabel)
    await wrapper.get(`input[placeholder="${namePlaceholder}"]`).setValue('Azbel Kador')

    await clickButton(wrapper, addLabel)

    const nameInput = wrapper.find(`input[placeholder="${namePlaceholder}"]`)
    const alert = wrapper.find('[data-testid="structure-save-error"]')
    expect({
      typedName: nameInput.exists() ? (nameInput.element as HTMLInputElement).value : null,
      saveError: alert.exists() ? alert.text() : null,
      role: alert.exists() ? alert.attributes('role') : null,
      handlerErrors,
    }).toEqual({ typedName: 'Azbel Kador', saveError, role: 'alert', handlerErrors: [] })
  })

  it.each([
    ['en', SAVE_ERROR.en, en.common.actions.edit, en.common.actions.save, en.industry.structures.namePlaceholder],
    ['fr', SAVE_ERROR.fr, fr.common.actions.edit, fr.common.actions.save, fr.industry.structures.namePlaceholder],
  ] as const)('keeps the edit form open with the typed name and the structure unchanged when the edit fails (%s)', async (locale, saveError, editLabel, saveLabel, namePlaceholder) => {
    reads.respondTo(STRUCTURES_PATH, respondWith({ structures: [RAITARU_PERIMETER], rigOptions: RIG_OPTIONS }))
    writes.respondTo(`PATCH ${STRUCTURES_PATH}/struct-1`, rejectWith(new ApiError('API error: 500', 500)))
    const wrapper = await mountStructureConfig(locale)
    await wrapper.get(`button[aria-label="${editLabel}"]`).trigger('click')
    await flushPromises()
    await wrapper.get(`input[placeholder="${namePlaceholder}"]`).setValue('Raitaru Perimeter II')

    await clickButton(wrapper, saveLabel)
    await vi.waitFor(() => expect(alertText(wrapper, 'structure-save-error')).toBe(saveError))

    const nameInput = wrapper.find(`input[placeholder="${namePlaceholder}"]`)
    const patchBodies = vi.mocked(apiRequest).mock.calls
      .filter(([, options]) => options?.method === 'PATCH')
      .map(([endpoint, options]) => [endpoint, JSON.parse(String(options?.body))])
    expect({
      typedName: nameInput.exists() ? (nameInput.element as HTMLInputElement).value : null,
      saveError: alertText(wrapper, 'structure-save-error'),
      sent: patchBodies,
      structures: useStructuresStore().structures,
      handlerErrors,
    }).toEqual({
      typedName: 'Raitaru Perimeter II',
      saveError,
      sent: [[`${STRUCTURES_PATH}/struct-1`, { name: 'Raitaru Perimeter II', securityType: 'highsec', structureType: 'raitaru', rigs: [] }]],
      structures: [RAITARU_PERIMETER],
      handlerErrors: [],
    })
  })

  it('names the structure already imported when the server answers a conflict', async () => {
    reads.respondTo(STRUCTURES_PATH, respondWith({ structures: [SOTIYO_HEK], rigOptions: RIG_OPTIONS }))
    writes.respondTo(`POST ${STRUCTURES_PATH}`, rejectWith(new ApiError('Conflict', 409)))
    const wrapper = await mountStructureConfig()
    await clickButton(wrapper, en.common.actions.add)
    Object.assign(wrapper.vm, { formName: 'Sotiyo Hek (import)', formLocationId: SOTIYO_HEK_LOCATION_ID })
    await flushPromises()

    await clickButton(wrapper, en.common.actions.add)

    expect(alertText(wrapper, 'structure-save-error'))
      .toBe('This structure is already imported (Sotiyo Hek). Edit it from the list instead.')
  })
})
