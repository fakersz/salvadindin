export type User = { id: string; username: string; created_at: string }
export type AuthResult = { user: User; recovery_codes?: string[] }

export async function authInfo(): Promise<{ csrf_token: string; registration_enabled: boolean }> {
  const response = await fetch('/api/auth/csrf', { credentials: 'same-origin', cache: 'no-store' })
  if (!response.ok) throw new Error('Não foi possível conectar. Tente novamente.')
  return response.json()
}

export async function currentUser(): Promise<User | null> {
  const response = await fetch('/api/auth/me', { credentials: 'same-origin', cache: 'no-store' })
  if (response.status === 401) return null
  if (!response.ok) throw new Error('Não foi possível verificar sua sessão. Tente novamente.')
  return (await response.json()).user
}

export async function mutate<T>(path: string, data: unknown): Promise<T> {
  const { csrf_token } = await authInfo()
  const response = await fetch(`/api/auth/${path}`, {
    method: 'POST', credentials: 'same-origin', cache: 'no-store',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf_token },
    body: JSON.stringify(data),
  })
  if (response.status === 204) return undefined as T
  const result = await response.json()
  if (!response.ok) throw new Error(result.error?.message || 'Não foi possível concluir. Tente novamente.')
  return result
}
