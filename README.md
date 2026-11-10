# SMK Starter Kit

Neutral Core for new Statamic applications. **Repository: robcio/smk-starter-kit.** Core → optional future modules → Brand. This repository contains the exported package, not the development workbench or a client website.

## Installation from GitHub

These commands become usable after the repository and tag are published. GitHub installation has not been verified remotely while this repository is only prepared locally.

For a new project, the documented Statamic CLI form is:

```sh
statamic new my-site robcio/smk-starter-kit
```

Or, inside a **new, empty Statamic application**, install the tagged version:

```sh
php please starter-kit:install robcio/smk-starter-kit:1.0.0 --without-user
npm ci
npm run build
node tools/smk/check-design-tokens.mjs
```

No Packagist or Marketplace registration is required for the documented public GitHub route. See [Creating a Starter Kit](https://statamic.dev/starter-kits/creating-a-starter-kit#publishing-a-starter-kit) and [Installing a Starter Kit](https://statamic.dev/starter-kits/installing-a-starter-kit).

Tested profile: PHP8.5.12, Laravel13.39.0, Statamic6.39.0, Node24.22.2/npm11.19.1. Native starter-kit dependencies pin Laravel/Statamic and five Symfony components to8.1.11. Do not skip dependencies, force compatibility errors, or disable advisory checks. Installation into an incompatible skeleton may be refused; prepare a matching fresh application rather than modifying an existing website. No guarantee is made for arbitrary future framework versions.

Create your own environment configuration, trusted origin, APP_KEY and account separately. Never copy credentials or users from another project. Keep local/staging noindex. Do not use --clear-site on existing content. The package includes the reviewed bootstrap/app.php integration and is not a safe overwrite for a customized bootstrap.

## Included

Five Global Sets, neutral tokens/themes/typography/containers, shared shell and accessible navigation for three trees, Hero/Rich Text/CTA Replicator, shared assets/Bard/links, basic SEO, Core profile plan/validation/recovery tools and neutral demo. Brand appearance belongs in resources/css/brand.

There are no business modules, client portfolio/pricing/reviews, runtime installer/manager, content migrations, forms, bookings, analytics or SEO extensions. No .env, accounts, vendor, node_modules, runtime or public/build is shipped. The initializer is not activated by installation; read tools/smk/profile-initializer.md before planning any apply. Never use apply on a live/customized project without a separately reviewed controlled procedure.

## Package validation and maintenance

```sh
php tools/verify-package.php
composer validate
```

The verifier checks the declared exported payload and package identity. It is not an exhaustive secret scanner or a replacement for consumer tests. Full consumer verification is performed outside this exported repository; the release handoff records the runs and their limits.

Versions are derived from Git tags (initial proposed tag v1.0.0). Do not edit an existing release tag. Do not reinstall the starter kit to update a customized website. Review and test changes separately; composer update alone does not update copied templates/blueprints.

## License and access

Composer license metadata remains proprietary. Public repository access does not grant an MIT or other open-source license. No public redistribution license is added by this preparation. Statamic and dependency licenses remain separate. A future change of licensing requires the owner's explicit decision.
