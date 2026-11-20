import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContributionTable from '@/components/group-industry/ContributionTable.vue'
import { apiRequest } from '@/services/api'
import type { GroupContribution } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-approve-all'

function contribution(overrides: Partial<GroupContribution>): GroupContribution {
  return {
    id: 'contribution',
    memberCharacterName: 'Aria Solberg',
    memberId: 'member-aria',
    bomItemId: 'bom-line',
    bomItemTypeName: 'Tritanium',
    type: 'material',
    quantity: 100,
    estimatedValue: 500,
    status: 'pending',
    isAutoDetected: false,
    isVerified: false,
    valuedAtJitaPrice: false,
    reopenable: false,
    duplicatesDerived: false,
    reviewedByCharacterName: null,
    reviewedAt: null,
    note: null,
    createdAt: '2026-11-20T09:00:00+00:00',
    reviewableByViewer: true,
    ...overrides,
  }
}

const PENDING_MATERIAL = contribution({ id: 'pending-material', bomItemTypeName: 'Tritanium' })
const PENDING_JOB = contribution({ id: 'pending-job', bomItemTypeName: 'Ishtar', type: 'job_install', quantity: 5 })
const AWAITING_WALLET = contribution({ id: 'awaiting-wallet', bomItemTypeName: 'Pyerite', awaitingQuantity: 20 })
const OWN_PENDING = contribution({ id: 'own-pending', bomItemTypeName: 'Mexallon', editableByViewer: true, reviewableByViewer: false })
const OWN_ESI_DELIVERY = contribution({ id: 'own-esi-delivery', bomItemTypeName: 'Zydrine', isAutoDetected: true, reviewableByViewer: false })
const SOLE_OWNER_OWN_PENDING = contribution({ id: 'sole-owner-own', bomItemTypeName: 'Megacyte', editableByViewer: true })
const APPROVED = contribution({ id: 'approved', bomItemTypeName: 'Isogen', status: 'approved' })

async function mountTable(contributions: GroupContribution[], isAdmin: boolean, locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockResolvedValueOnce(contributions)
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(ContributionTable, { global: { plugins: [i18n] }, props: { projectId: PROJECT_ID, isAdmin } })
  await flushPromises()
  return wrapper
}

function approveAllButton(wrapper: VueWrapper): DOMWrapper<HTMLButtonElement> | undefined {
  return wrapper.findAll<HTMLButtonElement>('button').find(button => button.text().startsWith('Approve all') || button.text().startsWith('Tout approuver'))
}

function patchedIds(): string[] {
  return vi.mocked(apiRequest).mock.calls
    .filter(([, options]) => options?.method === 'PATCH')
    .map(([endpoint]) => endpoint.split('/').pop() ?? '')
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ContributionTable approve all', () => {
  it('offers an admin Approve all counting only the contributions the admin can approve', async () => {
    const wrapper = await mountTable([PENDING_MATERIAL, PENDING_JOB, AWAITING_WALLET, OWN_PENDING, OWN_ESI_DELIVERY, APPROVED], true)

    expect(approveAllButton(wrapper)?.text()).toBe('Approve all (2)')
  })

  it('counts the own contribution of an owner nobody else could review', async () => {
    const wrapper = await mountTable([PENDING_MATERIAL, SOLE_OWNER_OWN_PENDING], true)

    expect(approveAllButton(wrapper)?.text()).toBe('Approve all (2)')
  })

  it('reads the label in French', async () => {
    const wrapper = await mountTable([PENDING_MATERIAL], true, 'fr')

    expect(approveAllButton(wrapper)?.text()).toBe('Tout approuver (1)')
  })

  it('offers a non-admin no Approve all', async () => {
    const wrapper = await mountTable([PENDING_MATERIAL, PENDING_JOB], false)

    expect(approveAllButton(wrapper)).toBeUndefined()
  })

  it('offers no Approve all when nothing can be approved', async () => {
    const wrapper = await mountTable([AWAITING_WALLET, OWN_PENDING, OWN_ESI_DELIVERY, APPROVED], true)

    expect(approveAllButton(wrapper)).toBeUndefined()
  })

  it('approves every approvable contribution and leaves the others pending', async () => {
    const wrapper = await mountTable([PENDING_MATERIAL, OWN_ESI_DELIVERY, PENDING_JOB, AWAITING_WALLET, OWN_PENDING], true)
    vi.mocked(apiRequest)
      .mockResolvedValueOnce({ ...PENDING_MATERIAL, status: 'approved' })
      .mockResolvedValueOnce({ ...PENDING_JOB, status: 'approved' })

    await approveAllButton(wrapper)?.trigger('click')
    await flushPromises()

    expect(patchedIds()).toEqual(['pending-material', 'pending-job'])
    expect(apiRequest).toHaveBeenLastCalledWith(
      `/industry/projects/${PROJECT_ID}/contributions/pending-job`,
      { method: 'PATCH', body: JSON.stringify({ status: 'approved' }) },
    )
    expect(approveAllButton(wrapper)).toBeUndefined()
  })

  it('stops at the first refused approval and shows its error', async () => {
    const wrapper = await mountTable([PENDING_MATERIAL, PENDING_JOB], true)
    vi.mocked(apiRequest).mockRejectedValueOnce(new Error('Cannot review a contribution of a completed project'))

    await approveAllButton(wrapper)?.trigger('click')
    await flushPromises()

    expect(patchedIds()).toEqual(['pending-material'])
    expect(wrapper.text()).toContain('Cannot review a contribution of a completed project')
    expect(approveAllButton(wrapper)?.text()).toBe('Approve all (2)')
  })
})
