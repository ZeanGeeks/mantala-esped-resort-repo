const API_BASE = (import.meta.env.VITE_API_URL || 'http://localhost/ESPED%20RESORT%20SYSTEM/LavaLust/public/api/v1').replace(/\/$/, '')

function buildBody(payload) {
  const params = new URLSearchParams()
  Object.entries(payload || {}).forEach(([key, value]) => {
    if (value === null || value === undefined) return
    params.append(key, String(value))
  })
  return params
}

export async function request(path, { method = 'GET', body, csrfToken, query } = {}) {
  const url = new URL(`${API_BASE}${path}`)
  if (query && typeof query === 'object') {
    Object.entries(query).forEach(([key, value]) => {
      if (value !== null && value !== undefined && value !== '') {
        url.searchParams.set(key, value)
      }
    })
  }

  const headers = {
    Accept: 'application/json',
  }
  if (csrfToken) {
    headers['X-CSRF-Token'] = csrfToken
  }

  const config = {
    method,
    credentials: 'include',
    headers,
  }

  if (method !== 'GET' && body) {
    headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8'
    config.body = buildBody(body)
  }

  const response = await fetch(url.toString(), config)
  const data = await response.json().catch(() => ({}))
  if (!response.ok) {
    throw new Error(data.error || data.message || `Request failed: ${response.status}`)
  }
  return data
}

export function getHealth() {
  return request('/health')
}

export function getCsrf() {
  return request('/csrf')
}
