import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BomTable from '@/components/group-industry/BomTable.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupMemberRole, GroupProject } from '@/stores/group-industry/types'
import { groupProjectRefreshes } from '@/views/GroupIndustry/groupProjectRefreshes'
import { present } from '../../present'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-1050'
const ASSIGN_BUTTON = '[data-testid="unclaimed-stock-assign"]'
const DONATE_BUTTON = '[data-testid="unclaimed-stock-donate"]'
const MEMBERS_ENDPOINT = `/industry/projects/${PROJECT_ID}/members`
const ALLOCATION_ENDPOINT = `/industry/projects/${PROJECT_ID}/contributions/unclaimed-stock`
const MEMBERS = [
  { id: 'participant-brann', characterName: 'Brann Holt', status: 'accepted', role: 'member' },
  { id: 'participant-dorn', characterName: 'Dorn Achar', status: 'pending', role: 'member' },
]

function materialLine(overrides: Partial<BomItem>): BomItem {
  return {
    id: 'line-ram',
    typeId: 11478,
    typeName: 'R.A.M.- Starship Tech',
    requiredQuantity: 100,
    fulfilledQuantity: 20,
    remainingQuantity: 80,
    fulfillmentPercent: 20,
    estimatedPrice: null,
    estimatedTotal: null,
    isJob: false,
    jobGroup: null,
    activityType: null,
    parentTypeId: null,
    meLevel: null,
    teLevel: null,
    runs: null,
    isFulfilled: false,
    jobMatches: [],
    matchedRuns: 0,
    purchases: [],
    purchasedQuantity: 0,
    reservations: [],
    pendingContributions: [],
    reservedQuantity: 0,
    freeQuantity: 80,
    containerQuantity: 80,
    containerStockState: 'known',
    unclaimedQuantity: 50,
    launchPrice: 12.5,
    unclaimedValue: 625,
    ...overrides,
  }
}

function mountBomAs(role: GroupMemberRole, line: BomItem): VueWrapper {
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = { id: PROJECT_ID, name: 'RAM batch', status: 'in_progress', myRole: role, items: [] } as unknown as GroupProject
  projectStore.bomItems = [line]
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(BomTable, { global: { plugins: [i18n], stubs: { teleport: true } } })
}

function postedBody(): Record<string, unknown> {
  const posts = vi.mocked(apiRequest).mock.calls.filter(([endpoint, options]) => endpoint === ALLOCATION_ENDPOINT && options?.method === 'POST')
  expect(posts).toHaveLength(1)
  return JSON.parse(String(present(posts[0])[1]?.body))
}

async function click(wrapper: VueWrapper, selector: string): Promise<void> {
  await wrapper.find(selector).trigger('click')
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(((endpoint: string) =>
    Promise.resolve(endpoint === MEMBERS_ENDPOINT ? MEMBERS : [])) as typeof apiRequest)
})

describe('unclaimed stock owner actions', () => {
  it('offers the owner to assign or donate the unclaimed stock', () => {
    const wrapper = mountBomAs('owner', materialLine({}))

    expect([wrapper.find(ASSIGN_BUTTON).exists(), wrapper.find(DONATE_BUTTON).exists()]).toEqual([true, true])
  })

  it.each<GroupMemberRole>(['admin', 'member'])('shows no allocation action to a %s', (role) => {
    const wrapper = mountBomAs(role, materialLine({}))

    expect([wrapper.find(ASSIGN_BUTTON).exists(), wrapper.find(DONATE_BUTTON).exists()]).toEqual([false, false])
  })

  it('keeps the badge read only for the owner when nothing is unclaimed', () => {
    const wrapper = mountBomAs('owner', materialLine({ unclaimedQuantity: 0, unclaimedValue: 0 }))

    expect([wrapper.find('[data-testid="unclaimed-stock-badge"]').exists(), wrapper.find(DONATE_BUTTON).exists()]).toEqual([false, false])
  })

  it('disables the assignment without a launch price but still allows the donation', () => {
    const wrapper = mountBomAs('owner', materialLine({ launchPrice: null, unclaimedValue: null }))

    expect([
      wrapper.find<HTMLButtonElement>(ASSIGN_BUTTON).element.disabled,
      wrapper.find<HTMLButtonElement>(DONATE_BUTTON).element.disabled,
    ]).toEqual([true, false])
  })

  it('donates the unclaimed quantity of the line', async () => {
    const line = materialLine({})
    const wrapper = mountBomAs('owner', line)

    await click(wrapper, DONATE_BUTTON)
    expect(wrapper.find<HTMLInputElement>('[data-testid="allocation-quantity"]').element.value).toBe('50')
    await click(wrapper, '[data-testid="allocation-submit"]')

    expect(postedBody()).toEqual({ bomItemId: line.id, quantity: 50, mode: 'donate' })
  })

  it('assigns a part of the unclaimed stock to an accepted participant only', async () => {
    const line = materialLine({})
    const wrapper = mountBomAs('owner', line)

    await click(wrapper, ASSIGN_BUTTON)
    const options = wrapper.findAll('[data-testid="allocation-participant"] option').map(option => option.text())
    expect(options.some(label => label.includes('Brann Holt'))).toBe(true)
    expect(options.some(label => label.includes('Dorn Achar'))).toBe(false)

    await wrapper.find('[data-testid="allocation-participant"]').setValue('participant-brann')
    await wrapper.find('[data-testid="allocation-quantity"]').setValue(30)
    await click(wrapper, '[data-testid="allocation-submit"]')

    expect(postedBody()).toEqual({ bomItemId: line.id, quantity: 30, mode: 'assign', participantId: 'participant-brann' })
  })

  it('does not submit an assignment before a participant is chosen', async () => {
    const wrapper = mountBomAs('owner', materialLine({}))

    await click(wrapper, ASSIGN_BUTTON)
    await click(wrapper, '[data-testid="allocation-submit"]')

    expect(vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === ALLOCATION_ENDPOINT)).toHaveLength(0)
  })
})

describe('groupProjectRefreshes on an unclaimed stock allocation', () => {
  it('refreshes the BOM, the contributions, the detail and the cockpit', () => {
    expect(groupProjectRefreshes('unclaimed_stock_allocated')).toEqual(['bom', 'contributions', 'projectDetail', 'cockpit'])
  })
})
