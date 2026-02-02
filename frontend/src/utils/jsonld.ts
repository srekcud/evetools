/**
 * JSON-LD / Hydra utilities for API Platform responses
 */

export interface HydraCollection<T> {
  '@context'?: string
  '@id'?: string
  '@type'?: string
  'hydra:member': T[]
  'hydra:totalItems'?: number
}

/**
 * Check if a response is a Hydra collection
 */
export function isHydraCollection<T>(data: unknown): data is HydraCollection<T> {
  return (
    typeof data === 'object' &&
    data !== null &&
    'hydra:member' in data &&
    Array.isArray((data as HydraCollection<T>)['hydra:member'])
  )
}

/**
 * Strip JSON-LD metadata from a resource object recursively
 * Removes @context, @id, @type properties at all levels
 */
export function stripJsonLdMetadata<T>(data: T): T {
  if (data === null || data === undefined) {
    return data
  }

  if (Array.isArray(data)) {
    return data.map(item => stripJsonLdMetadata(item)) as T
  }

  if (typeof data === 'object') {
    const result: Record<string, unknown> = {}
    for (const [key, value] of Object.entries(data as Record<string, unknown>)) {
      // Skip JSON-LD metadata keys
      if (key === '@context' || key === '@id' || key === '@type') {
        continue
      }
      // Recursively strip nested objects/arrays
      result[key] = stripJsonLdMetadata(value)
    }
    return result as T
  }

  return data
}

/**
 * Parse API response handling both JSON-LD collections and single resources
 * For collections: extracts hydra:member and strips metadata
 * For single resources: strips JSON-LD metadata
 */
export function parseApiResponse<T>(data: unknown): T {
  // If it's a Hydra collection, extract the members and strip metadata
  if (isHydraCollection<T>(data)) {
    const members = data['hydra:member']
    return members.map(item => stripJsonLdMetadata(item)) as unknown as T
  }

  // If it's an object with JSON-LD metadata, strip it
  if (typeof data === 'object' && data !== null) {
    return stripJsonLdMetadata(data) as T
  }

  // Return as-is
  return data as T
}
