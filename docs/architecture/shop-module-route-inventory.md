# Shop module route inventory

The checked-in capability source is [`config/shop_modules.php`](../../config/shop_modules.php). The reviewable route-inventory projection is generated with:

```powershell
php artisan erp:route-matrix --write
```

Review the generated [shop owner ERP route matrix](shop-owner-erp-route-matrix.md) for the loaded route, method, actor, module, owner-policy, and self-service classifications. This document intentionally contains only the source-of-truth link and generation command; runtime enforcement does not parse Markdown.

The bidirectional contract is covered by `ShopModuleRouteCoverageTest`: configured internal routes must be loaded, and loaded internal ERP routes must be classified with matching HTTP methods. Framework/tooling routes and intentionally excluded customer, public, SuperAdmin, and system-processing routes are represented explicitly where they are part of the internal inventory.
