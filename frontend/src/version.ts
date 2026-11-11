const DEVELOPMENT_VERSION = 'dev'

export function displayVersionOf(releaseTag: string | undefined): string {
  return releaseTag ? releaseTag.replace(/^V/, '') : DEVELOPMENT_VERSION
}

export const APP_VERSION = displayVersionOf(import.meta.env.VITE_APP_VERSION)
