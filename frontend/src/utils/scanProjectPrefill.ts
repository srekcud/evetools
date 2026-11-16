import type { BatchScanItem, BlueprintKind, BlueprintLibraryEntry, ProjectPrefillItem } from '@/stores/industry/types'

const KIND_PREFERENCE: Record<BlueprintKind, number> = { bpo: 0, bpr: 1, bpc: 2 }
const DEFAULT_RUNS = 1

function runsOfCopy(blueprint: BlueprintLibraryEntry): number {
  return blueprint.kind === 'bpc' && blueprint.runs !== null ? blueprint.runs : 0
}

function compareBlueprints(a: BlueprintLibraryEntry, b: BlueprintLibraryEntry): number {
  return KIND_PREFERENCE[a.kind] - KIND_PREFERENCE[b.kind]
    || b.me - a.me
    || b.te - a.te
    || runsOfCopy(b) - runsOfCopy(a)
}

export function pickOwnedBlueprint(productTypeId: number, blueprints: BlueprintLibraryEntry[]): BlueprintLibraryEntry | null {
  const candidates = blueprints.filter(blueprint => blueprint.productTypeId === productTypeId)
  if (candidates.length === 0) return null
  return candidates.reduce((best, candidate) => (compareBlueprints(candidate, best) < 0 ? candidate : best))
}

export function buildScanPrefillItems(
  products: BatchScanItem[],
  blueprints: BlueprintLibraryEntry[],
  productLabel: (product: BatchScanItem) => string,
): ProjectPrefillItem[] {
  return products.map((product): ProjectPrefillItem => {
    const owned = pickOwnedBlueprint(product.typeId, blueprints)
    if (owned === null) {
      return { typeId: product.typeId, typeName: productLabel(product), meLevel: product.meUsed, teLevel: product.teUsed, runs: DEFAULT_RUNS, blueprintSource: 'assumed' }
    }
    const copyRuns = runsOfCopy(owned)
    return {
      typeId: product.typeId,
      typeName: productLabel(product),
      meLevel: owned.me,
      teLevel: owned.te,
      runs: copyRuns > 0 ? copyRuns : DEFAULT_RUNS,
      blueprintSource: 'owned',
    }
  })
}
