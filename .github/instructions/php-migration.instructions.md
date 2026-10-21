---
description: "Use when auditing, converting, or debugging PHP 7.2-to-8.2 compatibility, removed APIs, runtime behavior changes, and deprecations."
applyTo: "**/*.php"
---

For PHP version-migration work, read [the reusable PHP 7.2-to-8.2 reference](../references/php-7.2-to-8.2.md). The [PHP Migration agent](../agents/php-migration.agent.md) provides the specialized workflow.

Apply this guidance only to migration-related tasks. The default conversion target is PHP 8.2-only; retaining PHP 7.2 support is not required unless explicitly requested. Do not change declared application requirements or dependencies without authorization. Treat search matches as candidates, preserve application behavior, and verify on the actual target runtime. Research or audit requests do not authorize application edits. Do not edit dependency-owned vendor code or assume backend-old/ is an active migration target.