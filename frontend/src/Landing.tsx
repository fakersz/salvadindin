import { useState } from "react";
import { LegalLinks } from "./LegalFooter";
import { applyTheme, preferredTheme } from "./theme";

export function Landing() {
  const [theme, setTheme] = useState(preferredTheme);
  return (
    <div className="landing-shell">
      <header className="auth-header">
        <a href="/" className="brand">
          <span aria-hidden="true">$</span>SalvaDinDin
        </a>
        <label className="theme-label">
          Tema{" "}
          <select
            value={theme}
            onChange={(e) => {
              setTheme(e.target.value);
              applyTheme(e.target.value);
            }}
          >
            <option value="system">Sistema</option>
            <option value="light">Claro</option>
            <option value="dark">Escuro</option>
          </select>
        </label>
      </header>
      <main className="landing-main">
        <span className="landing-label">Simples. Gratuito. Seu.</span>
        <h1>
          Seu dinheiro,
          <br />
          <span>em ordem.</span>
        </h1>
        <p>
          Contas, receitas e despesas.
          <br />
          Tudo em um só lugar.
        </p>
        <div className="landing-actions">
          <a href="/register" className="button compact">
            Começar
          </a>
          <a href="/login" className="text-button">
            Entrar <span aria-hidden="true">↗</span>
          </a>
        </div>
      </main>
      <footer className="landing-footer">
        <span>SalvaDinDin 2.0</span>
        <LegalLinks />
        <a href="https://github.com/fakersz/salvadindin/tree/v2-development">
          Código aberto ↗
        </a>
      </footer>
    </div>
  );
}
