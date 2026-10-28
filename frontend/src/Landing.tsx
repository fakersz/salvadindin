import { useState } from 'react'

type Theme = 'system' | 'light' | 'dark'

export function Landing() {
  const [theme, setTheme] = useState<Theme>('system')
  function changeTheme(value: Theme) {
    setTheme(value)
    if (value === 'system') delete document.documentElement.dataset.theme
    else document.documentElement.dataset.theme = value
  }

  return (
    <div className="flex min-h-screen flex-col">
      <header className="mx-auto flex w-full max-w-5xl flex-wrap items-center justify-between gap-4 px-6 py-7">
        <a href="#inicio" className="flex items-center gap-3 font-semibold tracking-tight">
          <span aria-hidden="true" className="grid size-9 place-items-center rounded-xl bg-green-700 text-white">$</span>
          SalvaDinDin
        </a>
        <label className="flex items-center gap-2 text-sm text-muted">
          Tema
          <select value={theme} onChange={e => changeTheme(e.target.value as Theme)}
            className="rounded-lg border border-line bg-surface px-3 py-2 text-ink">
            <option value="system">Sistema</option>
            <option value="light">Claro</option>
            <option value="dark">Escuro</option>
          </select>
        </label>
      </header>

      <nav className="mx-auto flex w-full max-w-5xl gap-5 px-6 text-sm"><a href="/login">Entrar</a><a href="/register">Criar conta</a></nav>

      <main id="inicio" className="mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center px-6 py-16 sm:py-24">
        <div className="max-w-2xl">
          <span className="inline-flex items-center gap-2 rounded-full border border-line bg-surface px-3 py-1 text-sm text-muted">
            <span className="size-2 rounded-full bg-green-600" aria-hidden="true" />
            Uma nova versão está nascendo
          </span>
          <h1 className="mt-7 text-4xl font-semibold leading-tight tracking-tight sm:text-6xl">SalvaDinDin <span className="text-accent">2.0</span></h1>
          <p className="mt-6 text-xl leading-relaxed text-muted">Mais clareza para o seu dinheiro.<br />Mais espaço para o que importa.</p>
          <p className="mt-5 max-w-lg leading-relaxed text-muted">Estamos construindo um assistente financeiro pessoal gratuito, minimalista e open source. Uma base simples para cuidar das suas finanças, um passo de cada vez.</p>
          <a href="https://github.com/fakersz/salvadindin/tree/v2-development"
            className="mt-9 inline-flex items-center gap-3 rounded-xl bg-green-700 px-5 py-3 font-medium text-white hover:bg-green-800">
            Acompanhar no GitHub <span aria-hidden="true">↗</span>
          </a>
        </div>
        <div className="mt-16 grid gap-6 border-t border-line pt-8 sm:grid-cols-3">
          {[
            ['Simples por escolha', 'Só o essencial, com cuidado em cada detalhe.'],
            ['Gratuito por propósito', 'Organização financeira acessível para todos.'],
            ['Construído em aberto', 'Um projeto para aprender e evoluir em comunidade.'],
          ].map(([title, text]) => <div key={title}><h2 className="font-medium">{title}</h2><p className="mt-2 text-sm leading-relaxed text-muted">{text}</p></div>)}
        </div>
      </main>
      <footer className="mx-auto w-full max-w-5xl px-6 py-6 text-sm text-muted">Em desenvolvimento · Sem cadastro ou funcionalidades financeiras nesta etapa.</footer>
    </div>
  )
}
