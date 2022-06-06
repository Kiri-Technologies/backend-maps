# Installation

1. Composer Install (pastikan extension=sodium sudah dihapus ; nya di php.ini)
2. Copy .env.example menjadi .env
3. php artisan vendor:publish --provider="Kreait\Laravel\Firebase\ServiceProvider" --tag=config
4. php artisan serve
