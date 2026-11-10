import { existsSync } from 'node:fs'
import { resolve } from 'node:path'

const SOURCE_ROOT = resolve(__dirname, '../../src')

describe('shopping list removal', () => {
  it.each([
    ['the shopping list view', 'views/ShoppingList.vue'],
    ['the shopping list results component', 'components/shopping/ShoppingListResults.vue'],
    ['the shopping components folder', 'components/shopping'],
  ])('leaves no trace of %s', (_case, relativePath) => {
    expect(existsSync(resolve(SOURCE_ROOT, relativePath))).toBe(false)
  })

  it('keeps the appraisal components at their new place', () => {
    expect(['AppraisalResults.vue', 'MissingVolumeWarning.vue', 'missingVolume.ts'].filter(
      file => !existsSync(resolve(SOURCE_ROOT, 'components/appraisal', file)),
    )).toEqual([])
  })
})
