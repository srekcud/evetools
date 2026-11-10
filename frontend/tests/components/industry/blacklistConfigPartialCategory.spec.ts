import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BlacklistConfig from '@/components/industry/BlacklistConfig.vue'
import { apiRequest } from '@/services/api'
import type { BlacklistConfig as BlacklistConfiguration } from '@/stores/industry/types'
import { apiOutcomesByPath, respondWith } from '../../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const BLACKLIST_PATH = '/industry/blacklist'
const FUEL_BLOCKS_GROUP_ID = 1136
const BIOCHEMICAL_FIRST_GROUP_ID = 661
const BIOCHEMICAL_SECOND_GROUP_ID = 662
const GROUP_OUTSIDE_EVERY_CATEGORY = 999_999

const PARTIAL_BLACKLIST: BlacklistConfiguration = {
  categories: [
    { key: 'fuel_blocks', label: 'Fuel blocks', groupIds: [FUEL_BLOCKS_GROUP_ID], blacklisted: false, partiallyBlacklisted: false },
    { key: 'biochem_reactions', label: 'Biochemical reactions', groupIds: [BIOCHEMICAL_FIRST_GROUP_ID, BIOCHEMICAL_SECOND_GROUP_ID], blacklisted: false, partiallyBlacklisted: true },
  ],
  groupIds: [BIOCHEMICAL_FIRST_GROUP_ID, GROUP_OUTSIDE_EVERY_CATEGORY],
  items: [],
}

const reads = apiOutcomesByPath()
const changes = apiOutcomesByPath()
let changeBodies: unknown[] = []

async function mountBlacklistConfig(): Promise<VueWrapper> {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(BlacklistConfig, { global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function categoryCheckbox(wrapper: VueWrapper, categoryLabel: string): HTMLInputElement {
  const label = wrapper.findAll('label').find(candidate => candidate.text() === categoryLabel)
  expect(label, `${categoryLabel} category`).toBeDefined()
  return label!.get('input[type="checkbox"]').element as HTMLInputElement
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  changeBodies = []
  reads.clear()
  changes.clear()
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (options?.method === 'PUT') changeBodies.push(JSON.parse(String(options.body)))
    const answer = options?.method === 'PUT' ? changes.answer(endpoint) : reads.answer(endpoint)
    if (answer === undefined) throw new Error(`Unexpected request ${endpoint}`)
    return answer
  })
})

describe('BlacklistConfig partial category (issue 139)', () => {
  it('shows a partially blacklisted category as indeterminate and not checked', async () => {
    reads.respondTo(BLACKLIST_PATH, respondWith(PARTIAL_BLACKLIST))

    const wrapper = await mountBlacklistConfig()

    const biochemical = categoryCheckbox(wrapper, 'Biochemical reactions')
    const fuelBlocks = categoryCheckbox(wrapper, 'Fuel blocks')
    expect({
      biochemical: { checked: biochemical.checked, indeterminate: biochemical.indeterminate },
      fuelBlocks: { checked: fuelBlocks.checked, indeterminate: fuelBlocks.indeterminate },
    }).toEqual({
      biochemical: { checked: false, indeterminate: true },
      fuelBlocks: { checked: false, indeterminate: false },
    })
  })

  it('keeps the groups really blacklisted, outside every category included, when another category is ticked', async () => {
    reads.respondTo(BLACKLIST_PATH, respondWith(PARTIAL_BLACKLIST))
    changes.respondTo(BLACKLIST_PATH, respondWith(PARTIAL_BLACKLIST))
    const wrapper = await mountBlacklistConfig()

    await wrapper.findAll('label').find(label => label.text() === 'Fuel blocks')!.get('input').setValue(true)
    await flushPromises()

    expect(changeBodies).toEqual([{ groupIds: [BIOCHEMICAL_FIRST_GROUP_ID, GROUP_OUTSIDE_EVERY_CATEGORY, FUEL_BLOCKS_GROUP_ID], typeIds: [] }])
  })

  it('completes the partial category with its missing group when it is ticked', async () => {
    reads.respondTo(BLACKLIST_PATH, respondWith(PARTIAL_BLACKLIST))
    changes.respondTo(BLACKLIST_PATH, respondWith(PARTIAL_BLACKLIST))
    const wrapper = await mountBlacklistConfig()

    await wrapper.findAll('label').find(label => label.text() === 'Biochemical reactions')!.get('input').setValue(true)
    await flushPromises()

    expect(changeBodies).toEqual([{ groupIds: [BIOCHEMICAL_FIRST_GROUP_ID, GROUP_OUTSIDE_EVERY_CATEGORY, BIOCHEMICAL_SECOND_GROUP_ID], typeIds: [] }])
  })

  it('shows a fully blacklisted category as checked and not indeterminate', async () => {
    reads.respondTo(BLACKLIST_PATH, respondWith({
      ...PARTIAL_BLACKLIST,
      categories: PARTIAL_BLACKLIST.categories.map(category => category.key === 'biochem_reactions' ? { ...category, blacklisted: true, partiallyBlacklisted: false } : category),
      groupIds: [BIOCHEMICAL_FIRST_GROUP_ID, BIOCHEMICAL_SECOND_GROUP_ID],
    }))

    const wrapper = await mountBlacklistConfig()

    const biochemical = categoryCheckbox(wrapper, 'Biochemical reactions')
    expect({ checked: biochemical.checked, indeterminate: biochemical.indeterminate }).toEqual({ checked: true, indeterminate: false })
  })
})
