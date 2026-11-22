import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import JobLinkModal from '@/components/group-industry/JobLinkModal.vue'
import LineRentalModal from '@/components/group-industry/LineRentalModal.vue'
import MemberTable from '@/components/group-industry/MemberTable.vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { AvailableJob, BomItem, GroupMember, JobMatch } from '@/stores/group-industry/types'
import type { Character, User } from '@/types'
import type { TestLocale } from '../../localeTexts'
import { mountProductionJobRows } from '../../productionJobRows'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const ESI_STATUS_LABELS: Record<TestLocale, Record<string, string>> = {
  en: {
    active: 'Running',
    paused: 'Paused',
    ready: 'Ready',
    delivered: 'Delivered',
    cancelled: 'Cancelled',
    reverted: 'Reverted',
    unknown: 'Unknown status',
  },
  fr: {
    active: 'En cours',
    paused: 'En pause',
    ready: 'Terminé',
    delivered: 'Livré',
    cancelled: 'Annulé',
    reverted: 'Rétabli',
    unknown: 'Statut inconnu',
  },
}
const LINKED_JOB_LABELS: Record<TestLocale, Record<string, string>> = {
  en: { ...ESI_STATUS_LABELS.en, active: 'Ready to deliver', ready: 'Ready to deliver' },
  fr: { ...ESI_STATUS_LABELS.fr, active: 'À livrer', ready: 'À livrer' },
}
const ESI_STATUSES = ['active', 'paused', 'ready', 'delivered', 'cancelled', 'reverted']
const UNRECOGNISED_ESI_STATUS = 'in_limbo'
const LOCALES: TestLocale[] = ['en', 'fr']

function i18nFor(locale: TestLocale) {
  return createI18n({ legacy: false, locale, messages: { en, fr } })
}

function availableJob(status: string): AvailableJob {
  return {
    esiJobId: 910_001,
    characterName: 'Aria Solberg',
    productTypeId: 12005,
    productTypeName: 'Ishtar',
    startDate: '2026-11-20T08:00:00+00:00',
    runs: 4,
    status,
    cost: 1_250_000,
    linkedToBomLineId: null,
    linkedToBomLineName: null,
    matchId: null,
    linkedRuns: 0,
    remainingRuns: 4,
  } as AvailableJob
}

function mountJobLinkModal(locale: TestLocale, status: string): VueWrapper {
  return mount(JobLinkModal, {
    props: { show: true, lineName: 'Ishtar', jobs: [availableJob(status)], loading: false, error: null, linkingEsiJobId: null },
    global: { plugins: [i18nFor(locale)], stubs: { teleport: true } },
  })
}

function bomLineWithMatch(status: string): BomItem {
  const match: JobMatch = {
    id: 'match-aria',
    bomLineId: 'job-ishtar',
    esiJobId: 480012,
    cost: 1_250_000,
    status,
    endDate: '2026-11-07T10:00:00+00:00',
    runs: 3,
    characterName: 'Aria Solberg',
    matchedAt: '2026-11-06T08:00:00+00:00',
    facilityId: null,
    facilityName: null,
    manual: false,
  } as JobMatch

  return {
    id: 'job-ishtar',
    typeId: 12005,
    typeName: 'Ishtar',
    requiredQuantity: 10,
    fulfilledQuantity: 0,
    remainingQuantity: 10,
    fulfillmentPercent: 0,
    estimatedPrice: null,
    estimatedTotal: null,
    isJob: true,
    jobGroup: 'final',
    activityType: 'manufacturing',
    parentTypeId: null,
    meLevel: 10,
    teLevel: 20,
    runs: 10,
    isFulfilled: false,
    jobMatches: [match],
    matchedRuns: 3,
    purchases: [],
    purchasedQuantity: 0,
    reservations: [],
    pendingContributions: [],
    reservedQuantity: 0,
    freeQuantity: 0,
  } as BomItem
}

describe('an ESI job status is shown through its translated label, never raw', () => {
  it.each(LOCALES)('labels the status of each job offered for linking in %s', (locale) => {
    const labels = Object.fromEntries(ESI_STATUSES.map(status => [status, mountJobLinkModal(locale, status).get('[data-testid="available-job"]').text()]))

    for (const status of ESI_STATUSES) {
      expect(labels[status], status).toContain(ESI_STATUS_LABELS[locale][status])
      expect(labels[status], status).not.toContain(status)
    }
  })

  it.each(LOCALES)('labels the status of each job linked to a production line in %s', async (locale) => {
    const labels: Record<string, string> = {}
    for (const status of ESI_STATUSES) {
      const wrapper = await mountProductionJobRows({ lines: [bomLineWithMatch(status)], locale })
      labels[status] = wrapper.get('[data-testid="job-match"]').text()
    }

    for (const status of ESI_STATUSES) {
      expect(labels[status], status).toContain(LINKED_JOB_LABELS[locale][status])
    }
    expect(labels.cancelled).not.toContain('cancelled')
    expect(labels.delivered).not.toContain('delivered')
  })

  it.each(LOCALES)('shows an unknown status rather than the raw value in %s', async (locale) => {
    const offered = mountJobLinkModal(locale, UNRECOGNISED_ESI_STATUS).get('[data-testid="available-job"]').text()
    const linked = (await mountProductionJobRows({ lines: [bomLineWithMatch(UNRECOGNISED_ESI_STATUS)], locale })).get('[data-testid="job-match"]').text()

    expect(offered).toContain(ESI_STATUS_LABELS[locale].unknown)
    expect(offered).not.toContain(UNRECOGNISED_ESI_STATUS)
    expect(linked).toContain(ESI_STATUS_LABELS[locale].unknown)
    expect(linked).not.toContain(UNRECOGNISED_ESI_STATUS)
  })
})

describe('line rental rate categories are translated', () => {
  const RATE_LABELS: Record<TestLocale, string[]> = {
    en: ['Component T1', 'Module T1', 'Ship T1', 'Module T2', 'Ship T2', 'Capital T1', 'Capital T2', 'Location Factor'],
    fr: ['Composant T1', 'Module T1', 'Vaisseau T1', 'Module T2', 'Vaisseau T2', 'Capital T1', 'Capital T2', 'Facteur localisation'],
  }

  it.each(LOCALES)('shows the rate categories in %s', (locale) => {
    setActivePinia(createPinia())
    const wrapper = mount(LineRentalModal, { props: { show: true }, global: { plugins: [i18nFor(locale)], stubs: { teleport: true } } })

    const categories = wrapper.findAll('tbody tr').map(row => row.find('td').text())

    expect(categories).toEqual(RATE_LABELS[locale])
  })
})

describe('the member management confirmation is translated', () => {
  const OWNER = { id: 'member-owner', name: 'Aria Solberg', characterId: 2112000001 }
  const PROMOTED = { id: 'member-m', name: 'Dorn Achar', characterId: 2112000004 }

  function member(pilot: typeof OWNER, role: GroupMember['role']): GroupMember {
    return {
      id: pilot.id,
      characterName: pilot.name,
      characterId: pilot.characterId,
      corporationId: 98000001,
      corporationName: 'Sabre Doctrine Corp',
      role,
      status: 'accepted',
      totalContributionValue: 0,
      contributionCount: 0,
      joinedAt: '2026-10-20T18:30:00+00:00',
    }
  }

  function ownerUser(): User {
    const mainCharacter: Character = {
      id: 'character-owner',
      eveCharacterId: OWNER.characterId,
      name: OWNER.name,
      corporationId: 98000001,
      corporationName: 'Sabre Doctrine Corp',
      allianceId: null,
      allianceName: null,
      isMain: true,
      hasValidToken: true,
      lastSyncAt: null,
    }

    return { id: 'user-owner', authStatus: 'full', mainCharacter, characters: [mainCharacter], corporationId: 98000001, corporationName: 'Sabre Doctrine Corp' }
  }

  function mountAsOwner(locale: TestLocale): VueWrapper {
    setActivePinia(createPinia())
    useAuthStore().user = ownerUser()
    const store = useGroupDistributionStore()
    store.members = [member(OWNER, 'owner'), member(PROMOTED, 'member')]
    vi.spyOn(store, 'fetchMembers').mockResolvedValue(undefined)

    return mount(MemberTable, {
      props: { projectId: 'project-sabre-batch', isAdmin: true, isOwner: true },
      global: { plugins: [i18nFor(locale)], stubs: { ConfirmModal: true } },
    })
  }

  it('words the promotion confirmation and the role labels in French', async () => {
    const wrapper = mountAsOwner('fr')
    await wrapper.findAll('tbody tr')[1].findAll('button')[0].trigger('click')
    await flushPromises()

    const confirmation = wrapper.get('confirm-modal-stub')
    expect(confirmation.attributes('title')).toBe('Promouvoir en admin')
    expect(confirmation.attributes('subtitle')).toBe('Promouvoir Dorn Achar en admin ?')
    expect(confirmation.attributes('confirmlabel')).toBe('Promouvoir')
    expect(wrapper.findAll('tbody tr')[0].text()).toContain('Propriétaire')
  })

  it('keeps the English promotion confirmation', async () => {
    const wrapper = mountAsOwner('en')
    await wrapper.findAll('tbody tr')[1].findAll('button')[0].trigger('click')
    await flushPromises()

    const confirmation = wrapper.get('confirm-modal-stub')
    expect(confirmation.attributes('title')).toBe('Promote to Admin')
    expect(confirmation.attributes('subtitle')).toBe('Promote Dorn Achar to admin?')
    expect(confirmation.attributes('confirmlabel')).toBe('Promote')
  })
})

describe('the flow card detail labels exist in both languages', () => {
  const DETAIL_KEYS = 'groupIndustry.flow.detail'
  const MESSAGES: Record<TestLocale, unknown> = { en, fr }
  const ORIGIN_KINDS = ['buyOrBuild', 'whereToBuy', 'courier', 'contributions', 'useMyStock', 'depositToCheck', 'blueprints', 'research', 'jobs', 'reservation', 'planning', 'structure', 'decryptors', 'whereToSell', 'sellOrders', 'salesToMatch']
  const PLAIN_KEYS = ['unknown', 'closeDetail', 'nothingMore', 'intro', 'linkJob', 'openTab']
  const FRENCH_ORIGINS: Record<string, string> = {
    buyOrBuild: 'Approvisionnement › Acheter ou fabriquer',
    whereToBuy: 'Approvisionnement › Où acheter',
    courier: 'Approvisionnement › Contrats de courier',
    contributions: 'Contributions',
    useMyStock: 'Approvisionnement › Utiliser mon stock',
    depositToCheck: 'Contributions › Apports à vérifier',
    blueprints: 'Approvisionnement › BPC possédés',
    research: 'Production › Recherche',
    jobs: 'Production › Chaîne, Lier un job',
    reservation: 'Production › Réservation, location de lignes',
    planning: 'Planning',
    structure: 'Production › Structures, Santé des structures',
    decryptors: 'Invention › Choix du décrypteur',
    whereToSell: 'Finances › Prévision de vente',
    sellOrders: 'Ventes › Mes ordres',
    salesToMatch: 'Ventes › Ventes à rattacher',
  }

  function detailText(locale: TestLocale, key: string): string {
    const message = `${DETAIL_KEYS}.${key}`.split('.').reduce<unknown>((node, part) => (node as Record<string, unknown> | undefined)?.[part], MESSAGES[locale])
    return typeof message === 'string' ? message.replace(/[\u00a0\u202f]/g, ' ') : `missing ${locale} message ${key}`
  }

  it('has every block origin and plain label in both languages, worded in French as the spec does', () => {
    for (const locale of LOCALES) {
      const texts = Object.fromEntries([...ORIGIN_KINDS.map(kind => `origins.${kind}`), ...PLAIN_KEYS].map(key => [key, detailText(locale, key)]))

      expect(Object.entries(texts).filter(([key, text]) => text === '' || text === `missing ${locale} message ${key}`), locale).toEqual([])
    }
    expect(Object.fromEntries(ORIGIN_KINDS.map(kind => [kind, detailText('fr', `origins.${kind}`)]))).toEqual(FRENCH_ORIGINS)
    expect(detailText('fr', 'titles.buyOrBuild')).toBe('Acheter ou fabriquer')
    expect(detailText('en', 'titles.buyOrBuild')).toBe('Buy or build')
    expect(detailText('en', 'origins.buyOrBuild')).toBe('Sourcing › Buy or build')
    expect(detailText('fr', 'intro')).toBe('Ce que les onglets actuels montrent sur cet objet, réuni sur sa carte.')
    expect(detailText('fr', 'nothingMore')).toBe('Rien de plus sur cette carte.')
    expect(detailText('fr', 'unknown').toLowerCase()).toBe('inconnu')
  })
})

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})
