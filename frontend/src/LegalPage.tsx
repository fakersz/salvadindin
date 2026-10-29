import type { ReactNode } from "react";
import { legalContact } from "./legalContact";

const updated = "29 de outubro de 2026";
const repository = "https://github.com/fakersz/salvadindin/tree/v2-development";
const lgpd =
  "https://www2.camara.leg.br/legin/fed/lei/2018/lei-13709-14-agosto-2018-787077-normaatualizada-pl.html";
const anpd =
  "https://www.gov.br/anpd/pt-br/assuntos/titular-de-dados/direito-dos-titulares";

function Contact({
  subject = "Privacidade — SalvaDinDin",
}: {
  subject?: string;
}) {
  return legalContact.email ? (
    <a
      href={`mailto:${legalContact.email}?subject=${encodeURIComponent(subject)}`}
    >
      {legalContact.email}
    </a>
  ) : (
    <span className="legal-pending">
      Canal de contato pendente de confirmação pelo responsável. Não envie dados
      por canais públicos.
    </span>
  );
}

type Section = { id: string; title: string; content: ReactNode };
type Document = { title: string; intro: string; sections: Section[] };

const privacy: Document = {
  title: "Política de Privacidade",
  intro:
    "Como seus dados são usados para organizar suas finanças e manter seu acesso seguro.",
  sections: [
    {
      id: "responsavel",
      title: "1. Responsável e contato",
      content: (
        <>
          <p>
            {legalContact.controller} é o responsável pelas decisões sobre o
            tratamento de dados no SalvaDinDin 2.0, disponível em
            salvadindin.online. Para assuntos de privacidade, acesso, correção
            ou exclusão, utilize <Contact />.
          </p>
          <p>
            Esta política descreve a versão atualmente publicada. Ela não
            representa certificação de conformidade jurídica nem aprovação pela
            Meta.
          </p>
        </>
      ),
    },
    {
      id: "dados",
      title: "2. Dados que tratamos",
      content: (
        <>
          <ul>
            <li>
              <strong>Cadastro:</strong> nome de usuário, identificador interno
              e datas de criação, atualização e último acesso. O cadastro básico
              não exige nome civil, e-mail, CPF ou telefone.
            </li>
            <li>
              <strong>Autenticação:</strong> hash da senha, hashes de tokens de
              sessão e de códigos de recuperação, datas de validade, uso e
              revogação. A senha não é armazenada em texto puro.
            </li>
            <li>
              <strong>Finanças:</strong> contas financeiras, tipo e saldo
              inicial informado, categorias, receitas, despesas, transferências
              entre registros internos, valores, descrições e datas. Os saldos
              são calculados a partir desses dados.
            </li>
            <li>
              <strong>Segurança e operação:</strong> endereço IP e metadados
              técnicos de acesso, método, rota sem parâmetros, status e duração
              da requisição. O controle de tentativas usa identificadores
              derivados por HMAC, janelas e contadores.
            </li>
            <li>
              <strong>WhatsApp, quando vinculado:</strong> identificador do
              remetente, texto necessário para interpretar a solicitação, dados
              temporários de confirmação e metadados de mensagens e respostas. O
              identificador é guardado criptografado e também representado por
              HMAC para busca e controle de duplicação.
            </li>
            <li>
              <strong>Atendimento:</strong> dados que você fornecer para exercer
              seus direitos, comprovar a titularidade e receber a resposta.
            </li>
          </ul>
          <p>
            Descrições e mensagens livres podem conter informações pessoais
            inseridas por você. Evite dados de terceiros, informações de saúde,
            documentos, senhas e informações de cartão: eles não são necessários
            para os lançamentos.
          </p>
        </>
      ),
    },
    {
      id: "finalidades",
      title: "3. Finalidades e bases legais",
      content: (
        <>
          <p>
            Usamos os dados de cadastro, acesso e finanças para prestar o
            serviço solicitado: autenticar você, manter sua sessão, recuperar o
            acesso e organizar seus registros. A base aplicável é a execução do
            serviço e das providências solicitadas pelo titular, conforme o art.
            7º, V, da LGPD.
          </p>
          <p>
            Prevenir abuso, proteger contas e investigar falhas pode envolver
            legítimo interesse, com análise de necessidade e respeito aos seus
            direitos (art. 7º, IX). Obrigações legais ou regulatórias e
            exercício regular de direitos serão observados quando efetivamente
            aplicáveis (art. 7º, II e VI).
          </p>
          <p>
            O WhatsApp é opcional e só é vinculado após uma ação sua no site e a
            confirmação pelo código enviado ao bot. O processamento necessário
            às solicitações feitas por esse canal segue a prestação do serviço
            solicitada por você. Quando uma operação depender de consentimento,
            ele deve ser específico e pode ser revogado; aceitar esta política
            não constitui consentimento genérico para outros usos.
          </p>
          <p>
            Não usamos seus lançamentos para publicidade direcionada, venda de
            dados, análise de crédito ou execução de operações bancárias.
          </p>
        </>
      ),
    },
    {
      id: "cookies",
      title: "4. Cookies e sessão",
      content: (
        <>
          <p>
            A aplicação utiliza cookies necessários à sessão e à proteção contra
            solicitações indevidas (CSRF). São cookies Secure, HttpOnly e
            SameSite=Lax. Os tokens de sessão não são armazenados em
            localStorage.
          </p>
          <p>
            Na configuração atual, a sessão tem validade de até sete dias e o
            token CSRF, de duas horas. Sair encerra a sessão correspondente;
            redefinir a senha por um código de recuperação revoga as sessões
            abertas. Expiração de acesso não significa exclusão imediata dos
            registros de sessão no banco.
          </p>
          <p>
            Não há ferramenta de publicidade ou análise comportamental integrada
            nesta versão. Desabilitar os cookies necessários pode impedir login
            e operações autenticadas.
          </p>
        </>
      ),
    },
    {
      id: "whatsapp",
      title: "5. Integração opcional com WhatsApp",
      content: (
        <>
          <p>
            Utilizamos exclusivamente a WhatsApp Business Platform Cloud API
            oficial da Meta. Ao usar o bot, a Meta também processa mensagens e
            metadados conforme seus próprios termos e políticas. Não utilizamos
            clientes não oficiais de WhatsApp Web.
          </p>
          <p>
            O código de vinculação expira em dez minutos, é de uso único e tem
            seu hash armazenado. O bot interpreta mensagens por regras e pede
            confirmação antes de registrar uma receita ou despesa. Se não
            identificar a conta, ele solicita esclarecimentos. Identificadores
            únicos de mensagens evitam lançamentos duplicados.
          </p>
          <p>
            O sistema precisa ler o conteúdo recebido para atender sua
            solicitação. Não guardamos o corpo completo do webhook ou um
            histórico integral de conversas, mas mantemos rascunhos e respostas
            temporárias necessários ao fluxo, de forma criptografada, além de
            metadados de segurança.
          </p>
          <p>
            Você pode revogar códigos pendentes ou desvincular em{" "}
            <a href="/app/settings/integrations">Integrações</a>. A
            desvinculação interrompe novos lançamentos pelo canal, cancela
            pendências e respostas ainda na fila. Uma requisição à Meta já em
            andamento pode não ser recolhida. Lançamentos já confirmados no site
            não são apagados ao desvincular.
          </p>
          <p>
            Não enviamos mensagens promocionais ou notificações proativas nesta
            versão. As respostas operacionais ficam restritas à janela permitida
            para atendimento iniciada pela mensagem recebida. A Meta pode
            aplicar seus próprios limites e condições comerciais.
          </p>
        </>
      ),
    },
    {
      id: "compartilhamento",
      title: "6. Prestadores e compartilhamento",
      content: (
        <>
          <p>
            Provedores de hospedagem e infraestrutura podem tratar dados
            técnicos necessários para operar o site, armazenar informações e
            manter HTTPS. A Meta participa do processamento quando você utiliza
            a integração com WhatsApp. O canal de atendimento informado também
            pode envolver o provedor de comunicação escolhido.
          </p>
          <p>
            O compartilhamento deve se limitar ao necessário para a finalidade,
            segurança e obrigações aplicáveis. Não publicamos os dados
            cadastrados no repositório de código nem os vendemos a anunciantes.
            Autoridades podem receber informações quando houver dever legal ou
            ordem válida.
          </p>
          <p>
            A infraestrutura e os prestadores podem envolver processamento fora
            do Brasil. Quando houver transferência internacional, devem ser
            observados os mecanismos e salvaguardas previstos na LGPD.
            Informações adicionais sobre prestadores e transferências podem ser
            solicitadas pelo contato desta política.
          </p>
        </>
      ),
    },
    {
      id: "seguranca",
      title: "7. Medidas de segurança",
      content: (
        <>
          <p>
            As medidas implementadas incluem HTTPS, senhas protegidas por
            Argon2id, tokens de sessão e recuperação armazenados como hash,
            proteção CSRF, limites de tentativas, validação de assinatura dos
            webhooks e verificação de titularidade nos acessos a dados
            financeiros.
          </p>
          <p>
            A infraestrutura separa a aplicação e suas permissões de banco.
            Segredos operacionais ficam fora do repositório; os logs de acesso
            omitem parâmetros e cabeçalhos sensíveis. Identificadores do
            WhatsApp e rascunhos temporários usam criptografia autenticada na
            aplicação.
          </p>
          <p>
            Isso não significa criptografia de ponta a ponta de todos os dados
            dentro do PostgreSQL. O serviço precisa processar seus lançamentos e
            mensagens. Nenhuma medida garante anonimato absoluto ou elimina
            todos os riscos de incidente.
          </p>
        </>
      ),
    },
    {
      id: "retencao",
      title: "8. Retenção e descarte",
      content: (
        <>
          <p>
            Cadastro e registros financeiros são mantidos enquanto necessários à
            conta e ao serviço, até a análise de uma solicitação de exclusão ou
            outra justificativa aplicável. A versão atual não tem exclusão
            automática de toda a conta por botão.
          </p>
          <p>
            Contas financeiras arquivadas preservam o histórico. Registros de
            sessão expirada ou revogada e códigos de recuperação podem
            permanecer no banco até uma limpeza administrativa ou exclusão da
            conta; não se tornam utilizáveis por permanecerem armazenados.
          </p>
          <p>
            No WhatsApp, confirmações expiram em dez minutos. O processo de
            limpeza do backend remove o conteúdo de rascunhos ao concluir,
            cancelar ou expirar e apaga textos e destinatários de respostas após
            conclusão ou expiração. Códigos e pendências expirados há mais de um
            dia são removidos, e metadados de mensagens, após trinta dias. Essas
            limpezas dependem da execução do serviço; uma indisponibilidade pode
            adiar o descarte.
          </p>
          <p>
            Registros de acesso têm rotação e limites de armazenamento; a versão
            atual não estabelece um prazo único de descarte em dias para todos
            os logs. Documentos de atendimento e eventual retenção excepcional
            devem se limitar ao necessário, com justificativa e acesso restrito.
          </p>
          <p>
            Cópias de segurança ou registros fora da base ativa, se existentes,
            podem exigir tratamento separado. Uma exclusão na base ativa não
            equivale a eliminação instantânea de toda cópia. O procedimento deve
            identificar essas cópias, limitar o uso residual e impedir que dados
            excluídos voltem ao serviço em uma restauração.
          </p>
        </>
      ),
    },
    {
      id: "direitos",
      title: "9. Seus direitos e solicitações",
      content: (
        <>
          <p>
            Conforme as condições da LGPD, você pode solicitar confirmação e
            acesso, correção, informações sobre compartilhamento, anonimização,
            bloqueio ou exclusão, portabilidade quando aplicável, oposição e
            revogação de consentimento. O exercício desses direitos é gratuito.
          </p>
          <p>
            Para solicitar, escreva para <Contact /> e informe o nome de
            usuário, o direito que deseja exercer e um meio para resposta. A
            titularidade será verificada de forma proporcional. Não envie senha,
            token de sessão nem códigos de recuperação.
          </p>
          <p>
            Atenderemos nos prazos legais aplicáveis. A declaração completa de
            acesso observa o art. 19 da LGPD; pedidos de exclusão passam por
            verificação e análise das hipóteses legais de conservação. A
            resposta informará o resultado, eventuais limitações e sua
            justificativa. Não há exportação completa automatizada nesta versão.
          </p>
          <p>
            Veja o <a href="/exclusao-de-dados">procedimento de exclusão</a>. Se
            não conseguir exercer seus direitos, você pode recorrer à{" "}
            <a href={anpd}>ANPD</a> e aos demais órgãos competentes.
          </p>
        </>
      ),
    },
    {
      id: "alteracoes",
      title: "10. Alterações desta política",
      content: (
        <>
          <p>
            Atualizações serão publicadas nesta página com nova data. Mudanças
            relevantes nas finalidades ou bases de tratamento exigem informação
            adequada e, quando necessário, nova manifestação do titular.
            Consulte esta política ao utilizar novas integrações.
          </p>
          <p>
            Referências:{" "}
            <a href={lgpd}>Lei Geral de Proteção de Dados Pessoais</a> e{" "}
            <a href={anpd}>orientações da ANPD sobre direitos dos titulares</a>.
          </p>
        </>
      ),
    },
  ],
};

const terms: Document = {
  title: "Termos de Serviço",
  intro: "Regras de uso do SalvaDinDin para organizar suas finanças pessoais.",
  sections: [
    {
      id: "servico",
      title: "1. Serviço e responsável",
      content: (
        <>
          <p>
            O SalvaDinDin 2.0 é um serviço gratuito de organização de finanças
            pessoais, mantido por {legalContact.controller}. Ele permite
            cadastrar contas financeiras, categorias, receitas, despesas e
            movimentações internas, além de consultar saldos calculados a partir
            dos dados informados.
          </p>
          <p>
            O projeto é desenvolvido em{" "}
            <a href={repository}>repositório público</a>, com proposta de código
            aberto. A licença open source específica ainda está em definição: o
            repositório atual não contém uma licença que autorize
            irrestritamente uso, modificação e redistribuição. Estes termos
            tratam do uso do serviço e não substituem essa licença.
          </p>
          <p>
            Para dúvidas e exercício de direitos, entre em contato por{" "}
            <Contact subject="Atendimento — SalvaDinDin" />.
          </p>
        </>
      ),
    },
    {
      id: "limites",
      title: "2. O que o aplicativo faz",
      content: (
        <>
          <p>
            O SalvaDinDin não é banco nem instituição financeira. Não movimenta
            dinheiro, executa pagamentos ou transferências bancárias, guarda
            fundos nem solicita credenciais de internet banking. “Transferir”
            entre contas no aplicativo apenas registra uma movimentação interna
            para fins de organização.
          </p>
          <p>
            Os resumos e cálculos não constituem consultoria financeira
            profissional individualizada, recomendação de investimento ou
            garantia de resultado. Confira informações importantes com seus
            próprios registros e, se necessário, com profissional habilitado.
          </p>
        </>
      ),
    },
    {
      id: "acesso",
      title: "3. Conta, acesso e uso responsável",
      content: (
        <>
          <p>
            Você é responsável pela exatidão e legitimidade dos dados que
            inserir e por revisar valores, datas, contas e confirmações. Guarde
            sua senha e seus códigos de recuperação em local seguro. O serviço
            não recupera a senha original; a recuperação atual depende dos
            códigos fornecidos no cadastro.
          </p>
          <p>
            Não utilize o serviço para fraude, invasão, acesso a dados de
            terceiros, envio de conteúdo ilícito, tentativas de contornar
            limites de segurança ou automação abusiva. Não compartilhe
            credenciais nem insira dados de terceiros sem uma justificativa
            legítima.
          </p>
          <p>
            Medidas proporcionais de proteção podem limitar requisições ou
            suspender acessos relacionados a abuso ou risco. Quando possível e
            compatível com a segurança e obrigações legais, o responsável
            informará o motivo e oferecerá contato para esclarecimento. Não há
            perda automática dos seus direitos sobre os dados.
          </p>
        </>
      ),
    },
    {
      id: "integracao",
      title: "4. WhatsApp opcional",
      content: (
        <>
          <p>
            A integração utiliza a Cloud API oficial da Meta e depende de
            disponibilidade, configuração e regras dessa plataforma. Vincular é
            opcional; o site pode ser usado sem WhatsApp.
          </p>
          <p>
            O bot interpreta somente mensagens suportadas e pode pedir
            esclarecimentos. Revise o resumo e confirme o lançamento antes de
            salvar. Uma mensagem recebida ou uma resposta aceita pela API não
            garante que o lançamento tenha sido concluído: confira o histórico
            do site em caso de dúvida e evite repetir confirmações sem
            verificar.
          </p>
          <p>
            É possível revogar códigos e desvincular em{" "}
            <a href="/app/settings/integrations">Integrações</a>. A
            desvinculação não remove lançamentos anteriores nem o histórico
            existente na sua conta ou na Meta. As regras de privacidade estão na{" "}
            <a href="/privacidade">Política de Privacidade</a>.
          </p>
        </>
      ),
    },
    {
      id: "disponibilidade",
      title: "5. Disponibilidade e responsabilidades",
      content: (
        <>
          <p>
            O serviço está em evolução e pode passar por manutenção, falhas ou
            períodos de indisponibilidade. Não prometemos funcionamento
            ininterrupto ou ausência total de erros. Alterações relevantes devem
            ser informadas de maneira adequada; a gratuidade não afasta
            obrigações previstas em lei.
          </p>
          <p>
            As responsabilidades do usuário e do responsável pelo serviço serão
            avaliadas conforme sua conduta, o dano e a legislação aplicável.
            Estes termos não excluem responsabilidades que não possam ser
            afastadas por lei, nem direitos à reparação, informação, proteção de
            dados ou defesa do consumidor.
          </p>
        </>
      ),
    },
    {
      id: "dados",
      title: "6. Dados, alterações e encerramento",
      content: (
        <>
          <p>
            O tratamento de dados segue a{" "}
            <a href="/privacidade">Política de Privacidade</a>. Você pode pedir
            acesso, correção ou exclusão pelo contato indicado, seguindo a{" "}
            <a href="/exclusao-de-dados">orientação de exclusão</a>. A versão
            atual não oferece um botão de exclusão integral ou exportação
            completa automatizada.
          </p>
          <p>
            Novas versões destes termos terão a data de atualização
            identificada. Mudanças não reduzem direitos legais já assegurados e
            não autorizam usos de dados incompatíveis com as informações
            prestadas ao titular.
          </p>
        </>
      ),
    },
    {
      id: "legislacao",
      title: "7. Legislação e resolução de conflitos",
      content: (
        <>
          <p>
            Aplicam-se as leis brasileiras, incluindo a LGPD e as normas de
            proteção do consumidor quando cabíveis. Para tentar solucionar um
            problema, utilize <Contact subject="Atendimento — SalvaDinDin" />.
            Esse contato não é uma condição para procurar autoridades ou o Poder
            Judiciário.
          </p>
          <p>
            São preservados os órgãos e foros legalmente competentes, inclusive
            as regras que protegem o domicílio do consumidor quando aplicáveis.
            Não há arbitragem obrigatória, renúncia genérica a direitos ou
            eleição de foro que afaste garantias legais.
          </p>
        </>
      ),
    },
  ],
};

const deletion: Document = {
  title: "Exclusão de dados",
  intro:
    "Como pedir a exclusão da sua conta e como encerrar apenas a integração com WhatsApp.",
  sections: [
    {
      id: "solicitar",
      title: "1. Solicite pelo canal de privacidade",
      content: (
        <>
          <p>
            O responsável pelo atendimento é {legalContact.controller}. Envie
            sua solicitação para{" "}
            <Contact subject="Solicitação de exclusão de dados — SalvaDinDin" />{" "}
            com o assunto “Exclusão de dados — SalvaDinDin”.
          </p>
          <p>
            Informe seu nome de usuário, se deseja excluir toda a conta ou
            apenas dados específicos, e um canal para receber a resposta. Não
            envie senha, cookies, tokens de sessão, código de vinculação ou
            códigos de recuperação. Não publique dados financeiros ou documentos
            em issues do GitHub ou outros canais públicos.
          </p>
          <p>
            A exclusão integral é administrativa nesta versão. Esta página não
            executa a exclusão e não há um botão automático que remova toda a
            conta.
          </p>
        </>
      ),
    },
    {
      id: "verificacao",
      title: "2. Verificação de titularidade e acompanhamento",
      content: (
        <>
          <p>
            Recebido o pedido, o responsável verifica as informações necessárias
            para identificar a conta e confirmar que o solicitante pode agir por
            ela. Como o cadastro não exige e-mail, conhecer o nome de usuário ou
            escrever de um endereço de e-mail não basta, por si só, para provar
            titularidade.
          </p>
          <p>
            O atendimento deve combinar uma confirmação por acesso autenticado à
            conta ou pelo WhatsApp já vinculado, com uma referência temporária
            comunicada em particular, sem solicitar sua senha. Se você perdeu
            esses acessos, o caso será analisado individualmente, com exigências
            proporcionais e sem acesso aos dados de terceiros.
          </p>
          <p>
            O resultado e eventuais etapas adicionais serão informados pelo
            mesmo canal privado de atendimento ou por outro canal combinado com
            você. Serão observados os prazos legais aplicáveis; não prometemos
            exclusão instantânea nem um prazo operacional que ainda não foi
            estabelecido.
          </p>
        </>
      ),
    },
    {
      id: "whatsapp",
      title: "3. Para desvincular somente o WhatsApp",
      content: (
        <>
          <ol>
            <li>
              Entre na sua conta em <a href="/login">salvadindin.online</a>.
            </li>
            <li>
              Acesse Segurança → Integrações, ou abra{" "}
              <a href="/app/settings/integrations">a página de integrações</a>.
            </li>
            <li>Escolha “Desvincular WhatsApp” e confirme a desvinculação.</li>
          </ol>
          <p>
            Você também pode revogar um código ainda pendente nessa tela. A
            desvinculação remove o vínculo, cancela pendências de confirmação e
            respostas que ainda não foram enviadas. Requisições já em andamento
            podem não ser recolhidas.
          </p>
          <p>
            Isso não exclui sua conta SalvaDinDin, seus lançamentos já salvos ou
            mensagens e outros registros mantidos pela Meta ou no seu próprio
            WhatsApp. Para eliminar dados da conta, faça o pedido descrito
            acima; para dados mantidos pela Meta, consulte os canais e as
            configurações dessa plataforma.
          </p>
        </>
      ),
    },
    {
      id: "escopo",
      title: "4. O que pode ser excluído",
      content: (
        <>
          <p>
            Após verificação e análise das condições aplicáveis, o procedimento
            pode abranger cadastro, hash da senha, sessões, códigos de
            recuperação, contas financeiras, categorias, receitas, despesas,
            movimentações, vínculo do WhatsApp, códigos pendentes, rascunhos e
            metadados associados à conta.
          </p>
          <p>
            O procedimento deve impedir novos acessos e envios pendentes
            relacionados à conta, verificar os vínculos entre registros e
            confirmar a remoção do conjunto correto. A exclusão de uma conta
            financeira por arquivamento preserva o histórico e não substitui a
            exclusão integral solicitada pelo titular.
          </p>
          <p>
            Não há exportação completa por botão nesta versão. Caso queira
            solicitar acesso aos seus dados antes da exclusão, inclua isso no
            pedido; o atendimento avaliará a forma de fornecer somente as suas
            informações.
          </p>
        </>
      ),
    },
    {
      id: "retencao",
      title: "5. Retenção excepcional e cópias",
      content: (
        <>
          <p>
            Dados estritamente necessários podem ser conservados quando houver
            obrigação legal aplicável, exercício regular de direitos ou
            finalidade de segurança e prevenção de fraude juridicamente
            justificada. Essas hipóteses não autorizam conservar
            indiscriminadamente todo o histórico financeiro.
          </p>
          <p>
            A resposta deve informar o que foi excluído, o que eventualmente
            ficou retido, a justificativa e o critério de duração. Logs técnicos
            ou cópias de segurança, quando existentes, podem exigir descarte
            separado da base ativa; dados excluídos não devem ser reintroduzidos
            no uso normal durante uma restauração.
          </p>
          <p>
            Não prometemos apagar, pelo SalvaDinDin, registros controlados
            independentemente pela Meta, pelo provedor de comunicação ou por
            terceiros. Podemos explicar quais dados foram compartilhados e
            indicar os canais correspondentes quando aplicável.
          </p>
        </>
      ),
    },
    {
      id: "direitos",
      title: "6. Outros direitos e informações",
      content: (
        <>
          <p>
            Você também pode solicitar correção, informações sobre tratamento e
            compartilhamento, ou contestar a decisão sobre seu pedido. Utilize{" "}
            <Contact /> e consulte a{" "}
            <a href="/privacidade">Política de Privacidade</a>.
          </p>
          <p>
            Os direitos previstos em lei permanecem preservados. Se não
            conseguir exercê-los diretamente com o responsável, consulte as{" "}
            <a href={anpd}>orientações da ANPD</a>.
          </p>
        </>
      ),
    },
  ],
};

const documents: Record<string, Document> = {
  "/privacidade": privacy,
  "/termos": terms,
  "/exclusao-de-dados": deletion,
};

export function isLegalPath(path: string) {
  return Object.hasOwn(documents, path);
}

export function LegalPage({ path }: { path: string }) {
  const document = documents[path];
  return (
    <article className="legal-document">
      <header className="legal-heading">
        <span className="eyebrow">Informações legais</span>
        <h1>{document.title}</h1>
        <p>{document.intro}</p>
        <span className="legal-updated">
          Atualizado em <time dateTime="2026-10-29">{updated}</time>
        </span>
      </header>
      {!legalContact.email && (
        <p className="notice" role="status">
          Publicação em revisão: o canal de contato ainda precisa ser informado
          e confirmado pelo responsável.
        </p>
      )}
      <div className="legal-layout">
        <nav className="legal-toc" aria-label="Sumário">
          <span>Nesta página</span>
          {document.sections.map((section) => (
            <a key={section.id} href={`#${section.id}`}>
              {section.title.replace(/^\d+\. /, "")}
            </a>
          ))}
        </nav>
        <div className="legal-content">
          {document.sections.map((section) => (
            <section
              id={section.id}
              key={section.id}
              aria-labelledby={`${section.id}-title`}
            >
              <h2 id={`${section.id}-title`}>{section.title}</h2>
              {section.content}
            </section>
          ))}
        </div>
      </div>
    </article>
  );
}
