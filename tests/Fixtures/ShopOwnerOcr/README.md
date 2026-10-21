# Shop-owner OCR contract fixtures

These JSON fixtures contain synthetic evidence values only. They contain no document images, personal identifiers, OCR output, or real applicant information. The cases are test inputs for the fixed evidence and score contract, not measurements of OCR quality or fraud detection.

`screening-contract.json` is consumed by both the TypeScript and PHP tests. It covers the four required logical slots, DTI/SEC selection, conditional sole-proprietor evidence, local-confidence boundaries, missing evidence, unavailable scans, and aggregate precedence. `recognition-evidence.json` contains synthetic pass-local word/field examples for complete, ambiguous, role-specific, and contradictory evidence; the browser recognizer phase will consume these examples.

There are no representative document-image fixtures in this checkout yet. Only images with documented permission and redaction, or visibly synthetic test documents that cannot be mistaken for official records, may be added for actual browser-engine checks.
