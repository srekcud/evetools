import { mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectCard from '@/components/group-industry/ProjectCard.vue'
import AvailableProjectCard from '@/components/group-industry/AvailableProjectCard.vue'
import type { GroupProject } from '@/stores/group-industry/types'

type Locale = 'en' | 'fr'
type ProjectStatus = GroupProject['status']

const ALL_STATUSES: ProjectStatus[] = ['draft', 'published', 'in_progress', 'selling', 'completed']

const HECATE_PROJECT: GroupProject = {
  id: 'project-hecate',
  name: 'Hecate batch',
  status: 'draft',
  shortLinkCode: 'hecate77',
  containerNames: [],
  ownerCharacterName: 'Cyra Lune',
  ownerCorporationId: 98000002,
  membersCount: 1,
  contributionsCount: 0,
  items: [{ typeId: 35683, typeName: 'Hecate', meLevel: 8, teLevel: 16, runs: 12 }],
  totalBomValue: 310000000,
  fulfillmentPercent: 0,
  approvedContributionValue: 0,
  brokerFeePercent: 3,
  salesTaxPercent: 3.4,
  lineRentalRatesOverride: null,
  blacklistGroupIds: [],
  blacklistTypeIds: [],
  createdAt: '2026-10-25T08:00:00+00:00',
  myRole: 'owner',
  myStatus: 'accepted',
  private: false,
  notes: null,
  acceptsOutsideFavoriteSystem: false,
  excludesInvention: false,
  minimumOutsideSaving: 5,
  sellStructureId: null,
  sellStructureName: null,
}

const wrappers: VueWrapper[] = []

afterEach(() => {
  wrappers.splice(0).forEach(wrapper => wrapper.unmount())
})

function mountCard(component: typeof ProjectCard | typeof AvailableProjectCard, locale: Locale, status: ProjectStatus): VueWrapper {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(component, { props: { project: { ...HECATE_PROJECT, status } }, global: { plugins: [i18n] } })
  wrappers.push(wrapper)
  return wrapper
}

function statusBadgeTexts(wrapper: VueWrapper): string[] {
  return wrapper.findAll('span').filter(span => span.classes().includes('border') && span.classes().includes('rounded')).map(span => span.text())
}

function badgeLabelsOf(component: typeof ProjectCard | typeof AvailableProjectCard, locale: Locale): Record<string, string> {
  return Object.fromEntries(ALL_STATUSES.map((status) => {
    const labels = statusBadgeTexts(mountCard(component, locale, status))
    const expected = (locale === 'fr' ? fr : en).groupIndustry.status[status]
    return [status, labels.includes(expected) ? expected : `missing ${expected} in ${labels.join('|')}`]
  }))
}

describe('project status labels come from the translated groupIndustry.status keys', () => {
  it('writes the French statuses with their accents', () => {
    expect(fr.groupIndustry.status).toEqual({
      draft: 'Brouillon',
      published: 'Publié',
      in_progress: 'En cours',
      selling: 'En vente',
      completed: 'Terminé',
    })
  })

  it('shows the French status on the project card', () => {
    expect(badgeLabelsOf(ProjectCard, 'fr')).toEqual({
      draft: 'Brouillon',
      published: 'Publié',
      in_progress: 'En cours',
      selling: 'En vente',
      completed: 'Terminé',
    })
  })

  it('shows the French status on the available project card', () => {
    expect(badgeLabelsOf(AvailableProjectCard, 'fr')).toEqual({
      draft: 'Brouillon',
      published: 'Publié',
      in_progress: 'En cours',
      selling: 'En vente',
      completed: 'Terminé',
    })
  })

  it('keeps the English statuses on both cards', () => {
    expect({
      project: badgeLabelsOf(ProjectCard, 'en'),
      available: badgeLabelsOf(AvailableProjectCard, 'en'),
    }).toEqual({
      project: { draft: 'Draft', published: 'Published', in_progress: 'In Progress', selling: 'Selling', completed: 'Completed' },
      available: { draft: 'Draft', published: 'Published', in_progress: 'In Progress', selling: 'Selling', completed: 'Completed' },
    })
  })
})

describe('the blacklist has one name taken from the glossary', () => {
  it('uses Blacklist on the creation modal and never « liste noire » in French', () => {
    const frenchTexts = JSON.stringify(fr.groupIndustry).toLowerCase()
    expect({
      blacklist: fr.groupIndustry.modals.newProject.blacklist,
      blacklistLabel: fr.groupIndustry.modals.newProject.blacklistLabel,
      listeNoire: frenchTexts.includes('liste noire'),
    }).toEqual({ blacklist: 'Blacklist', blacklistLabel: 'Blacklist', listeNoire: false })
  })

  it('accents the French blacklist hints of the creation modal', () => {
    expect({
      description: fr.groupIndustry.modals.newProject.blacklistDesc,
      hint: fr.groupIndustry.modals.newProject.blacklistHint,
    }).toEqual({
      description: 'Les items ci-dessous seront achetés, pas fabriqués.',
      hint: 'Défauts issus de vos paramètres industrie. Surcharge par projet.',
    })
  })

  it('keeps the production blacklist editor title of the project screen', () => {
    expect({
      fr: fr.groupIndustry.blacklistEditor.title,
      en: en.groupIndustry.blacklistEditor.title,
    }).toEqual({ fr: 'Blacklist de production', en: 'Production blacklist' })
  })
})

describe('what a BOM line delivered is called the same everywhere', () => {
  it('names the BOM column, the chain node counter and the materials summary « delivered »', () => {
    expect({
      bomColumn: { fr: fr.groupIndustry.bom.fulfilled, en: en.groupIndustry.bom.fulfilled },
      nodeCounter: { fr: fr.groupIndustry.chain.flow.supplied, en: en.groupIndustry.chain.flow.supplied },
      materialsSummary: { fr: fr.groupIndustry.chain.flow.materialsSummary, en: en.groupIndustry.chain.flow.materialsSummary },
    }).toEqual({
      bomColumn: { fr: 'Livré', en: 'Delivered' },
      nodeCounter: { fr: '{fulfilled} / {required} livrés', en: '{fulfilled} / {required} delivered' },
      materialsSummary: { fr: '{supplied} / {total} lignes de matériaux livrées', en: '{supplied} / {total} material lines delivered' },
    })
  })

  it('keeps the delivered state of the purchases to deliver and of the courier', () => {
    expect({
      purchases: { fr: fr.groupIndustry.purchasesToDeliver.state.delivered, en: en.groupIndustry.purchasesToDeliver.state.delivered },
      courier: { fr: fr.groupIndustry.courier.state.delivered, en: en.groupIndustry.courier.state.delivered },
    }).toEqual({
      purchases: { fr: 'livré', en: 'delivered' },
      courier: { fr: 'livré', en: 'delivered' },
    })
  })
})
