import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContributeModal from '@/components/group-industry/ContributeModal.vue'
import type { BomItem } from '@/stores/group-industry/types'
import { bomLine } from './companionFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn().mockResolvedValue({}),
}))

const PROJECT_ID = 'project-8'

function reservedLine(quantity: number, pendingQuantity: number): BomItem {
  return bomLine({
    id: 'bom-nocxium',
    typeId: 38,
    typeName: 'Nocxium',
    requiredQuantity: 150,
    remainingQuantity: 150,
    reservations: [{ participantId: 'participant-alpha', characterName: 'Alpha', quantity, isMine: true }],
    pendingContributions: pendingQuantity > 0 ? [{ participantId: 'participant-alpha', characterName: 'Alpha', quantity: pendingQuantity, isMine: true }] : [],
    reservedQuantity: quantity,
  })
}

async function openedQuantityFor(item: BomItem): Promise<number> {
  setActivePinia(createPinia())
  const wrapper: VueWrapper = mount(ContributeModal, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })], stubs: { teleport: true } },
    props: { show: false, projectId: PROJECT_ID, prefilledBomItem: item },
  })
  await wrapper.setProps({ show: true })
  await flushPromises()
  return Number((wrapper.find('input[type="number"]').element as HTMLInputElement).value)
}

describe('ContributeModal prefilled quantity of a reserved line', () => {
  it('prefills the reserved quantity minus the quantity I already delivered and that awaits approval', async () => {
    expect(await openedQuantityFor(reservedLine(50, 20))).toBe(30)
  })

  it('prefills the whole reserved quantity when nothing awaits approval', async () => {
    expect(await openedQuantityFor(reservedLine(50, 0))).toBe(50)
  })

  it('ignores the pending quantity of another participant', async () => {
    const item = {
      ...reservedLine(50, 0),
      pendingContributions: [{ participantId: 'participant-zeta', characterName: 'Zeta', quantity: 88, isMine: false }],
    }

    expect(await openedQuantityFor(item)).toBe(50)
  })
})
