export type SourcingOverride = 'open' | 'closed'

const STORAGE_KEY_PREFIX = 'group-industry-sourcing-'
const OVERRIDES: readonly string[] = ['open', 'closed']

export function isSourcingComplete(materials: readonly { isFulfilled: boolean }[]): boolean {
  return materials.length > 0 && materials.every(material => material.isFulfilled)
}

export function isSourcingCollapsed(complete: boolean, override: SourcingOverride | null): boolean {
  return complete && override !== 'open'
}

export function sourcingStorageKey(projectId: string): string {
  return `${STORAGE_KEY_PREFIX}${projectId}`
}

export function readSourcingOverride(storage: Pick<Storage, 'getItem'>, projectId: string): SourcingOverride | null {
  const stored = storage.getItem(sourcingStorageKey(projectId))
  return stored !== null && OVERRIDES.includes(stored) ? (stored as SourcingOverride) : null
}

export function writeSourcingOverride(storage: Pick<Storage, 'setItem'>, projectId: string, override: SourcingOverride): void {
  storage.setItem(sourcingStorageKey(projectId), override)
}
