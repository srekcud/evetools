import type { IndustryAction } from '@/stores/industry/actions'

export function industryAction(overrides: Partial<IndustryAction> & Pick<IndustryAction, 'projectId' | 'code'>): IndustryAction {
  return {
    projectName: null,
    blocked: false,
    tab: null,
    targetStatus: null,
    bomLineId: null,
    typeId: null,
    typeName: null,
    quantity: null,
    severity: 'info',
    impactIsk: null,
    jitaUnitPrice: null,
    breakEvenPerUnit: null,
    ...overrides,
  }
}
