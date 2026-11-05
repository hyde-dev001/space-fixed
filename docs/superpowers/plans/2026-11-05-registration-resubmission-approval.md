# Registration resubmission approval

Pending registration review must include only pending, non-current document candidates. A previously rejected supporting document without a successor can otherwise appear in the review and be submitted with the current documents, which the approval service correctly rejects. Keep historical records and private-file, metadata, reviewer and authorization checks intact.

The registration list now projects only current review candidates for pending applications. The admin page surfaces document-specific server errors and resets viewed marks when a review is reopened. Approved and rejected registration history remains available.

The regression covers three signed resubmissions, an old supporting document omitted in later submissions, a newly submitted supporting document, and successful approval of the five current candidates. The pre-fix review projection failed this case. The separate document lifecycle test and admin-page interaction exercise approval and reviewer payloads.

Verification: 57 registration, document and module tests passed with SQLite in memory; seven admin-page tests passed. A broader run reported 12 authorization failures and four risky tests; the same 12 failure names and four risky tests were reproduced against the unchanged integration baseline with the same test setup. No live registration or provider was accessed. Actual production acceptance remains unverified.
