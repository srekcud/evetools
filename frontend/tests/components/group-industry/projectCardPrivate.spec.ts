import { mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectCard from '@/components/group-industry/ProjectCard.vue'
import type { GroupProject } from '@/stores/group-industry/types'

const HECATE_PROJECT: GroupProject = {
  id: 'project-hecate',
  name: 'Hecate batch',
  status: 'draft',
  shortLinkCode: 'hecate77',
  containerNames: [],
  ownerCharacterName: 'Cyra Lune',
  ownerCorporationId: 98000002,
  membersCount: 1,
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

function mountProjectCard(privacy: Pick<GroupProject, 'private'>): VueWrapper {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(ProjectCard, { props: { project: { ...HECATE_PROJECT, ...privacy } }, global: { plugins: [i18n] } })
}

function privateBadges(wrapper: VueWrapper): string[] {
  return wrapper.findAll('span').map(span => span.text()).filter(text => text === 'Private')
}

describe('ProjectCard privacy', () => {
  it('shows a Private badge on a private project and none on a shared one', () => {
    expect({
      private: privateBadges(mountProjectCard({ private: true })),
      shared: privateBadges(mountProjectCard({ private: false })),
    }).toEqual({ private: ['Private'], shared: [] })
  })
})
