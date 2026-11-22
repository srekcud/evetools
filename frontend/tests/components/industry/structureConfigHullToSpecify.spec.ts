import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import StructureConfig from '@/components/industry/StructureConfig.vue'
import { ApiError, apiRequest } from '@/services/api'
import type { StructureConfig as StructureConfigRecord } from '@/stores/industry/types'
import { apiOutcomesByPath, rejectWith, respondWith } from '../../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

type Locale = 'en' | 'fr'

const STRUCTURES_PATH = '/industry/structures'
const CORPORATION_STRUCTURES_PATH = '/industry/corporation-structures'
const RIG_OPTIONS = { manufacturing: [], reaction: [] }
const PREVIEW_DEBOUNCE_MS = 300
const SERVER_REFUSAL = 'The rigs fitted are not of the size of this hull'
const WARNING_TEXTS = {
  en: { title: 'Hull to specify', hint: 'The role bonus depends on the hull: pick it to find out' },
  fr: { title: 'Coque à préciser', hint: 'Le bonus de rôle dépend de la coque : choisis-la pour le connaître' },
} as const

const LEGACY_COMPLEX: StructureConfigRecord = {
  id: 'struct-legacy-complex',
  name: 'Perimeter Complex',
  locationId: 1_035_000_000_021,
  solarSystemId: 30_000_144,
  securityType: 'nullsec',
  structureType: 'engineering_complex',
  rigs: ['Standup M-Set Ammunition Manufacturing Material Efficiency II'],
  isDefault: false,
  isCorporationStructure: false,
  manufacturingMaterialBonus: 5.04,
  reactionMaterialBonus: 0,
  manufacturingTimeBonus: null,
  reactionTimeBonus: null,
  hullToSpecify: true,
  facilityTaxRate: 1.5,
  createdAt: '2026-09-02T09:00:00+00:00',
}

const LEGACY_REFINERY: StructureConfigRecord = {
  ...LEGACY_COMPLEX,
  id: 'struct-legacy-refinery',
  name: 'Hek Refinery',
  locationId: 1_035_000_000_022,
  structureType: 'refinery',
  rigs: [],
  manufacturingMaterialBonus: 0,
}

const AZBEL_KADOR: StructureConfigRecord = {
  ...LEGACY_COMPLEX,
  id: 'struct-azbel',
  name: 'Azbel Kador',
  locationId: 1_035_000_000_023,
  structureType: 'azbel',
  manufacturingTimeBonus: 30,
  reactionTimeBonus: 0,
  hullToSpecify: false,
}

const reads = apiOutcomesByPath()
const writes = apiOutcomesByPath()
let bonusPreviewAnswer: unknown = {}

function warningOf(wrapper: VueWrapper, structure: StructureConfigRecord) {
  return wrapper.find(`[data-testid="hull-to-specify-warning-${structure.id}"]`)
}

function cardOf(wrapper: VueWrapper, structure: StructureConfigRecord): string {
  const card = wrapper.findAll('h4').find(title => title.text() === structure.name)?.element.parentElement?.parentElement
  return card?.textContent?.replace(/\s+/g, ' ') ?? 'absent'
}

function hullChoicesOf(wrapper: VueWrapper, structure: StructureConfigRecord): string[] {
  const select = wrapper.find(`[data-testid="hull-to-specify-select-${structure.id}"]`)
  if (!select.exists()) return ['select-absent']
  return select.findAll('option').map(option => (option.element as HTMLOptionElement).value).filter(value => value !== '')
}

function patchesSent() {
  return vi.mocked(apiRequest).mock.calls.filter(([, options]) => options?.method === 'PATCH')
}

async function mountStructureConfigListing(structures: StructureConfigRecord[], locale: Locale = 'en'): Promise<VueWrapper> {
  reads.respondTo(STRUCTURES_PATH, respondWith({ structures, rigOptions: RIG_OPTIONS }))
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(StructureConfig, {
    global: {
      plugins: [i18n],
      stubs: { teleport: true, FavoriteSystemsConfig: true },
    },
  })
  await flushPromises()
  return wrapper
}

async function pickHullAndApply(wrapper: VueWrapper, structure: StructureConfigRecord, hull: string): Promise<void> {
  const select = wrapper.find(`[data-testid="hull-to-specify-select-${structure.id}"]`)
  if (select.exists()) await select.setValue(hull)
  const apply = wrapper.find(`[data-testid="hull-to-specify-apply-${structure.id}"]`)
  if (apply.exists()) await apply.trigger('click')
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  reads.clear()
  writes.clear()
  bonusPreviewAnswer = {}
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (endpoint === CORPORATION_STRUCTURES_PATH) return { structures: [] }
    if (endpoint === `${STRUCTURES_PATH}/bonus-preview`) return bonusPreviewAnswer
    const answer = options?.method ? writes.answer(`${options.method} ${endpoint}`) : reads.answer(endpoint)
    if (answer === undefined) throw new Error(`Unexpected request ${options?.method ?? 'GET'} ${endpoint}`)
    return answer
  })
})

afterEach(() => {
  vi.useRealTimers()
})

describe('StructureConfig list with a structure whose hull is to specify (issue 1343)', () => {
  it.each(['en', 'fr'] as const)('warns on the card of a legacy type structure with the hull to specify and the reason (%s)', async locale => {
    const wrapper = await mountStructureConfigListing([LEGACY_COMPLEX], locale)

    const warning = warningOf(wrapper, LEGACY_COMPLEX)
    expect({
      shown: warning.exists(),
      title: warning.exists() ? warning.text().includes(WARNING_TEXTS[locale].title) : null,
      hint: warning.exists() ? warning.text().includes(WARNING_TEXTS[locale].hint) : null,
      amber: warning.exists() ? warning.html().includes('text-amber-400') : null,
    }).toEqual({ shown: true, title: true, hint: true, amber: true })
  })

  it('shows no time bonus, neither null nor zero, on the card but keeps the material bonus of the rigs', async () => {
    const wrapper = await mountStructureConfigListing([LEGACY_COMPLEX, LEGACY_REFINERY])

    const complexCard = cardOf(wrapper, LEGACY_COMPLEX)
    const refineryCard = cardOf(wrapper, LEGACY_REFINERY)
    expect({
      complexNull: complexCard.includes('null'),
      complexTimeLine: complexCard.includes('TE Manuf') || complexCard.includes('TE Reactions'),
      complexZeroPercent: /-0\s?%/.test(complexCard),
      complexMaterial: complexCard.includes('-5.04%'),
      refineryNull: refineryCard.includes('null'),
      refineryTimeLine: refineryCard.includes('TE Manuf') || refineryCard.includes('TE Reactions'),
      refineryZeroPercent: /-0\s?%/.test(refineryCard),
    }).toEqual({
      complexNull: false,
      complexTimeLine: false,
      complexZeroPercent: false,
      complexMaterial: true,
      refineryNull: false,
      refineryTimeLine: false,
      refineryZeroPercent: false,
    })
  })

  it('does not warn on a structure whose hull is known and keeps its time bonus', async () => {
    const wrapper = await mountStructureConfigListing([LEGACY_COMPLEX, AZBEL_KADOR])

    expect(wrapper.findAll('[data-testid^="hull-to-specify-warning-"]').map(warning => warning.attributes('data-testid')))
      .toEqual(['hull-to-specify-warning-struct-legacy-complex'])
    expect(cardOf(wrapper, AZBEL_KADOR)).toContain('-30%')
  })

  it('offers the hulls of an Engineering Complex and those of a Refinery, each for its own type', async () => {
    const wrapper = await mountStructureConfigListing([LEGACY_COMPLEX, LEGACY_REFINERY])

    expect({
      complex: hullChoicesOf(wrapper, LEGACY_COMPLEX),
      refinery: hullChoicesOf(wrapper, LEGACY_REFINERY),
    }).toEqual({
      complex: ['raitaru', 'azbel', 'sotiyo'],
      refinery: ['athanor', 'tatara'],
    })
  })

  it('patches only the structure type with the hull picked and drops the warning once the server answers with the known hull', async () => {
    const wrapper = await mountStructureConfigListing([LEGACY_COMPLEX])
    writes.respondTo(`PATCH ${STRUCTURES_PATH}/${LEGACY_COMPLEX.id}`, respondWith({
      ...LEGACY_COMPLEX,
      structureType: 'azbel',
      manufacturingTimeBonus: 30,
      reactionTimeBonus: 0,
      hullToSpecify: false,
    }))

    await pickHullAndApply(wrapper, LEGACY_COMPLEX, 'azbel')

    expect({
      patches: patchesSent(),
      warningStillShown: warningOf(wrapper, LEGACY_COMPLEX).exists(),
      timeBonusShown: cardOf(wrapper, LEGACY_COMPLEX).includes('-30%'),
    }).toEqual({
      patches: [[`${STRUCTURES_PATH}/${LEGACY_COMPLEX.id}`, { method: 'PATCH', body: JSON.stringify({ structureType: 'azbel' }) }]],
      warningStillShown: false,
      timeBonusShown: true,
    })
  })

  it('patches the tatara hull of a legacy refinery without touching its other fields', async () => {
    const wrapper = await mountStructureConfigListing([LEGACY_REFINERY])
    writes.respondTo(`PATCH ${STRUCTURES_PATH}/${LEGACY_REFINERY.id}`, respondWith({ ...LEGACY_REFINERY, structureType: 'tatara', hullToSpecify: false, manufacturingTimeBonus: 0, reactionTimeBonus: 25 }))

    await pickHullAndApply(wrapper, LEGACY_REFINERY, 'tatara')

    expect(patchesSent())
      .toEqual([[`${STRUCTURES_PATH}/${LEGACY_REFINERY.id}`, { method: 'PATCH', body: JSON.stringify({ structureType: 'tatara' }) }]])
  })

  it('shows the message of the server in the warning when it refuses the hull and keeps the structure as it was', async () => {
    const wrapper = await mountStructureConfigListing([LEGACY_COMPLEX])
    writes.respondTo(`PATCH ${STRUCTURES_PATH}/${LEGACY_COMPLEX.id}`, rejectWith(new ApiError(SERVER_REFUSAL, 400)))

    await pickHullAndApply(wrapper, LEGACY_COMPLEX, 'sotiyo')

    const warning = warningOf(wrapper, LEGACY_COMPLEX)
    expect({
      shown: warning.exists(),
      message: warning.exists() ? warning.text().includes(SERVER_REFUSAL) : null,
      title: warning.exists() ? warning.text().includes(WARNING_TEXTS.en.title) : null,
    }).toEqual({ shown: true, message: true, title: true })
  })
})

describe('StructureConfig form preview for a hull to specify (issue 1343)', () => {
  async function openFormOnLegacyType(locale: Locale): Promise<VueWrapper> {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    const wrapper = await mountStructureConfigListing([], locale)
    const addButton = wrapper.findAll('button').find(candidate => candidate.text() === (locale === 'fr' ? fr : en).common.actions.add)
    expect(addButton, 'add button').toBeDefined()
    await addButton?.trigger('click')
    await flushPromises()
    Object.assign(wrapper.vm, { formStructureType: 'engineering_complex' })
    await flushPromises()
    await vi.advanceTimersByTimeAsync(PREVIEW_DEBOUNCE_MS)
    await flushPromises()
    return wrapper
  }

  it.each([
    ['en', 'Hull to specify'],
    ['fr', 'Coque à préciser'],
  ] as const)('replaces the time bonuses of the preview with the hull to specify instead of -null %% (%s)', async (locale, expectedValue) => {
    bonusPreviewAnswer = {
      manufacturingMaterialBonus: 5.04,
      reactionMaterialBonus: 0,
      manufacturingTimeBonus: null,
      reactionTimeBonus: null,
      hullToSpecify: true,
      unrecognisedRigs: [],
    }

    const wrapper = await openFormOnLegacyType(locale)

    const text = wrapper.text()
    expect({
      nullShown: text.includes('null'),
      timeValue: new RegExp(`TE Manufacturing:\\s*${expectedValue}`).test(text),
      materialValue: /ME Manufacturing:\s*-5\.04%/.test(text),
    }).toEqual({ nullShown: false, timeValue: true, materialValue: true })
  })
})
