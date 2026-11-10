import { workshopKpis } from '@/utils/workshopKpis'
import { workshopProject } from '../industryWorkshopProjectFixtures'

const RUNNING_PROJECT_ID = 'running-project'
const SELLING_PROJECT_ID = 'selling-project'
const DRAFT_PROJECT_ID = 'draft-project'
const COMPLETED_PROJECT_ID = 'completed-project'
const ACTIVE_PROJECT_IDS = new Set([RUNNING_PROJECT_ID, SELLING_PROJECT_ID, DRAFT_PROJECT_ID])

const RUNNING = workshopProject({ projectId: RUNNING_PROJECT_ID, engagedCapital: 1_500_000, profit: { amount: 900_000, status: 'provisional' } })
const SELLING = workshopProject({ projectId: SELLING_PROJECT_ID, engagedCapital: 750_000, profit: { amount: -300_000, status: 'provisional' } })
const DRAFT = workshopProject({ projectId: DRAFT_PROJECT_ID, engagedCapital: 0, profit: { amount: null, status: 'awaiting_sales' } })
const COMPLETED = workshopProject({ projectId: COMPLETED_PROJECT_ID, engagedCapital: 8_000_000, profit: { amount: 24_460_000, status: 'final' } })

describe('workshopKpis', () => {
  it('sums the known capital and the known profit of the active projects only', () => {
    expect(workshopKpis([RUNNING, SELLING, COMPLETED], ACTIVE_PROJECT_IDS)).toStrictEqual({
      capital: { amount: 2_250_000, unknownCount: 0 },
      profit: { amount: 600_000, unknownCount: 0 },
    })
  })

  it('counts a project without a profit amount as unknown without adding anything to the sum', () => {
    expect(workshopKpis([RUNNING, DRAFT], ACTIVE_PROJECT_IDS).profit).toStrictEqual({ amount: 900_000, unknownCount: 1 })
  })

  it('counts a project without a capital figure as unknown without adding anything to the sum', () => {
    const unknownCapital = workshopProject({ projectId: SELLING_PROJECT_ID, engagedCapital: null, profit: { amount: 100_000, status: 'final' } })

    expect(workshopKpis([RUNNING, unknownCapital], ACTIVE_PROJECT_IDS).capital).toStrictEqual({ amount: 1_500_000, unknownCount: 1 })
  })

  it('keeps a real zero capital as a known value', () => {
    expect(workshopKpis([DRAFT], ACTIVE_PROJECT_IDS).capital).toStrictEqual({ amount: 0, unknownCount: 0 })
  })

  it('gives no amount instead of zero when no active project has a known profit', () => {
    expect(workshopKpis([DRAFT, COMPLETED], ACTIVE_PROJECT_IDS).profit).toStrictEqual({ amount: null, unknownCount: 1 })
  })

  it('gives no amount instead of zero when there is no active project', () => {
    expect(workshopKpis([COMPLETED], ACTIVE_PROJECT_IDS)).toStrictEqual({
      capital: { amount: null, unknownCount: 0 },
      profit: { amount: null, unknownCount: 0 },
    })
  })
})
