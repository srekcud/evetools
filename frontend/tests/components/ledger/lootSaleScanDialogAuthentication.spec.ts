import { mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import LootSaleScanDialog from '@/components/ledger/pve/LootSaleScanDialog.vue'
import type { LootSaleScanResult } from '@/types/pve'

const NEW_PVE_KEYS = [
  'lootScanSummaryUnknownProjects',
  'corpProjectsAuthenticationError',
  'charactersToReconnect',
  'reconnectCharactersLink',
]

const EmptyView = { render: () => null }

function scanResult(overrides: Partial<LootSaleScanResult>): LootSaleScanResult {
  return {
    scannedTransactions: 3,
    scannedContracts: 2,
    scannedProjects: 0,
    detectedSales: [],
    charactersToReconnect: [],
    corporationsWithoutValidCharacter: [],
    ...overrides,
  }
}

function mountDialog(scanResults: LootSaleScanResult, locale: 'en' | 'fr' = 'en'): VueWrapper {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: EmptyView },
      { path: '/characters', component: EmptyView },
    ],
  })
  return mount(LootSaleScanDialog, {
    props: { visible: true, scanResults, isImporting: false },
    global: { plugins: [i18n, router], stubs: { teleport: true } },
  })
}

function reconnectLinkTargets(wrapper: VueWrapper, label: string): (string | undefined)[] {
  return wrapper.findAll('a').filter(link => link.text() === label).map(link => link.attributes('href'))
}

function newPveMessages(messages: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(Object.entries(messages).filter(([key]) => NEW_PVE_KEYS.includes(key)))
}

describe('LootSaleScanDialog corporation project authentication', () => {
  it('shows unknown projects instead of 0 when scannedProjects is null', () => {
    const wrapper = mountDialog(scanResult({ scannedTransactions: 3, scannedContracts: 2, scannedProjects: null }))

    expect(wrapper.text()).toContain('3 transactions, 2 contracts, projects: unknown - 0 sales detected')
    expect(wrapper.text()).not.toContain('0 projects')
  })

  it('shows unknown projects in French when scannedProjects is null', () => {
    const wrapper = mountDialog(scanResult({ scannedTransactions: 3, scannedContracts: 2, scannedProjects: null }), 'fr')

    expect(wrapper.text()).toContain('3 transactions, 2 contrats, projets : inconnu - 0 ventes détectées')
    expect(wrapper.text()).not.toContain('0 projets')
  })

  it('shows an authentication error alert naming the unreadable corporation with a link to the characters page', () => {
    const wrapper = mountDialog(scanResult({ scannedProjects: null, corporationsWithoutValidCharacter: ['Fictional Mining Corp'] }))

    const alerts = wrapper.findAll('[role="alert"]')
    expect(alerts).toHaveLength(1)
    expect(alerts[0].text()).toContain(
      'Authentication error: projects of Fictional Mining Corp could not be read, no character of this corporation has a valid authorization.',
    )
    expect(reconnectLinkTargets(wrapper, 'Reconnect characters')).toEqual(['/characters'])
  })

  it('lists several characters to reconnect with the plural message and a link to the characters page', () => {
    const wrapper = mountDialog(scanResult({ scannedProjects: 1, charactersToReconnect: ['Pilot One', 'Pilot Two', 'Pilot Four'] }))

    expect(wrapper.text()).toContain('3 characters to reconnect, skipped by the scan: Pilot One, Pilot Two, Pilot Four')
    expect(wrapper.findAll('[role="alert"]')).toHaveLength(0)
    expect(reconnectLinkTargets(wrapper, 'Reconnect characters')).toEqual(['/characters'])
  })

  it('lists a single character to reconnect with the singular message', () => {
    const wrapper = mountDialog(scanResult({ scannedProjects: 2, charactersToReconnect: ['Pilot One'] }))

    expect(wrapper.text()).toContain('1 character to reconnect, skipped by the scan: Pilot One')
    expect(wrapper.text()).not.toContain('characters to reconnect')
  })

  it('lists a single character to reconnect with the French singular message', () => {
    const wrapper = mountDialog(scanResult({ scannedProjects: 2, charactersToReconnect: ['Pilot Three'] }), 'fr')

    expect(wrapper.text()).toContain('1 personnage à reconnecter, ignoré par le scan : Pilot Three')
    expect(reconnectLinkTargets(wrapper, 'Reconnecter des personnages')).toEqual(['/characters'])
  })

  it('keeps the empty state below the alert and the characters to reconnect when no sale is detected', () => {
    const wrapper = mountDialog(scanResult({
      scannedTransactions: 5,
      scannedContracts: 0,
      scannedProjects: null,
      charactersToReconnect: ['Pilot One', 'Pilot Two', 'Pilot Three', 'Pilot Four'],
      corporationsWithoutValidCharacter: ['Fictional Mining Corp', 'Second Fictional Corp'],
    }))

    expect(wrapper.text()).toContain('5 transactions, 0 contracts, projects: unknown - 0 sales detected')
    expect(wrapper.get('[role="alert"]').text()).toContain(
      'Authentication error: projects of Fictional Mining Corp, Second Fictional Corp could not be read',
    )
    expect(wrapper.text()).toContain('4 characters to reconnect, skipped by the scan: Pilot One, Pilot Two, Pilot Three, Pilot Four')
    expect(wrapper.text()).toContain('No loot sales detected')
    const targets = reconnectLinkTargets(wrapper, 'Reconnect characters')
    expect(targets.length).toBeGreaterThan(0)
    expect(new Set(targets)).toEqual(new Set(['/characters']))
  })

  it('keeps the 0 projects scanned summary without alert nor reconnect message when every corporation was read', () => {
    const wrapper = mountDialog(scanResult({ scannedTransactions: 4, scannedContracts: 1, scannedProjects: 0 }))

    expect(wrapper.text()).toContain('4 transactions, 1 contracts, 0 projects scanned - 0 sales detected')
    expect(wrapper.findAll('[role="alert"]')).toHaveLength(0)
    expect(wrapper.text()).not.toContain('to reconnect')
    expect(reconnectLinkTargets(wrapper, 'Reconnect characters')).toEqual([])
  })

  it('declares the four new pve keys with the specified texts in English and French', () => {
    expect(newPveMessages(en.pve)).toEqual({
      lootScanSummaryUnknownProjects: '{transactions} transactions, {contracts} contracts, projects: unknown - {detected} sales detected',
      corpProjectsAuthenticationError: 'Authentication error: projects of {corporations} could not be read, no character of this corporation has a valid authorization.',
      charactersToReconnect: '{count} character to reconnect, skipped by the scan: {names} | {count} characters to reconnect, skipped by the scan: {names}',
      reconnectCharactersLink: 'Reconnect characters',
    })
    expect(newPveMessages(fr.pve)).toEqual({
      lootScanSummaryUnknownProjects: '{transactions} transactions, {contracts} contrats, projets : inconnu - {detected} ventes détectées',
      corpProjectsAuthenticationError: "Erreur d'authentification : projets de {corporations} illisibles, aucun personnage de cette corporation n'a d'autorisation valide.",
      charactersToReconnect: '{count} personnage à reconnecter, ignoré par le scan : {names} | {count} personnages à reconnecter, ignorés par le scan : {names}',
      reconnectCharactersLink: 'Reconnecter des personnages',
    })
  })
})
