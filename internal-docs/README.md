# Running locally

## Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (must be running)
- [Node.js](https://nodejs.org/) >= 24
- [PHP](https://www.php.net/) >= 8.1
- [Composer](https://getcomposer.org/)

## Getting Started

```bash
# Install dependencies
composer install
npm install

# Start the local WordPress environment
npm run dev
```

The site will be available at **http://localhost:8888**.

Log in at http://localhost:8888/wp-admin with:
- **Username:** `admin`
- **Password:** `password`

The plugin is automatically loaded and can be activated from **Plugins** in wp-admin.

## Available Commands

### Environment

| Command | Description |
|---------|-------------|
| `npm run dev` | Start WordPress |
| `npm run stop` | Stop WordPress |
| `npm run destroy` | Remove the environment and its data |
| `npm run wp-cli -- <command>` | Run a WP-CLI command (e.g. `npm run wp-cli -- plugin list`) |

### Code Quality

| Command | Description |
|---------|-------------|
| `composer check` | Run all checks (lint + phpcs + phpstan) |
| `composer lint` | PHP syntax check |
| `composer phpcs` | Check coding standards (WordPress VIP) |
| `composer phpcbf` | Auto-fix coding standards violations |
| `composer phpstan` | Static analysis |
| `composer test` | Run PHPUnit tests |
