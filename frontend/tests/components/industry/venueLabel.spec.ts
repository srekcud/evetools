import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
// Module planned by issue #61: label logic extracted from ProfitMarginTab.vue (bestVenueLabel).
import { venueLabel } from '@/components/industry/venueLabel'

// Real EN messages so the test pins the actual "industry.margins.instantSellTag" value ("instant sell").
const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
const t = i18n.global.t

const STRUCTURE_NAME = 'C-J6MT - 1st Taj Mahgoon (Keepstar)'

describe('venueLabel', () => {
  it('labels jitaSell as "Jita sell"', () => {
    expect(venueLabel('jitaSell', STRUCTURE_NAME, t)).toBe('Jita sell')
  })

  it('labels structureSell with the structure name', () => {
    expect(venueLabel('structureSell', STRUCTURE_NAME, t)).toBe('C-J6MT - 1st Taj Mahgoon (Keepstar)')
  })

  it('labels contractSell as "Contract"', () => {
    expect(venueLabel('contractSell', STRUCTURE_NAME, t)).toBe('Contract')
  })

  // Issue #61: structureBuy (instant sale to a buy order on the structure) was labelled "Jita".
  it('labels structureBuy with the structure name followed by the instant sell mention', () => {
    expect(venueLabel('structureBuy', STRUCTURE_NAME, t)).toBe('C-J6MT - 1st Taj Mahgoon (Keepstar) (instant sell)')
  })

  it('never labels structureBuy as Jita', () => {
    expect(venueLabel('structureBuy', STRUCTURE_NAME, t)).not.toContain('Jita')
  })

  // Unknown venue key: empty string rather than a misleading venue name, and rather than the raw
  // backend key (an internal identifier like "fooBar" is not meaningful to the user).
  // bestVenueLabel already returns '' when there is no best venue, so the template handles it.
  it('returns an empty string for an unknown venue key', () => {
    expect(venueLabel('unknownVenue', STRUCTURE_NAME, t)).toBe('')
  })
})
