import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import UnknownValue from '@/components/common/UnknownValue.vue'
import ProjectSettlementBlock from '@/components/group-industry/ProjectSettlementBlock.vue'
import { apiRequest } from '@/services/api'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useGroupSettlementStore } from '@/stores/group-industry/settlement'
import { useGroupSurplusStore } from '@/stores/group-industry/surplus'
import type { Distribution, GroupProject, ProjectSettlement, ProjectSurplus, ProjectSurplusLine } from '@/stores/group-industry/types'
import { messageOf } from '../../localeTexts'
import { completedProject, memberDistribution, profitableDistribution } from './projectDiscordRecapFixtures'
import { SETTLEMENT_REFERENCE, projectSettlement, projectTransfer } from './projectSettlementFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const PROJECT_ID = 'project-sabre'
const STORED_AT = '2026-11-22T09:30:00+00:00'
const BLANK = /[\u00a0\u202f]/g
const MONEY = /\d+(\.\d+)?\s?[KMB]/

const PYERITE: ProjectSurplusLine = {
  lineId: 'line-pyerite',
  typeId: 35,
  typeName: 'Pyerite',
  requiredQuantity: 800,
  contributedQuantity: 950,
  surplusQuantity: 150,
  unitPrice: 8,
  surplusValue: 1_200,
  source: 'contribution',
}
const MORPHITE: ProjectSurplusLine = {
  lineId: 'line-morphite',
  typeId: 11_399,
  typeName: 'Morphite',
  requiredQuantity: 100,
  contributedQuantity: 105,
  surplusQuantity: 5,
  unitPrice: 10_000,
  surplusValue: 50_000,
  source: 'contribution',
}
const ISOGEN_WITHOUT_VALUE: ProjectSurplusLine = {
  lineId: 'line-isogen',
  typeId: 37,
  typeName: 'Isogen',
  requiredQuantity: 200,
  contributedQuantity: 260,
  surplusQuantity: 60,
  unitPrice: null,
  surplusValue: null,
  source: 'contribution',
}

const NOT_STORED_SURPLUS: ProjectSurplus = { id: PROJECT_ID, totalValue: 1_200, stored: false, storedAt: null, storedBy: null, lines: [PYERITE] }
const STORED_SURPLUS: ProjectSurplus = { ...NOT_STORED_SURPLUS, stored: true, storedAt: STORED_AT, storedBy: 'Aria Solberg' }

const CORIN_TO_ARIA = projectTransfer({ fromParticipantId: 'member-corin', fromName: 'Corin Vale', toParticipantId: 'member-aria', toName: 'Aria Solberg', amount: 500_000, canConfirm: true })
const CORIN_TO_BRANN = projectTransfer({ fromParticipantId: 'member-corin', fromName: 'Corin Vale', toParticipantId: 'member-brann', toName: 'Brann Holt', amount: 300_000 })

function settledDistribution(overrides: Partial<Distribution> = {}): Distribution {
  return profitableDistribution({
    netRevenue: 1_000_000,
    totalProjectCost: 700_000,
    surplusStored: false,
    storedSurplusValue: null,
    members: [
      memberDistribution({ memberId: 'member-aria', characterName: 'Aria Solberg', materialCosts: 370_000, jobInstallCosts: 40_000, bpcCosts: 10_000, lineRentalCosts: 0, courierCosts: 0, totalCostsEngaged: 420_000, sharePercent: 50, payoutTotal: 500_000 }),
      memberDistribution({ memberId: 'member-brann', characterName: 'Brann Holt', materialCosts: 230_000, jobInstallCosts: 30_000, bpcCosts: 0, lineRentalCosts: 20_000, courierCosts: 0, totalCostsEngaged: 280_000, sharePercent: 30, payoutTotal: 300_000 }),
      memberDistribution({ memberId: 'member-corin', characterName: 'Corin Vale', materialCosts: 0, jobInstallCosts: 0, totalCostsEngaged: 0, sharePercent: 20, payoutTotal: 200_000 }),
    ],
    ...overrides,
  })
}

interface BlockSetup {
  project?: GroupProject
  distribution?: Distribution
  settlement?: ProjectSettlement
  surplus?: ProjectSurplus
  canManage?: boolean
  canClose?: boolean
}

function answerApi(setup: Required<Pick<BlockSetup, 'distribution' | 'settlement' | 'surplus'>>): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (endpoint === `/industry/projects/${PROJECT_ID}/surplus-storage` && options?.method === 'POST') return STORED_SURPLUS
    if (endpoint === `/industry/projects/${PROJECT_ID}/distribution`) return setup.distribution
    if (endpoint === `/industry/projects/${PROJECT_ID}/settlement`) return setup.settlement
    if (endpoint === `/industry/projects/${PROJECT_ID}/surplus`) return setup.surplus
    if (options?.method === 'POST') return setup.settlement
    throw new Error(`Unexpected request ${endpoint}`)
  })
}

async function mountBlock(setup: BlockSetup = {}): Promise<VueWrapper> {
  const distribution = setup.distribution ?? settledDistribution()
  const settlement = setup.settlement ?? projectSettlement({ transfers: [CORIN_TO_ARIA, CORIN_TO_BRANN] })
  const surplus = setup.surplus ?? NOT_STORED_SURPLUS
  answerApi({ distribution, settlement, surplus })
  const pinia = createPinia()
  setActivePinia(pinia)
  useGroupProjectStore().currentProject = setup.project ?? completedProject({ status: 'selling' })
  useGroupDistributionStore().distribution = distribution
  useGroupSettlementStore().settlement = settlement
  const surplusStore = useGroupSurplusStore()
  surplusStore.lines = surplus.lines
  surplusStore.totalValue = surplus.totalValue
  surplusStore.stored = surplus.stored
  surplusStore.storedAt = surplus.storedAt
  surplusStore.storedBy = surplus.storedBy
  const wrapper = mount(ProjectSettlementBlock, {
    props: { projectId: PROJECT_ID, canManage: setup.canManage ?? false, canClose: setup.canClose ?? false },
    global: { plugins: [pinia, createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
  await flushPromises()
  return wrapper
}

function textOf(wrapper: VueWrapper, testId: string): string {
  const found = wrapper.find(`[data-testid="${testId}"]`)
  return found.exists() ? found.text().replace(BLANK, ' ') : 'absent'
}

function has(wrapper: VueWrapper, testId: string): boolean {
  return wrapper.find(`[data-testid="${testId}"]`).exists()
}

function attributeOf(wrapper: VueWrapper, testId: string, attribute: string): string {
  const found = wrapper.find(`[data-testid="${testId}"]`)
  return found.exists() ? (found.attributes(attribute) ?? 'none') : 'absent'
}

function showsUnknown(wrapper: VueWrapper, testId: string): boolean {
  const found = wrapper.find(`[data-testid="${testId}"]`)
  return found.exists() && found.findComponent(UnknownValue).exists()
}

async function click(wrapper: VueWrapper, testId: string): Promise<void> {
  const found = wrapper.find(`[data-testid="${testId}"]`)
  if (found.exists()) await found.trigger('click')
  await flushPromises()
}

const TRANSFER_CELL = (from: string, to: string): string => `settlement-transfer-${from}-${to}`
const RECEIVED_BOX = (from: string, to: string): string => `settlement-received-${from}-${to}`

const clipboard = { writeText: vi.fn() }

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  clipboard.writeText = vi.fn().mockResolvedValue(undefined)
  Object.defineProperty(navigator, 'clipboard', { value: clipboard, configurable: true })
})

afterEach(() => {
  Reflect.deleteProperty(navigator, 'clipboard')
})

describe('ProjectSettlementBlock, distribution table and cascade', () => {
  it('shows each participant with the share, the payout and the state of the transfers, who holds the sales, and the cascade with the stored surplus', async () => {
    const wrapper = await mountBlock({
      distribution: settledDistribution({ surplusStored: true, storedSurplusValue: 50_000 }),
      settlement: projectSettlement({ transfers: [{ ...CORIN_TO_ARIA, received: true }, CORIN_TO_BRANN] }),
      surplus: { ...STORED_SURPLUS, totalValue: 50_000, lines: [MORPHITE] },
    })

    expect({
      ariaShare: textOf(wrapper, 'settlement-share-member-aria').includes('50'),
      ariaPayout: textOf(wrapper, 'settlement-payout-member-aria').includes('500.00 K'),
      brannPayout: textOf(wrapper, 'settlement-payout-member-brann').includes('300.00 K'),
      corinPayout: textOf(wrapper, 'settlement-payout-member-corin').includes('200.00 K'),
      receivedFromCorin: attributeOf(wrapper, TRANSFER_CELL('member-corin', 'member-aria'), 'data-state'),
      pendingFromCorin: attributeOf(wrapper, TRANSFER_CELL('member-corin', 'member-brann'), 'data-state'),
      pendingNamesTheSender: textOf(wrapper, TRANSFER_CELL('member-corin', 'member-brann')).includes('Corin Vale'),
      holdsSales: ['aria', 'brann', 'corin'].map(member => has(wrapper, `settlement-holds-sales-member-${member}`)),
    }).toEqual({
      ariaShare: true,
      ariaPayout: true,
      brannPayout: true,
      corinPayout: true,
      receivedFromCorin: 'received',
      pendingFromCorin: 'pending',
      pendingNamesTheSender: true,
      holdsSales: [false, false, true],
    })
    expect({
      netRevenue: textOf(wrapper, 'settlement-cascade-netRevenue').includes('1.00 M'),
      materials: textOf(wrapper, 'settlement-cascade-materials').includes('600.00 K'),
      installations: textOf(wrapper, 'settlement-cascade-installations').includes('100.00 K'),
      surplus: textOf(wrapper, 'settlement-cascade-surplus').includes('50.00 K'),
      profit: textOf(wrapper, 'settlement-cascade-profit').includes('350.00 K'),
    }).toEqual({ netRevenue: true, materials: true, installations: true, surplus: true, profit: true })
  })

  it('lets only the recipient tick a transfer, locks the ones the journal verified and posts the tick to the receipt route', async () => {
    const verifiedBrannToAria = projectTransfer({ fromParticipantId: 'member-brann', fromName: 'Brann Holt', toParticipantId: 'member-aria', toName: 'Aria Solberg', amount: 40_000, canConfirm: true, received: true, journalVerified: true })
    const wrapper = await mountBlock({ settlement: projectSettlement({ transfers: [CORIN_TO_ARIA, CORIN_TO_BRANN, verifiedBrannToAria] }) })
    const boxes = [['member-corin', 'member-aria'], ['member-corin', 'member-brann'], ['member-brann', 'member-aria']]

    expect(boxes.map(([from, to]) => attributeOf(wrapper, RECEIVED_BOX(from, to), 'disabled'))).toEqual(['none', '', ''])

    const tickable = wrapper.find(`[data-testid="${RECEIVED_BOX('member-corin', 'member-aria')}"]`)
    if (tickable.exists()) await tickable.setValue(true)
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledWith(
      `/industry/projects/${PROJECT_ID}/transfers/member-corin/member-aria/receipt`,
      { method: 'POST', body: JSON.stringify({ received: true }) },
    )
  })

  it('shows an unknown value, never zero, in the cascade and the payouts and says why the transfers are unavailable', async () => {
    const [aria, brann, corin] = settledDistribution().members
    const wrapper = await mountBlock({
      distribution: settledDistribution({ totalProjectCost: null, members: [{ ...aria, payoutTotal: null, sharePercent: null }, { ...brann, materialCosts: null }, corin] }),
      settlement: projectSettlement({ transfers: null, transfersUnavailableReason: 'unknown_cost' }),
    })

    expect({
      payout: showsUnknown(wrapper, 'settlement-payout-member-aria'),
      materials: showsUnknown(wrapper, 'settlement-cascade-materials'),
      profit: showsUnknown(wrapper, 'settlement-cascade-profit'),
      netRevenueKnown: showsUnknown(wrapper, 'settlement-cascade-netRevenue'),
      profitShowsNoMoney: MONEY.test(textOf(wrapper, 'settlement-cascade-profit')),
      reason: textOf(wrapper, 'settlement-transfers-unavailable').includes(messageOf('en', 'groupIndustry.transfers.unavailable.unknown_cost')),
      noTransferCell: wrapper.findAll('[data-testid^="settlement-transfer-"]').length,
    }).toEqual({ payout: true, materials: true, profit: true, netRevenueKnown: false, profitShowsNoMoney: false, reason: true, noTransferCell: 0 })
  })
})

describe('ProjectSettlementBlock, surplus', () => {
  it('lists the surplus lines with their value or none, the total, and offers storing it to the managers only, then posts the storage', async () => {
    const surplus: ProjectSurplus = { ...NOT_STORED_SURPLUS, totalValue: null, lines: [PYERITE, ISOGEN_WITHOUT_VALUE] }
    const reader = await mountBlock({ surplus, canManage: false })

    expect({
      pyerite: textOf(reader, 'settlement-surplus-row-line-pyerite').includes('Pyerite') && textOf(reader, 'settlement-surplus-row-line-pyerite').includes('150'),
      isogenKnown: attributeOf(reader, 'settlement-surplus-value-line-isogen', 'data-known'),
      pyeriteKnown: attributeOf(reader, 'settlement-surplus-value-line-pyerite', 'data-known'),
      totalUnknown: showsUnknown(reader, 'settlement-surplus-total'),
      readerButton: has(reader, 'settlement-store-surplus'),
    }).toEqual({ pyerite: true, isogenKnown: 'false', pyeriteKnown: 'true', totalUnknown: true, readerButton: false })

    const manager = await mountBlock({ canManage: true })
    expect(has(manager, 'settlement-store-surplus')).toBe(true)
    await click(manager, 'settlement-store-surplus')

    expect(apiRequest).toHaveBeenCalledWith(`/industry/projects/${PROJECT_ID}/surplus-storage`, expect.objectContaining({ method: 'POST' }))
    expect(has(manager, 'settlement-store-surplus')).toBe(false)
    expect(textOf(manager, 'settlement-surplus-stored')).toContain('Aria Solberg')
  })

  it('shows who stored the surplus and the value counted to the project, or an unknown value, with no button left', async () => {
    const counted = await mountBlock({
      distribution: settledDistribution({ surplusStored: true, storedSurplusValue: 1_200 }),
      surplus: STORED_SURPLUS,
      canManage: true,
    })
    const unknown = await mountBlock({
      distribution: settledDistribution({ surplusStored: true, storedSurplusValue: null }),
      surplus: { ...STORED_SURPLUS, totalValue: null, lines: [PYERITE, ISOGEN_WITHOUT_VALUE] },
      canManage: true,
    })

    expect({
      countedNamesTheStorer: textOf(counted, 'settlement-surplus-stored').includes('Aria Solberg'),
      countedValue: textOf(counted, 'settlement-surplus-stored').includes('1.20 K'),
      countedButton: has(counted, 'settlement-store-surplus'),
      unknownShowsNoMoney: MONEY.test(textOf(unknown, 'settlement-surplus-stored')),
      unknownButton: has(unknown, 'settlement-store-surplus'),
      unknownBlock: has(unknown, 'settlement-surplus-stored'),
    }).toEqual({ countedNamesTheStorer: true, countedValue: true, countedButton: false, unknownShowsNoMoney: false, unknownButton: false, unknownBlock: true })
  })
})

describe('ProjectSettlementBlock, closing and copying', () => {
  it('offers the closing of a selling project to who can close it, emits close, and shows the closed stamp once the project is completed', async () => {
    const closer = await mountBlock({ canClose: true })
    const onlyReader = await mountBlock({ canClose: false })
    const closed = await mountBlock({ project: completedProject({ status: 'completed' }), canClose: true })

    await click(closer, 'settlement-close')

    expect({
      closeEmitted: closer.emitted('close')?.length ?? 0,
      closerStamp: has(closer, 'settlement-closed-stamp'),
      readerButton: has(onlyReader, 'settlement-close'),
      readerStamp: has(onlyReader, 'settlement-closed-stamp'),
      closedStamp: has(closed, 'settlement-closed-stamp'),
      closedButton: has(closed, 'settlement-close'),
    }).toEqual({ closeEmitted: 1, closerStamp: false, readerButton: false, readerStamp: false, closedStamp: true, closedButton: false })
  })

  it('copies the payment reference and the Discord recap when the transfers are known, and tells when the clipboard refuses', async () => {
    const wrapper = await mountBlock({ distribution: settledDistribution({ surplusStored: true, storedSurplusValue: 50_000 }), surplus: { ...STORED_SURPLUS, totalValue: 50_000, lines: [MORPHITE] } })
    const unknownTransfers = await mountBlock({ settlement: projectSettlement({ transfers: null, transfersUnavailableReason: 'awaiting_sales' }) })

    expect(textOf(wrapper, 'settlement-reference')).toBe(SETTLEMENT_REFERENCE)
    await click(wrapper, 'settlement-copy-reference')
    await click(wrapper, 'settlement-copy-discord')

    const copied = clipboard.writeText.mock.calls.map(([text]) => String(text))
    const discordButton = unknownTransfers.find('[data-testid="settlement-copy-discord"]')
    expect({
      reference: copied[0],
      recapNamesTheProject: copied[1]?.includes('Sabre run'),
      recapCarriesTheReference: copied[1]?.includes(SETTLEMENT_REFERENCE),
      recapProfitCountsTheStoredSurplus: copied[1]?.includes('350.00 K'),
      discordCopyWithUnknownTransfers: discordButton.exists() && discordButton.attributes('disabled') === undefined,
      noAlertYet: has(wrapper, 'settlement-copy-failed'),
    }).toEqual({ reference: SETTLEMENT_REFERENCE, recapNamesTheProject: true, recapCarriesTheReference: true, recapProfitCountsTheStoredSurplus: true, discordCopyWithUnknownTransfers: false, noAlertYet: false })

    clipboard.writeText = vi.fn().mockRejectedValue(new Error('denied'))
    await click(wrapper, 'settlement-copy-reference')

    expect(attributeOf(wrapper, 'settlement-copy-failed', 'role')).toBe('alert')
  })
})
