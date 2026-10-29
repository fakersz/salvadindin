export function LegalLinks() {
  return (
    <nav className="legal-links" aria-label="Informações legais">
      <a href="/privacidade">Privacidade</a>
      <a href="/termos">Termos</a>
      <a href="/exclusao-de-dados">Exclusão de dados</a>
    </nav>
  );
}

export function LegalFooter() {
  return (
    <footer className="site-legal-footer">
      <span>SalvaDinDin 2.0</span>
      <LegalLinks />
    </footer>
  );
}
