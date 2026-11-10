import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import EscalationsTab from '@/components/ledger/EscalationsTab.vue'
import { useEscalationStore, type Escalation } from '@/stores/escalation'

const FAR_FUTURE = '2099-01-01T00:00:00+00:00'

const OWNED_ESCALATION_FOR_SALE: Escalation = {
  id: 'escalation-for-sale',
  characterId: 2112000001,
  characterName: 'Ilyra Castell',
  type: 'Serpentis Hideaway',
  solarSystemId: 30000142,
  solarSystemName: 'Jita',
  securityStatus: -0.5,
  price: 100,
  visibility: 'perso',
  bmStatus: 'bm',
  saleStatus: 'envente',
  notes: null,
  corporationId: 98000001,
  expiresAt: FAR_FUTURE,
  createdAt: '2026-11-09T08:00:00+00:00',
  updatedAt: '2026-11-09T08:00:00+00:00',
  isOwner: true,
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('EscalationsTab sale dialog', () => {
  it.each([
    ['en', en, en.escalations.saleStatus.forSale, 'Do not also enter a loot sale for this escalation.'],
    ['fr', fr, fr.escalations.saleStatus.forSale, 'Ne saisissez pas aussi une vente de loot pour cette escalation.'],
  ] as const)('warns in %s against entering a loot sale for a sold escalation', async (locale, messages, forSaleLabel, expectedWarning) => {
    const escalationStore = useEscalationStore()
    vi.spyOn(escalationStore, 'fetchEscalations').mockResolvedValue(undefined as never)
    vi.spyOn(escalationStore, 'fetchCorpEscalations').mockResolvedValue(undefined as never)
    escalationStore.escalations = [OWNED_ESCALATION_FOR_SALE]
    const wrapper = mount(EscalationsTab, {
      global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })], stubs: { teleport: true } },
    })
    await flushPromises()

    await wrapper.findAll('button').find(button => button.text() === forSaleLabel)?.trigger('click')
    await flushPromises()

    const dialog = wrapper.find('[role="dialog"]')
    expect({ title: dialog.exists() ? dialog.text().includes(messages.escalations.sellModal.title) : null, warning: dialog.exists() ? dialog.text().includes(expectedWarning) : null })
      .toEqual({ title: true, warning: true })
  })
})
