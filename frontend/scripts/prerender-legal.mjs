import { readFile, writeFile } from "node:fs/promises";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { createServer } from "vite";

// Publish readable documents even for clients that do not execute JavaScript.
const server = await createServer({
  server: { middlewareMode: true, hmr: false },
  appType: "custom",
});
try {
  const { Shell } = await server.ssrLoadModule("/src/App.tsx");
  const { LegalPage } = await server.ssrLoadModule("/src/LegalPage.tsx");
  const template = await readFile("dist/index.html", "utf8");
  for (const [path, title] of [
    ["/privacidade", "Política de Privacidade"],
    ["/termos", "Termos de Serviço"],
    ["/exclusao-de-dados", "Exclusão de dados"],
  ]) {
    const content = renderToStaticMarkup(
      createElement(Shell, { legal: true }, createElement(LegalPage, { path })),
    );
    const html = template
      .replace('<div id="root"></div>', '<div id="root">' + content + "</div>")
      .replace(/<title>.*?<\/title>/, `<title>${title} | SalvaDinDin</title>`)
      .replace(
        /<meta name="description" content="[^"]*"\s*\/?\s*>/,
        `<meta name="description" content="${title} do SalvaDinDin 2.0. Responsável, tratamento de dados, direitos e contato.">`,
      );
    await writeFile("dist" + path + ".html", html);
    process.stdout.write("Documento público gerado: " + path + "\n");
  }
} finally {
  await server.close();
}
