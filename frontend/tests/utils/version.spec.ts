import { displayVersionOf } from '@/version'

describe('displayVersionOf', () => {
  it('drops the V prefix of a release tag', () => {
    expect(displayVersionOf('V0.14')).toBe('0.14')
  })

  it('keeps the patch number of a release tag', () => {
    expect(displayVersionOf('V0.7.1')).toBe('0.7.1')
  })

  it('shows dev when the build carries no release tag', () => {
    expect(displayVersionOf(undefined)).toBe('dev')
    expect(displayVersionOf('')).toBe('dev')
  })
})
