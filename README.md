# Zero for Laravel

Laravel-owned model contracts, queries and automatic CRUD for Rocicorp Zero.

Install the current stable release, **v0.1.0**, in your Laravel application:

```sh
composer require nickwelsh/zero-for-laravel
pnpm add @nickwelsh/zero-for-laravel
pnpm add --save-exact @rocicorp/zero@1.10.0
php artisan vendor:publish --tag=zero-config
```

Keep the Composer and JavaScript packages on matching release versions. Use Zero
cache **1.10.0**; React bindings additionally require React 18 or 19.

Development takes place in the [source monorepo](https://github.com/nickwelsh/zero-for-laravel-monorepo).
`nickwelsh/zero-for-laravel` is the generated PHP distribution mirror. Send issues and
pull requests to the monorepo; changes made directly to the mirror are not supported.

See the [monorepo documentation](https://github.com/nickwelsh/zero-for-laravel-monorepo#readme) for installation, authorization, configuration and React usage.

Requires PHP 8.3+ and Laravel 12 or 13.
