const ts = require("typescript");
const fs = require("fs");
const path = "resources/js/Pages/ShopOwner/Repairs/service management/POS.tsx";
const program = ts.createProgram([path], { jsx: ts.JsxEmit.Preserve, allowJs: true, target: ts.ScriptTarget.Latest, module: ts.ModuleKind.CommonJS });
const diags = ts.getPreEmitDiagnostics(program);
if (diags.length === 0) {
  console.log("NO_ERRORS");
  process.exit(0);
}
for (const diag of diags) {
  const msg = ts.flattenDiagnosticMessageText(diag.messageText, "\n");
  const line = diag.file ? diag.file.getLineAndCharacterOfPosition(diag.start) : { line: -1, character: -1 };
  console.log(`${line.line+1}:${line.character+1}: ${msg}`);
}
process.exit(1);
