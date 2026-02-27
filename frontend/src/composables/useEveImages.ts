/**
 * Composable for handling EVE Online images with fallback support
 */

const EVE_IMAGE_SERVER = 'https://images.evetech.net'

// Default fallback SVG for items without icons (SKINs, etc.)
const FALLBACK_ICON_SVG = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" fill="none">
  <rect width="32" height="32" rx="4" fill="#1e293b"/>
  <path d="M8 12h16M8 16h12M8 20h8" stroke="#475569" stroke-width="2" stroke-linecap="round"/>
</svg>`

const FALLBACK_ICON_DATA_URI = 'data:image/svg+xml,' + encodeURIComponent(FALLBACK_ICON_SVG)

export type ImageSize = 32 | 64 | 128 | 256 | 512

// EVE categories whose types have no icon on images.evetech.net
const ICONLESS_CATEGORY_IDS = new Set([91]) // 91 = SKINs

export function useEveImages() {
  /**
   * Get type icon URL (items, ships, modules, etc.)
   * Pass categoryId to skip the network request for types without icons (SKINs).
   */
  function getTypeIconUrl(typeId: number, size: ImageSize = 32, categoryId?: number): string {
    if (categoryId != null && ICONLESS_CATEGORY_IDS.has(categoryId)) {
      return FALLBACK_ICON_DATA_URI
    }
    return `${EVE_IMAGE_SERVER}/types/${typeId}/icon?size=${size}`
  }

  /**
   * Get character portrait URL
   */
  function getCharacterPortraitUrl(characterId: number, size: ImageSize = 64): string {
    return `${EVE_IMAGE_SERVER}/characters/${characterId}/portrait?size=${size}`
  }

  /**
   * Get corporation logo URL
   */
  function getCorporationLogoUrl(corporationId: number, size: ImageSize = 32): string {
    return `${EVE_IMAGE_SERVER}/corporations/${corporationId}/logo?size=${size}`
  }

  /**
   * Get alliance logo URL
   */
  function getAllianceLogoUrl(allianceId: number, size: ImageSize = 32): string {
    return `${EVE_IMAGE_SERVER}/alliances/${allianceId}/logo?size=${size}`
  }

  /**
   * Handle image load error by replacing with fallback
   * Use this as @error handler on img elements
   */
  function onImageError(event: Event): void {
    const img = event.target as HTMLImageElement
    if (img.src !== FALLBACK_ICON_DATA_URI) {
      img.src = FALLBACK_ICON_DATA_URI
    }
  }

  /**
   * Get the fallback icon data URI directly
   */
  function getFallbackIconUrl(): string {
    return FALLBACK_ICON_DATA_URI
  }

  return {
    getTypeIconUrl,
    getCharacterPortraitUrl,
    getCorporationLogoUrl,
    getAllianceLogoUrl,
    onImageError,
    getFallbackIconUrl,
  }
}
