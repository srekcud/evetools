import type { User, Character, AssetsResponse } from '@/types'
import router from '@/router'

/**
 * Handle 401 Unauthorized errors by clearing token and redirecting to login.
 */
export function handleUnauthorized(): void {
  localStorage.removeItem('jwt_token')
  router.push('/login')
}

/**
 * Authenticated fetch wrapper that handles 401 errors globally.
 * Use this instead of raw fetch() for API calls.
 */
export async function authFetch(
  input: RequestInfo | URL,
  init?: RequestInit,
): Promise<Response> {
  const token = localStorage.getItem('jwt_token')
  const headers = new Headers(init?.headers)

  if (token && !headers.has('Authorization')) {
    headers.set('Authorization', `Bearer ${token}`)
  }
  if (!headers.has('X-Requested-With')) {
    headers.set('X-Requested-With', 'XMLHttpRequest')
  }

  const response = await fetch(input, { ...init, headers })

  if (response.status === 401) {
    handleUnauthorized()
  }

  return response
}

/**
 * Centralized API request function with JWT handling and 401 redirect.
 */
export async function apiRequest<T>(
  endpoint: string,
  options: RequestInit = {},
): Promise<T> {
  const token = localStorage.getItem('jwt_token')
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    ...(options.headers as Record<string, string>),
  }

  if (token) {
    headers['Authorization'] = `Bearer ${token}`
  }

  const response = await fetch(`/api${endpoint}`, { ...options, headers })

  if (!response.ok) {
    if (response.status === 401) {
      handleUnauthorized()
      throw new Error('Session expirée. Veuillez vous reconnecter.')
    }
    const errorData = await safeJsonParse<{ error?: string }>(response).catch(() => ({ error: undefined }))
    throw new Error(errorData.error || `API error: ${response.status}`)
  }

  if (response.status === 204) return null as T
  return safeJsonParse<T>(response)
}

/**
 * Safely parse JSON from a response that might have extra content appended
 * (e.g., Symfony debug toolbar HTML injected after JSON).
 */
export async function safeJsonParse<T>(response: Response): Promise<T> {
  const text = await response.text()

  // Try normal JSON parse first
  try {
    return JSON.parse(text) as T
  } catch {
    // If that fails, try to extract JSON from the beginning
    // JSON objects start with { and arrays start with [
    const firstChar = text.trim()[0]
    if (firstChar !== '{' && firstChar !== '[') {
      throw new Error('Response is not JSON')
    }

    // Find the matching closing bracket
    let depth = 0
    let inString = false
    let escapeNext = false
    const openBracket = firstChar
    const closeBracket = firstChar === '{' ? '}' : ']'

    for (let i = 0; i < text.length; i++) {
      const char = text[i]

      if (escapeNext) {
        escapeNext = false
        continue
      }

      if (char === '\\' && inString) {
        escapeNext = true
        continue
      }

      if (char === '"' && !escapeNext) {
        inString = !inString
        continue
      }

      if (inString) continue

      if (char === openBracket) {
        depth++
      } else if (char === closeBracket) {
        depth--
        if (depth === 0) {
          // Found the end of the JSON
          const jsonStr = text.substring(0, i + 1)
          return JSON.parse(jsonStr) as T
        }
      }
    }

    throw new Error('Could not extract valid JSON from response')
  }
}

class ApiService {
  private baseUrl = '/api'
  private authToken: string | null = null

  setAuthToken(token: string | null) {
    this.authToken = token
  }

  private async request<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(options.headers as Record<string, string>),
    }

    if (this.authToken) {
      headers['Authorization'] = `Bearer ${this.authToken}`
    }

    const response = await fetch(`${this.baseUrl}${endpoint}`, {
      ...options,
      headers,
    })

    if (!response.ok) {
      if (response.status === 401) {
        handleUnauthorized()
        throw new Error('Session expirée. Veuillez vous reconnecter.')
      }
      throw new Error(`API error: ${response.status}`)
    }

    return response.json()
  }

  async getMe(): Promise<User> {
    return this.request<User>('/me')
  }

  async getCharacters(): Promise<Character[]> {
    return this.request<Character[]>('/me/characters')
  }

  async getCharacterAssets(characterId: string, locationId?: number): Promise<AssetsResponse> {
    const params = locationId ? `?locationId=${locationId}` : ''
    return this.request<AssetsResponse>(`/me/characters/${characterId}/assets${params}`)
  }

  async refreshCharacterAssets(characterId: string): Promise<{ status: string; message: string }> {
    return this.request(`/me/characters/${characterId}/assets/refresh`, { method: 'POST' })
  }

  async getCorporationAssets(divisionName?: string): Promise<AssetsResponse> {
    const params = divisionName ? `?divisionName=${divisionName}` : ''
    return this.request<AssetsResponse>(`/me/corporation/assets${params}`)
  }

  async refreshCorporationAssets(): Promise<{ status: string; message: string }> {
    return this.request('/me/corporation/assets/refresh', { method: 'POST' })
  }

  async setMainCharacter(characterId: string): Promise<void> {
    return this.request(`/me/characters/${characterId}/main`, { method: 'POST' })
  }

  getEveLoginUrl(): string {
    return '/auth/eve'
  }

  getCharacterPortraitUrl(characterId: number, size: 64 | 128 | 256 | 512 = 128): string {
    return `https://images.evetech.net/characters/${characterId}/portrait?size=${size}`
  }

  getCorporationLogoUrl(corporationId: number, size: 64 | 128 | 256 = 128): string {
    return `https://images.evetech.net/corporations/${corporationId}/logo?size=${size}`
  }

  getAllianceLogoUrl(allianceId: number, size: 64 | 128 | 256 = 128): string {
    return `https://images.evetech.net/alliances/${allianceId}/logo?size=${size}`
  }

  getTypeIconUrl(typeId: number, size: 32 | 64 = 64): string {
    return `https://images.evetech.net/types/${typeId}/icon?size=${size}`
  }
}

export const api = new ApiService()
