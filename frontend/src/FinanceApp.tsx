import { useCallback, useEffect, useRef, useState } from "react";
import type { FormEvent, ReactNode } from "react";
import type { User } from "./api";
import { mutate } from "./api";
import { accountTypes, finance, kinds, money, parseMoney } from "./financeApi";
import type { Account, Category, Entry, Summary } from "./financeApi";

type Modal =
  | { kind: "account"; row?: Account }
  | { kind: "category"; row?: Category }
  | { kind: "transaction"; row?: Entry; type?: Entry["type"] }
  | { kind: "confirm"; path: string; message: string }
  | null;
type Filters = {
  start_date: string;
  end_date: string;
  type: string;
  account_id: string;
  category_id: string;
};
const emptyFilters: Filters = {
  start_date: "",
  end_date: "",
  type: "",
  account_id: "",
  category_id: "",
};

function NavIcon({ name }: { name: string }) {
  const paths: Record<string, ReactNode> = {
    dashboard: (
      <>
        <rect x="3" y="3" width="7" height="7" rx="1.5" />
        <rect x="14" y="3" width="7" height="7" rx="1.5" />
        <rect x="3" y="14" width="7" height="7" rx="1.5" />
        <rect x="14" y="14" width="7" height="7" rx="1.5" />
      </>
    ),
    accounts: (
      <>
        <rect x="3" y="5" width="18" height="15" rx="3" />
        <path d="M3 9h18M15 14h6M17 14v2" />
      </>
    ),
    transactions: (
      <>
        <path d="M4 7h15m-4-4 4 4-4 4M20 17H5m4-4-4 4 4 4" />
      </>
    ),
    categories: (
      <>
        <path d="m12 3 9 9-9 9-9-9V3z" />
        <circle cx="7.5" cy="7.5" r="1" />
      </>
    ),
    security: (
      <>
        <path d="m12 3 8 3v6c0 4-5 8-8 9-3-1-8-5-8-9V6z" />
        <path d="m8 12 3 3 5-6" />
      </>
    ),
  };
  return (
    <svg
      className="nav-icon"
      width="20"
      height="20"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      {paths[name]}
    </svg>
  );
}

function Dialog({
  title,
  children,
  close,
  busy,
}: {
  title: string;
  children: ReactNode;
  close: () => void;
  busy: boolean;
}) {
  const ref = useRef<HTMLElement>(null);
  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null;
    document.body.classList.add("modal-open");
    ref.current?.querySelector<HTMLElement>("input,select,button")?.focus();
    return () => {
      document.body.classList.remove("modal-open");
      previous?.focus();
    };
  }, []);
  return (
    <div className="dialog-backdrop">
      <section
        ref={ref}
        className="finance-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="dialog-title"
        onKeyDown={(event) => {
          if (event.key === "Escape" && !busy) close();
          if (event.key === "Tab") {
            const nodes = [
              ...(ref.current?.querySelectorAll<HTMLElement>(
                "button:not(:disabled),input,select,textarea,a[href]",
              ) || []),
            ];
            const first = nodes[0],
              last = nodes.at(-1);
            if (event.shiftKey && document.activeElement === first) {
              event.preventDefault();
              last?.focus();
            }
            if (!event.shiftKey && document.activeElement === last) {
              event.preventDefault();
              first?.focus();
            }
          }
        }}
      >
        <header>
          <h2 id="dialog-title">{title}</h2>
          <button
            type="button"
            className="icon-button"
            disabled={busy}
            onClick={close}
            aria-label="Fechar formulário"
          >
            ×
          </button>
        </header>
        {children}
      </section>
    </div>
  );
}

function AccountForm({
  row,
  save,
  busy,
}: {
  row?: Account;
  save: (path: string, data: unknown, method?: string) => void;
  busy: boolean;
}) {
  const [name, setName] = useState(row?.name || "");
  const [type, setType] = useState(row?.type || "checking");
  const [initial, setInitial] = useState(
    row?.initial_balance.replace(".", ",") || "0,00",
  );
  const [error, setError] = useState("");
  function submit(event: FormEvent) {
    event.preventDefault();
    try {
      save(
        "/accounts" + (row ? "/" + row.id : ""),
        { name, type, initial_balance: parseMoney(initial) },
        row ? "PATCH" : "POST",
      );
    } catch (e) {
      setError((e as Error).message);
    }
  }
  return (
    <form onSubmit={submit} aria-busy={busy}>
      <div className="field">
        <label htmlFor="account-name">Nome da conta</label>
        <input
          id="account-name"
          maxLength={60}
          required
          value={name}
          onChange={(e) => setName(e.target.value)}
        />
      </div>
      <div className="field">
        <label htmlFor="account-type">Tipo da conta</label>
        <select
          id="account-type"
          value={type}
          onChange={(e) => setType(e.target.value)}
        >
          {Object.entries(accountTypes).map(([key, label]) => (
            <option key={key} value={key}>
              {label}
            </option>
          ))}
        </select>
      </div>
      <div className="field">
        <label htmlFor="initial-balance">Saldo inicial (R$)</label>
        <input
          id="initial-balance"
          inputMode="decimal"
          value={initial}
          onChange={(e) => setInitial(e.target.value)}
          required
        />
        <small>Saldo antes dos lançamentos. Pode ser negativo.</small>
      </div>
      {error && (
        <p role="alert" className="form-error">
          {error}
        </p>
      )}
      <button className="button" disabled={busy}>
        {busy ? "Salvando…" : "Salvar conta"}
      </button>
    </form>
  );
}

function CategoryForm({
  row,
  save,
  busy,
}: {
  row?: Category;
  save: (path: string, data: unknown, method?: string) => void;
  busy: boolean;
}) {
  const [name, setName] = useState(row?.name || "");
  const [type, setType] = useState(row?.type || "expense");
  const [icon, setIcon] = useState(row?.icon || "");
  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        save(
          "/categories" + (row ? "/" + row.id : ""),
          { name, type, icon: icon || null },
          row ? "PATCH" : "POST",
        );
      }}
      aria-busy={busy}
    >
      <div className="field">
        <label htmlFor="category-name">Nome da categoria</label>
        <input
          id="category-name"
          value={name}
          onChange={(e) => setName(e.target.value)}
          required
          maxLength={60}
        />
      </div>
      <div className="field">
        <label htmlFor="category-type">Tipo da categoria</label>
        <select
          id="category-type"
          value={type}
          onChange={(e) => setType(e.target.value as Category["type"])}
        >
          <option value="expense">Despesa</option>
          <option value="income">Receita</option>
        </select>
      </div>
      <div className="field">
        <label htmlFor="category-icon">Ícone (opcional)</label>
        <input
          id="category-icon"
          value={icon}
          onChange={(e) => setIcon(e.target.value)}
          maxLength={16}
          placeholder="Ex.: ★"
        />
      </div>
      <button className="button" disabled={busy}>
        {busy ? "Salvando…" : "Salvar categoria"}
      </button>
    </form>
  );
}

function localDate(iso?: string) {
  const d = iso ? new Date(iso) : new Date();
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000)
    .toISOString()
    .slice(0, 16);
}
function EntryForm({
  row,
  type: initialType,
  accounts,
  categories,
  save,
  busy,
}: {
  row?: Entry;
  type?: Entry["type"];
  accounts: Account[];
  categories: Category[];
  save: (path: string, data: unknown, method?: string) => void;
  busy: boolean;
}) {
  const available = accounts.filter((a) => a.is_active);
  const [type, setType] = useState<Entry["type"]>(
    row?.type || initialType || "expense",
  );
  const [source, setSource] = useState(
    row?.account_id || available[0]?.id || "",
  );
  const [destination, setDestination] = useState(
    row?.destination_account_id || "",
  );
  const [category, setCategory] = useState(row?.category_id || "");
  const [amount, setAmount] = useState(row?.amount.replace(".", ",") || "");
  const [description, setDescription] = useState(row?.description || "");
  const [occurred, setOccurred] = useState(localDate(row?.occurred_at));
  const [error, setError] = useState("");
  function submit(event: FormEvent) {
    event.preventDefault();
    try {
      const exact = parseMoney(amount);
      if (exact.startsWith("-") || !/[1-9]/.test(exact))
        throw new Error("O valor precisa ser maior que zero.");
      save(
        "/transactions" + (row ? "/" + row.id : ""),
        {
          account_id: source,
          destination_account_id: type === "transfer" ? destination : null,
          category_id: type === "transfer" ? null : category || null,
          type,
          amount: exact,
          description,
          occurred_at: new Date(occurred).toISOString(),
        },
        row ? "PATCH" : "POST",
      );
    } catch (e) {
      setError((e as Error).message);
    }
  }
  return (
    <form onSubmit={submit} aria-busy={busy}>
      <div className="field">
        <label htmlFor="entry-type">Tipo de movimentação</label>
        <select
          id="entry-type"
          value={type}
          onChange={(e) => {
            setType(e.target.value as Entry["type"]);
            setCategory("");
            setDestination("");
          }}
        >
          {Object.entries(kinds).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </select>
      </div>
      <div className="field">
        <label htmlFor="entry-account">
          {type === "transfer" ? "Conta de origem" : "Conta"}
        </label>
        <select
          id="entry-account"
          required
          value={source}
          onChange={(e) => {
            setSource(e.target.value);
            setDestination("");
          }}
        >
          <option value="">Selecione</option>
          {available.map((a) => (
            <option key={a.id} value={a.id}>
              {a.name}
            </option>
          ))}
        </select>
      </div>
      {type === "transfer" ? (
        <div className="field">
          <label htmlFor="entry-destination">Conta de destino</label>
          <select
            id="entry-destination"
            required
            value={destination}
            onChange={(e) => setDestination(e.target.value)}
          >
            <option value="">Selecione</option>
            {available
              .filter((a) => a.id !== source)
              .map((a) => (
                <option key={a.id} value={a.id}>
                  {a.name}
                </option>
              ))}
          </select>
        </div>
      ) : (
        <div className="field">
          <label htmlFor="entry-category">Categoria</label>
          <select
            id="entry-category"
            value={category}
            onChange={(e) => setCategory(e.target.value)}
          >
            <option value="">Sem categoria</option>
            {categories
              .filter((c) => c.type === type)
              .map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
          </select>
        </div>
      )}
      <div className="field">
        <label htmlFor="entry-amount">Valor (R$)</label>
        <input
          id="entry-amount"
          inputMode="decimal"
          value={amount}
          onChange={(e) => setAmount(e.target.value)}
          placeholder="0,00"
          required
        />
      </div>
      <div className="field">
        <label htmlFor="entry-description">Descrição</label>
        <input
          id="entry-description"
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          maxLength={240}
          required
        />
      </div>
      <div className="field">
        <label htmlFor="entry-date">Data e hora</label>
        <input
          id="entry-date"
          type="datetime-local"
          required
          value={occurred}
          onChange={(e) => setOccurred(e.target.value)}
        />
        <small>Horário local. Datas futuras ficam agendadas.</small>
      </div>
      {error && (
        <p role="alert" className="form-error">
          {error}
        </p>
      )}
      <button className="button" disabled={busy || !available.length}>
        {busy ? "Salvando…" : "Salvar movimentação"}
      </button>
    </form>
  );
}

export function FinanceApp({
  user,
  security,
}: {
  user: User;
  security: ReactNode;
}) {
  const path = window.location.pathname.replace(/\/$/, "");
  const section =
    path === "/app/accounts"
      ? "accounts"
      : path === "/app/transactions"
        ? "transactions"
        : path === "/app/categories"
          ? "categories"
          : path === "/app/security"
            ? "security"
            : "dashboard";
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [summary, setSummary] = useState<Summary | null>(null);
  const [entries, setEntries] = useState<Entry[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [filters, setFilters] = useState<Filters>(emptyFilters);
  const [month, setMonth] = useState("");
  const [modal, setModal] = useState<Modal>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [theme, setTheme] = useState("system");
  const sequence = useRef(0);
  const load = useCallback(async () => {
    const number = ++sequence.current;
    setLoading(true);
    setError("");
    const query = new URLSearchParams({ page: String(page), per_page: "20" });
    Object.entries(filters).forEach(([key, value]) => {
      if (value) query.set(key, value);
    });
    try {
      const [a, c, s, t] = await Promise.all([
        finance<{ items: Account[] }>("/accounts"),
        finance<{ items: Category[] }>("/categories"),
        section === "dashboard"
          ? finance<Summary>(
              "/dashboard/summary" + (month ? "?month=" + month : ""),
            )
          : Promise.resolve(null),
        section === "transactions"
          ? finance<{ items: Entry[]; total: number }>("/transactions?" + query)
          : Promise.resolve(null),
      ]);
      if (number !== sequence.current) return;
      setAccounts(a.items);
      setCategories(c.items);
      setSummary(s);
      setEntries(t?.items || []);
      setTotal(t?.total || 0);
    } catch (e) {
      if (number === sequence.current) setError((e as Error).message);
    } finally {
      if (number === sequence.current) setLoading(false);
    }
  }, [page, filters, month, section]);
  useEffect(() => {
    void load();
    return () => {
      sequence.current++;
    };
  }, [load]);
  function open(value: Modal) {
    setError("");
    setNotice("");
    setModal(value);
  }
  async function save(resource: string, data: unknown, method = "POST") {
    setBusy(true);
    setError("");
    try {
      await finance(resource, method, data);
      setModal(null);
      setNotice("Alteração salva.");
      await load();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  async function logout() {
    setBusy(true);
    try {
      await mutate("logout", {});
      window.location.replace("/login");
    } catch (e) {
      setError((e as Error).message);
      setBusy(false);
    }
  }
  function accountName(id: string | null) {
    return accounts.find((a) => a.id === id)?.name || "Conta";
  }
  function categoryName(id: string | null) {
    return categories.find((c) => c.id === id)?.name || "Sem categoria";
  }
  function changeFilter(key: keyof Filters, value: string) {
    setPage(1);
    setFilters((current) => ({ ...current, [key]: value }));
  }
  function entryList(items: Entry[], edit = false) {
    return !items.length ? (
      <p className="empty-state">Sem movimentações.</p>
    ) : (
      <ul className="entry-list">
        {items.map((t) => {
          const archived =
            !accounts.find((a) => a.id === t.account_id)?.is_active ||
            (!!t.destination_account_id &&
              !accounts.find((a) => a.id === t.destination_account_id)
                ?.is_active);
          return (
            <li key={t.id} className="entry-row">
              <span className={"entry-symbol " + t.type} aria-hidden="true">
                {t.type === "income" ? "↗" : t.type === "expense" ? "↙" : "⇄"}
              </span>
              <div className="entry-info">
                <strong>{t.description}</strong>
                <span>
                  {kinds[t.type]} · {accountName(t.account_id)}
                  {t.destination_account_id
                    ? " → " + accountName(t.destination_account_id)
                    : " · " + categoryName(t.category_id)}
                </span>
                <time dateTime={t.occurred_at}>
                  {new Date(t.occurred_at).toLocaleString("pt-BR", {
                    timeZone: "America/Sao_Paulo",
                    dateStyle: "short",
                    timeStyle: "short",
                  })}
                  {new Date(t.occurred_at) > new Date() ? " · Agendada" : ""}
                </time>
              </div>
              <strong className={"entry-value " + t.type}>
                {t.type === "expense" ? "−" : t.type === "income" ? "+" : ""}
                {money(t.amount)}
              </strong>
              {edit && (
                <div className="row-actions">
                  {archived ? (
                    <small>Conta arquivada</small>
                  ) : (
                    <>
                      <button
                        type="button"
                        onClick={() => open({ kind: "transaction", row: t })}
                        aria-label={"Editar " + t.description}
                      >
                        Editar
                      </button>
                      <button
                        type="button"
                        onClick={() =>
                          open({
                            kind: "confirm",
                            path: "/transactions/" + t.id,
                            message:
                              "Excluir esta movimentação? Não é possível desfazer.",
                          })
                        }
                        aria-label={"Excluir " + t.description}
                      >
                        Excluir
                      </button>
                    </>
                  )}
                </div>
              )}
            </li>
          );
        })}
      </ul>
    );
  }
  const nav = [
    ["/app", "Visão geral", "dashboard"],
    ["/app/accounts", "Contas", "accounts"],
    ["/app/transactions", "Movimentações", "transactions"],
    ["/app/categories", "Categorias", "categories"],
    ["/app/security", "Segurança", "security"],
  ];
  return (
    <div className="finance-shell">
      <aside className="finance-sidebar">
        <a className="brand" href="/app">
          <span aria-hidden="true">$</span>SalvaDinDin
        </a>
        <nav aria-label="Navegação financeira">
          {nav.map(([href, label, key]) => (
            <a
              key={href}
              href={href}
              aria-label={label}
              className={key === "security" ? "security-link" : undefined}
              aria-current={section === key ? "page" : undefined}
            >
              <NavIcon name={key} />
              <span className="nav-label-wide">{label}</span>
              <span className="nav-label-short">
                {key === "dashboard" ? "Início" : key === "transactions" ? "Extrato" : label}
              </span>
            </a>
          ))}
        </nav>
      </aside>
      <div className="finance-workspace">
        <header className="finance-topbar">
          <span className="user-label">Olá, {user.username}</span>
          <label className="theme-label">
            Tema{" "}
            <select
              value={theme}
              onChange={(e) => {
                setTheme(e.target.value);
                if (e.target.value === "system")
                  delete document.documentElement.dataset.theme;
                else document.documentElement.dataset.theme = e.target.value;
              }}
            >
              <option value="system">Sistema</option>
              <option value="light">Claro</option>
              <option value="dark">Escuro</option>
            </select>
          </label>
          {section !== "security" && (
            <button className="text-button" onClick={logout} disabled={busy}>
              Sair
            </button>
          )}
        </header>
        <main className="finance-main">
          <header className="page-heading">
            <div>
              <h1>
                {section === "dashboard"
                  ? "Visão geral"
                  : section === "accounts"
                    ? "Contas"
                    : section === "transactions"
                      ? "Movimentações"
                      : section === "categories"
                        ? "Categorias"
                        : "Segurança"}
              </h1>
            </div>
            {section === "accounts" ? (
              <button
                className="button compact"
                onClick={() => open({ kind: "account" })}
              >
                Nova conta
              </button>
            ) : section === "categories" ? (
              <button
                className="button compact"
                onClick={() => open({ kind: "category" })}
              >
                Nova categoria
              </button>
            ) : (
              section !== "security" && (
                <button
                  className="button compact"
                  disabled={!accounts.some((a) => a.is_active)}
                  onClick={() => open({ kind: "transaction" })}
                >
                  + Movimentação
                </button>
              )
            )}
          </header>
          {notice && (
            <p className="notice" role="status">
              {notice}
            </p>
          )}
          {error && !modal && (
            <div className="form-error" role="alert">
              {error}{" "}
              <button
                className="text-button"
                onClick={() => {
                  setError("");
                  void load();
                }}
              >
                Tentar novamente
              </button>
            </div>
          )}
          {section === "security" ? (
            security
          ) : loading ? (
            <p role="status" className="empty-state">
              Atualizando seus dados…
            </p>
          ) : (
            <>
              {section === "dashboard" && summary && (
                <>
                  <div className="month-control">
                    <label htmlFor="dashboard-month">Mês</label>
                    <input
                      id="dashboard-month"
                      type="month"
                      value={month || summary.month}
                      onChange={(e) => setMonth(e.target.value)}
                    />
                  </div>
                  <div className="metric-grid">
                    {[
                      ["Saldo atual", summary.balance],
                      ["Receitas", summary.income],
                      ["Despesas", summary.expense],
                      ["Resultado", summary.result],
                    ].map(([label, value], i) => (
                      <article
                        className={"metric-card metric-" + i}
                        key={label}
                      >
                        <span>{label}</span>
                        <strong
                          className={value.startsWith("-") ? "negative" : ""}
                        >
                          {money(value)}
                        </strong>
                      </article>
                    ))}
                  </div>
                  {!accounts.length ? (
                    <section className="panel welcome-panel">
                      <span aria-hidden="true" className="welcome-symbol">
                        ◎
                      </span>
                      <h2>Sua primeira conta</h2>
                      <p>Adicione uma conta para começar.</p>
                      <button
                        className="button compact"
                        onClick={() => open({ kind: "account" })}
                      >
                        Criar primeira conta
                      </button>
                    </section>
                  ) : (
                    <>
                      <div className="quick-actions">
                        <button
                          className="button secondary compact"
                          disabled={!accounts.some((a) => a.is_active)}
                          onClick={() =>
                            open({ kind: "transaction", type: "income" })
                          }
                        >
                          + Receita
                        </button>
                        <button
                          className="button secondary compact"
                          disabled={!accounts.some((a) => a.is_active)}
                          onClick={() =>
                            open({ kind: "transaction", type: "expense" })
                          }
                        >
                          − Despesa
                        </button>
                        <button
                          className="button secondary compact"
                          disabled={
                            accounts.filter((a) => a.is_active).length < 2
                          }
                          onClick={() =>
                            open({ kind: "transaction", type: "transfer" })
                          }
                        >
                          ⇄ Transferir
                        </button>
                      </div>
                      <section className="panel">
                        <div className="panel-heading">
                          <h2>Contas</h2>
                          <a href="/app/accounts">Gerenciar contas →</a>
                        </div>
                        <ul className="balance-list">
                          {summary.accounts.map((a) => (
                            <li key={a.id}>
                              <span>
                                {a.name}
                                {!a.is_active && <small> · Arquivada</small>}
                              </span>
                              <strong
                                className={
                                  a.balance.startsWith("-") ? "negative" : ""
                                }
                              >
                                {money(a.balance)}
                                {a.balance.startsWith("-") && (
                                  <small>Saldo negativo</small>
                                )}
                              </strong>
                            </li>
                          ))}
                        </ul>
                      </section>
                    </>
                  )}
                  <div className="dashboard-columns">
                    <section className="panel">
                      <div className="panel-heading">
                        <h2>Recentes</h2>
                        <a href="/app/transactions">Ver histórico →</a>
                      </div>
                      {entryList(summary.recent_transactions)}
                    </section>
                    <section className="panel">
                      <h2>Despesas por categoria</h2>
                      {!summary.expense_by_category.length ? (
                        <p className="empty-state">Sem despesas neste mês.</p>
                      ) : (
                        <ul className="distribution">
                          {summary.expense_by_category.map((c) => {
                            const cents = (s: string) =>
                              BigInt(s.replace(".", ""));
                            const percent =
                              summary.expense === "0.00"
                                ? 0
                                : Number(
                                    (cents(c.amount) * 100n) /
                                      cents(summary.expense),
                                  );
                            return (
                              <li key={c.category_id || "none"}>
                                <div>
                                  <span>{c.name}</span>
                                  <strong>{money(c.amount)}</strong>
                                </div>
                                <meter
                                  min="0"
                                  max="100"
                                  value={percent}
                                  aria-label={`${c.name}: ${percent}% das despesas`}
                                />
                              </li>
                            );
                          })}
                        </ul>
                      )}
                    </section>
                  </div>
                  <details className="summary-notes">
                    <summary>Sobre os valores</summary>
                    <p>
                      Saldo atual inclui contas arquivadas, sem agendamentos. O
                      resumo usa o mês selecionado, no horário de Brasília;
                      transferências não contam como receita ou despesa.
                    </p>
                  </details>
                </>
              )}
              {section === "accounts" &&
                (!accounts.length ? (
                  <section className="panel empty-state">
                    <h2>Nenhuma conta ainda</h2>
                    <p>Adicione uma conta para começar.</p>
                  </section>
                ) : (
                  <div className="account-grid">
                    {accounts.map((a) => (
                      <article
                        className={
                          "panel account-card" +
                          (!a.is_active ? " archived" : "")
                        }
                        key={a.id}
                      >
                        <span className="eyebrow">
                          {accountTypes[a.type]}
                          {!a.is_active ? " · Arquivada" : ""}
                        </span>
                        <h2>{a.name}</h2>
                        <strong
                          className={
                            "account-balance " +
                            (a.balance.startsWith("-") ? "negative" : "")
                          }
                        >
                          {money(a.balance)}
                        </strong>
                        {a.balance.startsWith("-") && (
                          <p className="negative">Atenção: saldo negativo.</p>
                        )}
                        <p>Saldo inicial: {money(a.initial_balance)}</p>
                        <div className="row-actions">
                          <button
                            onClick={() => open({ kind: "account", row: a })}
                          >
                            Editar conta
                          </button>
                          {a.is_active ? (
                            <button
                              onClick={() =>
                                open({
                                  kind: "confirm",
                                  path: "/accounts/" + a.id,
                                  message:
                                    "Arquivar conta? O histórico será mantido e novos lançamentos bloqueados.",
                                })
                              }
                            >
                              Arquivar
                            </button>
                          ) : (
                            <button
                              disabled={busy}
                              onClick={() =>
                                void save(
                                  "/accounts/" + a.id,
                                  { is_active: true },
                                  "PATCH",
                                )
                              }
                            >
                              Reativar
                            </button>
                          )}
                        </div>
                      </article>
                    ))}
                  </div>
                ))}
              {section === "categories" && (
                <section className="panel">
                  <ul className="category-list">
                    {categories.map((c) => (
                      <li key={c.id}>
                        <span className="category-icon" aria-hidden="true">
                          {c.icon || (c.type === "income" ? "↗" : "↙")}
                        </span>
                        <div>
                          <strong>{c.name}</strong>
                          <small>{kinds[c.type]}</small>
                        </div>
                        <div className="row-actions">
                          <button
                            onClick={() => open({ kind: "category", row: c })}
                            aria-label={"Editar categoria " + c.name}
                          >
                            Editar
                          </button>
                          <button
                            onClick={() =>
                              open({
                                kind: "confirm",
                                path: "/categories/" + c.id,
                                message:
                                  "Excluir categoria? Categorias em uso não podem ser excluídas.",
                              })
                            }
                            aria-label={"Excluir categoria " + c.name}
                          >
                            Excluir
                          </button>
                        </div>
                      </li>
                    ))}
                  </ul>
                  {!categories.length && (
                    <p className="empty-state">
                      Crie uma categoria para organizar suas movimentações.
                    </p>
                  )}
                </section>
              )}
              {section === "transactions" && (
                <>
                  <section
                    className="panel filters"
                    aria-label="Filtros do histórico"
                  >
                    <div>
                      <label htmlFor="filter-start">De</label>
                      <input
                        id="filter-start"
                        type="date"
                        value={filters.start_date}
                        onChange={(e) =>
                          changeFilter("start_date", e.target.value)
                        }
                      />
                    </div>
                    <div>
                      <label htmlFor="filter-end">Até</label>
                      <input
                        id="filter-end"
                        type="date"
                        value={filters.end_date}
                        onChange={(e) =>
                          changeFilter("end_date", e.target.value)
                        }
                      />
                    </div>
                    <div>
                      <label htmlFor="filter-type">Tipo</label>
                      <select
                        id="filter-type"
                        value={filters.type}
                        onChange={(e) => changeFilter("type", e.target.value)}
                      >
                        <option value="">Todos</option>
                        {Object.entries(kinds).map(([v, label]) => (
                          <option value={v} key={v}>
                            {label}
                          </option>
                        ))}
                      </select>
                    </div>
                    <div>
                      <label htmlFor="filter-account">Conta</label>
                      <select
                        id="filter-account"
                        value={filters.account_id}
                        onChange={(e) =>
                          changeFilter("account_id", e.target.value)
                        }
                      >
                        <option value="">Todas</option>
                        {accounts.map((a) => (
                          <option key={a.id} value={a.id}>
                            {a.name}
                            {!a.is_active ? " (arquivada)" : ""}
                          </option>
                        ))}
                      </select>
                    </div>
                    <div>
                      <label htmlFor="filter-category">Categoria</label>
                      <select
                        id="filter-category"
                        value={filters.category_id}
                        onChange={(e) =>
                          changeFilter("category_id", e.target.value)
                        }
                      >
                        <option value="">Todas</option>
                        {categories.map((c) => (
                          <option value={c.id} key={c.id}>
                            {c.name} · {kinds[c.type]}
                          </option>
                        ))}
                      </select>
                    </div>
                    <button
                      className="text-button"
                      onClick={() => {
                        setFilters(emptyFilters);
                        setPage(1);
                      }}
                    >
                      Limpar filtros
                    </button>
                  </section>
                  <section className="panel">
                    {entryList(entries, true)}
                    <div className="pagination">
                      <span>
                        {total} movimentações · página {page} de{" "}
                        {Math.max(1, Math.ceil(total / 20))}
                      </span>
                      <div>
                        <button
                          disabled={page === 1 || loading}
                          onClick={() => setPage((p) => p - 1)}
                        >
                          Anterior
                        </button>
                        <button
                          disabled={page * 20 >= total || loading}
                          onClick={() => setPage((p) => p + 1)}
                        >
                          Próxima
                        </button>
                      </div>
                    </div>
                  </section>
                </>
              )}
            </>
          )}
        </main>
      </div>
      {modal && (
        <Dialog
          title={
            modal.kind === "account"
              ? modal.row
                ? "Editar conta"
                : "Nova conta"
              : modal.kind === "category"
                ? modal.row
                  ? "Editar categoria"
                  : "Nova categoria"
                : modal.kind === "transaction"
                  ? modal.row
                    ? "Editar movimentação"
                    : "Nova movimentação"
                  : "Confirmar ação"
          }
          close={() => {
            setModal(null);
            setError("");
          }}
          busy={busy}
        >
          {error && (
            <p role="alert" className="form-error">
              {error}
            </p>
          )}
          {modal.kind === "account" ? (
            <AccountForm row={modal.row} save={save} busy={busy} />
          ) : modal.kind === "category" ? (
            <CategoryForm row={modal.row} save={save} busy={busy} />
          ) : modal.kind === "transaction" ? (
            <EntryForm
              row={modal.row}
              type={modal.type}
              accounts={accounts}
              categories={categories}
              save={save}
              busy={busy}
            />
          ) : (
            <>
              <p>{modal.message}</p>
              <div className="dialog-actions">
                <button
                  className="button secondary"
                  disabled={busy}
                  onClick={() => setModal(null)}
                >
                  Cancelar
                </button>
                <button
                  className="button danger"
                  disabled={busy}
                  onClick={() =>
                    void save(modal.path, { confirm: true }, "DELETE")
                  }
                >
                  {busy ? "Aguarde…" : "Confirmar"}
                </button>
              </div>
            </>
          )}
        </Dialog>
      )}
    </div>
  );
}
