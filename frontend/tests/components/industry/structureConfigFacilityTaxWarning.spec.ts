import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import StructureConfig from '@/components/industry/StructureConfig.vue'
import { apiRequest } from '@/services/api'
import type { StructureConfig as StructureConfigRecord } from '@/stores/industry/types'
import { apiOutcomesByPath, respondWith } from '../../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

type Locale = 'en' | 'fr'

const STRUCTURES_PATH = '/industry/structures'
const CORPORATION_STRUCTURES_PATH = '/industry/corporation-structures'
const RIG_OPTIONS = { manufacturing: [], reaction: [] }
const WARNING_COLOR_CLASS = 'text-amber-400'

const RAITARU_WITHOUT_FACILITY_TAX: StructureConfigRecord = {
  id: 'struct-unset',
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
  facilityTaxRate: null,
  createdAt: '2026-10-20T09:00:00+00:00',
}

const AZBEL_WITH_FACILITY_TAX_SET_TO_ZERO: StructureConfigRecord = {
  ...RAITARU_WITHOUT_FACILITY_TAX,
  id: 'struct-zero',
  name: 'Azbel Kador',
  structureType: 'azbel',
  facilityTaxRate: 0,
}

const SOTIYO_WITH_A_FACILITY_TAX: StructureConfigRecord = {
  ...RAITARU_WITHOUT_FACILITY_TAX,
  id: 'struct-taxed',
  name: 'Sotiyo Hek',
  structureType: 'sotiyo',
  facilityTaxRate: 1.25,
}

const NPC_STATION: StructureConfigRecord = {
  ...RAITARU_WITHOUT_FACILITY_TAX,
  id: 'struct-station',
  name: 'Jita IV - Moon 4',
  structureType: 'station',
  facilityTaxRate: 0.25,
  manufacturingMaterialBonus: 0,
  manufacturingTimeBonus: 0,
}

const reads = apiOutcomesByPath()

function warningOf(wrapper: VueWrapper, structure: StructureConfigRecord) {
  return wrapper.find(`[data-testid="facility-tax-warning-${structure.id}"]`)
}

function cardOf(wrapper: VueWrapper, structure: StructureConfigRecord): string {
  const card = wrapper.findAll('h4').find(title => title.text() === structure.name)?.element.parentElement?.parentElement
  return card?.textContent?.replace(/\s+/g, ' ') ?? 'absent'
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

async function openAddForm(wrapper: VueWrapper): Promise<void> {
  const addButton = wrapper.findAll('button').find(candidate => candidate.text() === en.common.actions.add)
  expect(addButton, 'add button').toBeDefined()
  await addButton?.trigger('click')
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  reads.clear()
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === CORPORATION_STRUCTURES_PATH) return { structures: [] }
    if (endpoint === `${STRUCTURES_PATH}/bonus-preview`) return {}
    const answer = reads.answer(endpoint)
    if (answer === undefined) throw new Error(`Unexpected request ${endpoint}`)
    return answer
  })
})

describe('StructureConfig list with a facility tax never entered (issue 780)', () => {
  it.each([
    ['en', en.industry.structures.facilityTaxNotSet],
    ['fr', fr.industry.structures.facilityTaxNotSet],
  ] as const)('warns on the facility tax line of a player structure without tax (%s)', async (locale, notSet) => {
    const wrapper = await mountStructureConfigListing([RAITARU_WITHOUT_FACILITY_TAX], locale)

    const warning = warningOf(wrapper, RAITARU_WITHOUT_FACILITY_TAX)
    expect({
      shown: warning.exists(),
      text: warning.exists() ? warning.text() : null,
      alertColor: warning.exists() ? warning.classes(WARNING_COLOR_CLASS) : null,
    }).toEqual({ shown: true, text: notSet, alertColor: true })
  })

  it('shows a facility tax entered as zero as 0% without any warning', async () => {
    const wrapper = await mountStructureConfigListing([AZBEL_WITH_FACILITY_TAX_SET_TO_ZERO])

    expect({
      warned: warningOf(wrapper, AZBEL_WITH_FACILITY_TAX_SET_TO_ZERO).exists(),
      card: cardOf(wrapper, AZBEL_WITH_FACILITY_TAX_SET_TO_ZERO).includes(`${en.industry.structures.facilityTax} 0%`),
    }).toEqual({ warned: false, card: true })
  })

  it('warns only on the structure without tax when several structures are listed', async () => {
    const wrapper = await mountStructureConfigListing([SOTIYO_WITH_A_FACILITY_TAX, RAITARU_WITHOUT_FACILITY_TAX, AZBEL_WITH_FACILITY_TAX_SET_TO_ZERO])

    expect(wrapper.findAll('[data-testid^="facility-tax-warning-"]').map(warning => warning.attributes('data-testid')))
      .toEqual(['facility-tax-warning-struct-unset'])
    expect(cardOf(wrapper, SOTIYO_WITH_A_FACILITY_TAX)).toContain(`${en.industry.structures.facilityTax} 1.25%`)
  })

  it('keeps the fixed facility tax of an NPC station without any warning', async () => {
    const wrapper = await mountStructureConfigListing([NPC_STATION])

    expect({
      warned: warningOf(wrapper, NPC_STATION).exists(),
      card: cardOf(wrapper, NPC_STATION).includes(`${en.industry.structures.facilityTax} 0.25%`),
    }).toEqual({ warned: false, card: true })
  })

  it('does not warn on an NPC station even when its tax comes back empty', async () => {
    const wrapper = await mountStructureConfigListing([{ ...NPC_STATION, facilityTaxRate: null }])

    expect(warningOf(wrapper, NPC_STATION).exists()).toBe(false)
  })
})

describe('StructureConfig form with a facility tax left empty (issue 780)', () => {
  function helpOf(wrapper: VueWrapper) {
    return wrapper.find('[data-testid="facility-tax-unknown-help"]')
  }

  it('explains under the empty field that the install cost stays unknown until the tax is entered', async () => {
    const wrapper = await mountStructureConfigListing([])

    await openAddForm(wrapper)

    const help = helpOf(wrapper)
    expect({ shown: help.exists(), readable: help.exists() && help.text().length > 0 && !help.text().includes('industry.structures.') })
      .toEqual({ shown: true, readable: true })
  })

  it('hides the help once a tax is entered, zero included', async () => {
    const wrapper = await mountStructureConfigListing([])
    await openAddForm(wrapper)

    await wrapper.get('#structure-facility-tax').setValue('0')

    expect(helpOf(wrapper).exists()).toBe(false)
  })

  it('shows no help for an NPC station, whose tax is fixed by the game', async () => {
    const wrapper = await mountStructureConfigListing([])
    await openAddForm(wrapper)

    Object.assign(wrapper.vm, { formStructureType: 'station' })
    await flushPromises()

    expect(helpOf(wrapper).exists()).toBe(false)
  })
})
