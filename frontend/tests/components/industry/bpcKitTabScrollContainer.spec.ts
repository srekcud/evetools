import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import BpcKitTab from '@/components/industry/BpcKitTab.vue'
import { apiRequest } from '@/services/api'
import type { BpcKit } from '@/stores/industry/types'
import { tablesInScrollContainer } from '../tableScrollContainer'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-226'

const ISHTAR_BPC_KIT: BpcKit = {
  id: PROJECT_ID,
  isT2: true,
  inventions: [
    {
      productTypeId: 12_005,
      productName: 'Ishtar',
      baseProbability: 0.3,
      unknownReason: null,
      desiredSuccesses: 2,
      datacores: [
        { typeId: 20_410, typeName: 'Datacore - Gallentean Starship Engineering', quantity: 8, unitPrice: 95_000, totalPrice: 760_000 },
        { typeId: 20_424, typeName: 'Datacore - Mechanical Engineering', quantity: 8, unitPrice: 41_000, totalPrice: 328_000 },
      ],
      decryptorOptions: [
        {
          decryptorTypeId: null,
          decryptorName: 'No Decryptor',
          me: 2,
          te: 4,
          runs: 1,
          probability: 0.3,
          costPerAttempt: 1_250_000,
          expectedAttempts: 7,
          totalCost: 8_750_000,
          costBreakdown: { datacores: 1_088_000, decryptor: 0, copyCost: 62_000, inventionInstall: 100_000 },
          missingPriceTypeIds: [],
          missingAdjustedPriceTypeIds: [],
          unknownReason: null,
        },
        {
          decryptorTypeId: 34_204,
          decryptorName: 'Parity Decryptor',
          me: 3,
          te: 2,
          runs: 4,
          probability: 0.45,
          costPerAttempt: 3_150_000,
          expectedAttempts: 5,
          totalCost: 15_750_000,
          costBreakdown: { datacores: 1_088_000, decryptor: 1_900_000, copyCost: 62_000, inventionInstall: 100_000 },
          missingPriceTypeIds: [],
          missingAdjustedPriceTypeIds: [],
          unknownReason: null,
        },
      ],
    },
  ],
  summary: { totalInventionCost: 8_750_000, bestDecryptorTypeId: null, totalBpcKitCost: 8_750_000 },
}

async function mountBpcKitTab() {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockResolvedValue(ISHTAR_BPC_KIT)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(BpcKitTab, {
    global: { plugins: [i18n] },
    props: { projectId: PROJECT_ID },
  })
  await flushPromises()
  return wrapper
}

describe('BpcKitTab on a narrow screen', () => {
  it('puts the decryptor comparison table in a horizontally scrollable container', async () => {
    const wrapper = await mountBpcKitTab()

    expect(tablesInScrollContainer(wrapper)).toEqual([true])
  })
})
