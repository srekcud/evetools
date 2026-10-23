import { projectProfitTotal } from '@/utils/projectProfitTotal'

const KNOWN_PROFIT_OF_THE_WIDOW_PROJECT = 1_000_000
const LOSS_OF_THE_RIFTER_PROJECT = -200_000
const PROFIT_OF_THE_PERSONAL_USE_ISHTAR_PROJECT = 500_000

describe('projectProfitTotal', () => {
  it('sums the profits of projects, losses included', () => {
    expect(projectProfitTotal([
      { profit: KNOWN_PROFIT_OF_THE_WIDOW_PROJECT, personalUse: false },
      { profit: LOSS_OF_THE_RIFTER_PROJECT, personalUse: false },
    ])).toEqual({ total: 800_000, unknownProfitCount: 0 })
  })

  it('excludes and counts the projects with an unknown profit', () => {
    expect(projectProfitTotal([
      { profit: KNOWN_PROFIT_OF_THE_WIDOW_PROJECT, personalUse: false },
      { profit: null, personalUse: false },
      { profit: null, personalUse: false },
    ])).toEqual({ total: 1_000_000, unknownProfitCount: 2 })
  })

  it('ignores personal use projects, known or unknown profit', () => {
    expect(projectProfitTotal([
      { profit: KNOWN_PROFIT_OF_THE_WIDOW_PROJECT, personalUse: false },
      { profit: PROFIT_OF_THE_PERSONAL_USE_ISHTAR_PROJECT, personalUse: true },
      { profit: null, personalUse: true },
    ])).toEqual({ total: 1_000_000, unknownProfitCount: 0 })
  })

  it('returns a zero total and no excluded project without projects', () => {
    expect(projectProfitTotal([])).toEqual({ total: 0, unknownProfitCount: 0 })
  })
})
