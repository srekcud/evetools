import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { CHART_COLORS, CHART_SERIES } from '@/utils/chartColors'

const themeCss = readFileSync(resolve(__dirname, '../../src/style.css'), 'utf-8')

const COLOR_TOKEN_BY_KEY: Record<keyof typeof CHART_COLORS, string> = {
  profit: '--color-profit',
  loss: '--color-loss',
  warning: '--color-warning',
  danger: '--color-danger',
  info: '--color-info',
  muted: '--color-muted',
  subtle: '--color-subtle',
  neutral: '--color-neutral',
  surface: '--color-surface',
  text: '--color-chart-text',
  grid: '--color-chart-grid',
  brand: '--color-brand',
}

function readThemeToken(name: string): string | undefined {
  return themeCss.match(new RegExp(`${name}:\\s*(#[0-9a-fA-F]{6});`))?.[1]
}

describe('chartColors', () => {
  it.each(Object.entries(COLOR_TOKEN_BY_KEY))('keeps %s aligned with the %s theme token', (key, token) => {
    expect(readThemeToken(token)).toBe(CHART_COLORS[key as keyof typeof CHART_COLORS])
  })

  it('keeps the series palette aligned with the chart theme tokens', () => {
    const themeSeries = CHART_SERIES.map((_, index) => readThemeToken(`--color-chart-${index + 1}`))

    expect(themeSeries).toEqual([...CHART_SERIES])
  })

  it('declares no chart series token beyond the palette', () => {
    expect(readThemeToken(`--color-chart-${CHART_SERIES.length + 1}`)).toBeUndefined()
  })
})
