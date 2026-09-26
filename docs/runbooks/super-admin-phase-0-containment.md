# Super Admin Phase 0 Containment Runbook

This runbook deploys the Phase 0 containment changes for privileged authorization, the compromised Super Admin credential, sensitive-document storage, and routine hard deletion.

The procedure is security-sensitive. Use an approved maintenance window, keep database and storage backups available, and stop when any required verification fails. Never place a credential value in this document, shell history, an environment variable, or a command argument.

## Preconditions and stop conditions

Before maintenance begins, confirm all of the following:

- An authorized operator has console access and a tested recovery Super Admin account.
- A verified database backup and separate backups of the public and private storage roots are available and restorable.
- The production session driver is `database`, the configured session table exists and is writable, and the configured session connection matches the application database connection.
- The active Super Admin count is known and the single account selected for rotation is recoverable and active.
- Read-only counts exist for `ShopDocument` rows and customer Valid ID rows, separated by disk value and path/file presence. Do not infer a missing file's location.
- The maintenance window allows public-file removal, credential rotation, and a deliberate global database-session logout.
- The deployed application version has passed the focused Phase 0 tests and route inspection described below.

Stop immediately for a missing or conflicting file, an unsupported session driver or session table, an unavailable recovery account, an audit failure, a failed credential rotation, or any failed focused verification. Do not target `sessions.user_id`: the deployed framework behavior does not reliably identify the `super_admin` guard in that column, so rotation deliberately clears all database-backed sessions.

## Forward deployment

1. Put the application in maintenance mode.

2. Back up the database, public storage root, and private storage root. Record backup identifiers and verify that each backup can be read.

3. Deploy the Phase 0 application code, including the additive disk metadata migration, mixed-disk readers, private writers, scoped document routes, capability middleware, audit writer, credential-rotation command, migration command, and hard-delete containment.

4. Run the normal database migrations:

   ```powershell
   php artisan migrate --force
   ```

   Confirm that `shop_documents.disk` and `users.valid_id_disk` exist and that legacy rows still report `public` until the file migration completes.

5. Rotate the compromised Super Admin credential interactively. Pass only the existing Super Admin email as the argument; the command collects the current and replacement values through hidden prompts:

   ```powershell
   php artisan super-admin:rotate-compromised-credential <existing-super-admin-email>
   ```

   The command requires an active, unique Super Admin, a database session store, and a writable configured session table. It changes the credential, rotates the remember token, clears every database session, and writes the privileged audit event in one transaction.

6. Verify the rotation before continuing:

   - The compromised credential is rejected.
   - The replacement credential succeeds for the recoverable account.
   - The configured session table is empty and the operator understands that every affected user must authenticate again.
   - The command's reported operation ID matches the corresponding audit record; audit actor, event, target, and correlation data are server-generated.

7. Run the sensitive-document migration in dry-run mode with a bounded batch size:

   ```powershell
   php artisan security:migrate-sensitive-documents-private --dry-run --chunk=100
   ```

   Review the separate Shop Document and customer Valid ID counts. Resolve every `missing`, `conflict`, `failed`, and `public_duplicates_remaining` result before writing anything. Never guess a path, disk, or replacement file.

8. Run the real copy-first migration:

   ```powershell
   php artisan security:migrate-sensitive-documents-private --chunk=100
   ```

   Each successful record is copied to the same relative path on the private `local` disk, verified by size and SHA-256, switched to private metadata transactionally, and only then removed from the public disk. The command is resumable. It does not print paths, hashes, filenames, or document contents.

9. Rerun the migration until the result is stable:

   ```powershell
   php artisan security:migrate-sensitive-documents-private --chunk=100
   ```

   The expected steady state is `already_private` for migrated rows and zero conflicts, missing files, failures, and remaining public duplicates. A matching public duplicate is removed only after verification.

10. Run the focused verification and route inspection:

    ```powershell
    vendor/bin/phpunit tests/Unit/Models/SuperAdminCapabilityTest.php tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php tests/Feature/SuperAdmin/PrivilegedAuditTest.php tests/Feature/SuperAdmin/KnownCredentialContainmentTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/SuperAdmin/SensitiveDocumentMigrationCommandTest.php tests/Feature/SuperAdmin/HardDeleteContainmentTest.php --display-warnings --display-deprecations --display-errors
    php artisan route:list --path=shop-owner-registration --except-vendor
    php artisan route:list --path=admin --except-vendor
    ```

    Confirm that canonical registration mutations exist once under `/admin`, the legacy registration surface is GET-only, private document routes have their capability middleware, and the three routine hard-delete route names are absent.

11. Reconcile database disk metadata against both storage roots. Confirm new sensitive uploads use `local`, legacy rows are either migrated or explicitly stopped for investigation, and no sensitive payload exposes a raw path or `/storage/` URL.

12. Exit maintenance mode. Perform browser checks with both a regular Admin and a Super Admin: review only the permitted registration states, access an authorized sensitive document, verify an out-of-scope document is not disclosed, confirm audit events are written, and confirm suspension/activation still work. Confirm no permanent-delete control is present.

## Rollback with a security floor

Rollback is allowed only inside a maintenance window and only after a verified backup decision. The security floor remains in force during rollback:

1. Re-enter maintenance mode.

2. Prepare verified public copies and switch metadata back to public without deleting the private copies:

   ```powershell
   php artisan security:migrate-sensitive-documents-private --restore-public --chunk=100
   ```

   Require zero missing, conflicts, failures, and remaining public duplicates. Verify both public and private bytes before rolling application code or schema back.

3. Keep the private copies until rollback validation is complete. Do not manually remove either copy while investigating a mismatch.

4. Never restore the compromised credential, routine hard-delete routes, missing capability checks, or raw sensitive-document URLs. Credential rotation is forward-only.

5. Do not restore the cleared database sessions. Affected users must authenticate again after maintenance.

6. If rollback would reintroduce public sensitive-document exposure or destructive privileged routes, stop the rollback and roll forward with a corrective patch instead.

## Command contract checks

Run these checks against the deployed release before authorizing the maintenance window:

```powershell
php artisan help super-admin:rotate-compromised-credential
php artisan help security:migrate-sensitive-documents-private
```

The credential command must expose only the required email argument and hidden interactive prompts at runtime. The document command must expose `--dry-run`, `--restore-public`, and `--chunk` with the expected defaults. Do not add secret values to either command's examples or automation configuration.
