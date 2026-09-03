let csrfToken = null

async function ensureCsrf(force = false) {
  if (force) csrfToken = null
  if (csrfToken) return csrfToken

  const r = await fetch('/api/csrf-token', {
    credentials: 'same-origin',
    cache: 'no-store',
  })

  if (!r.ok) {
    throw new Error(`Gagal mengambil CSRF token (HTTP ${r.status})`)
  }

  const d = await r.json()
  csrfToken = d.token
  return csrfToken
}

async function request(
  path,
  { method = 'GET', body, form = false } = {},
  retry419 = true
) {
  const headers = {
    Accept: 'application/json',
  }

  const isWrite = method !== 'GET' && method !== 'HEAD'

  if (isWrite) {
    headers['X-CSRF-TOKEN'] = await ensureCsrf()
  }

  if (body && !form) {
    headers['Content-Type'] = 'application/json'
  }

  const r = await fetch(path, {
    method,
    headers,
    credentials: 'same-origin',
    cache: 'no-store',
    body: body
      ? form
        ? body
        : JSON.stringify(body)
      : undefined,
  })

  // Kalau session berubah, ambil CSRF baru dan ulang sekali.
  if (r.status === 419 && isWrite && retry419) {
    await ensureCsrf(true)

    return request(
      path,
      { method, body, form },
      false
    )
  }

  let data = {}

  try {
    data = await r.json()
  } catch {}

  if (!r.ok) {
    const msg =
      data.message ||
      Object.values(data.errors || {}).flat()[0] ||
      `HTTP ${r.status}`

    const e = new Error(msg)
    e.status = r.status
    e.data = data
    throw e
  }

  return data
}

export async function api(path, options = {}) {
  return request(path, options, true)
}

export function resetCsrf() {
  csrfToken = null
}