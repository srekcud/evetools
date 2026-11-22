import type { UnassignedJob, UnassignedJobCandidate } from '@/stores/group-industry/types'

export function unassignedCandidate(overrides: Partial<UnassignedJobCandidate> = {}): UnassignedJobCandidate {
  return {
    projectId: 'project-ishtar-322',
    projectName: 'Ishtar batch',
    lineId: 'line-ishtar-hull',
    lineName: 'Ishtar',
    remainingRuns: 6,
    score: 9,
    signals: ['intent', 'exact_fit'],
    oversized: false,
    ...overrides,
  }
}

export function unassignedJob(overrides: Partial<UnassignedJob> = {}): UnassignedJob {
  return {
    esiJobId: 9_101,
    characterName: 'Alpha',
    blueprintTypeId: 11_994,
    productTypeId: 12_005,
    productTypeName: 'Ishtar',
    runs: 4,
    activityId: 1,
    stationId: 60_003_760,
    stationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
    startDate: '2026-11-22T08:00:00+00:00',
    endDate: '2026-11-22T20:00:00+00:00',
    status: 'active',
    candidates: [unassignedCandidate()],
    ...overrides,
  }
}
