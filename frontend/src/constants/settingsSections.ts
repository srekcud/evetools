export const SETTINGS_SECTIONS = ['account', 'display', 'market', 'industry', 'mining', 'pve', 'notifications'] as const

export type SettingsSection = typeof SETTINGS_SECTIONS[number]

export const DEFAULT_SETTINGS_SECTION: SettingsSection = 'account'

export const SETTINGS_PATH = '/settings'

export function settingsSectionLabelKey(section: SettingsSection): string {
  return `settings.sections.${section}`
}

export function isSettingsSection(value: unknown): value is SettingsSection {
  return SETTINGS_SECTIONS.some(section => section === value)
}
