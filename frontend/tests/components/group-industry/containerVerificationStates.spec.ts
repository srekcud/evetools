import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BomTable from '@/components/group-industry/BomTable.vue'
import ContainerBanner from '@/components/group-industry/ContainerBanner.vue'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type {
  BomItem,
  ContainerCheckUnknownReason,
  ContainerVerification,
  ContainerVerificationStatus,
  GroupProject,
} from '@/stores/group-industry/types'

type Locale = 'en' | 'fr'

const PROJECT_ID = 'project-hull-run'
const CONTAINER_NAME = 'Build'
const RAW_TRANSLATION_KEY = /groupIndustry\.[a-zA-Z.]+/

const TRITANIUM = { id: 'bom-tritanium', typeId: 34, typeName: 'Tritanium', requiredQuantity: 500 }
const PYERITE = { id: 'bom-pyerite', typeId: 35, typeName: 'Pyerite', requiredQuantity: 800 }
const MEXALLON = { id: 'bom-mexallon', typeId: 36, typeName: 'Mexallon', requiredQuantity: 200 }

type Material = typeof TRITANIUM

function bomLine(material: Material): BomItem {
  return {
    id: material.id,
    typeId: material.typeId,
    typeName: material.typeName,
    requiredQuantity: material.requiredQuantity,
    fulfilledQuantity: 0,
    remainingQuantity: material.requiredQuantity,
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
    isFulfilled: false,
  }
}

function verification(
  material: Material,
  status: ContainerVerificationStatus,
  containerQuantity: number | null,
  unknownReason: ContainerCheckUnknownReason | null = null,
): ContainerVerification {
  return {
    bomItemId: material.id,
    typeId: material.typeId,
    typeName: material.typeName,
    requiredQuantity: material.requiredQuantity,
    containerQuantity,
    status,
    unknownReason,
  }
}

function hullRunProject(): GroupProject {
  return {
    id: PROJECT_ID,
    name: 'Hull run',
    status: 'in_progress',
    shortLinkCode: 'hull0001',
    containerName: CONTAINER_NAME,
    ownerCharacterName: 'Aria Solberg',
    ownerCorporationId: 98000001,
    membersCount: 2,
    items: [{ typeId: 22456, typeName: 'Sabre', meLevel: 10, teLevel: 20, runs: 1 }],
    totalBomValue: null,
    fulfillmentPercent: 0,
    approvedContributionValue: 0,
    brokerFeePercent: 3,
    salesTaxPercent: 3,
    lineRentalRatesOverride: null,
    blacklistGroupIds: [],
    blacklistTypeIds: [],
    createdAt: '2026-10-28T08:00:00+00:00',
    myRole: 'owner',
    myStatus: 'accepted',
  }
}

function i18nFor(locale: Locale) {
  return createI18n({ legacy: false, locale, messages: { en, fr } })
}

function givenVerification(lines: ContainerVerification[]): void {
  setActivePinia(createPinia())
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = hullRunProject()
  projectStore.bomItems = [bomLine(TRITANIUM), bomLine(PYERITE), bomLine(MEXALLON)]
  projectStore.containerVerification = lines
}

function mountBomTable(locale: Locale, lines: ContainerVerification[]): VueWrapper {
  givenVerification(lines)
  return mount(BomTable, { global: { plugins: [i18nFor(locale)] } })
}

function mountBanner(locale: Locale, lines: ContainerVerification[]): VueWrapper {
  givenVerification(lines)
  return mount(ContainerBanner, {
    props: { containerName: CONTAINER_NAME, projectId: PROJECT_ID },
    global: { plugins: [i18nFor(locale)] },
  })
}

function normalized(text: string): string {
  return text.replace(/\s+/g, ' ').trim()
}

function nameCellOf(wrapper: VueWrapper, material: Material): string {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.findAll('td')[1]?.text().startsWith(material.typeName))
  if (row === undefined) throw new Error(`no BOM row for ${material.typeName}`)
  return normalized(row.findAll('td')[1].text())
}

function occurrencesOf(text: string, searched: string): number {
  return text.split(searched).length - 1
}

const CHECKED_LINES = [
  verification(TRITANIUM, 'partial', 450),
  verification(PYERITE, 'verified', 800),
  verification(MEXALLON, 'missing', 0),
]

describe('BomTable state of each material line in the container', () => {
  it('shows a verified, a partial and a missing line in English', () => {
    const wrapper = mountBomTable('en', CHECKED_LINES)

    expect([nameCellOf(wrapper, TRITANIUM), nameCellOf(wrapper, PYERITE), nameCellOf(wrapper, MEXALLON)]).toEqual([
      expect.stringMatching(/^Tritanium Partial\b.*\b450 \/ 500\b/),
      expect.stringMatching(/^Pyerite Verified\b.*\b800 \/ 800\b/),
      expect.stringMatching(/^Mexallon Missing from container\b/),
    ])
  })

  it('shows a verified, a partial and a missing line in French', () => {
    const wrapper = mountBomTable('fr', CHECKED_LINES)

    expect([nameCellOf(wrapper, TRITANIUM), nameCellOf(wrapper, PYERITE), nameCellOf(wrapper, MEXALLON)]).toEqual([
      expect.stringMatching(/^Tritanium Partiel\b.*\b450 \/ 500\b/),
      expect.stringMatching(/^Pyerite Vérifié.*\b800 \/ 800\b/),
      expect.stringMatching(/^Mexallon Absent du container\b/),
    ])
  })

  it('shows a missing line without the label of a verified or partial line', () => {
    const wrapper = mountBomTable('en', CHECKED_LINES)

    expect(nameCellOf(wrapper, MEXALLON)).not.toMatch(/Verified|Partial/)
  })

  it.each<[string, ContainerVerification[]]>([
    ['container not found', [verification(TRITANIUM, 'container_not_found', null), verification(PYERITE, 'container_not_found', null), verification(MEXALLON, 'container_not_found', null)]],
    ['unknown', [verification(TRITANIUM, 'unknown', null, 'corporation_assets_not_synced'), verification(PYERITE, 'unknown', null, 'corporation_assets_not_synced'), verification(MEXALLON, 'unknown', null, 'corporation_assets_not_synced')]],
  ])('shows nothing on the lines when the check is %s, the banner tells it once', (_state, lines) => {
    const wrapper = mountBomTable('en', lines)

    expect([nameCellOf(wrapper, TRITANIUM), nameCellOf(wrapper, PYERITE), nameCellOf(wrapper, MEXALLON)]).toEqual(['Tritanium', 'Pyerite', 'Mexallon'])
  })
})

describe('ContainerBanner state of the container check', () => {
  it('tells once in English that the container is not found', () => {
    const wrapper = mountBanner('en', [verification(TRITANIUM, 'container_not_found', null), verification(PYERITE, 'container_not_found', null)])

    expect(occurrencesOf(normalized(wrapper.text()), 'Container not found')).toBe(1)
  })

  it('tells once in French that the container is not found', () => {
    const wrapper = mountBanner('fr', [verification(TRITANIUM, 'container_not_found', null), verification(PYERITE, 'container_not_found', null)])

    expect(occurrencesOf(normalized(wrapper.text()), 'Container introuvable')).toBe(1)
  })

  it.each<[ContainerCheckUnknownReason, RegExp]>([
    ['corporation_assets_not_synced', /Synchronisez les assets corpo/],
    ['no_visible_division', /Aucune division partagée/],
    ['owner_without_corporation', /L['’]owner n['’]a pas de corporation/],
  ])('tells once in French that the check cannot be made, with the action for %s', (reason, action) => {
    const wrapper = mountBanner('fr', [verification(TRITANIUM, 'unknown', null, reason), verification(PYERITE, 'unknown', null, reason)])
    const text = normalized(wrapper.text())

    expect([occurrencesOf(text, 'Non vérifiable'), action.test(text)]).toEqual([1, true])
  })

  it('tells in English that the check cannot be made, with a different action for each reason', () => {
    const reasons: ContainerCheckUnknownReason[] = ['corporation_assets_not_synced', 'no_visible_division', 'owner_without_corporation']
    const texts = reasons.map(reason => normalized(mountBanner('en', [verification(TRITANIUM, 'unknown', null, reason)]).text()))

    expect(texts.map(text => occurrencesOf(text, 'Cannot be checked'))).toEqual([1, 1, 1])
    expect(new Set(texts).size).toBe(3)
    expect(texts.filter(text => RAW_TRANSLATION_KEY.test(text))).toEqual([])
  })

  it('tells neither not found nor cannot be checked once the lines are checked or before any check', () => {
    const texts = [CHECKED_LINES, []].map(lines => normalized(mountBanner('en', lines).text()))

    expect(texts.map(text => /Container not found|Cannot be checked/.test(text))).toEqual([false, false])
  })
})
