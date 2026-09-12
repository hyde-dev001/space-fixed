# Shop owner ERP parity

Owner parity is evaluated from the catalog and loaded routes, not from navigation labels or duplicated Markdown tables. The authoritative policy is [`config/shop_modules.php`](../../config/shop_modules.php); the generated [shop owner ERP route matrix](shop-owner-erp-route-matrix.md) records current exposure, supporting APIs, domain-rule metadata, risk, persistence, and self-service decisions.

Regenerate the projection after route or capability changes:

```powershell
php artisan erp:route-matrix --write
```

An owner policy of `allowed` only makes a paired route eligible for review. Tenant scope, record authorization, workflow rules, actor persistence, audit, idempotency, and external effects remain separate implementation gates.
