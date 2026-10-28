import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import CommandPalette from '@/components/common/CommandPalette.vue'
import { apiRequest } from '@/services/api'
import type { IndustryProject, SearchResult } from '@/stores/industry/types'
import type { GroupProject, GroupProjectItem, GroupProjectStatus } from '@/stores/group-industry/types'

export const TYPE_SEARCH_DEBOUNCE_MS = 250
export const STARTING_ROUTE = '/characters'

const HIDDEN_MODULES_STORAGE_KEY = 'evetools_hidden_modules'
const INDUSTRY_PROJECTS_ENDPOINT = '/industry/projects'
const GROUP_INDUSTRY_PROJECTS_ENDPOINT = '/group-industry/projects'
const TYPE_SEARCH_ENDPOINT_PREFIX = '/industry/search?q='

const appRoutes = [
  '/dashboard',
  '/ledger',
  '/industry',
  '/group-industry',
  '/group-industry/:id',
  '/appraisal',
  '/planetary',
  '/market',
  '/assets',
  '/characters',
  '/admin',
]

export type ApiAnswer<T> = T | Error | Promise<T>

export interface PaletteApi {
  industryProjects: ApiAnswer<IndustryProject[]>
  groupIndustryProjects: ApiAnswer<GroupProject[]>
  typesForQuery: (query: string) => ApiAnswer<SearchResult[]>
}

interface PaletteMountOptions {
  locale?: 'en' | 'fr'
  hiddenModules?: string[]
}

export function industryProject(id: string, overrides: Partial<IndustryProject>): IndustryProject {
  return {
    id,
    productTypeName: 'Raven',
    productTypeId: 638,
    displayName: null,
    runs: 1,
    meLevel: 10,
    teLevel: 20,
    maxJobDurationDays: 2,
    status: 'active',
    personalUse: false,
    isT2: false,
    bpoCost: null,
    materialCost: null,
    transportCost: null,
    jobsCost: null,
    taxAmount: null,
    sellPrice: null,
    totalCost: null,
    estimatedJobCost: null,
    estimatedSellPrice: null,
    estimatedSellPriceSource: null,
    estimatedTaxAmount: null,
    estimatedMaterialCost: null,
    profit: null,
    profitPercent: null,
    notes: null,
    createdAt: '2026-10-20T10:00:00Z',
    completedAt: null,
    jobsStartDate: null,
    ...overrides,
  }
}

export function groupIndustryProject(id: string, name: string | null, status: GroupProjectStatus, items: GroupProjectItem[]): GroupProject {
  return {
    id,
    name,
    status,
    shortLinkCode: `link-${id}`,
    containerName: null,
    ownerCharacterName: 'Sylvain Duckers',
    ownerCorporationId: 98000001,
    membersCount: 3,
    items,
    totalBomValue: 1250000000,
    fulfillmentPercent: 40,
    approvedContributionValue: 480000000,
    brokerFeePercent: 3,
    salesTaxPercent: 4.5,
    lineRentalRatesOverride: null,
    blacklistGroupIds: [],
    blacklistTypeIds: [],
    createdAt: '2026-10-21T10:00:00Z',
    myRole: 'owner',
    myStatus: 'accepted',
  }
}

export function groupProjectItem(typeId: number, typeName: string, runs: number): GroupProjectItem {
  return { typeId, typeName, meLevel: 10, teLevel: 20, runs }
}

export const RAVEN_BATCH = industryProject('p1', { displayName: 'Raven batch', productTypeId: 638, productTypeName: 'Raven', runs: 3 })
export const UNNAMED_COMPLETED_PROJECT = industryProject('p2', {
  displayName: null,
  rootProducts: [],
  productTypeName: null,
  productTypeId: 99000001,
  runs: 5,
  status: 'completed',
})
export const CORP_TITAN = groupIndustryProject('g1', 'Corp Titan', 'in_progress', [groupProjectItem(11567, 'Avatar', 1)])

export const RAVEN: SearchResult = { typeId: 638, typeName: 'Raven', isT2: false }
export const RAVEN_NAVY_ISSUE: SearchResult = { typeId: 17636, typeName: 'Raven Navy Issue', isT2: false }

export function commonTypesForQuery(query: string): SearchResult[] {
  return query === 'rav' ? [RAVEN, RAVEN_NAVY_ISSUE] : []
}

export function pendingAnswer<T>(): Promise<T> {
  return new Promise<T>(() => {})
}

let paletteApi: PaletteApi = defaultPaletteApi()

function defaultPaletteApi(): PaletteApi {
  return {
    industryProjects: [RAVEN_BATCH, UNNAMED_COMPLETED_PROJECT],
    groupIndustryProjects: [CORP_TITAN],
    typesForQuery: commonTypesForQuery,
  }
}

function settle<T>(answer: ApiAnswer<T>, wrap: (value: T) => unknown): Promise<unknown> {
  if (answer instanceof Error) {
    return Promise.reject(answer)
  }
  if (answer instanceof Promise) {
    return answer.then(wrap)
  }
  return Promise.resolve(wrap(answer))
}

export function stubPaletteApi(overrides: Partial<PaletteApi> = {}): void {
  paletteApi = { ...defaultPaletteApi(), ...overrides }
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === INDUSTRY_PROJECTS_ENDPOINT) {
      return settle(paletteApi.industryProjects, projects => ({ projects }))
    }
    if (endpoint === GROUP_INDUSTRY_PROJECTS_ENDPOINT) {
      return settle(paletteApi.groupIndustryProjects, projects => projects)
    }
    if (endpoint.startsWith(TYPE_SEARCH_ENDPOINT_PREFIX)) {
      const query = decodeURIComponent(endpoint.slice(TYPE_SEARCH_ENDPOINT_PREFIX.length))
      return settle(paletteApi.typesForQuery(query), results => ({ results }))
    }
    throw new Error(`Unexpected request ${endpoint}`)
  })
}

export function requestedEndpoints(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint)
}

export function typeSearchQueries(): string[] {
  return requestedEndpoints()
    .filter(endpoint => endpoint.startsWith(TYPE_SEARCH_ENDPOINT_PREFIX))
    .map(endpoint => decodeURIComponent(endpoint.slice(TYPE_SEARCH_ENDPOINT_PREFIX.length)))
}

export async function mountPalette({ locale = 'en', hiddenModules }: PaletteMountOptions = {}) {
  localStorage.clear()
  if (hiddenModules !== undefined) {
    localStorage.setItem(HIDDEN_MODULES_STORAGE_KEY, JSON.stringify(hiddenModules))
  }
  const pinia = createPinia()
  setActivePinia(pinia)
  const router: Router = createRouter({
    history: createMemoryHistory(),
    routes: appRoutes.map(route => ({ path: route, component: { template: '<div />' } })),
  })
  await router.push(STARTING_ROUTE)
  await router.isReady()
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  let closeFromParent: () => Promise<void> = async () => {}
  const wrapper = mount(CommandPalette, {
    attachTo: document.body,
    props: { show: false, isAdmin: false, onClose: () => closeFromParent() },
    global: { plugins: [pinia, i18n, router] },
  })
  closeFromParent = async () => {
    await wrapper.setProps({ show: false })
  }
  const open = async () => {
    await wrapper.setProps({ show: true })
    await flushPromises()
  }
  return { wrapper, router, open, close: closeFromParent }
}

export async function mountOpenPalette(options: PaletteMountOptions = {}) {
  const palette = await mountPalette(options)
  await palette.open()
  return palette
}

export function searchInput(): HTMLInputElement | null {
  return document.querySelector<HTMLInputElement>('[role="combobox"]')
}

function openSearchInput(): HTMLInputElement {
  const input = searchInput()
  expect(input, 'command palette search input').not.toBeNull()
  return input as HTMLInputElement
}

export function textOf(element: Element): string {
  const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT)
  const parts: string[] = []
  for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
    const part = (node.textContent ?? '').replace(/\s+/g, ' ').trim()
    if (part !== '') {
      parts.push(part)
    }
  }
  return parts.join(' ')
}

export function paletteText(): string {
  const dialog = searchInput()?.closest('[role="dialog"]')
  return dialog ? textOf(dialog) : ''
}

function groupElements(): HTMLElement[] {
  return Array.from(document.querySelectorAll<HTMLElement>('[role="listbox"] [role="group"]'))
}

function groupName(group: Element): string {
  const labelledBy = group.getAttribute('aria-labelledby')
  if (labelledBy) {
    const label = document.getElementById(labelledBy)
    return label ? textOf(label) : ''
  }
  return group.getAttribute('aria-label') ?? ''
}

export function groupNames(): string[] {
  return groupElements().map(groupName)
}

export function groupNamed(name: string): HTMLElement {
  const group = groupElements().find(candidate => groupName(candidate) === name)
  expect(group, `palette group ${name}`).toBeDefined()
  return group as HTMLElement
}

export function groupText(name: string): string {
  return textOf(groupNamed(name))
}

export function groupOptions(name: string): HTMLElement[] {
  return Array.from(groupNamed(name).querySelectorAll<HTMLElement>('[role="option"]'))
}

export function groupOptionTexts(name: string): string[] {
  return groupOptions(name).map(textOf)
}

export function optionElements(): HTMLElement[] {
  return Array.from(document.querySelectorAll<HTMLElement>('[role="listbox"] [role="option"]'))
}

export function activeOption(): HTMLElement | null {
  const activeOptions = optionElements().filter(option => option.getAttribute('aria-selected') === 'true')
  return activeOptions.length === 1 ? activeOptions[0] : null
}

export function activeOptionText(): string | null {
  const option = activeOption()
  return option ? textOf(option) : null
}

export function actionButtonTexts(option: Element): string[] {
  return Array.from(option.querySelectorAll('button')).map(textOf)
}

export async function typeQuery(query: string): Promise<void> {
  const input = openSearchInput()
  input.value = query
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}

export async function waitForTypeSearch(): Promise<void> {
  await vi.advanceTimersByTimeAsync(TYPE_SEARCH_DEBOUNCE_MS)
  await flushPromises()
}

export async function typeQueryAndWaitForTypes(query: string): Promise<void> {
  await typeQuery(query)
  await waitForTypeSearch()
}

export async function pressKey(key: string, init: KeyboardEventInit = {}): Promise<KeyboardEvent> {
  const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init })
  openSearchInput().dispatchEvent(event)
  await flushPromises()
  return event
}

export async function pressArrowDown(times: number): Promise<void> {
  for (let press = 0; press < times; press++) {
    await pressKey('ArrowDown')
  }
}
