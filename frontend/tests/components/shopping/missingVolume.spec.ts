import { missingVolumeTypeNames } from '@/components/shopping/missingVolume'

const TRITANIUM = { typeId: 34, typeName: 'Tritanium' }
const PYERITE = { typeId: 35, typeName: 'Pyerite' }

describe('missingVolumeTypeNames', () => {
  it('names the items whose type has no volume', () => {
    expect(missingVolumeTypeNames([TRITANIUM, PYERITE], [35])).toBe('Pyerite')
  })

  it('returns an empty string when every volume is known', () => {
    expect(missingVolumeTypeNames([TRITANIUM, PYERITE], [])).toBe('')
  })

  it('names a type only once when it appears on several items', () => {
    expect(missingVolumeTypeNames([TRITANIUM, PYERITE, TRITANIUM], [34, 35])).toBe('Tritanium, Pyerite')
  })
})
