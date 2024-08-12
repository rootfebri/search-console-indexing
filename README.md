# Laravel Indexing Application

This is a Laravel application designed to handle URL indexing using Google's Indexing API. The application integrates OAuth authentication and Amazon S3 for file storage.

## Features
- **URL Indexing**: Index URLs using Google's Indexing API.
- **OAuth Authentication**: Secure authentication using OAuth.
- **Amazon S3 Integration**: Store files using Amazon S3.
- **Progress Tracking**: Track the progress of URL indexing.
- **Error Handling**: Robust error handling and retry mechanisms.

## Requirements
- PHP 8.2 or higher
- Composer
- Laravel 11.x or higher
- Google API Client
- Guzzle HTTP Client
- Amazon S3 SDK

## Indexing Tutorial coming soon

## Installation
1. **Clone the repository**:
    ```sh
    git clone https://github.com/rootfebri/search-console-indexing.git
    cd search-console-indexing
    ```

2. **Install dependencies**:
    ```sh
    composer install
    ```

3. **Copy the example environment file and configure it**:
    ```sh
    cp .env.example .env
    ```

4. **Generate an application key**:
    ```sh
    php artisan key:generate
    ```

5. **Run database migrations**:
    ```sh
    php artisan migrate
    ```

## Usage
> [!TIP]
> Best ui with unix shell or WSL on windows

### Running the Indexing Command

```sh
# To start the indexing process, run the following command:
php artisan indexing
```
```sh
php artisan s3
```
> [!CAUTION]
> To make OAuth, first you need to run this on `localhost:80` changing the port to other could be challenging if you are not familiar with google api indexing
```sh
sudo php artisan serve --host=localhost --port=80
php artisan add
```