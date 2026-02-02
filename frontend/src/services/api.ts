import type { User, Character, AssetsResponse } from '@/types'
import router from '@/router'
import { useRateLimitStore } from '@/stores/rateLimit'
import { parseApiResponse } from '@/utils/jsonld'

/**
 * API Platform validation error structure
 */
export interface ApiValidationViolation {
  propertyPath: string
  message: string
  code?: string
}

export interface ApiValidationError {
  '@type': 'ConstraintViolationList'
  status: number
  violations: ApiValidationViolation[]
  description: string
  detail: string
}

/**
 * Custom error class for API validation errors (422)
 */
export class ValidationError extends Error {
  public readonly violations: ApiValidationViolation[]
  public readonly status: number = 422

  constructor(data: ApiValidationError) {
    // Use description or build from violations
    const message = data.description || data.violations.map(v => v.message).join(', ')
    super(message)
    this.name = 'ValidationError'
    this.violations = data.violations || []
  }

  /**
   * Get error message for a specific field
   */
  getFieldError(field: string): string | null {
    const violation = this.violations.find(v => v.propertyPath === field)
    return violation?.message || null
  }

  /**
   * Get all field errors as a map
   */
  getFieldErrors(): Record<string, string> {
    const errors: Record<string, string> = {}
    for (const v of this.violations) {
      errors[v.propertyPath] = v.message
    }
    return errors
  }
}

/**
 * Check if an error is a ValidationError
 */
export function isValidationError(error: unknown): error is ValidationError {
  return error instanceof ValidationError
}

/**
 * Endpoints that make ESI API calls and should be blocked when rate limited
 */
const ESI_ENDPOINTS = [
  '/characters/',
  '/assets',
  '/contracts',
  '/industry/search-structure',
  '/industry/corporation-structures',
  '/shopping-list',
  '/ansiblex',
  '/pve/',
]

function isEsiEndpoint(endpoint: string): boolean {
  return ESI_ENDPOINTS.some(e => endpoint.includes(e))
}

/**
 * Flag to prevent multiple 401 redirects when several API calls fail simultaneously.
 */
let isRedirectingToLogin = false

/**
 * Custom event dispatched when a 401 error requires logout.
 * The auth store listens for this to clear its state.
 */
export const AUTH_LOGOUT_EVENT = 'auth:logout'

/**
 * Handle 401 Unauthorized errors by clearing token and redirecting to login.
 * Uses a flag to prevent multiple concurrent redirects.
 */
export function handleUnauthorized(): void {
  if (isRedirectingToLogin) {
    return
  }
  isRedirectingToLogin = true
  localStorage.removeItem('jwt_token')

  // Dispatch event for auth store to clear its state
  window.dispatchEvent(new CustomEvent(AUTH_LOGOUT_EVENT))

  router.push('/login').finally(() => {
    // Reset flag after navigation completes (or fails)
    isRedirectingToLogin = false
  })
}

/**
 * Authenticated fetch wrapper that handles 401 errors globally.
 * Use this instead of raw fetch() for API calls.
 */
export async function authFetch(
  input: RequestInfo | URL,
  init?: RequestInit,
): Promise<Response> {
  // Skip API calls if we're already redirecting to login
  if (isRedirectingToLogin) {
    throw new Error('Session expirée. Redirection en cours...')
  }

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
  const method = options.method?.toUpperCase() || 'GET'
  // API Platform requires application/merge-patch+json for PATCH requests
  const contentType = method === 'PATCH' ? 'application/merge-patch+json' : 'application/json'
  const headers: Record<string, string> = {
    'Content-Type': contentType,
    'X-Requested-With': 'XMLHttpRequest',
    ...(options.headers as Record<string, string>),
  }

  if (token) {
    headers['Authorization'] = `Bearer ${token}`
  }

  // Check if we're rate limited before making ESI-related requests
  const rateLimitStore = useRateLimitStore()
  if (rateLimitStore.isRateLimited && isEsiEndpoint(endpoint)) {
    throw new Error(`Rate limit actif (${rateLimitStore.remainingSeconds}s). Action bloquée.`)
  }

  const response = await fetch(`/api${endpoint}`, { ...options, headers })

  if (!response.ok) {
    if (response.status === 401) {
      handleUnauthorized()
      throw new Error('Session expirée. Veuillez vous reconnecter.')
    }

    // Handle rate limiting (420 from ESI or 429)
    if (response.status === 429 || response.status === 420) {
      const rateLimitStore = useRateLimitStore()
      rateLimitStore.setRateLimited()
      throw new Error('Rate limit ESI atteint. Veuillez patienter.')
    }

    // Handle validation errors (422) from API Platform
    if (response.status === 422) {
      const errorData = await safeJsonParse<ApiValidationError>(response).catch(() => null)
      if (errorData && errorData['@type'] === 'ConstraintViolationList') {
        throw new ValidationError(errorData)
      }
      // Fallback if not a proper validation error format
      throw new Error(errorData?.description || 'Erreur de validation')
    }

    type ErrorResponse = { error?: string; description?: string; detail?: string; 'hydra:description'?: string }
    const errorData = await safeJsonParse<ErrorResponse>(response).catch((): ErrorResponse => ({}))

    // Also check for rate limit in error message
    const errorMsg = errorData?.error
    if (errorMsg && (errorMsg.includes('rate limit') || errorMsg.includes('Rate limit') || errorMsg.includes('Error limited'))) {
      const rateLimitStore = useRateLimitStore()
      rateLimitStore.setRateLimited()
    }

    throw new Error(errorData?.error || errorData?.description || errorData?.detail || errorData?.['hydra:description'] || `API error: ${response.status}`)
  }

  if (response.status === 204) return null as T
  return safeJsonParse<T>(response)
}

/**
 * Safely parse JSON from a response that might have extra content appended
 * (e.g., Symfony debug toolbar HTML injected after JSON).
 * Automatically handles JSON-LD responses by stripping metadata.
 */
export async function safeJsonParse<T>(response: Response): Promise<T> {
  const text = await response.text()
  let data: unknown

  // Try normal JSON parse first
  try {
    data = JSON.parse(text)
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
          data = JSON.parse(jsonStr)
          break
        }
      }
    }

    if (data === undefined) {
      throw new Error('Could not extract valid JSON from response')
    }
  }

  // Parse JSON-LD response (strip metadata, extract hydra:member for collections)
  return parseApiResponse<T>(data)
}

class ApiService {
  private baseUrl = '/api'
  private authToken: string | null = null

  setAuthToken(token: string | null) {
    this.authToken = token
  }

  private async request<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
    // Skip API calls if we're already redirecting to login
    if (isRedirectingToLogin) {
      throw new Error('Session expirée. Redirection en cours...')
    }

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
        this.authToken = null
        handleUnauthorized()
        throw new Error('Session expirée. Veuillez vous reconnecter.')
      }
      throw new Error(`API error: ${response.status}`)
    }

    const data = await response.json()
    return parseApiResponse<T>(data)
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
    return this.request(`/me/characters/${characterId}/assets/refresh`, { method: 'POST', body: JSON.stringify({}) })
  }

  async getCorporationAssets(divisionName?: string): Promise<AssetsResponse> {
    const params = divisionName ? `?divisionName=${divisionName}` : ''
    return this.request<AssetsResponse>(`/me/corporation/assets${params}`)
  }

  async refreshCorporationAssets(): Promise<{ status: string; message: string }> {
    return this.request('/me/corporation/assets/refresh', { method: 'POST', body: JSON.stringify({}) })
  }

  async setMainCharacter(characterId: string): Promise<void> {
    return this.request(`/me/characters/${characterId}/set-main`, { method: 'POST', body: JSON.stringify({}) })
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
