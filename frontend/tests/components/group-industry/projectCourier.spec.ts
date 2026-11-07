import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectCourier from '@/components/group-industry/ProjectCourier.vue'
import { apiRequest } from '@/services/api'
import type { ProjectCourier as ProjectCourierData } from '@/stores/group-industry/types'
import {
  IN_TRANSIT_CONTRACT_ID,
  LINKED_IN_TRANSIT,
  NOCXIUM_TYPE_ID,
  OUTSTANDING_CONTRACT_ID,
  readyCourier,
  TRITANIUM_TYPE_ID,
} from '../../courierFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-batch'
const COURIER_PATH = `/industry/projects/${PROJECT_ID}/courier`
const LINK_PATH = `/industry/projects/${PROJECT_ID}/courier-contract`
const DASH = '—'
const clipboard = { writeText: vi.fn() }

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

async function mountCourier(...reads: ProjectCourierData[]): Promise<VueWrapper> {
  const pendingReads = [...reads]
  vi.mocked(apiRequest).mockImplementation(async (path: string) => {
    if (path === COURIER_PATH) {
      return pendingReads.length > 1 ? pendingReads.shift() : pendingReads[0]
    }
    return undefined
  })
  setActivePinia(createPinia())
  const wrapper = mount(ProjectCourier, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function shown(wrapper: VueWrapper, testId: string): string {
  const found = wrapper.find(`[data-testid="${testId}"]`)
  return found.exists() ? found.text().replace(/[\u00a0\u202f]/g, ' ') : 'absent'
}

function digitsOf(wrapper: VueWrapper, testId: string): string {
  return shown(wrapper, testId).replace(/\D/g, '')
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  clipboard.writeText = vi.fn().mockResolvedValue(undefined)
  Object.defineProperty(navigator, 'clipboard', { value: clipboard, configurable: true })
})

afterEach(() => {
  Reflect.deleteProperty(navigator, 'clipboard')
})

describe('ProjectCourier', () => {
  it('shows the prefilled contract from Jita to the workshop with its volume, collateral, reward, jumps and durations', async () => {
    const wrapper = await mountCourier(readyCourier(PROJECT_ID))

    expect(apiRequest).toHaveBeenCalledWith(COURIER_PATH)
    expect(shown(wrapper, 'courier-route')).toContain('Jita')
    expect(shown(wrapper, 'courier-route')).toContain('Perimeter')
    expect(shown(wrapper, 'courier-volume')).toContain('953.5')
    expect(digitsOf(wrapper, 'courier-collateral')).toContain('22632500')
    expect(digitsOf(wrapper, 'courier-reward')).toContain('1287225')
    expect(digitsOf(wrapper, 'courier-jumps')).toContain('9')
    expect(digitsOf(wrapper, 'courier-days-to-complete')).toContain('3')
    expect(digitsOf(wrapper, 'courier-expiration')).toContain('7')
  })

  it('shows an unknown reward as a dash with its cause when the route is unknown, and keeps the known volume and collateral', async () => {
    const wrapper = await mountCourier(readyCourier(PROJECT_ID, { reward: null, jumps: null, quoteStatus: 'route_unknown' }))

    expect(shown(wrapper, 'courier-reward')).toContain(DASH)
    expect(digitsOf(wrapper, 'courier-reward')).not.toContain('0')
    expect(shown(wrapper, 'courier-jumps')).toContain(DASH)
    expect(shown(wrapper, 'courier-volume')).toContain('953.5')
    expect(digitsOf(wrapper, 'courier-collateral')).toContain('22632500')
    expect(wrapper.get('[data-testid="courier-unknown-cause"]').attributes('data-cause')).toBe('route_unknown')
  })

  it('shows an unknown volume and reward as dashes and lists the types without volume when a type has no SDE volume', async () => {
    const wrapper = await mountCourier(readyCourier(PROJECT_ID, {
      volumeCubicMeters: null,
      reward: null,
      quoteStatus: 'volume_unknown',
      missingVolumeTypeIds: [NOCXIUM_TYPE_ID],
    }))

    expect(shown(wrapper, 'courier-volume')).toContain(DASH)
    expect(shown(wrapper, 'courier-reward')).toContain(DASH)
    expect(digitsOf(wrapper, 'courier-collateral')).toContain('22632500')
    expect(wrapper.get('[data-testid="courier-unknown-cause"]').attributes('data-cause')).toBe('volume_unknown')
    expect(shown(wrapper, 'courier-unknown-cause')).toContain(String(NOCXIUM_TYPE_ID))
  })

  it('says no purchase is attached and shows no contract figure when the project has no purchase', async () => {
    const wrapper = await mountCourier(readyCourier(PROJECT_ID, {
      volumeCubicMeters: null,
      collateral: null,
      reward: null,
      jumps: null,
      quoteStatus: 'no_purchases',
      lines: [],
      candidates: [],
    }))

    expect(wrapper.find('[data-testid="courier-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="courier-contract-text"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="courier-copy"]').exists()).toBe(false)
  })

  it('copies exactly the shown contract to the clipboard', async () => {
    const wrapper = await mountCourier(readyCourier(PROJECT_ID))
    const shownContract = wrapper.get('[data-testid="courier-contract-text"]').element.textContent ?? ''

    await wrapper.get('[data-testid="courier-copy"]').trigger('click')
    await flushPromises()

    expect(shownContract).toContain('Jita')
    expect(shownContract).toContain('Perimeter')
    expect(clipboard.writeText.mock.calls).toEqual([[shownContract]])
  })

  it('shows the transport state of the contract and of every purchased line once a contract is linked', async () => {
    const wrapper = await mountCourier(readyCourier(PROJECT_ID, {
      contract: LINKED_IN_TRANSIT,
      lines: [
        { bomLineId: 'line-tritanium', typeId: TRITANIUM_TYPE_ID, purchasedQuantity: 8000, transportState: 'in_transit' },
        { bomLineId: 'line-nocxium', typeId: NOCXIUM_TYPE_ID, purchasedQuantity: 150, transportState: 'delivered' },
      ],
    }))

    expect(wrapper.get('[data-testid="courier-state"]').attributes('data-state')).toBe('in_transit')
    expect(wrapper.get(`[data-testid="courier-line-${TRITANIUM_TYPE_ID}"]`).attributes('data-state')).toBe('in_transit')
    expect(wrapper.get(`[data-testid="courier-line-${NOCXIUM_TYPE_ID}"]`).attributes('data-state')).toBe('delivered')
    expect(digitsOf(wrapper, `courier-line-${TRITANIUM_TYPE_ID}`)).toContain('8000')
  })

  it('lists the candidate contracts of the owner and marks only the one matching the quote', async () => {
    const wrapper = await mountCourier(readyCourier(PROJECT_ID))

    expect(wrapper.get(`[data-testid="courier-candidate-${OUTSTANDING_CONTRACT_ID}"]`).attributes('data-matches')).toBe('true')
    expect(wrapper.get(`[data-testid="courier-candidate-${IN_TRANSIT_CONTRACT_ID}"]`).attributes('data-matches')).toBe('false')
    expect(wrapper.get(`[data-testid="courier-candidate-${OUTSTANDING_CONTRACT_ID}"]`).attributes('data-state')).toBe('waiting')
    expect(wrapper.get(`[data-testid="courier-candidate-${IN_TRANSIT_CONTRACT_ID}"]`).attributes('data-state')).toBe('in_transit')
  })

  it('shows the station names of a candidate and the explicit location id when the name is unknown', async () => {
    const wrapper = await mountCourier(readyCourier(PROJECT_ID))

    const matching = shown(wrapper, `courier-candidate-${OUTSTANDING_CONTRACT_ID}`)
    expect(matching).toContain('Jita IV - Moon 4 - Caldari Navy Assembly Plant → Perimeter - Ishtar Workshop')
    expect(matching).not.toContain('#60003760')
    const other = shown(wrapper, `courier-candidate-${IN_TRANSIT_CONTRACT_ID}`)
    expect(other).toContain('Jita IV - Moon 4 - Caldari Navy Assembly Plant → #1035466617946')
  })

  it('shows the station names of the linked contract', async () => {
    const wrapper = await mountCourier(readyCourier(PROJECT_ID, {
      contract: { ...LINKED_IN_TRANSIT, endLocationName: 'Perimeter - Ishtar Workshop' },
    }))

    expect(shown(wrapper, 'courier-linked-route')).toContain('Jita IV - Moon 4 - Caldari Navy Assembly Plant → Perimeter - Ishtar Workshop')
  })

  it('shows the location id of the linked contract when its station name is unknown', async () => {
    const wrapper = await mountCourier(readyCourier(PROJECT_ID, { contract: LINKED_IN_TRANSIT }))

    expect(shown(wrapper, 'courier-linked-route')).toContain('Jita IV - Moon 4 - Caldari Navy Assembly Plant → #1035466617946')
  })

  it('shows no candidate and no link or unlink button to a participant who is not the owner', async () => {
    const wrapper = await mountCourier(readyCourier(PROJECT_ID, { canLink: false, candidates: [], contract: LINKED_IN_TRANSIT }))

    expect(wrapper.get('[data-testid="courier-state"]').attributes('data-state')).toBe('in_transit')
    expect(wrapper.find(`[data-testid="courier-candidate-${OUTSTANDING_CONTRACT_ID}"]`).exists()).toBe(false)
    expect(wrapper.find(`[data-testid="courier-link-${OUTSTANDING_CONTRACT_ID}"]`).exists()).toBe(false)
    expect(wrapper.find('[data-testid="courier-unlink"]').exists()).toBe(false)
  })

  it('links the chosen candidate and shows the state read after the link', async () => {
    const linked = readyCourier(PROJECT_ID, { contract: { ...LINKED_IN_TRANSIT, contractId: OUTSTANDING_CONTRACT_ID, state: 'waiting' }, lines: [
      { bomLineId: 'line-tritanium', typeId: TRITANIUM_TYPE_ID, purchasedQuantity: 8000, transportState: 'waiting' },
      { bomLineId: 'line-nocxium', typeId: NOCXIUM_TYPE_ID, purchasedQuantity: 150, transportState: 'waiting' },
    ] })
    const wrapper = await mountCourier(readyCourier(PROJECT_ID), linked)

    await wrapper.get(`[data-testid="courier-link-${OUTSTANDING_CONTRACT_ID}"]`).trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledWith(LINK_PATH, { method: 'POST', body: JSON.stringify({ contractId: OUTSTANDING_CONTRACT_ID }) })
    expect(wrapper.get('[data-testid="courier-state"]').attributes('data-state')).toBe('waiting')
    expect(wrapper.get(`[data-testid="courier-line-${TRITANIUM_TYPE_ID}"]`).attributes('data-state')).toBe('waiting')
  })

  it('unlinks the contract and shows every line as not shipped again', async () => {
    const wrapper = await mountCourier(readyCourier(PROJECT_ID, { contract: LINKED_IN_TRANSIT, lines: [
      { bomLineId: 'line-tritanium', typeId: TRITANIUM_TYPE_ID, purchasedQuantity: 8000, transportState: 'in_transit' },
    ] }), readyCourier(PROJECT_ID, { lines: [
      { bomLineId: 'line-tritanium', typeId: TRITANIUM_TYPE_ID, purchasedQuantity: 8000, transportState: 'not_shipped' },
    ] }))

    await wrapper.get('[data-testid="courier-unlink"]').trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledWith(LINK_PATH, { method: 'DELETE' })
    expect(wrapper.find('[data-testid="courier-state"]').exists()).toBe(false)
    expect(wrapper.get(`[data-testid="courier-line-${TRITANIUM_TYPE_ID}"]`).attributes('data-state')).toBe('not_shipped')
  })
})
