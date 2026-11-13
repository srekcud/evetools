import { containerNamesFromText, containerNamesToText } from '@/utils/containerNames'

describe('containerNamesFromText', () => {
  it('reads one container name per line', () => {
    expect(containerNamesFromText('Mats\nProducts')).toEqual(['Mats', 'Products'])
  })

  it('trims each name and ignores blank lines', () => {
    expect(containerNamesFromText('  Mats  \n\n   \r\nProducts\r\n')).toEqual(['Mats', 'Products'])
  })

  it('keeps a comma inside a name', () => {
    expect(containerNamesFromText('Mats, minerals')).toEqual(['Mats, minerals'])
  })

  it('returns no name for an empty text', () => {
    expect(containerNamesFromText('')).toEqual([])
  })
})

describe('containerNamesToText', () => {
  it('writes one name per line', () => {
    expect(containerNamesToText(['Mats', 'Products'])).toBe('Mats\nProducts')
  })

  it('writes an empty text when there is no name', () => {
    expect(containerNamesToText([])).toBe('')
  })
})
