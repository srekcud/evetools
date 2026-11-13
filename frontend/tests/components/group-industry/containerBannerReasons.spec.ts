import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContainerBanner from '@/components/group-industry/ContainerBanner.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useSyncStore } from '@/stores/sync'
import type { ContainerVerification, ContainerVerificationStatus } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

type Locale = 'en' | 'fr'

const PROJECT_ID = 'project-hull-run'
const CONTAINER_NAME = 'Build'
const ASSETS_SYNCED_AT = '2026-11-10T08:15:00+00:00'
const SYNC_DATE_TEXT = /10\/11|11\/10|10\.11/
const REFRESH_URL = '/me/corporation/assets/refresh'
const VERIFICATION_URL = `/industry/projects/${PROJECT_ID}/container-verification`
const RAW_TRANSLATION_KEY = /groupIndustry\.[a-zA-Z.]+/

function line(status: ContainerVerificationStatus, assetsSyncedAt: string | null = ASSETS_SYNCED_AT): ContainerVerification {
  return {
    bomItemId: 'bom-tritanium',
    typeId: 34,
    typeName: 'Tritanium',
    requiredQuantity: 500,
    containerQuantity: null,
    status,
    unknownReason: null,
    assetsSyncedAt,
    missingContainerNames: [],
  }
}

function mountBanner(locale: Locale, lines: ContainerVerification[], canVerify = true): VueWrapper {
  setActivePinia(createPinia())
  useGroupProjectStore().containerVerification = lines
  return mount(ContainerBanner, {
    props: { containerNames: [CONTAINER_NAME], projectId: PROJECT_ID, canVerify },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

function normalized(wrapper: VueWrapper): string {
  return wrapper.text().replace(/\s+/g, ' ').trim()
}

function completedCorporationAssetsSync() {
  useSyncStore().syncStatus['corporation-assets'] = {
    syncType: 'corporation-assets',
    status: 'completed',
    progress: 100,
    message: null,
    data: null,
    timestamp: '2026-11-12T10:00:00+00:00',
  }
}

function refreshAnswers(refreshAnswer: unknown): void {
  vi.mocked(apiRequest).mockImplementation(async path => (path === REFRESH_URL ? refreshAnswer : []))
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ContainerBanner reason of a container that is not found', () => {
  it.each([
    ['en', 'container_in_hidden_division', /hidden from the project owner/],
    ['fr', 'container_in_hidden_division', /masquée au propriétaire/],
    ['en', 'corporation_assets_stale', /out of date/],
    ['fr', 'corporation_assets_stale', /périmés/],
  ] as [Locale, ContainerVerificationStatus, RegExp][])('says why in %s for %s', (locale, status, expectedWording) => {
    const text = normalized(mountBanner(locale, [line(status)]))

    expect([expectedWording.test(text), RAW_TRANSLATION_KEY.test(text)]).toEqual([true, false])
  })

  it('keeps the existing wording when no container has the name in the shared divisions', () => {
    const text = normalized(mountBanner('en', [line('container_not_found')]))

    expect([text.includes('Container not found'), /hidden from the project owner|out of date/.test(text)]).toEqual([true, false])
  })

  it('shows no problem for a verified container', () => {
    const text = normalized(mountBanner('en', [line('verified')]))

    expect(/Container not found|hidden from the project owner|out of date/.test(text)).toBe(false)
  })
})

describe('ContainerBanner corporation assets sync date', () => {
  it('shows the date of the last sync next to the check', () => {
    const wrapper = mountBanner('en', [line('container_not_found')])

    expect(wrapper.get('[data-testid="container-assets-synced-at"]').text()).toMatch(SYNC_DATE_TEXT)
  })

  it('shows no date when the assets were never synced', () => {
    const wrapper = mountBanner('en', [line('unknown', null)])

    expect(wrapper.find('[data-testid="container-assets-synced-at"]').exists()).toBe(false)
  })
})

describe('ContainerBanner corporation assets refresh', () => {
  it('offers the refresh only to a member who can run the check', () => {
    expect([
      mountBanner('en', [line('container_not_found')], true).find('[data-testid="container-refresh-assets"]').exists(),
      mountBanner('en', [line('container_not_found')], false).find('[data-testid="container-refresh-assets"]').exists(),
    ]).toEqual([true, false])
  })

  it('starts the corporation assets sync, then checks the container again once the sync is completed', async () => {
    refreshAnswers({ status: 'pending' })
    const wrapper = mountBanner('en', [line('container_not_found')])

    await wrapper.get('[data-testid="container-refresh-assets"]').trigger('click')
    await flushPromises()
    const callsBeforeCompletion = vi.mocked(apiRequest).mock.calls.map(call => call[0])
    completedCorporationAssetsSync()
    await flushPromises()

    expect([
      callsBeforeCompletion,
      vi.mocked(apiRequest).mock.calls.map(call => call[0]),
    ]).toEqual([[REFRESH_URL], [REFRESH_URL, VERIFICATION_URL]])
  })

  it('posts the refresh', async () => {
    refreshAnswers({ status: 'pending' })
    const wrapper = mountBanner('en', [line('container_not_found')])

    await wrapper.get('[data-testid="container-refresh-assets"]').trigger('click')
    await flushPromises()

    expect(vi.mocked(apiRequest).mock.calls[0][1]).toMatchObject({ method: 'POST' })
  })

  it('shows the reason and does not check again when no Director can refresh the assets', async () => {
    refreshAnswers({ status: 'error', error: 'No Director connected.' })
    const wrapper = mountBanner('en', [line('container_not_found')])

    await wrapper.get('[data-testid="container-refresh-assets"]').trigger('click')
    await flushPromises()
    completedCorporationAssetsSync()
    await flushPromises()

    expect([
      wrapper.get('[data-testid="container-refresh-error"]').text(),
      vi.mocked(apiRequest).mock.calls.map(call => call[0]),
    ]).toEqual(['No Director connected.', [REFRESH_URL]])
  })
})
