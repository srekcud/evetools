import { MAXIMUM_BLUEPRINT_COPIES, parseBulkLine } from '@/components/group-industry/bulkPasteLines'

describe('parseBulkLine blueprint copies', () => {
  it('reads the repeat suffix as the number of identical blueprint copies', () => {
    expect(parseBulkLine('Ogre II 2 4 x10 *5')).toEqual({
      request: { typeName: 'Ogre II', meLevel: 2, teLevel: 4, runs: 10, copies: 5 },
    })
  })

  it('reads one copy when the line has no repeat suffix', () => {
    expect(parseBulkLine('Ferox 2 4 x100')).toEqual({
      request: { typeName: 'Ferox', meLevel: 2, teLevel: 4, runs: 100, copies: 1 },
    })
  })

  it('accepts the repeat suffix on a line whose runs have no x prefix', () => {
    expect(parseBulkLine('Ogre II 2 4 10 *3')).toEqual({
      request: { typeName: 'Ogre II', meLevel: 2, teLevel: 4, runs: 10, copies: 3 },
    })
  })

  it('accepts the maximum number of copies', () => {
    expect(parseBulkLine(`Ogre II 2 4 x10 *${MAXIMUM_BLUEPRINT_COPIES}`)).toHaveProperty('request.copies', MAXIMUM_BLUEPRINT_COPIES)
  })

  it.each([
    ['zero copies', 'Ogre II 2 4 x10 *0'],
    ['more copies than the maximum', `Ogre II 2 4 x10 *${MAXIMUM_BLUEPRINT_COPIES + 1}`],
  ])('rejects %s with the copies reason', (_label, line) => {
    expect(parseBulkLine(line)).toEqual({ rejection: 'copies' })
  })

  it.each([
    ['a repeat suffix without a number', 'Ogre II 2 4 x10 *'],
    ['a repeat suffix detached from its number', 'Ogre II 2 4 x10 * 5'],
    ['a repeat suffix glued to the runs', 'Ogre II 2 4 x10*5'],
  ])('rejects %s as a format error', (_label, line) => {
    expect(parseBulkLine(line)).toEqual({ rejection: 'format' })
  })

  it('keeps the other rejections ahead of the copies when several fields are wrong', () => {
    expect(parseBulkLine('Ogre II 11 4 x10 *0')).toEqual({ rejection: 'materialEfficiency' })
  })
})
