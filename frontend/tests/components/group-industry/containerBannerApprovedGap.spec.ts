import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContainerBanner from '@/components/group-industry/ContainerBanner.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type {
  BomItem,
  ContainerVerification,
  ContainerVerificationStatus,
} from '@/stores/group-industry/types'
import { present } from '../../present'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

type Locale = 'en' | 'fr'

const PROJECT_ID = 'project-hull-run'
const CONTAINER_NAME = 'Build'

const TRITANIUM = { id: 'bom-tritanium', typeId: 34, typeName: 'Tritanium', requiredQuantity: 500 }
const PYERITE = { id: 'bom-pyerite', typeId: 35, typeName: 'Pyerite', requiredQuantity: 800 }
const MEXALLON = { id: 'bom-mexallon', typeId: 36, typeName: 'Mexallon', requiredQuantity: 200 }

type Material = typeof TRITANIUM

function bomLine(material: Material, approvedQuantity: number): BomItem {
  return {
    id: material.id,
    typeId: material.typeId,
    typeName: material.typeName,
    requiredQuantity: material.requiredQuantity,
    fulfilledQuantity: approvedQuantity,
    remainingQuantity: Math.max(0, material.requiredQuantity - approvedQuantity),
    fulfillmentPercent: 0,
    estimatedPrice: null,
    estimatedTotal: null,
    isJob: false,
    jobGroup: null,
    activityType: null,
    parentTypeId: null,
    meLevel: null,
    teLevel: null,
    runs: null,
    isFulfilled: approvedQuantity >= material.requiredQuantity,
    jobMatches: [],
    matchedRuns: 0,
    purchases: [],
    purchasedQuantity: 0,
    reservations: [],
    reservedQuantity: 0,
    freeQuantity: 0,
  }
}

function verification(
  material: Material,
  status: ContainerVerificationStatus,
  containerQuantity: number | null,
): ContainerVerification {
  return {
    bomItemId: material.id,
    typeId: material.typeId,
    typeName: material.typeName,
    requiredQuantity: material.requiredQuantity,
    containerQuantity,
    status,
    unknownReason: status === 'unknown' ? 'corporation_assets_not_synced' : null,
    assetsSyncedAt: null,
  }
}

function i18nFor(locale: Locale) {
  return createI18n({ legacy: false, locale, messages: { en, fr } })
}

function mountBanner(
  locale: Locale,
  approved: [Material, number][],
  lines: ContainerVerification[],
  canVerify: boolean,
): VueWrapper {
  setActivePinia(createPinia())
  const projectStore = useGroupProjectStore()
  projectStore.bomItems = approved.map(([material, quantity]) => bomLine(material, quantity))
  projectStore.containerVerification = lines
  return mount(ContainerBanner, {
    props: { containerName: CONTAINER_NAME, projectId: PROJECT_ID, canVerify },
    global: { plugins: [i18nFor(locale)] },
  })
}

function normalized(wrapper: VueWrapper): string {
  return wrapper.text().replace(/\s+/g, ' ').trim()
}

function verifyButton(wrapper: VueWrapper) {
  return wrapper.findAll('button').find(candidate => /Verify All|Tout verifier/.test(candidate.text()))
}

const APPROVED_BUT_PARTIAL_OR_ABSENT = [
  verification(TRITANIUM, 'partial', 450),
  verification(PYERITE, 'verified', 800),
  verification(MEXALLON, 'missing', 0),
]

const APPROVED_QUANTITIES: [Material, number][] = [[TRITANIUM, 500], [PYERITE, 800], [MEXALLON, 200]]

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ContainerBanner gap between the approved contributions and the container', () => {
  it('lists in English each approved line that is partial or absent in the container', () => {
    const text = normalized(mountBanner('en', APPROVED_QUANTITIES, APPROVED_BUT_PARTIAL_OR_ABSENT, true))

    expect([
      text.includes('Tritanium: 450 / 500 in the container, 500 approved'),
      text.includes('Mexallon: 0 / 200 in the container, 200 approved'),
      text.includes('Pyerite'),
    ]).toEqual([true, true, false])
  })

  it('lists the same lines in French', () => {
    const text = normalized(mountBanner('fr', APPROVED_QUANTITIES, APPROVED_BUT_PARTIAL_OR_ABSENT, true))

    expect([
      text.includes('Tritanium : 450 / 500 dans le container, 500 approuvés'),
      text.includes('Mexallon : 0 / 200 dans le container, 200 approuvés'),
    ]).toEqual([true, true])
  })

  it('shows the last result to a member who cannot run the check', () => {
    const text = normalized(mountBanner('en', APPROVED_QUANTITIES, APPROVED_BUT_PARTIAL_OR_ABSENT, false))

    expect(text.includes('Tritanium: 450 / 500 in the container, 500 approved')).toBe(true)
  })

  it('compares the container with the approved quantity, not with the required quantity', () => {
    const text = normalized(mountBanner('en', [[TRITANIUM, 300]], [verification(TRITANIUM, 'partial', 300)], true))

    expect(text.includes('Tritanium: 300 / 500')).toBe(false)
  })

  it('does not list a line without any approved contribution', () => {
    const text = normalized(mountBanner('en', [[MEXALLON, 0]], [verification(MEXALLON, 'missing', 0)], true))

    expect(text.includes('Mexallon')).toBe(false)
  })

  it('does not list a line whose approved quantity beyond the requirement is not in the container', () => {
    const text = normalized(mountBanner('en', [[TRITANIUM, 600]], [verification(TRITANIUM, 'verified', 500)], true))

    expect(text.includes('Tritanium')).toBe(false)
  })

  it.each<[string, ContainerVerification[]]>([
    ['not checked yet', []],
    ['container not found', [verification(TRITANIUM, 'container_not_found', null)]],
    ['unknown', [verification(TRITANIUM, 'unknown', null)]],
  ])('does not list any line when the check is %s', (_state, lines) => {
    const text = normalized(mountBanner('en', [[TRITANIUM, 500]], lines, true))

    expect(text.includes('Tritanium')).toBe(false)
  })
})

function withoutContainerQuantity(line: ContainerVerification): ContainerVerification {
  const { containerQuantity: _omitted, ...rest } = line
  return rest as ContainerVerification
}

describe('ContainerBanner when the API omits the container quantity', () => {
  it.each<[string, ContainerVerificationStatus]>([
    ['container not found', 'container_not_found'],
    ['unknown', 'unknown'],
  ])('does not list a gap line when the check is %s and the quantity is absent', (_state, status) => {
    const text = normalized(mountBanner('en', [[TRITANIUM, 500]], [withoutContainerQuantity(verification(TRITANIUM, status, null))], true))

    expect([text.includes('Tritanium'), text.includes('---'), text.includes('0 approved')]).toEqual([false, false, false])
  })

  it('keeps the container problem message as the only signal', () => {
    const lines = [withoutContainerQuantity(verification(TRITANIUM, 'container_not_found', null))]
    const text = normalized(mountBanner('en', [[TRITANIUM, 500]], lines, true))

    expect([text.includes('Container not found'), text.includes('in the container')]).toEqual([true, false])
  })

  it('still lists a partial line next to a line whose quantity is absent', () => {
    const lines = [
      withoutContainerQuantity(verification(PYERITE, 'unknown', null)),
      verification(TRITANIUM, 'partial', 450),
    ]
    const text = normalized(mountBanner('en', [[TRITANIUM, 500], [PYERITE, 800]], lines, true))

    expect([
      text.includes('Tritanium: 450 / 500 in the container, 500 approved'),
      text.includes('Pyerite'),
    ]).toEqual([true, false])
  })
})

describe('ContainerBanner verification button', () => {
  it('shows the button to an admin', () => {
    const wrapper = mountBanner('en', APPROVED_QUANTITIES, [], true)

    expect(verifyButton(wrapper)).toBeDefined()
  })

  it('hides the button from a member who cannot run the check', () => {
    const wrapper = mountBanner('en', APPROVED_QUANTITIES, [], false)

    expect(verifyButton(wrapper)).toBeUndefined()
  })

  it('runs the check of the project when an admin clicks', async () => {
    vi.mocked(apiRequest).mockResolvedValue([verification(TRITANIUM, 'partial', 450)])
    const wrapper = mountBanner('en', APPROVED_QUANTITIES, [], true)

    await present(verifyButton(wrapper)).trigger('click')
    await flushPromises()

    expect([
      vi.mocked(apiRequest).mock.calls[0][0],
      normalized(wrapper).includes('Tritanium: 450 / 500 in the container, 500 approved'),
    ]).toEqual([`/industry/projects/${PROJECT_ID}/container-verification`, true])
  })

  it('tells when the check fails instead of staying silent', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Access denied'))
    const wrapper = mountBanner('en', APPROVED_QUANTITIES, [], true)

    await present(verifyButton(wrapper)).trigger('click')
    await flushPromises()

    expect(normalized(wrapper)).toMatch(/Verification failed/)
  })

  it('tells the failure in French', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Access denied'))
    const wrapper = mountBanner('fr', APPROVED_QUANTITIES, [], true)

    await present(verifyButton(wrapper)).trigger('click')
    await flushPromises()

    expect(normalized(wrapper)).toMatch(/Vérification échouée/)
  })

  it('does not tell a failure before any click', () => {
    const wrapper = mountBanner('en', APPROVED_QUANTITIES, [], true)

    expect(normalized(wrapper)).not.toMatch(/Verification failed/)
  })
})
