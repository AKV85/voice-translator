# Voice Translator

Push-to-talk voice translation application built with Laravel.

The application is intended to let two people speaking different languages communicate using speech recognition, machine translation, and synthesized speech.

## MVP

Initial language pair:

- English → Russian
- Russian → English

Core flow:

Audio recording → Speech-to-Text → Translation → Text-to-Speech → Audio playback

Both the recognized source text and translated text will be displayed to the user.

External speech and translation services will be isolated behind provider interfaces so providers can be replaced without changing the core application flow.

## Tech Stack

- PHP 8.4
- Laravel 13
- Laravel Sail
- Docker
- MySQL 8.4
- Blade
- Alpine.js
- Tailwind CSS
- Vite
- Pest

## Requirements

- Docker Desktop
- WSL2 on Windows
- Git

## Local Setup

Clone the repository:

    git clone https://github.com/AKV85/voice-translator.git
    cd voice-translator

Create the environment file:

    cp .env.example .env

Install Composer dependencies:

    docker run --rm \
        -u "$(id -u):$(id -g)" \
        -v "$(pwd):/var/www/html" \
        -w /var/www/html \
        laravelsail/php84-composer:latest \
        composer install --ignore-platform-reqs

Start the application:

    ./vendor/bin/sail up -d

Generate the application key:

    ./vendor/bin/sail artisan key:generate

Run database migrations:

    ./vendor/bin/sail artisan migrate

Install frontend dependencies:

    ./vendor/bin/sail npm install

Build frontend assets:

    ./vendor/bin/sail npm run build

Run the test suite:

    ./vendor/bin/sail pest

The application is available at:

    http://localhost

## Development

Start containers:

    ./vendor/bin/sail up -d

Start the Vite development server:

    ./vendor/bin/sail npm run dev

Run tests:

    ./vendor/bin/sail pest

Stop containers:

    ./vendor/bin/sail down

## Code Quality

Run tests:

    ./vendor/bin/sail pest

Check code style:

    ./vendor/bin/sail pint --test

Run static analysis:

    ./vendor/bin/sail php ./vendor/bin/phpstan analyse

Validate Composer configuration:

    ./vendor/bin/sail composer validate

## Architecture

The application will use provider-neutral interfaces for external speech services.

Planned provider contracts:

- SpeechToTextProvider
- TranslationProvider
- TextToSpeechProvider

Google Cloud will be used for the first implementation:

- Google Speech-to-Text
- Google Cloud Translation
- Google Text-to-Speech

Provider-specific API calls must not be placed directly in controllers.

## Project Status

Current milestone: Voice Translator MVP.

The initial project environment is configured with Laravel Sail, PHP 8.4, MySQL, Alpine.js, Tailwind CSS, Vite, and Pest.

Speech and translation integrations will be implemented in subsequent development tasks.