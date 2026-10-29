import { mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import CharacterSyncFailuresAlert from '@/components/industry/CharacterSyncFailuresAlert.vue'
import type { CharacterSyncFailure, CharacterSyncFailureReason } from '@/stores/industry/types'

const EmptyView = { render: () => null }
const TITLE = 'Jobs not synced for 3 characters, matching used the last known jobs:'

function mountAlert(failures: CharacterSyncFailure[], locale: 'en' | 'fr' = 'en'): VueWrapper {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: EmptyView },
      { path: '/characters', component: EmptyView },
    ],
  })
  return mount(CharacterSyncFailuresAlert, {
    props: { title: TITLE, failures },
    global: { plugins: [i18n, router] },
  })
}

function lines(wrapper: VueWrapper): string[] {
  return wrapper.findAll('li').map(line => line.text())
}

function linkTargets(wrapper: VueWrapper, label: string): (string | undefined)[] {
  return wrapper.findAll('a').filter(link => link.text() === label).map(link => link.attributes('href'))
}

function syncFailureKeys(messages: { industry: Record<string, unknown> }): string[] {
  const syncFailures = messages.industry.syncFailures as Record<string, unknown> | undefined
  if (!syncFailures) return []
  const reasons = syncFailures.reasons as Record<string, unknown> | undefined
  return [
    ...Object.keys(syncFailures).filter(key => key !== 'reasons'),
    ...Object.keys(reasons ?? {}).map(key => `reasons.${key}`),
  ].sort()
}

describe('CharacterSyncFailuresAlert', () => {
  it('shows the title and one line per failed character in the given order', () => {
    const wrapper = mountAlert([
      { characterName: 'Pilot Three', reason: 'sync_failed', statusCode: null },
      { characterName: 'Pilot One', reason: 'esi_error', statusCode: 404 },
      { characterName: 'Pilot Two', reason: 'esi_rate_limited', statusCode: 429 },
    ])

    expect(wrapper.text()).toContain(TITLE)
    expect(lines(wrapper)).toEqual([
      'Pilot Three: sync failed',
      'Pilot One: ESI error 404',
      'Pilot Two: ESI request limit reached, retry later',
    ])
  })

  it.each<[CharacterSyncFailureReason, number | null, string]>([
    ['missing_token', null, 'Pilot One: no ESI token, reconnect the character'],
    ['missing_scope', null, 'Pilot One: the token lacks a required ESI permission, reconnect the character'],
    ['authentication_required', null, 'Pilot One: authorization revoked or expired, reconnect the character'],
    ['esi_rate_limited', 420, 'Pilot One: ESI request limit reached, retry later'],
    ['esi_unavailable', 0, 'Pilot One: ESI unavailable (maintenance or server error), retry later'],
    ['esi_error', 403, 'Pilot One: ESI error 403'],
    ['sync_failed', null, 'Pilot One: sync failed'],
  ])('translates the %s reason in english', (reason, statusCode, expectedLine) => {
    const wrapper = mountAlert([{ characterName: 'Pilot One', reason, statusCode }])

    expect(lines(wrapper)).toEqual([expectedLine])
  })

  it.each<[CharacterSyncFailureReason, number | null, string]>([
    ['missing_token', null, 'Pilot Two : aucun token ESI, reconnectez le personnage'],
    ['missing_scope', null, 'Pilot Two : il manque une autorisation ESI au token, reconnectez le personnage'],
    ['authentication_required', null, 'Pilot Two : autorisation révoquée ou expirée, reconnectez le personnage'],
    ['esi_rate_limited', 429, 'Pilot Two : limite de requêtes ESI atteinte, réessayez plus tard'],
    ['esi_unavailable', 504, 'Pilot Two : ESI indisponible (maintenance ou erreur serveur), réessayez plus tard'],
    ['esi_error', 401, 'Pilot Two : erreur ESI 401'],
    ['sync_failed', null, 'Pilot Two : échec de la synchronisation'],
  ])('translates the %s reason in french', (reason, statusCode, expectedLine) => {
    const wrapper = mountAlert([{ characterName: 'Pilot Two', reason, statusCode }], 'fr')

    expect(lines(wrapper)).toEqual([expectedLine])
  })

  it.each<CharacterSyncFailureReason>(['missing_token', 'missing_scope', 'authentication_required'])(
    'links to the characters page when a character must be reconnected for %s',
    (reason) => {
      const wrapper = mountAlert([
        { characterName: 'Pilot One', reason: 'esi_unavailable', statusCode: 503 },
        { characterName: 'Pilot Two', reason, statusCode: null },
      ])

      expect(linkTargets(wrapper, 'Reconnect characters')).toEqual(['/characters'])
    },
  )

  it('links to the characters page in french', () => {
    const wrapper = mountAlert([{ characterName: 'Pilot Three', reason: 'missing_scope', statusCode: null }], 'fr')

    expect(linkTargets(wrapper, 'Reconnecter des personnages')).toEqual(['/characters'])
  })

  it('shows no link when no character must be reconnected', () => {
    const wrapper = mountAlert([
      { characterName: 'Pilot One', reason: 'esi_error', statusCode: 403 },
      { characterName: 'Pilot Two', reason: 'esi_rate_limited', statusCode: 420 },
      { characterName: 'Pilot Three', reason: 'sync_failed', statusCode: null },
    ])

    expect(lines(wrapper)).toHaveLength(3)
    expect(wrapper.findAll('a')).toHaveLength(0)
  })

  it('shows no link for esi_unavailable alone', () => {
    const wrapper = mountAlert([{ characterName: 'Pilot Two', reason: 'esi_unavailable', statusCode: 502 }])

    expect(lines(wrapper)).toEqual(['Pilot Two: ESI unavailable (maintenance or server error), retry later'])
    expect(wrapper.findAll('a')).toHaveLength(0)
  })

  it('asks to be dismissed from a close button with an accessible name', async () => {
    const wrapper = mountAlert([{ characterName: 'Pilot Two', reason: 'esi_error', statusCode: 403 }])

    const closeButtons = wrapper.findAll('button').filter(button => button.attributes('aria-label') === 'Close')
    expect(closeButtons).toHaveLength(1)
    await closeButtons[0].trigger('click')

    expect(wrapper.emitted('dismiss')).toEqual([[]])
  })

  it('has the same sync failure keys in english and french', () => {
    const expectedKeys = [
      'jobsTitle',
      'line',
      'reasons.authentication_required',
      'reasons.esi_error',
      'reasons.esi_rate_limited',
      'reasons.esi_unavailable',
      'reasons.missing_scope',
      'reasons.missing_token',
      'reasons.sync_failed',
      'reconnectLink',
      'skillsTitle',
    ]

    expect(syncFailureKeys(en)).toEqual(expectedKeys)
    expect(syncFailureKeys(fr)).toEqual(expectedKeys)
  })
})
