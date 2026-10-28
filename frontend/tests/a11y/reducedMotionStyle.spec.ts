import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const stylesheet = readFileSync(resolve(__dirname, '../../src/style.css'), 'utf8')

function reducedMotionRule(): string {
  const start = stylesheet.indexOf('@media (prefers-reduced-motion: reduce)')
  if (start === -1) throw new Error('No prefers-reduced-motion rule in style.css')
  const bodyStart = stylesheet.indexOf('{', start)
  let depth = 0
  for (let index = bodyStart; index < stylesheet.length; index++) {
    if (stylesheet[index] === '{') depth++
    if (stylesheet[index] === '}') depth--
    if (depth === 0) return stylesheet.slice(bodyStart + 1, index)
  }
  throw new Error('Unterminated prefers-reduced-motion rule in style.css')
}

describe('prefers-reduced-motion in style.css', () => {
  it.each(['.animate-pulse', '.animate-pulse-slow', '.animate-pulse-slower', '.animate-glow'])(
    'turns off the decorative animation %s',
    (selector) => {
      const rule = reducedMotionRule()

      expect(rule).toMatch(new RegExp(`${selector.replace('.', '\\.')}\\s*[,{]`))
      expect(rule).toMatch(/animation:\s*none\s*!important/)
    },
  )

  it('leaves the loading spinners animated', () => {
    expect(reducedMotionRule()).not.toContain('animate-spin')
  })
})
