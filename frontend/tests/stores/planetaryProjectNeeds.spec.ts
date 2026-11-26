import { createPinia, setActivePinia } from 'pinia'
import { i18n } from '@/i18n'
import { usePlanetaryProjectNeedsStore } from '@/stores/planetaryProjectNeeds'
import {
  BROADCAST_NODE_NEED,
  PROJECT_NEEDS_WITH_THREE_PRODUCTS,
  ROBOTICS_NEED,
  UNKNOWN_STOCK_NEED,
  projectNeedsOf,
} from '../planetaryProjectNeedsFixtures'
import { apiCalls, serve } from '../planetarySettingsFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

function freshStore() {
  setActivePinia(createPinia())
  return usePlanetaryProjectNeedsStore()
}

describe('planetaryProjectNeeds store', () => {
  it('reads the products asked by the projects with one request and exposes them in the order received with their null stock kept', async () => {
    serve({ 'GET /planetary/project-needs': PROJECT_NEEDS_WITH_THREE_PRODUCTS })
    const store = freshStore()
    expect(store.products).toEqual([])

    await store.load()

    expect(apiCalls()).toEqual([{ method: 'GET', endpoint: '/planetary/project-needs', body: undefined }])
    expect(store.products).toEqual([BROADCAST_NODE_NEED, ROBOTICS_NEED, UNKNOWN_STOCK_NEED])
    expect(store.products[2].stock).toBeNull()
    expect(store.products[2].missing).toBeNull()
    expect(store.isLoading).toBe(false)
    expect(store.error).toBeNull()
  })

  it('exposes an empty list when no project asks for a product', async () => {
    serve({ 'GET /planetary/project-needs': projectNeedsOf([]) })
    const store = freshStore()

    await store.load()

    expect(store.products).toEqual([])
    expect(store.error).toBeNull()
    expect(store.isLoading).toBe(false)
  })

  it('exposes the translated load error and no product when the read fails, then clears it on the next success', async () => {
    serve({ 'GET /planetary/project-needs': new Error('Network down') })
    const store = freshStore()

    await store.load()

    expect(store.error).toBe(i18n.global.t('pi.stock.projectNeeds.loadError'))
    expect(store.error).not.toBe('pi.stock.projectNeeds.loadError')
    expect(store.products).toEqual([])
    expect(store.isLoading).toBe(false)

    serve({ 'GET /planetary/project-needs': projectNeedsOf([ROBOTICS_NEED]) })
    await store.load()

    expect(store.error).toBeNull()
    expect(store.products).toEqual([ROBOTICS_NEED])
  })

  it('is loading while the request is pending and not any more once it is answered', async () => {
    let answer: (value: unknown) => void = () => undefined
    serve({ 'GET /planetary/project-needs': () => new Promise(resolve => { answer = resolve }) })
    const store = freshStore()

    const pending = store.load()

    expect(store.isLoading).toBe(true)
    answer(projectNeedsOf([ROBOTICS_NEED]))
    await pending
    expect(store.isLoading).toBe(false)
    expect(store.products).toEqual([ROBOTICS_NEED])
  })
})
