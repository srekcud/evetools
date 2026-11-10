import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ProjectTransfers from '@/components/group-industry/ProjectTransfers.vue'
import { apiRequest } from '@/services/api'
import { useGroupSettlementStore } from '@/stores/group-industry/settlement'
import type { ProjectSettlement } from '@/stores/group-industry/types'
import { SETTLEMENT_REFERENCE, projectSettlement, projectTransfer } from './projectSettlementFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-sabre'
const ROW = (from: string, to: string) => `[data-testid="transfer-row-${from}-${to}"]`
const TICK = (from: string, to: string) => `[data-testid="transfer-received-${from}-${to}"]`

enableAutoUnmount(afterEach)

function mountWith(settlement: ProjectSettlement) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useGroupSettlementStore().settlement = settlement
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(ProjectTransfers, { props: { projectId: PROJECT_ID }, global: { plugins: [pinia, i18n] } })
}

describe('ProjectTransfers', () => {
  beforeEach(() => {
    vi.mocked(apiRequest).mockReset()
  })

  it('shows the payment reference to put in the reason of every in-game transfer', () => {
    const wrapper = mountWith(projectSettlement())

    expect(wrapper.get('[data-testid="transfer-reference"]').text()).toBe(SETTLEMENT_REFERENCE)
  })

  it('lists each transfer with who pays whom and the amount', () => {
    const wrapper = mountWith(projectSettlement())

    const row = wrapper.get(ROW('member-aria', 'member-brann')).text()
    expect(row).toContain('Aria Solberg')
    expect(row).toContain('Brann Holt')
    expect(row).toContain('277,500')
  })

  it('lets only the recipient tick a transfer', () => {
    const wrapper = mountWith(projectSettlement({
      transfers: [
        projectTransfer({ canConfirm: true }),
        projectTransfer({ fromParticipantId: 'member-corin', fromName: 'Corin Vale', canConfirm: false }),
      ],
    }))

    expect(wrapper.get(TICK('member-aria', 'member-brann')).attributes('disabled')).toBeUndefined()
    expect(wrapper.get(TICK('member-corin', 'member-brann')).attributes('disabled')).toBeDefined()
  })

  it('shows a transfer found in the wallet journal as verified and locks its tick', () => {
    const wrapper = mountWith(projectSettlement({
      transfers: [
        projectTransfer({ canConfirm: true, received: true, journalVerified: true, receivedAt: '2026-11-10T18:00:00+00:00' }),
        projectTransfer({ fromParticipantId: 'member-corin', fromName: 'Corin Vale', canConfirm: true }),
      ],
    }))

    expect(wrapper.find(`[data-testid="transfer-journal-verified-member-aria-member-brann"]`).exists()).toBe(true)
    expect((wrapper.get(TICK('member-aria', 'member-brann')).element as HTMLInputElement).checked).toBe(true)
    expect(wrapper.get(TICK('member-aria', 'member-brann')).attributes('disabled')).toBeDefined()
    expect(wrapper.find(`[data-testid="transfer-journal-verified-member-corin-member-brann"]`).exists()).toBe(false)
    expect(wrapper.get(TICK('member-corin', 'member-brann')).attributes('disabled')).toBeUndefined()
  })

  it('sends the tick to the receipt route of the transfer and shows the settlement it returns', async () => {
    const ticked = projectSettlement({ transfers: [projectTransfer({ canConfirm: true, received: true, receivedAt: '2026-11-09T20:00:00+00:00' })] })
    vi.mocked(apiRequest).mockResolvedValueOnce(ticked)
    const wrapper = mountWith(projectSettlement({ transfers: [projectTransfer({ canConfirm: true })] }))

    await wrapper.get(TICK('member-aria', 'member-brann')).setValue(true)
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledWith(
      `/industry/projects/${PROJECT_ID}/transfers/member-aria/member-brann/receipt`,
      { method: 'POST', body: JSON.stringify({ received: true }) },
    )
    expect((wrapper.get(TICK('member-aria', 'member-brann')).element as HTMLInputElement).checked).toBe(true)
  })

  it('shows the rounding gap only when there is one', () => {
    expect(mountWith(projectSettlement()).find('[data-testid="transfer-rounding-gap"]').exists()).toBe(false)
    expect(mountWith(projectSettlement({ roundingGap: 0.01 })).get('[data-testid="transfer-rounding-gap"]').text()).toContain('0.01')
  })

  it('says why the transfers are unavailable instead of listing zero', () => {
    const wrapper = mountWith(projectSettlement({ transfers: null, transfersUnavailableReason: 'unsold_products' }))

    expect(wrapper.get('[data-testid="transfers-unavailable"]').attributes('data-reason')).toBe('unsold_products')
    expect(wrapper.find('[data-testid="transfer-reference"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-testid^="transfer-row-"]')).toHaveLength(0)
  })

  it('says nothing has to be transferred when every participant already holds their payout', () => {
    const wrapper = mountWith(projectSettlement({ transfers: [], checks: { ...projectSettlement().checks, transfersTotal: 0, transfersReceived: 0 } }))

    expect(wrapper.find('[data-testid="transfers-none"]').exists()).toBe(true)
  })
})
