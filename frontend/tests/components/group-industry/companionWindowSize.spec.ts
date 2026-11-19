import { describe, expect, it } from 'vitest'
import { COMPANION_WINDOW_SIZES } from '@/components/group-industry/companionWindowSize'

describe('COMPANION_WINDOW_SIZES', () => {
  it('sizes the bar window at 1240 by 72 so every counter and the alert fit', () => {
    expect(COMPANION_WINDOW_SIZES.bar).toEqual({ width: 1240, height: 72 })
  })

  it('keeps the column window at 360 by 640', () => {
    expect(COMPANION_WINDOW_SIZES.column).toEqual({ width: 360, height: 640 })
  })
})
