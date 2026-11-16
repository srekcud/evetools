import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import StructureListTransfer from '@/components/industry/StructureListTransfer.vue'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const EXPORT_PATH = '/industry/structures/export'
const IMPORT_PATH = '/industry/structures/import'

const EXPORT_DOCUMENT = {
  format: 'evetools.industry-structures',
  version: 1,
  structures: [
    { name: 'Home Raitaru', locationId: 1035466617946, solarSystemId: 30000142, structureType: 'raitaru', securityType: 'nullsec', rigs: [], facilityTaxRate: 3.5 },
    { name: 'Custom Azbel', locationId: null, solarSystemId: null, structureType: 'azbel', securityType: 'lowsec', rigs: [], facilityTaxRate: null },
    { name: 'Broken', locationId: null, solarSystemId: null, structureType: 'azbel', securityType: 'lowsec', rigs: ['Nope'], facilityTaxRate: 99 },
  ],
}

const PREVIEW_STATUSES = ['create', 'skip', 'invalid']

interface ImportBody {
  entries: Array<{ name: string }>
  overwriteExisting: boolean
  dryRun: boolean
}

let importBodies: ImportBody[] = []

function importAnswer(body: ImportBody) {
  return {
    id: 'import',
    applied: !body.dryRun,
    entries: body.entries.map((entry, index) => ({
      index,
      name: entry.name,
      locationId: null,
      status: PREVIEW_STATUSES[index] ?? 'create',
      errors: PREVIEW_STATUSES[index] === 'invalid' ? ['The rig "Nope" is not in the rig catalogue.'] : [],
    })),
  }
}

function mountTransfer(): VueWrapper {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(StructureListTransfer, { global: { plugins: [i18n] } })
}

async function paste(wrapper: VueWrapper, text: string): Promise<void> {
  await wrapper.get('[data-testid="structure-import-text"]').setValue(text)
  await wrapper.get('[data-testid="structure-import-preview-button"]').trigger('click')
  await flushPromises()
}

function rowStates(wrapper: VueWrapper): Array<{ status: string | undefined, checked: boolean }> {
  return wrapper.findAll('[data-testid="structure-import-row"]').map(row => ({
    status: row.attributes('data-status'),
    checked: (row.get('[data-testid="structure-import-row-select"]').element as HTMLInputElement).checked,
  }))
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  importBodies = []
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (endpoint === EXPORT_PATH) return { id: 'export', ...EXPORT_DOCUMENT }
    if (endpoint === IMPORT_PATH) {
      const body = JSON.parse(String(options?.body)) as ImportBody
      importBodies.push(body)
      return importAnswer(body)
    }
    throw new Error(`Unexpected request ${endpoint}`)
  })
})

describe('StructureListTransfer (issue 998)', () => {
  it('exports the server list as a JSON document without the internal id', async () => {
    const wrapper = mountTransfer()

    await wrapper.get('[data-testid="structure-export-button"]').trigger('click')
    await flushPromises()

    const text = (wrapper.get('[data-testid="structure-export-text"]').element as HTMLTextAreaElement).value
    expect(JSON.parse(text)).toEqual(EXPORT_DOCUMENT)
  })

  it('shows an error and sends nothing when the pasted text is not JSON', async () => {
    const wrapper = mountTransfer()

    await paste(wrapper, 'not json at all')

    expect({
      error: wrapper.find('[data-testid="structure-import-error"]').exists(),
      requests: vi.mocked(apiRequest).mock.calls.length,
    }).toEqual({ error: true, requests: 0 })
  })

  it('refuses a JSON document that is not a structure list', async () => {
    const wrapper = mountTransfer()

    await paste(wrapper, JSON.stringify({ format: 'something-else', version: 1, structures: [] }))

    expect({
      error: wrapper.find('[data-testid="structure-import-error"]').exists(),
      requests: vi.mocked(apiRequest).mock.calls.length,
    }).toEqual({ error: true, requests: 0 })
  })

  it('previews the pasted list with a dry run and checks only the entries that would be created', async () => {
    const wrapper = mountTransfer()

    await paste(wrapper, JSON.stringify(EXPORT_DOCUMENT))

    expect({ sent: importBodies, rows: rowStates(wrapper) }).toEqual({
      sent: [{ entries: EXPORT_DOCUMENT.structures, overwriteExisting: false, dryRun: true }],
      rows: [
        { status: 'create', checked: true },
        { status: 'skip', checked: false },
        { status: 'invalid', checked: false },
      ],
    })
  })

  it('shows the reasons of an invalid entry', async () => {
    const wrapper = mountTransfer()

    await paste(wrapper, JSON.stringify(EXPORT_DOCUMENT))

    const invalidRow = wrapper.findAll('[data-testid="structure-import-row"]')[2]
    expect(invalidRow.text()).toContain('not in the rig catalogue')
  })

  it('imports only the checked entries and reports the result, then tells the parent to reload', async () => {
    const wrapper = mountTransfer()
    await paste(wrapper, JSON.stringify(EXPORT_DOCUMENT))

    await wrapper.get('[data-testid="structure-import-apply"]').trigger('click')
    await flushPromises()

    expect({
      applied: importBodies[1],
      resultShown: wrapper.find('[data-testid="structure-import-result"]').exists(),
      imported: wrapper.emitted('imported')?.length,
    }).toEqual({
      applied: { entries: [EXPORT_DOCUMENT.structures[0]], overwriteExisting: false, dryRun: false },
      resultShown: true,
      imported: 1,
    })
  })

  it('includes an entry the user checks by hand and the overwrite option in the import', async () => {
    const wrapper = mountTransfer()
    await paste(wrapper, JSON.stringify(EXPORT_DOCUMENT))

    await wrapper.findAll('[data-testid="structure-import-row-select"]')[1].setValue(true)
    await wrapper.get('[data-testid="structure-import-overwrite"]').setValue(true)
    await flushPromises()
    await wrapper.get('[data-testid="structure-import-apply"]').trigger('click')
    await flushPromises()

    const lastBody = importBodies[importBodies.length - 1]
    expect({ names: lastBody.entries.map(entry => entry.name), overwrite: lastBody.overwriteExisting, dryRun: lastBody.dryRun })
      .toEqual({ names: ['Home Raitaru', 'Custom Azbel'], overwrite: true, dryRun: false })
  })

  it('does not offer the import before a preview exists', () => {
    const wrapper = mountTransfer()

    expect(wrapper.find('[data-testid="structure-import-apply"]').exists()).toBe(false)
  })
})
