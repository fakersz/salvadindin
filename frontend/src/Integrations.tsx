import { useCallback, useEffect, useState } from "react";
import { finance } from "./financeApi";

type LinkStatus = {
  available: boolean;
  connected: boolean;
  linked_at: string | null;
  code_expires_at: string | null;
};
const path = "/integrations/whatsapp";

export function Integrations() {
  const [status, setStatus] = useState<LinkStatus | null>(null);
  const [code, setCode] = useState("");
  const [expires, setExpires] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [confirmUnlink, setConfirmUnlink] = useState(false);
  const refresh = useCallback(async () => {
    const value = await finance<LinkStatus>(path + "/status");
    setStatus(value);
    if (value.connected || !value.code_expires_at) setCode("");
  }, []);
  useEffect(() => {
    let active = true;
    finance<LinkStatus>(path + "/status")
      .then((value) => {
        if (active) setStatus(value);
      })
      .catch((e) => {
        if (active) setError((e as Error).message);
      });
    return () => {
      active = false;
    };
  }, []);
  useEffect(() => {
    if (!status?.code_expires_at || status.connected || !status.available)
      return;
    const timer = window.setInterval(() => {
      if (Date.parse(status.code_expires_at!) <= Date.now()) setCode("");
      void refresh().catch((e) => setError((e as Error).message));
    }, 5000);
    return () => window.clearInterval(timer);
  }, [status?.code_expires_at, status?.connected, status?.available, refresh]);
  async function action(kind: "generate" | "revoke" | "unlink") {
    setBusy(true);
    setError("");
    try {
      if (kind === "generate") {
        const value = await finance<{ code: string; expires_at: string }>(
          path + "/link-codes",
          "POST",
          {},
        );
        setCode(value.code);
        setExpires(value.expires_at);
      } else {
        await finance(
          path + (kind === "revoke" ? "/link-codes" : "/link"),
          "DELETE",
          { confirm: true },
        );
        setCode("");
        setConfirmUnlink(false);
      }
      await refresh();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <section
      className="panel integration-panel"
      aria-labelledby="whatsapp-heading"
      aria-busy={busy}
    >
      <div className="panel-heading">
        <h2 id="whatsapp-heading">WhatsApp</h2>
        {status && (
          <span
            className={
              "integration-status" +
              (status.available && status.connected ? " connected" : "")
            }
            role="status"
          >
            {!status.available
              ? "Indisponível"
              : status.connected
                ? "Conectado"
                : "Desconectado"}
          </span>
        )}
      </div>
      {!status && !error && (
        <p className="empty-state" role="status">
          Carregando…
        </p>
      )}
      {status && !status.available && (
        <p className="notice">
          A integração oficial da Meta ainda não foi configurada. A vinculação
          ficará disponível após a ativação.
        </p>
      )}
      {status?.available && !status.connected && (
        <>
          <p className="integration-copy">
            Registre receitas e despesas por mensagem, sempre com sua
            confirmação.
          </p>
          {code && (
            <div className="link-code" role="status">
              <p>Envie ao número oficial do bot:</p>
              <code>VINCULAR {code}</code>
              <small>
                Uso único · expira às{" "}
                {new Date(expires).toLocaleTimeString("pt-BR", {
                  hour: "2-digit",
                  minute: "2-digit",
                })}
                .
              </small>
            </div>
          )}
          {!code && status.code_expires_at && (
            <p className="notice">
              Há um código pendente. Use o código que você guardou ou gere outro
              para substituí-lo.
            </p>
          )}
          <div className="integration-actions">
            <button
              className="button compact"
              disabled={busy}
              onClick={() => void action("generate")}
            >
              {status.code_expires_at ? "Gerar outro código" : "Gerar código"}
            </button>
            {status.code_expires_at && (
              <button
                className="text-button"
                disabled={busy}
                onClick={() => void action("revoke")}
              >
                Revogar código
              </button>
            )}
          </div>
          <p className="integration-copy">
            A vinculação só é concluída quando o bot receber seu código. Nunca
            envie sua senha ou seus códigos de recuperação.
          </p>
        </>
      )}
      {status?.connected && (
        <>
          <p className="integration-copy">
            Seu WhatsApp está vinculado. Exemplo: “gastei 35 no mercado”.
          </p>
          {!confirmUnlink ? (
            <button
              className="button secondary compact"
              disabled={busy}
              onClick={() => setConfirmUnlink(true)}
            >
              Desvincular WhatsApp
            </button>
          ) : (
            <div className="notice">
              <p>
                Desvincular e cancelar as confirmações pendentes? Seus
                lançamentos já salvos serão mantidos.
              </p>
              <div className="integration-actions">
                <button
                  className="button compact danger"
                  disabled={busy}
                  onClick={() => void action("unlink")}
                >
                  Confirmar desvinculação
                </button>
                <button
                  className="text-button"
                  disabled={busy}
                  onClick={() => setConfirmUnlink(false)}
                >
                  Cancelar
                </button>
              </div>
            </div>
          )}
        </>
      )}
      <details className="security-panel">
        <summary>Privacidade</summary>
        <p>
          Seu identificador é armazenado de forma criptografada e não aparece
          nesta tela. Não guardamos o histórico completo da conversa. Rascunhos
          expiram em 10 minutos; desvincular impede novos lançamentos por
          WhatsApp. As mensagens passam pela plataforma da Meta.
        </p>
      </details>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      {error && !status && (
        <button
          className="text-button"
          onClick={() =>
            void refresh()
              .then(() => setError(""))
              .catch((e) => setError((e as Error).message))
          }
        >
          Tentar novamente
        </button>
      )}
    </section>
  );
}
