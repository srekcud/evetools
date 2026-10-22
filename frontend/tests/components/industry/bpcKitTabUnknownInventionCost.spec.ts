import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BpcKitTab from '@/components/industry/BpcKitTab.vue'
import { apiRequest } from '@/services/api'
import type { BpcKit, BpcKitDecryptorOption } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-8'
const ACCELERANT_DECRYPTOR_TYPE_ID = 34201
const PARITY_DECRYPTOR_TYPE_ID = 34204
const PROCESS_DECRYPTOR_TYPE_ID = 34205

const NO_DECRYPTOR_OPTION: BpcKitDecryptorOption = {
  decryptorTypeId: null,
  decryptorName: 'No Decryptor',
  me: 2,
  te: 4,
  runs: 10,
  probability: 0.34,
  costPerAttempt: 2_300,
  expectedAttempts: 3,
  totalCost: 6_900,
  costBreakdown: { datacores: 800, decryptor: 0, copyCost: 1_000, inventionInstall: 500 },
  missingPriceTypeIds: [],
  missingAdjustedPriceTypeIds: [],
  unknownReason: null,
}

const ACCELERANT_OPTION: BpcKitDecryptorOption = {
  decryptorTypeId: ACCELERANT_DECRYPTOR_TYPE_ID,
  decryptorName: 'Accelerant Decryptor',
  me: 4,
  te: 14,
  runs: 11,
  probability: 0.408,
  costPerAttempt: 52_300,
  expectedAttempts: 3,
  totalCost: 156_900,
  costBreakdown: { datacores: 800, decryptor: 50_000, copyCost: 1_000, inventionInstall: 500 },
  missingPriceTypeIds: [],
  missingAdjustedPriceTypeIds: [],
  unknownReason: null,
}

const PARITY_OPTION_WITHOUT_PRICE: BpcKitDecryptorOption = {
  decryptorTypeId: PARITY_DECRYPTOR_TYPE_ID,
  decryptorName: 'Parity Decryptor',
  me: 3,
  te: 2,
  runs: 13,
  probability: 0.51,
  costPerAttempt: null,
  expectedAttempts: 2,
  totalCost: null,
  costBreakdown: { datacores: 800, decryptor: null, copyCost: 1_000, inventionInstall: 500 },
  missingPriceTypeIds: [PARITY_DECRYPTOR_TYPE_ID],
  missingAdjustedPriceTypeIds: [],
  unknownReason: 'missing_market_price',
}

const PROCESS_OPTION_WITHOUT_INSTALL_COST: BpcKitDecryptorOption = {
  decryptorTypeId: PROCESS_DECRYPTOR_TYPE_ID,
  decryptorName: 'Process Decryptor',
  me: 5,
  te: 10,
  runs: 10,
  probability: 0.374,
  costPerAttempt: null,
  expectedAttempts: 3,
  totalCost: null,
  costBreakdown: { datacores: 800, decryptor: 70_000, copyCost: 1_000, inventionInstall: null },
  missingPriceTypeIds: [],
  missingAdjustedPriceTypeIds: [11399, 11478],
  unknownReason: 'missing_adjusted_price',
}

const SABRE_BPC_KIT: BpcKit = {
  id: PROJECT_ID,
  isT2: true,
  inventions: [
    {
      productTypeId: 22456,
      productName: 'Sabre',
      baseProbability: 0.34,
      unknownReason: null,
      desiredSuccesses: 1,
      datacores: [
        { typeId: 20424, typeName: 'Datacore - Mechanical Engineering', quantity: 2, unitPrice: 100, totalPrice: 200 },
        { typeId: 20172, typeName: 'Datacore - Minmatar Starship Engineering', quantity: 3, unitPrice: 200, totalPrice: 600 },
      ],
      decryptorOptions: [NO_DECRYPTOR_OPTION, ACCELERANT_OPTION, PARITY_OPTION_WITHOUT_PRICE, PROCESS_OPTION_WITHOUT_INSTALL_COST],
    },
  ],
  summary: { totalInventionCost: 6_900, bestDecryptorTypeId: null, totalBpcKitCost: 6_900 },
}

const bpcKitLabels = en.industry.bpcKitTab
const unknownLabel = en.industry.inventionUnknown.label

async function mountBpcKitTab(): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockResolvedValue(SABRE_BPC_KIT)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(BpcKitTab, {
    global: { plugins: [i18n] },
    props: { projectId: PROJECT_ID },
  })
  await flushPromises()
  return wrapper
}

function optionRow(wrapper: VueWrapper, rowLabel: string): DOMWrapper<Element> {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.findAll('td')[1]?.text() === rowLabel)
  expect(row, `option row ${rowLabel}`).toBeDefined()
  return row!
}

function breakdownDecryptorCost(row: DOMWrapper<Element>): string {
  const label = row.findAll('span').find(span => span.text() === bpcKitLabels.decryptor)
  expect(label, 'decryptor breakdown label').toBeDefined()
  return label!.element.nextElementSibling?.textContent?.trim() ?? ''
}

function breakdownInstallCost(row: DOMWrapper<Element>): string {
  const label = row.findAll('span').find(span => span.text() === bpcKitLabels.installCost)
  expect(label, 'install cost breakdown label').toBeDefined()
  return label!.element.nextElementSibling?.textContent?.trim() ?? ''
}

function totalCostCell(row: DOMWrapper<Element>): string {
  const cells = row.findAll('td')
  return cells[cells.length - 1]!.text()
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('BpcKitTab with a decryptor without market price', () => {
  it('shows the cost per attempt, total cost and decryptor cost of an unpriced option as unknown, never as a dash or zero', async () => {
    const parityRow = optionRow(await mountBpcKitTab(), 'Parity Decryptor')

    expect(parityRow.findAll('td')[6]!.text().startsWith(unknownLabel)).toBe(true)
    expect(totalCostCell(parityRow)).toBe(unknownLabel)
    expect(breakdownDecryptorCost(parityRow)).toBe(unknownLabel)
    expect(parityRow.text()).not.toMatch(/---|null|NaN/)
  })

  it('shows the price of a priced decryptor and a dash for the option without decryptor in the cost breakdown', async () => {
    const wrapper = await mountBpcKitTab()

    expect(breakdownDecryptorCost(optionRow(wrapper, 'Accelerant Decryptor'))).toBe('50.00 K')
    expect(totalCostCell(optionRow(wrapper, 'Accelerant Decryptor'))).toBe('156.90 K')
    expect(breakdownDecryptorCost(optionRow(wrapper, bpcKitLabels.none))).toBe('--')
    expect(totalCostCell(optionRow(wrapper, bpcKitLabels.none))).toBe('6.90 K')
  })
})

describe('BpcKitTab with an invention install cost that cannot be estimated', () => {
  it('shows the install cost, cost per attempt and total cost of the option as unknown, never as zero', async () => {
    const processRow = optionRow(await mountBpcKitTab(), 'Process Decryptor')

    expect(breakdownInstallCost(processRow)).toBe(unknownLabel)
    expect(processRow.findAll('td')[6]!.text().startsWith(unknownLabel)).toBe(true)
    expect(totalCostCell(processRow)).toBe(unknownLabel)
    expect(processRow.text()).not.toMatch(/---|null|NaN/)
  })

  it('keeps showing the install cost of an option whose install cost is known', async () => {
    const wrapper = await mountBpcKitTab()

    expect(breakdownInstallCost(optionRow(wrapper, 'Accelerant Decryptor'))).toBe('500')
  })
})
