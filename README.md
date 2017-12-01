API backend for administration panels.

## Install

```bash
composer require mr-timofey/laravel-admin-api
```

Follow installation instructions from
[mr-timofey/laravel-aio-images](https://github.com/mrTimofey/laravel-aio-images)
[mr-timofey/laravel-simple-tokens](https://github.com/mrTimofey/laravel-simple-tokens)
to properly install dependencies.

```bash
php artisan vendor:publish --provider="MrTimofey\LaravelAdminApi\ServiceProvider"
```

Open `config/admin_api.php` for further configuration instructions.

## Frontend

Supported frontend solutions:
* https://github.com/mrTimofey/vue-admin