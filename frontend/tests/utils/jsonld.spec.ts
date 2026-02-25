import { stripJsonLdMetadata, isHydraCollection, parseApiResponse } from '@/utils/jsonld'

describe('jsonld utilities', () => {
  // ---------------------------------------------------------------------------
  // stripJsonLdMetadata
  // ---------------------------------------------------------------------------
  describe('stripJsonLdMetadata', () => {
    it('removes @context, @id, and @type from a flat object', () => {
      const input = {
        '@context': '/api/contexts/Item',
        '@id': '/api/items/1',
        '@type': 'Item',
        name: 'Tritanium',
        quantity: 100,
      }
      const result = stripJsonLdMetadata(input)
      expect(result).toEqual({ name: 'Tritanium', quantity: 100 })
      expect('@context' in result).toBe(false)
      expect('@id' in result).toBe(false)
      expect('@type' in result).toBe(false)
    })

    it('recursively strips metadata from nested objects', () => {
      const input = {
        '@context': '/api/contexts/Project',
        '@id': '/api/projects/1',
        '@type': 'Project',
        name: 'Sabre Build',
        owner: {
          '@id': '/api/users/42',
          '@type': 'User',
          username: 'Pilot',
        },
      }
      const result = stripJsonLdMetadata(input)
      expect(result).toEqual({
        name: 'Sabre Build',
        owner: { username: 'Pilot' },
      })
    })

    it('recursively strips metadata from arrays of objects', () => {
      const input = {
        '@context': '/api/contexts/List',
        items: [
          { '@id': '/api/items/1', '@type': 'Item', name: 'Pyerite' },
          { '@id': '/api/items/2', '@type': 'Item', name: 'Mexallon' },
        ],
      }
      const result = stripJsonLdMetadata(input)
      expect(result).toEqual({
        items: [{ name: 'Pyerite' }, { name: 'Mexallon' }],
      })
    })

    it('handles deeply nested structures', () => {
      const input = {
        '@type': 'Root',
        level1: {
          '@type': 'Level1',
          level2: {
            '@type': 'Level2',
            value: 'deep',
          },
        },
      }
      const result = stripJsonLdMetadata(input)
      expect(result).toEqual({
        level1: { level2: { value: 'deep' } },
      })
    })

    it('returns null and undefined as-is', () => {
      expect(stripJsonLdMetadata(null)).toBeNull()
      expect(stripJsonLdMetadata(undefined)).toBeUndefined()
    })

    it('returns primitive values as-is', () => {
      expect(stripJsonLdMetadata(42)).toBe(42)
      expect(stripJsonLdMetadata('hello')).toBe('hello')
      expect(stripJsonLdMetadata(true)).toBe(true)
    })

    it('strips metadata from top-level arrays', () => {
      const input = [
        { '@id': '/api/items/1', name: 'A' },
        { '@id': '/api/items/2', name: 'B' },
      ]
      const result = stripJsonLdMetadata(input)
      expect(result).toEqual([{ name: 'A' }, { name: 'B' }])
    })

    it('preserves non-metadata @ keys if they exist', () => {
      const input = {
        '@context': 'should be removed',
        '@custom': 'should be kept',
        name: 'Test',
      }
      const result = stripJsonLdMetadata(input)
      expect(result).toEqual({
        '@custom': 'should be kept',
        name: 'Test',
      })
    })

    it('handles empty objects', () => {
      expect(stripJsonLdMetadata({})).toEqual({})
    })

    it('handles objects containing only metadata', () => {
      const input = { '@context': 'x', '@id': 'y', '@type': 'z' }
      expect(stripJsonLdMetadata(input)).toEqual({})
    })
  })

  // ---------------------------------------------------------------------------
  // isHydraCollection
  // ---------------------------------------------------------------------------
  describe('isHydraCollection', () => {
    it('returns true for a valid Hydra collection', () => {
      const data = {
        '@context': '/api/contexts/Items',
        '@id': '/api/items',
        '@type': 'Collection',
        member: [{ name: 'A' }, { name: 'B' }],
        totalItems: 2,
      }
      expect(isHydraCollection(data)).toBe(true)
    })

    it('returns true for a minimal collection with only member array', () => {
      expect(isHydraCollection({ member: [] })).toBe(true)
    })

    it('returns false for null', () => {
      expect(isHydraCollection(null)).toBe(false)
    })

    it('returns false for undefined', () => {
      expect(isHydraCollection(undefined)).toBe(false)
    })

    it('returns false for primitives', () => {
      expect(isHydraCollection(42)).toBe(false)
      expect(isHydraCollection('string')).toBe(false)
    })

    it('returns false for objects without member', () => {
      expect(isHydraCollection({ '@type': 'Item', name: 'A' })).toBe(false)
    })

    it('returns false when member is not an array', () => {
      expect(isHydraCollection({ member: 'not-array' })).toBe(false)
      expect(isHydraCollection({ member: 42 })).toBe(false)
      expect(isHydraCollection({ member: {} })).toBe(false)
    })
  })

  // ---------------------------------------------------------------------------
  // parseApiResponse
  // ---------------------------------------------------------------------------
  describe('parseApiResponse', () => {
    it('extracts and strips members from a Hydra collection', () => {
      const data = {
        '@context': '/api/contexts/Items',
        '@id': '/api/items',
        '@type': 'Collection',
        member: [
          { '@id': '/api/items/1', '@type': 'Item', name: 'Tritanium', qty: 500 },
          { '@id': '/api/items/2', '@type': 'Item', name: 'Pyerite', qty: 200 },
        ],
        totalItems: 2,
      }
      type Item = { name: string; qty: number }
      const result = parseApiResponse<Item[]>(data)
      expect(result).toEqual([
        { name: 'Tritanium', qty: 500 },
        { name: 'Pyerite', qty: 200 },
      ])
    })

    it('strips metadata from a single resource', () => {
      const data = {
        '@context': '/api/contexts/Item',
        '@id': '/api/items/1',
        '@type': 'Item',
        name: 'Megacyte',
      }
      type Item = { name: string }
      const result = parseApiResponse<Item>(data)
      expect(result).toEqual({ name: 'Megacyte' })
    })

    it('returns primitives as-is', () => {
      expect(parseApiResponse<number>(42)).toBe(42)
      expect(parseApiResponse<string>('test')).toBe('test')
      expect(parseApiResponse<null>(null)).toBeNull()
    })

    it('handles empty Hydra collection', () => {
      const data = {
        '@context': '/api/contexts/Items',
        '@type': 'Collection',
        member: [],
        totalItems: 0,
      }
      const result = parseApiResponse<never[]>(data)
      expect(result).toEqual([])
    })

    it('strips nested metadata in collection members', () => {
      const data = {
        member: [
          {
            '@id': '/api/projects/1',
            name: 'Build',
            steps: [
              { '@id': '/api/steps/1', material: 'Tritanium' },
            ],
          },
        ],
      }
      type Project = { name: string; steps: { material: string }[] }
      const result = parseApiResponse<Project[]>(data)
      expect(result).toEqual([
        { name: 'Build', steps: [{ material: 'Tritanium' }] },
      ])
    })
  })
})
