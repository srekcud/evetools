export function present<T>(value: T | null | undefined, description = 'value'): T {
  if (value === null || value === undefined) {
    throw new Error(`Expected ${description} to be present, got ${String(value)}`)
  }
  return value
}
