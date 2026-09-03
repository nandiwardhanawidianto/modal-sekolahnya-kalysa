let csrfToken = null

// Cache GET sederhana di memori browser. Database tetap menjadi sumber kebenaran.
// Semua perubahan data dari aplikasi akan mengosongkan cache otomatis.
const getCache = new Map()
const inFlight = new Map()

function ttlFor(path) {
  if (path === '/api/me') return 30 * 60 * 1000
  if (path === '/api/stores') return 30 * 60 * 1000
  if (path.includes('/products')) return 20 * 60 * 1000
  if (path.includes('/imports')) return 5 * 60 * 1000
  if (path.includes('/ads')) return 10 * 60 * 1000
  if (path.includes('/closings')) return 10 * 60 * 1000
  if (path.includes('/report') || path.includes('/cashflow')) return 10 * 60 * 1000
  return 10 * 60 * 1000
}

export function clearApiCache(prefix = '') {
  if (!prefix) {
    getCache.clear()
    return
  }
  for (const key of getCache.keys()) {
    if (key.startsWith(prefix)) getCache.delete(key)
  }
}

export function apiCacheInfo(path) {
  const entry = getCache.get(path)
  if (!entry) return null
  return {
    cachedAt: entry.cachedAt,
    ageMs: Date.now() - entry.cachedAt,
    fresh: Date.now() - entry.cachedAt < ttlFor(path),
  }
}

async function ensureCsrf(force = false) {
  if (force) csrfToken = null
  if (csrfToken) return csrfToken

  const r = await fetch('/api/csrf-token', {
    credentials: 'same-origin',
    cache: 'no-store',
  })

  if (!r.ok) throw new Error(`Gagal mengambil CSRF token (HTTP ${r.status})`)
  const d = await r.json()
  csrfToken = d.token
  return csrfToken
}

async function request(path, { method = 'GET', body, form = false } = {}, retry419 = true) {
  const headers = { Accept: 'application/json' }
  const isWrite = method !== 'GET' && method !== 'HEAD'

  if (isWrite) headers['X-CSRF-TOKEN'] = await ensureCsrf()
  if (body && !form) headers['Content-Type'] = 'application/json'

  const r = await fetch(path, {
    method,
    headers,
    credentials: 'same-origin',
    cache: 'no-store',
    body: body ? (form ? body : JSON.stringify(body)) : undefined,
  })

  // Session ID can be regenerated after login/logout. Refresh the token once
  // instead of surfacing a misleading CSRF error to the user.
  if (r.status === 419 && isWrite && retry419) {
    await ensureCsrf(true)
    return request(path, { method, body, form }, false)
  }

  let data = {}
  try {
    data = await r.json()
  } catch {}

  if (!r.ok) {
    const msg = data.message || Object.values(data.errors || {}).flat()[0] || `HTTP ${r.status}`
    const e = new Error(msg)
    e.status = r.status
    e.data = data
    throw e
  }

  return data
}

async function getCached(path, fresh = false) {
  const now = Date.now()
  const cached = getCache.get(path)
  if (!fresh && cached && now - cached.cachedAt < ttlFor(path)) return cached.data

  // Hindari dua halaman meminta endpoint yang sama pada saat bersamaan.
  if (!fresh && inFlight.has(path)) return inFlight.get(path)

  const promise = request(path, { method: 'GET' }, true)
    .then(data => {
      getCache.set(path, { data, cachedAt: Date.now() })
      return data
    })
    .finally(() => inFlight.delete(path))

  inFlight.set(path, promise)
  return promise
}

export async function api(path, options = {}) {
  const method = String(options.method || 'GET').toUpperCase()
  if (method === 'GET' || method === 'HEAD') {
    return getCached(path, !!options.fresh)
  }

  const data = await request(path, options, true)

  // Preview tidak mengubah database. Semua POST/PATCH/PUT/DELETE lain dianggap
  // berpotensi mengubah laporan sehingga cache dibuang agar angka berikutnya fresh.
  if (!path.includes('/imports/preview')) clearApiCache()
  return data
}

export function apiFresh(path) {
  return getCached(path, true)
}

export function resetCsrf() {
  csrfToken = null
  clearApiCache()
}
