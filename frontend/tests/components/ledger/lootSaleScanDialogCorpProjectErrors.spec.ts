import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import LootSaleScanDialog from '@/components/ledger/pve/LootSaleScanDialog.vue'
import type { CorporationProjectError, DetectedLootSale, LootSaleScanResult } from '@/types/pve'

const EmptyView = { render: () => null }

const PROJECT_LIST_UNAVAILABLE: CorporationProjectError = {
  corporationName: 'Fictional Mining Corp',
  projectName: null,
  characterName: null,
  reason: 'esi_error',
  httpStatus: 503,
}

const VELDSPAR_HAUL_DETAIL_UNAVAILABLE: CorporationProjectError = {
  corporationName: 'Fictional Mining Corp',
  projectName: 'Veldspar Haul',
  characterName: null,
  reason: 'esi_error',
  httpStatus: 502,
}

const PILOT_TWO_WITHOUT_PROJECTS_SCOPE: CorporationProjectError = {
  corporationName: 'Fictional Mining Corp',
  projectName: 'Tritanium Drive',
  characterName: 'Pilot Two',
  reason: 'missing_projects_scope',
  httpStatus: null,
}

const NO_VALID_CHARACTER: CorporationProjectError = {
  corporationName: 'Fictional Mining Corp',
  projectName: null,
  characterName: null,
  reason: 'no_valid_character',
  httpStatus: null,
}

const PROJECT_LIST_UNREACHABLE: CorporationProjectError = {
  corporationName: 'Fictional Mining Corp',
  projectName: null,
  characterName: null,
  reason: 'esi_error',
  httpStatus: null,
}

const PILOT_ONE_CONTRIBUTION_INCOMPLETE: CorporationProjectError = {
  corporationName: 'Fictional Mining Corp',
  projectName: 'Tritanium Drive',
  characterName: 'Pilot One',
  reason: 'unexpected_response',
  httpStatus: null,
}

function contributionOfUnknownValue(): DetectedLootSale {
  return {
    transactionId: -1830477215,
    contractId: 0,
    projectId: 'a1b2c3d4-project',
    projectName: 'Tritanium Drive',
    type: 'corp_project',
    typeId: 34,
    typeName: '1000x Tritanium',
    quantity: 1000,
    price: null,
    dateIssued: '2026-10-28',
    characterName: 'Pilot One',
    source: 'corp_project',
    selected: true,
    needsPriceInput: true,
  }
}

function veldsparContribution(): DetectedLootSale {
  return {
    transactionId: -918273645,
    contractId: 0,
    projectId: 'e5f6a7b8-project',
    projectName: 'Veldspar Haul',
    type: 'corp_project',
    typeId: 1230,
    typeName: '400x Veldspar',
    quantity: 400,
    price: 2900,
    dateIssued: '2026-10-27',
    characterName: 'Pilot Two',
    source: 'corp_project',
    selected: true,
    needsPriceInput: false,
  }
}

function scanResult(overrides: Partial<LootSaleScanResult>): LootSaleScanResult {
  return {
    scannedTransactions: 3,
    scannedContracts: 2,
    scannedProjects: 1,
    detectedSales: [],
    charactersToReconnect: [],
    corporationProjectErrors: [],
    noLootTypesConfigured: false,
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

function alertTexts(wrapper: VueWrapper): string[] {
  return wrapper.findAll('[role="alert"]').map(alert => alert.text())
}

function reconnectLinkTargets(wrapper: VueWrapper, label: string): (string | undefined)[] {
  return wrapper.findAll('a').filter(link => link.text() === label).map(link => link.attributes('href'))
}

function buttonsLabelled(wrapper: VueWrapper, label: string) {
  return wrapper.findAll('button').filter(button => button.text() === label)
}

function messageAt(messages: unknown, path: string): unknown {
  return path.split('.').reduce<unknown>(
    (node, key) => (node !== null && typeof node === 'object' ? (node as Record<string, unknown>)[key] : undefined),
    messages,
  )
}

enableAutoUnmount(afterEach)

describe('LootSaleScanDialog corporation project errors', () => {
  it('names the corporation whose project list answered an ESI error, without the characters link', () => {
    const wrapper = mountDialog(scanResult({ scannedProjects: null, corporationProjectErrors: [PROJECT_LIST_UNAVAILABLE] }))

    expect(alertTexts(wrapper)).toHaveLength(1)
    expect(alertTexts(wrapper)[0]).toContain('Projects of Fictional Mining Corp could not be read: ESI error (HTTP 503)')
    expect(reconnectLinkTargets(wrapper, 'Reconnect characters')).toEqual([])
  })

  it('names the corporation whose project list answered an ESI error in French', () => {
    const wrapper = mountDialog(scanResult({ scannedProjects: null, corporationProjectErrors: [PROJECT_LIST_UNAVAILABLE] }), 'fr')

    expect(alertTexts(wrapper)[0]).toContain('Projets de Fictional Mining Corp illisibles : erreur ESI (HTTP 503)')
  })

  it('names the project whose detail answered an ESI error', () => {
    const wrapper = mountDialog(scanResult({ scannedProjects: 2, corporationProjectErrors: [VELDSPAR_HAUL_DETAIL_UNAVAILABLE] }))

    expect(alertTexts(wrapper)[0]).toContain('Project Veldspar Haul of Fictional Mining Corp could not be read: ESI error (HTTP 502)')
  })

  it('names the project whose detail answered an ESI error in French', () => {
    const wrapper = mountDialog(scanResult({ scannedProjects: 2, corporationProjectErrors: [VELDSPAR_HAUL_DETAIL_UNAVAILABLE] }), 'fr')

    expect(alertTexts(wrapper)[0]).toContain('Projet Veldspar Haul de Fictional Mining Corp illisible : erreur ESI (HTTP 502)')
  })

  it('asks to reconnect the contributor whose token lacks the projects scope with the characters link', () => {
    const wrapper = mountDialog(scanResult({ corporationProjectErrors: [PILOT_TWO_WITHOUT_PROJECTS_SCOPE] }))

    expect(alertTexts(wrapper)[0]).toContain(
      'Contribution of Pilot Two to project Tritanium Drive could not be read: access to corporation projects not granted, reconnect the character',
    )
    expect(reconnectLinkTargets(wrapper, 'Reconnect characters')).toEqual(['/characters'])
  })

  it('asks to reconnect the contributor whose token lacks the projects scope in French', () => {
    const wrapper = mountDialog(scanResult({ corporationProjectErrors: [PILOT_TWO_WITHOUT_PROJECTS_SCOPE] }), 'fr')

    expect(alertTexts(wrapper)[0]).toContain(
      'Contribution de Pilot Two au projet Tritanium Drive illisible : accès aux projets corporation non accordé, reconnectez le personnage',
    )
    expect(reconnectLinkTargets(wrapper, 'Reconnecter des personnages')).toEqual(['/characters'])
  })

  it('reports a corporation without any valid character in French with the characters link', () => {
    const wrapper = mountDialog(scanResult({ scannedProjects: null, corporationProjectErrors: [NO_VALID_CHARACTER] }), 'fr')

    expect(alertTexts(wrapper)[0]).toContain(
      "Projets de Fictional Mining Corp illisibles : aucun personnage de cette corporation n'a d'autorisation valide",
    )
    expect(reconnectLinkTargets(wrapper, 'Reconnecter des personnages')).toEqual(['/characters'])
  })

  it('reports an unreachable ESI without an HTTP status', () => {
    const wrapper = mountDialog(scanResult({ scannedProjects: null, corporationProjectErrors: [PROJECT_LIST_UNREACHABLE] }))

    expect(alertTexts(wrapper)[0]).toContain('Projects of Fictional Mining Corp could not be read: ESI unreachable')
    expect(alertTexts(wrapper)[0]).not.toContain('HTTP')
  })

  it('reports an unreachable ESI without an HTTP status in French', () => {
    const wrapper = mountDialog(scanResult({ scannedProjects: null, corporationProjectErrors: [PROJECT_LIST_UNREACHABLE] }), 'fr')

    expect(alertTexts(wrapper)[0]).toContain('Projets de Fictional Mining Corp illisibles : ESI injoignable')
  })

  it('reports an incomplete contribution answer', () => {
    const wrapper = mountDialog(scanResult({ corporationProjectErrors: [PILOT_ONE_CONTRIBUTION_INCOMPLETE] }))

    expect(alertTexts(wrapper)[0]).toContain('Contribution of Pilot One to project Tritanium Drive could not be read: incomplete ESI response')
    expect(reconnectLinkTargets(wrapper, 'Reconnect characters')).toEqual([])
  })

  it('reports an incomplete contribution answer in French', () => {
    const wrapper = mountDialog(scanResult({ corporationProjectErrors: [PILOT_ONE_CONTRIBUTION_INCOMPLETE] }), 'fr')

    expect(alertTexts(wrapper)[0]).toContain('Contribution de Pilot One au projet Tritanium Drive illisible : réponse ESI incomplète')
  })

  it('lists one alert per error in the received order', () => {
    const wrapper = mountDialog(scanResult({
      scannedProjects: null,
      corporationProjectErrors: [NO_VALID_CHARACTER, VELDSPAR_HAUL_DETAIL_UNAVAILABLE, PILOT_ONE_CONTRIBUTION_INCOMPLETE],
    }))

    const alerts = alertTexts(wrapper)
    expect(alerts).toHaveLength(3)
    expect(alerts[0]).toContain('Projects of Fictional Mining Corp could not be read: no character of this corporation has a valid authorization')
    expect(alerts[1]).toContain('Project Veldspar Haul of Fictional Mining Corp could not be read: ESI error (HTTP 502)')
    expect(alerts[2]).toContain('Contribution of Pilot One to project Tritanium Drive could not be read: incomplete ESI response')
  })

  it('keeps the detected sales listed below the project errors', () => {
    const wrapper = mountDialog(scanResult({ scannedProjects: 2, detectedSales: [veldsparContribution()], corporationProjectErrors: [VELDSPAR_HAUL_DETAIL_UNAVAILABLE] }))

    expect(alertTexts(wrapper)).toHaveLength(1)
    expect(wrapper.text()).toContain('400x Veldspar')
    expect(wrapper.text()).not.toContain('No loot sales detected')
  })

  it('asks the value of a contribution of unknown price before allowing its import', async () => {
    const wrapper = mountDialog(scanResult({ detectedSales: [contributionOfUnknownValue()] }))

    const valueInputs = wrapper.findAll('input[aria-label="1000x Tritanium"]')
    expect(valueInputs).toHaveLength(1)
    const [addButton] = buttonsLabelled(wrapper, 'Add')
    expect(addButton.attributes('disabled')).toBeDefined()

    await valueInputs[0].setValue(9000)
    await addButton.trigger('click')

    const importedSales = wrapper.emitted('importSale') as [DetectedLootSale][]
    expect(importedSales).toHaveLength(1)
    expect(importedSales[0][0].price).toBe(9000)
  })

  it('keeps a known contribution price as a fixed amount without value input', () => {
    const wrapper = mountDialog(scanResult({ detectedSales: [veldsparContribution()] }))

    expect(wrapper.findAll('input[aria-label="400x Veldspar"]')).toHaveLength(0)
    expect(buttonsLabelled(wrapper, 'Add')[0].attributes('disabled')).toBeUndefined()
  })

  it('summarises contracts and projects only and offers the loot type configuration when no loot type is configured', async () => {
    const wrapper = mountDialog(scanResult({
      noLootTypesConfigured: true,
      scannedTransactions: null,
      scannedContracts: 2,
      scannedProjects: 1,
      detectedSales: [veldsparContribution()],
    }))

    expect(wrapper.text()).toContain('2 contracts, 1 projects scanned - 1 sales detected')
    expect(wrapper.text()).not.toContain('transactions')
    expect(wrapper.text()).toContain('No loot type configured: market sales are not read, only PVE loot contracts and corporation projects are.')

    const configureButtons = buttonsLabelled(wrapper, 'Configure loot types')
    expect(configureButtons).toHaveLength(1)
    await configureButtons[0].trigger('click')
    expect(wrapper.emitted('openLootConfig')).toHaveLength(1)
  })

  it('summarises contracts and projects only in French when no loot type is configured', () => {
    const wrapper = mountDialog(scanResult({
      noLootTypesConfigured: true,
      scannedTransactions: null,
      scannedContracts: 2,
      scannedProjects: 1,
      detectedSales: [veldsparContribution()],
    }), 'fr')

    expect(wrapper.text()).toContain('2 contrats, 1 projets scannés - 1 ventes détectées')
    expect(wrapper.text()).toContain(
      'Aucun type de loot configuré : les ventes du marché ne sont pas lues, seuls les contrats de loot PVE et les projets corporation le sont.',
    )
    expect(buttonsLabelled(wrapper, 'Configurer les types de loot')).toHaveLength(1)
  })

  it('shows unknown projects without transactions when no loot type is configured and the projects could not be read', () => {
    const wrapper = mountDialog(scanResult({
      noLootTypesConfigured: true,
      scannedTransactions: null,
      scannedContracts: 0,
      scannedProjects: null,
      corporationProjectErrors: [PROJECT_LIST_UNAVAILABLE],
    }))

    expect(wrapper.text()).toContain('0 contracts, projects: unknown - 0 sales detected')
    expect(wrapper.text()).not.toContain('transactions')
    expect(alertTexts(wrapper)[0]).toContain('Projects of Fictional Mining Corp could not be read: ESI error (HTTP 503)')
  })

  it('neither shows the no loot type notice nor the configuration button when loot types are configured', () => {
    const wrapper = mountDialog(scanResult({ detectedSales: [veldsparContribution()] }))

    expect(wrapper.text()).toContain('3 transactions, 2 contracts, 1 projects scanned - 1 sales detected')
    expect(wrapper.text()).not.toContain('No loot type configured')
    expect(buttonsLabelled(wrapper, 'Configure loot types')).toHaveLength(0)
  })

  it('declares the lot 1 texts of the corporation project errors, the no loot type scan and the all period in English and French', () => {
    const expectedTexts: Record<string, [string, string]> = {
      'pve.corpProjectErrors.line': ['{subject}: {reason}', '{subject} : {reason}'],
      'pve.corpProjectErrors.subject.corporation': ['Projects of {corporation} could not be read', 'Projets de {corporation} illisibles'],
      'pve.corpProjectErrors.subject.project': ['Project {project} of {corporation} could not be read', 'Projet {project} de {corporation} illisible'],
      'pve.corpProjectErrors.subject.contribution': [
        'Contribution of {character} to project {project} could not be read',
        'Contribution de {character} au projet {project} illisible',
      ],
      'pve.corpProjectErrors.reason.no_valid_character': [
        'no character of this corporation has a valid authorization',
        "aucun personnage de cette corporation n'a d'autorisation valide",
      ],
      'pve.corpProjectErrors.reason.missing_projects_scope': [
        'access to corporation projects not granted, reconnect the character',
        'accès aux projets corporation non accordé, reconnectez le personnage',
      ],
      'pve.corpProjectErrors.reason.esi_error': ['ESI error (HTTP {status})', 'erreur ESI (HTTP {status})'],
      'pve.corpProjectErrors.reason.esi_error_network': ['ESI unreachable', 'ESI injoignable'],
      'pve.corpProjectErrors.reason.unexpected_response': ['incomplete ESI response', 'réponse ESI incomplète'],
      'pve.lootScanSummaryNoLootTypes': [
        '{contracts} contracts, {projects} projects scanned - {detected} sales detected',
        '{contracts} contrats, {projects} projets scannés - {detected} ventes détectées',
      ],
      'pve.lootScanSummaryNoLootTypesUnknownProjects': [
        '{contracts} contracts, projects: unknown - {detected} sales detected',
        '{contracts} contrats, projets : inconnu - {detected} ventes détectées',
      ],
      'pve.noLootTypesProjectsOnly': [
        'No loot type configured: market sales are not read, only PVE loot contracts and corporation projects are.',
        'Aucun type de loot configuré : les ventes du marché ne sont pas lues, seuls les contrats de loot PVE et les projets corporation le sont.',
      ],
      'pve.configureLootTypes': ['Configure loot types', 'Configurer les types de loot'],
      'pve.errors.lootSalesScanHttpFailure': [
        'Loot sales and corporation projects scan failed (HTTP {status})',
        'Scan des ventes de loot et des projets corporation en échec (HTTP {status})',
      ],
      'pve.errors.lootContractsScanHttpFailure': ['Loot contracts scan failed (HTTP {status})', 'Scan des contrats de loot en échec (HTTP {status})'],
      'ledger.period.all': ['All', 'Tout'],
    }

    const paths = Object.keys(expectedTexts)
    expect(Object.fromEntries(paths.map(path => [path, messageAt(en, path)]))).toEqual(
      Object.fromEntries(paths.map(path => [path, expectedTexts[path][0]])),
    )
    expect(Object.fromEntries(paths.map(path => [path, messageAt(fr, path)]))).toEqual(
      Object.fromEntries(paths.map(path => [path, expectedTexts[path][1]])),
    )
  })

  it('no longer declares the authentication error text replaced by the no valid character reason', () => {
    expect(messageAt(en, 'pve.corpProjectsAuthenticationError')).toBeUndefined()
    expect(messageAt(fr, 'pve.corpProjectsAuthenticationError')).toBeUndefined()
  })
})
