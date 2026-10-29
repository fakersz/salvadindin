import { useEffect, useState } from "react";
import type { FormEvent, ReactNode } from "react";
import { authInfo, currentUser, mutate } from "./api";
import type { AuthResult, User } from "./api";
import { Landing } from "./Landing";
import { FinanceApp } from "./FinanceApp";

type Mode = "login" | "register" | "recover";

function Shell({ children }: { children: ReactNode }) {
  const [theme, setTheme] = useState("system");
  function changeTheme(value: string) {
    setTheme(value);
    if (value === "system") delete document.documentElement.dataset.theme;
    else document.documentElement.dataset.theme = value;
  }
  return (
    <div className="auth-shell">
      <header className="auth-header">
        <a href="/" className="brand">
          <span aria-hidden="true">$</span> SalvaDinDin
        </a>
        <label className="theme-label">
          Tema{" "}
          <select value={theme} onChange={(e) => changeTheme(e.target.value)}>
            <option value="system">Sistema</option>
            <option value="light">Claro</option>
            <option value="dark">Escuro</option>
          </select>
        </label>
      </header>
      <main className="auth-main">{children}</main>
    </div>
  );
}

function PasswordField({
  id = "password",
  value,
  onChange,
  confirmation = false,
  login = false,
}: {
  id?: string;
  value: string;
  onChange: (value: string) => void;
  confirmation?: boolean;
  login?: boolean;
}) {
  const [visible, setVisible] = useState(false);
  const label = confirmation
    ? "Confirme a senha"
    : login
      ? "Senha"
      : "Nova senha";
  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      <div className="password-wrap">
        <input
          id={id}
          name={id}
          type={visible ? "text" : "password"}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          required
          minLength={login ? 1 : undefined}
          maxLength={256}
          autoComplete={login ? "current-password" : "new-password"}
        />
        <button
          className="show-password"
          type="button"
          onClick={() => setVisible(!visible)}
          aria-label={`${visible ? "Ocultar" : "Mostrar"} ${confirmation ? "confirmação de senha" : "senha"}`}
          aria-pressed={visible}
        >
          {visible ? "Ocultar" : "Mostrar"}
        </button>
      </div>
    </div>
  );
}

function RecoveryCodes({
  codes,
  onDone,
}: {
  codes: string[];
  onDone: () => void;
}) {
  const [saved, setSaved] = useState(false);
  useEffect(() => {
    if (saved) return;
    const warn = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = "";
    };
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [saved]);
  function download() {
    const blob = new Blob(
      [
        "Códigos de recuperação SalvaDinDin\nGuarde em local privado. Cada código pode ser usado uma vez.\n\n" +
          codes.join("\n"),
      ],
      { type: "text/plain;charset=utf-8" },
    );
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = "salvadindin-recuperacao.txt";
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }
  return (
    <section className="auth-card codes-card" aria-labelledby="codes-title">
      <span className="eyebrow">Seu acesso, sob seu controle</span>
      <h1 id="codes-title">Guarde seus códigos</h1>
      <p>
        Esta é a única exibição destes códigos. Cada um permite redefinir sua
        senha uma vez. Sem e-mail ou telefone, eles são sua forma de recuperar o
        acesso.
      </p>
      <p className="notice">
        Guarde em um gerenciador de senhas ou lugar privado. Nunca compartilhe
        estes códigos.
      </p>
      <ul className="codes-list" aria-label="Códigos de recuperação">
        {codes.map((code) => (
          <li key={code}>
            <code>{code}</code>
          </li>
        ))}
      </ul>
      <button className="button secondary" type="button" onClick={download}>
        Baixar códigos
      </button>
      <label className="check-label">
        <input
          type="checkbox"
          checked={saved}
          onChange={(e) => setSaved(e.target.checked)}
        />
        Guardei meus códigos em um lugar seguro.
      </label>
      <button className="button" disabled={!saved} onClick={onDone}>
        Continuar
      </button>
    </section>
  );
}

function AuthForm({
  mode,
  registrationEnabled,
}: {
  mode: Mode;
  registrationEnabled: boolean;
}) {
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [recoveryCode, setRecoveryCode] = useState("");
  const [codes, setCodes] = useState<string[] | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const registering = mode === "register";
  const recovering = mode === "recover";
  const title = registering
    ? "Criar conta"
    : recovering
      ? "Recupere seu acesso"
      : "Entrar";
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    setMessage("");
    if (
      Array.from(password).length > 128 ||
      (mode !== "login" && Array.from(password).length < 8)
    ) {
      setError("Senha: use de 8 a 128 caracteres.");
      return;
    }
    if (mode !== "login" && password !== confirmation) {
      setError("As senhas precisam ser iguais.");
      return;
    }
    setBusy(true);
    try {
      if (registering) {
        const result = await mutate<AuthResult>("register", {
          username,
          password,
          password_confirmation: confirmation,
        });
        setPassword("");
        setConfirmation("");
        setCodes(result.recovery_codes!);
      } else if (recovering) {
        const result = await mutate<{ message: string }>("recover", {
          username,
          recovery_code: recoveryCode,
          password,
          password_confirmation: confirmation,
        });
        setPassword("");
        setConfirmation("");
        setRecoveryCode("");
        setMessage(result.message);
      } else {
        await mutate<AuthResult>("login", { username, password });
        window.location.replace("/app");
      }
    } catch (e) {
      setError(
        e instanceof Error
          ? e.message
          : "Não foi possível conectar. Tente novamente.",
      );
    } finally {
      setBusy(false);
    }
  }
  if (codes)
    return (
      <RecoveryCodes
        codes={codes}
        onDone={() => {
          setCodes(null);
          window.location.replace("/app");
        }}
      />
    );
  return (
    <section className="auth-card" aria-labelledby="auth-title">
      <span className="eyebrow">SalvaDinDin 2.0</span>
      <h1 id="auth-title">{title}</h1>
      <p>
        {registering
          ? "Só usuário e senha."
          : recovering
            ? "Use um código. Suas sessões abertas serão encerradas."
            : "Acesse sua conta."}
      </p>
      {registering && !registrationEnabled ? (
        <div className="notice" role="status">
          O cadastro está temporariamente fechado.{" "}
          <a href="/login">Já tenho acesso</a>
        </div>
      ) : (
        <form onSubmit={submit} aria-busy={busy}>
          <div className="field">
            <label htmlFor="username">Usuário</label>
            <input
              id="username"
              name="username"
              value={username}
              onChange={(e) => setUsername(e.target.value)}
              autoComplete="username"
              autoCapitalize="none"
              spellCheck={false}
              required
              minLength={3}
              maxLength={30}
              pattern={"[A-Za-z0-9_.\\-]+"}
              aria-describedby="username-help"
            />
            <small id="username-help">
              3–30 caracteres, sem acentos. Letras, números, . - _
            </small>
          </div>
          {recovering && (
            <div className="field">
              <label htmlFor="recovery-code">Código de recuperação</label>
              <input
                id="recovery-code"
                value={recoveryCode}
                onChange={(e) => setRecoveryCode(e.target.value)}
                autoComplete="off"
                spellCheck={false}
                maxLength={80}
                required
              />
            </div>
          )}
          <PasswordField
            value={password}
            onChange={setPassword}
            login={mode === "login"}
          />
          {mode !== "login" && (
            <>
              <PasswordField
                id="password_confirmation"
                value={confirmation}
                onChange={setConfirmation}
                confirmation
              />
              <ul className="requirements" aria-label="Requisitos da senha">
                <li
                  data-met={
                    Array.from(password).length >= 8 &&
                    Array.from(password).length <= 128
                  }
                >
                  {Array.from(password).length >= 8 &&
                  Array.from(password).length <= 128
                    ? "✓"
                    : "○"}{" "}
                  Entre 8 e 128 caracteres
                </li>
                <li data-met={!!confirmation && confirmation === password}>
                  {confirmation && confirmation === password ? "✓" : "○"} Senhas
                  iguais
                </li>
                <li>Espaços e caracteres especiais são bem-vindos.</li>
              </ul>
            </>
          )}
          {error && (
            <p className="form-error" role="alert">
              {error}
            </p>
          )}
          {message && (
            <p className="notice" role="status">
              {message} <a href="/login">Ir para entrar</a>
            </p>
          )}
          <button className="button" type="submit" disabled={busy}>
            {busy
              ? "Aguarde…"
              : registering
                ? "Criar conta"
                : recovering
                  ? "Redefinir senha"
                  : "Entrar"}
          </button>
        </form>
      )}
      <nav className="auth-links" aria-label="Acesso">
        {mode !== "login" && <a href="/login">Já tenho conta</a>}
        {mode !== "register" && <a href="/register">Criar conta</a>}
        {mode !== "recover" && <a href="/recover">Esqueci minha senha</a>}
      </nav>
    </section>
  );
}

function PrivateApp({ user }: { user: User }) {
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [codes, setCodes] = useState<string[] | null>(null);
  async function logout() {
    setBusy(true);
    setError("");
    try {
      await mutate("logout", {});
      window.location.replace("/login");
    } catch (e) {
      setError(e instanceof Error ? e.message : "Tente novamente.");
      setBusy(false);
    }
  }
  async function regenerate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError("");
    try {
      const result = await mutate<{ recovery_codes: string[] }>(
        "recovery-codes/regenerate",
        { password },
      );
      setPassword("");
      setCodes(result.recovery_codes);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Tente novamente.");
    } finally {
      setBusy(false);
    }
  }
  if (codes)
    return <RecoveryCodes codes={codes} onDone={() => setCodes(null)} />;
  return (
    <section className="auth-card app-card">
      <span className="eyebrow">Seu espaço</span>
      <h1>Olá, {user.username}.</h1>
      <p>Gerencie seus códigos de recuperação e proteja seu acesso.</p>
      <a className="integration-link" href="/app/settings/integrations">Integrações · WhatsApp →</a>
      <div className="notice">
        Se perder a senha e os códigos, não será possível recuperar o acesso.
      </div>
      <button className="button secondary" disabled={busy} onClick={logout}>
        Sair
      </button>
      <details className="security-panel">
        <summary>Segurança e códigos de recuperação</summary>
        <p>
          Perdeu seus códigos? Confirme sua senha para gerar outros. Todos os
          códigos anteriores serão invalidados.
        </p>
        <form onSubmit={regenerate} aria-busy={busy}>
          <PasswordField value={password} onChange={setPassword} login />
          <button className="button" disabled={busy}>
            {busy ? "Aguarde…" : "Gerar novos códigos"}
          </button>
        </form>
      </details>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
    </section>
  );
}

export function App() {
  const path = window.location.pathname.replace(/\/$/, "") || "/";
  const [user, setUser] = useState<User | null | undefined>(undefined);
  const [registrationEnabled, setRegistrationEnabled] = useState(false);
  const [error, setError] = useState("");
  useEffect(() => {
    if (path === "/") return;
    let active = true;
    Promise.all([currentUser(), authInfo()])
      .then(([result, info]) => {
        if (!active) return;
        if (path.startsWith("/app") && !result) {
          window.location.replace("/login");
          return;
        }
        if ((path === "/login" || path === "/register") && result) {
          window.location.replace("/app");
          return;
        }
        setUser(result);
        setRegistrationEnabled(info.registration_enabled);
      })
      .catch((e) => {
        if (active)
          setError(
            e instanceof Error ? e.message : "Não foi possível conectar.",
          );
      });
    return () => {
      active = false;
    };
  }, [path]);
  if (path === "/") return <Landing />;
  if (error)
    return (
      <Shell>
        <section className="auth-card">
          <h1>Vamos tentar de novo?</h1>
          <p role="alert">{error}</p>
          <button className="button" onClick={() => window.location.reload()}>
            Tentar novamente
          </button>
        </section>
      </Shell>
    );
  if (user === undefined)
    return (
      <Shell>
        <p role="status" className="loading">
          Verificando seu acesso…
        </p>
      </Shell>
    );
  if (path.startsWith("/app") && user)
    return <FinanceApp user={user} security={<PrivateApp user={user} />} />;
  if (path === "/login" || path === "/register" || path === "/recover")
    return (
      <Shell>
        <AuthForm
          mode={path.slice(1) as Mode}
          registrationEnabled={registrationEnabled}
        />
      </Shell>
    );
  return (
    <Shell>
      <section className="auth-card">
        <h1>Página não encontrada</h1>
        <a href="/">Voltar ao início</a>
      </section>
    </Shell>
  );
}
