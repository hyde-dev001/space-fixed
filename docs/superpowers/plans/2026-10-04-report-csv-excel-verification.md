# Report CSV spreadsheet verification

Verification used current ManagerReportService output and Excel 16.0 on this Windows machine. All inputs were generated test fixtures. No existing workbook, production report or financial data was opened or changed.

## Discriminating evidence

The historical fixture contained valid UTF-8 bytes for José, multiplication, em dash and peso symbols, but no UTF-8 signature. Default Excel Workbooks.Open(filename, 0, true) decoded them incorrectly. A controlled copy with only EF BB BF prepended decoded the same content correctly. Customer code points included 233 for é. The shared buildCsvContent boundary now writes that signature before Summary.

The first test invocation overlapped the edit and passed; it is not accepted as a failing baseline. A separate verified invocation with only the new signature temporarily removed failed its signature assertion (one test, three assertions). Restoring the fix produced 33 passing report/owner-download tests, 244 assertions. Logs: %TEMP%/solespace-qa-csv-bom-red-verified.log and solespace-qa-csv-bom-green-verified.log.

## Actual exported-artifact checks

A temporary subclass captured the current, historical and formula-boundary formatted siblings during the existing export tests. The capture suite passed three tests/44 assertions. The production implementation, report snapshot and original artifacts were exercised by those tests; no separate hand-built CSV substituted for the final checks.

Each artifact was opened with the same default Excel method in a disposable hidden application, read-only, with events, external-link updating and macros disabled. Workbooks were closed without saving, then that application was closed.

| Export fixture | Imported evidence |
| --- | --- |
| Historical sales | Six rows, seven columns; one order row; José with embedded quotes/newline preserved; two readable items with quantities, peso values and embedded quote/comma; numeric 6000.25; zero formula cells. |
| New frozen sales | Nine rows, seven columns; one order row; Original Customer/Original Staff and numeric 6000 retained after source edits; readable Nike Shoes × 1 — ₱6,000.00; zero formula cells. |
| Formula boundary | Ten rows, seven columns; six order rows despite embedded text newlines; leading =,+,-,@ and whitespace/control variants remain escaped text; negative money remains numeric -10.25; zero formula cells. |

Captured CSV and Excel result JSON are under %TEMP%/solespace-qa-csv-excel. API capture log: %TEMP%/solespace-qa-csv-excel-capture-bom.log. Excel auto-converts timestamp cells to date serials; this check establishes import content/types, not a screenshot or human layout review.

One initial sandbox activation failed because no COM logon session was exposed. The first approved activation used an invalid code-page argument for Workbooks.Open; corrected to the documented default Open call before gathering evidence. [Microsoft's Open documentation](https://learn.microsoft.com/en-us/office/vba/api/excel.workbooks.open) distinguishes its platform argument from [OpenText's code-page support](https://learn.microsoft.com/en-us/office/vba/api/excel.workbooks.opentext). No machine setting changed.

## Preservation and limits

Only generated CSV presentation gains three encoding bytes. Stored report_data, source transactions, original historical file/path and generation/review decisions remain unchanged. Existing snapshot-preservation, owner tenant/path and formula/numeric tests pass. There is no dependency or migration.

Authenticated browser download and visual review remain separate pending checks. This local import does not identify the deployed release or establish deployed export behavior.
