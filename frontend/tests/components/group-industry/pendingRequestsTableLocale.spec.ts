import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import PendingRequestsTable from '@/components/group-industry/PendingRequestsTable.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { GroupMember } from '@/stores/group-industry/types'
import { expectNoneOf, expectOnlyLocaleTexts, messageOf, TEST_LOCALES, type TestLocale } from '../../localeTexts'

const CORPORATION_ID = 98_000_217

const PENDING_PILOT: GroupMember = {
  id: 'member-217',
  characterName: 'Ilse Varga',
  characterId: 2_119_000_217,
  corporationId: CORPORATION_ID,
  corporationName: 'Varga Haulage',
  role: 'member',
  status: 'pending',
  totalContributionValue: 0,
  contributionCount: 0,
  joinedAt: '2026-10-21T09:30:00+00:00',
}

const FORMER_HARDCODED_TEXTS = [
  'Pending Requests',
  'No pending requests',
  'Share the join link to invite new members',
  'Requested',
  'Short Link',
]

function mountPendingRequests(locale: TestLocale, members: GroupMember[]) {
  setActivePinia(createPinia())
  useGroupDistributionStore().members = members
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(PendingRequestsTable, {
    global: { plugins: [i18n] },
    props: { projectId: 'project-217', isAdmin: true },
  })
}

enableAutoUnmount(afterEach)

describe('PendingRequestsTable locale', () => {
  it.each(TEST_LOCALES)('shows only %s texts in the table of pending requests', (locale) => {
    const text = mountPendingRequests(locale, [PENDING_PILOT]).text()

    expectOnlyLocaleTexts(text, locale, [
      'groupIndustry.members.pendingRequests',
      'groupIndustry.members.columns.character',
      'groupIndustry.members.columns.requested',
      'groupIndustry.members.columns.actions',
      'groupIndustry.members.viaShortLink',
      'groupIndustry.members.acceptRequest',
      'groupIndustry.members.rejectRequest',
    ])
    expect(text).toContain(messageOf(locale, 'groupIndustry.members.corporationNumber').replace('{id}', String(CORPORATION_ID)))
    if (locale === 'fr') expectNoneOf(text, FORMER_HARDCODED_TEXTS)
  })

  it.each(TEST_LOCALES)('shows only %s texts when no request is pending', (locale) => {
    const text = mountPendingRequests(locale, []).text()

    expectOnlyLocaleTexts(text, locale, [
      'groupIndustry.members.pendingRequests',
      'groupIndustry.members.noPending',
      'groupIndustry.members.noPendingHint',
    ])
    if (locale === 'fr') expectNoneOf(text, FORMER_HARDCODED_TEXTS)
  })
})
