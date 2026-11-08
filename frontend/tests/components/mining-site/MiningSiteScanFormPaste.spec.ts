import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import type { MiningSite, MiningSiteScan } from '@/stores/miningSite'
import MiningSiteScanForm from '@/components/mining-site/MiningSiteScanForm.vue'
import {
  KERNITE,
  ORE_VALUES_PATH,
  SCANS_PATH,
  SCORDITE,
  VELDSPAR,
  at,
  found,
  i18nFor,
  oreValues,
  presentType,
  scanOf,
  siteOf,
} from '../../views/MiningSite/miningSiteFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const VELDSPAR_ROW = 'Veldspar\t10.000\t1.000 m3\t-\t3 km'
const SCORDITE_ROW = 'Scordite\t2.000\t300 m3\t-\t4 km'
const KERNITE_ROW = 'Kernite\t500\t600 m3\t-\t5 km'

function answerWith(recorded: MiningSiteScan): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint.startsWith(ORE_VALUES_PATH)) return oreValues() as never
    if (endpoint === SCANS_PATH) return recorded as never
    throw new Error(`Unexpected request ${endpoint}`)
  })
}

async function mountForm(site: MiningSite) {
  const i18n = i18nFor('en')
  const wrapper = mount(MiningSiteScanForm, { props: { site }, global: { plugins: [i18n] } })
  await flushPromises()
  return { wrapper, t: i18n.global.t }
}

async function readPaste(wrapper: VueWrapper, text: string): Promise<void> {
  await found(wrapper, '[data-testid="survey-paste"]').setValue(text)
  await found(wrapper, '[data-testid="survey-read"]').trigger('click')
  await flushPromises()
}

function draftLines(wrapper: VueWrapper): [string, string][] {
  return wrapper.findAll('[data-testid="scan-line"]').map(line => [
    (found(line, 'select[name="typeId"]').element as HTMLSelectElement).value,
    (found(line, 'input[name="units"]').element as HTMLInputElement).value,
  ])
}

function pastedText(wrapper: VueWrapper): string {
  return (found(wrapper, '[data-testid="survey-paste"]').element as HTMLTextAreaElement).value
}

function textOf(wrapper: VueWrapper, testId: string): string | null {
  const element = wrapper.find(`[data-testid="${testId}"]`)
  return element.exists() ? element.text() : null
}

function requestedEndpoints(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(call => `${call[1]?.method ?? 'GET'} ${call[0]}`)
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

enableAutoUnmount(afterEach)

describe('MiningSiteScanForm survey paste', () => {
  it('replaces the typed lines by the lines read from the paste, empties the field and sends nothing to the server', async () => {
    answerWith(scanOf(1, at('20:30:00'), [presentType(VELDSPAR, 10_000)]))
    const { wrapper } = await mountForm(siteOf())
    await found(wrapper, '[data-testid="scan-add-line"]').trigger('click')

    await readPaste(wrapper, [VELDSPAR_ROW, SCORDITE_ROW, KERNITE_ROW].join('\n'))

    expect({
      lines: draftLines(wrapper),
      field: pastedText(wrapper),
      issues: textOf(wrapper, 'survey-issues'),
      requests: requestedEndpoints(),
    }).toEqual({
      lines: [[String(VELDSPAR), '10000'], [String(SCORDITE), '2000'], [String(KERNITE), '500']],
      field: '',
      issues: null,
      requests: [`GET ${ORE_VALUES_PATH}?market=jita&side=buy`],
    })
  })

  it('applies no line and names the ore when a collapsed group is pasted', async () => {
    answerWith(scanOf(1, at('20:30:00'), [presentType(VELDSPAR, 10_000)]))
    const { wrapper } = await mountForm(siteOf())

    await readPaste(wrapper, [SCORDITE_ROW, 'Kernite [4] 112 ISK / m³', KERNITE_ROW].join('\n'))

    expect({
      lines: draftLines(wrapper),
      issues: textOf(wrapper, 'survey-issues'),
    }).toEqual({
      lines: [],
      issues: expect.stringContaining('Kernite'),
    })
  })

  it('keeps the other lines applied and lists the issue of an ore unknown to the SDE', async () => {
    answerWith(scanOf(1, at('20:30:00'), [presentType(VELDSPAR, 10_000)]))
    const { wrapper } = await mountForm(siteOf())

    await readPaste(wrapper, [VELDSPAR_ROW, 'Unobtainium\t10.000\t50.000 m3\t-\t3 km', SCORDITE_ROW].join('\n'))

    expect({
      lines: draftLines(wrapper),
      issues: textOf(wrapper, 'survey-issues'),
    }).toEqual({
      lines: [[String(VELDSPAR), '10000'], [String(SCORDITE), '2000']],
      issues: expect.stringContaining('Unobtainium'),
    })
  })

  it('shows the no lines error and no issue when an empty text is read', async () => {
    answerWith(scanOf(1, at('20:30:00'), [presentType(VELDSPAR, 10_000)]))
    const { wrapper, t } = await mountForm(siteOf())

    await readPaste(wrapper, '')

    expect({
      error: textOf(wrapper, 'scan-error'),
      issues: textOf(wrapper, 'survey-issues'),
      lines: draftLines(wrapper),
    }).toEqual({
      error: expect.stringContaining(t('miningSite.scan.errors.noLines')),
      issues: null,
      lines: [],
    })
  })

  it('sends the lines read from the paste only when the form is submitted', async () => {
    answerWith(scanOf(1, at('20:30:00'), [presentType(VELDSPAR, 10_000), presentType(SCORDITE, 2_000)]))
    const { wrapper } = await mountForm(siteOf())
    await readPaste(wrapper, [VELDSPAR_ROW, SCORDITE_ROW].join('\n'))
    const requestsAfterReading = requestedEndpoints()

    await found(wrapper, '[data-testid="scan-form"]').trigger('submit')
    await flushPromises()

    expect({
      requestsAfterReading,
      requests: requestedEndpoints(),
      body: vi.mocked(apiRequest).mock.calls.filter(call => call[1]?.method === 'POST').map(call => JSON.parse(String(call[1]?.body))),
    }).toEqual({
      requestsAfterReading: [`GET ${ORE_VALUES_PATH}?market=jita&side=buy`],
      requests: [`GET ${ORE_VALUES_PATH}?market=jita&side=buy`, `POST ${SCANS_PATH}`],
      body: [{ lines: [{ typeId: VELDSPAR, units: 10_000 }, { typeId: SCORDITE, units: 2_000 }] }],
    })
  })
})
