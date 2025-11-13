# SelfHelp WebApp

The SelfHelp WebApp is a modern, Symfony-based tool that allows to create sophisticated web applications serving as platforms for research experiments and data collection.

## Architecture

The application is built with a modular architecture where:

- **Pages** are organized as collections of sections rendered hierarchically
- **Sections** use different `styles` which define their appearance and behavior
- **Styles** contain configurable `fields` that define content and functionality
- **Fields** can contain simple values or nested child sections with their own styles

## Technical Stack

- **Framework**: Symfony 7.3
- **PHP**: 8.3+
- **Database**: MySQL 8.0+ with Doctrine ORM 3.3
- **API**: RESTful API with JWT authentication
- **UI Components**: Mantine UI library integration
- **Security**: Role-based access control (RBAC)

## Available Features

The system provides comprehensive functionality including:
- **CMS Management**: Full content management with hierarchical sections
- **User Management**: Role-based user system with permissions
- **API System**: RESTful API with JWT authentication and versioning
- **Form Builder**: Dynamic form creation with validation
- **Data Management**: Advanced data collection and export capabilities
- **Asset Management**: File upload and management system
- **Job Scheduling**: Automated task scheduling and execution
- **Multi-language Support**: Internationalization with translation system

 - For detailed architecture documentation refer to [ARCHITECTURE](ARCHITECTURE.md)
 - For development patterns and best practices refer to [DEVELOPMENT_GUIDE](DEVELOPMENT_GUIDE.md)
 - For information about recent changes refer to [CHANGELOG](CHANGELOG.md)
 - For comprehensive developer documentation refer to [Developer Docs](docs/developer/)
 - For API security architecture refer to [Security Guide](docs/api-security-architecture.md)
 - For permission system implementation refer to [Permission Guide](docs/permission-system-guide.md)

# Installation

## System Requirements

- **PHP**: 8.3 or higher
- **Database**: MySQL 8.0+ or MariaDB 10.5+
- **Web Server**: Apache 2.4+ or Nginx
- **Memory**: Minimum 512MB RAM, recommended 1GB+
- **Disk Space**: Minimum 500MB free space

## Install Dependencies

```bash
# Update package list
sudo apt update

# Install Apache web server
sudo apt install apache2

# Install MySQL/MariaDB database server
sudo apt install mysql-server

# Install PHP 8.3 and required extensions
sudo apt install php8.3 php8.3-fpm php8.3-mysql php8.3-xml php8.3-mbstring php8.3-intl php8.3-curl php8.3-zip php8.3-gd php8.3-apcu php8.3-opcache

# Install Composer (PHP dependency manager)
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Install Node.js and npm (for frontend assets)
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt-get install -y nodejs
```

## Install SelfHelp

```bash
# Clone the repository
sudo git clone https://github.com/humdek-unibe-ch/sh-selfhelp.git __project_name__

# Navigate to project directory
cd __project_name__

# Checkout the latest release (v8.0.0)
git checkout v8.0.0

# Install PHP dependencies
composer install --no-dev --optimize-autoloader

# Install Node.js dependencies
npm install

# Build frontend assets
npm run build

# Set proper permissions
sudo chown -R www-data:www-data .
sudo chmod -R 755 var/
sudo chmod -R 777 var/cache/ var/log/ var/sessions/
```

## Database Setup

```bash
# Create database
sudo mysql -u root -p
```

```sql
CREATE DATABASE __project_name__ CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '__db_user__'@'localhost' IDENTIFIED BY '__db_password__';
GRANT ALL PRIVILEGES ON __project_name__.* TO '__db_user__'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## Environment Configuration

```bash
# Copy environment template
cp .env.example .env

# Edit environment configuration
nano .env
```

Configure the following variables:
```env
APP_ENV=prod
APP_SECRET=your-secret-key-here
DATABASE_URL=mysql://__db_user__:__db_password__@127.0.0.1:3306/__project_name__
JWT_SECRET_KEY=your-jwt-secret-here
CORS_ALLOW_ORIGIN=^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$
```

## JWT Key Generation

The application uses JWT (JSON Web Tokens) for API authentication. You need to generate RSA key pairs for JWT token signing and verification.

### Generate JWT Keys

```bash
# Create JWT directory structure
mkdir -p config/jwt

# Generate private key (for production)
openssl genrsa -out config/jwt/private.pem -aes256 4096

# Generate public key from private key (for production)
openssl rsa -pubout -in config/jwt/private.pem -out config/jwt/public.pem

# For testing/development environment (no passphrase)
mkdir -p config/jwt/test
openssl genrsa -out config/jwt/test/private.pem 4096
openssl rsa -pubout -in config/jwt/test/private.pem -out config/jwt/test/public.pem
```

### Environment Variables

Update your `.env` file with the correct paths to the JWT keys:

```env
# For production
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem

# For testing (no passphrase required)
# JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/test/private.pem
# JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/test/public.pem
```

**Important**: Never commit JWT private keys to version control. Add `config/jwt/*.pem` to your `.gitignore` file.

## Database Migration

```bash
# Run Doctrine migrations
php bin/console doctrine:migrations:migrate --no-interaction

# Load initial data (if available)
php bin/console doctrine:fixtures:load --no-interaction
```

## Create Admin User

```bash
# Create initial admin user
php bin/console app:create-admin-user your.email@example.com "Admin Name"
```
## Web Server Configuration

### Apache Configuration

Create Apache virtual host configuration. Create `/etc/apache2/sites-available/__project_name__.conf`:

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    ServerAlias www.your-domain.com
    DocumentRoot /var/www/__project_name__/public

    <Directory /var/www/__project_name__/public>
        AllowOverride All
        Require all granted

        # Enable PHP-FPM
        <FilesMatch \.php$>
            SetHandler "proxy:unix:/var/run/php/php8.3-fpm.sock|fcgi://localhost"
        </FilesMatch>

        # Security headers
        Header always set X-Content-Type-Options nosniff
        Header always set X-Frame-Options DENY
        Header always set X-XSS-Protection "1; mode=block"
        Header always set Referrer-Policy "strict-origin-when-cross-origin"
    </Directory>

    # Enable HTTP/2
    Protocols h2 h2c http/1.1

    ErrorLog ${APACHE_LOG_DIR}/__project_name___error.log
    CustomLog ${APACHE_LOG_DIR}/__project_name___access.log combined

    # Redirect to HTTPS
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</VirtualHost>

<VirtualHost *:443>
    ServerName your-domain.com
    ServerAlias www.your-domain.com
    DocumentRoot /var/www/__project_name__/public

    <Directory /var/www/__project_name__/public>
        AllowOverride All
        Require all granted

        # Enable PHP-FPM
        <FilesMatch \.php$>
            SetHandler "proxy:unix:/var/run/php/php8.3-fpm.sock|fcgi://localhost"
        </FilesMatch>
    </Directory>

    # SSL Configuration
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/your-domain.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/your-domain.com/privkey.pem
    Include /etc/letsencrypt/options-ssl-apache.conf

    # Security headers
    Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
    Header always set X-Content-Type-Options nosniff
    Header always set X-Frame-Options DENY
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"

    # Enable HTTP/2
    Protocols h2 http/1.1

    ErrorLog ${APACHE_LOG_DIR}/__project_name___ssl_error.log
    CustomLog ${APACHE_LOG_DIR}/__project_name___ssl_access.log combined
</VirtualHost>
```

Enable the site and required modules:

```bash
# Enable required Apache modules
sudo a2enmod proxy_fcgi setenvif rewrite ssl headers

# Enable the site
sudo a2ensite __project_name__

# Disable default site
sudo a2dissite 000-default

# Reload Apache
sudo systemctl reload apache2
```

### Nginx Configuration (Alternative)

If using Nginx instead of Apache, create `/etc/nginx/sites-available/__project_name__`:

```nginx
server {
    listen 80;
    server_name your-domain.com www.your-domain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name your-domain.com www.your-domain.com;

    root /var/www/__project_name__/public;
    index index.php index.html;

    # SSL Configuration
    ssl_certificate /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

    # Security headers
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # PHP Configuration
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    # Static files
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    # Symfony routing
    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    # Deny access to sensitive files
    location ~ /(config|src|var)/ {
        deny all;
        return 404;
    }

    # Logs
    error_log /var/log/nginx/__project_name___error.log;
    access_log /var/log/nginx/__project_name___access.log;
}
```

Enable the Nginx site:

```bash
# Enable the site
sudo ln -s /etc/nginx/sites-available/__project_name__ /etc/nginx/sites-enabled/

# Remove default site
sudo rm /etc/nginx/sites-enabled/default

# Test configuration
sudo nginx -t

# Reload Nginx
sudo systemctl reload nginx
```

## Update Process

### Upgrading from Previous Versions

**Important**: Version 8.0.0 is a complete rewrite. Direct upgrades from versions prior to 8.0.0 are not supported. Please backup your data before attempting any upgrade.

### For Existing v8.x Installations

```bash
# Pull latest changes
git pull origin main

# Install new dependencies
composer install --no-dev --optimize-autoloader

# Update Node.js dependencies
npm install && npm run build

# Run database migrations
php bin/console doctrine:migrations:migrate

# Clear and warmup cache
php bin/console cache:clear
php bin/console cache:warmup

# Update assets permissions
sudo chown -R www-data:www-data var/ public/
sudo chmod -R 755 var/
sudo chmod -R 777 var/cache/ var/log/ var/sessions/
```

## Post-Installation

### Admin Account Setup

After successful installation, create your first admin user:

```bash
# Create admin user (replace with your email and name)
php bin/console app:create-admin-user your.email@example.com "Your Admin Name"
```

This command will:
- Create a new user account
- Assign admin role with full permissions
- Send an account validation email

### Initial Configuration

1. **Access the Application**: Navigate to `https://your-domain.com`
2. **Complete Registration**: Check your email for the validation link
3. **First Login**: Use your email and the password from the validation email
4. **CMS Access**: Navigate to `/admin` to access the CMS interface

### Environment-Specific Setup

#### Production Environment

```bash
# Set production environment
echo "APP_ENV=prod" >> .env

# Generate optimized autoloader
composer install --no-dev --optimize-autoloader --classmap-authoritative

# Install assets for production
npm run build

# Clear cache for production
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
```

#### Development Environment

```bash
# Set development environment
echo "APP_ENV=dev" >> .env

# Install all dependencies (including dev)
composer install

# Enable debug mode
echo "APP_DEBUG=1" >> .env
```

### SSL Certificate Setup (Let's Encrypt)

```bash
# Install Certbot
sudo apt install certbot python3-certbot-apache

# Obtain SSL certificate
sudo certbot --apache -d your-domain.com -d www.your-domain.com

# Test certificate renewal
sudo certbot renew --dry-run
```

### Monitoring and Maintenance

#### Log Files
- Application logs: `var/log/prod.log` (production) or `var/log/dev.log` (development)
- Web server logs: Check Apache/Nginx log directories configured above

#### Cache Management
```bash
# Clear Symfony cache
php bin/console cache:clear

# Clear Doctrine cache (if using Redis/APCu)
php bin/console doctrine:cache:clear-metadata
php bin/console doctrine:cache:clear-query
php bin/console doctrine:cache:clear-result
```

#### Database Maintenance
```bash
# Create backup
mysqldump -u username -p database_name > backup_$(date +%Y%m%d_%H%M%S).sql

# Check database status
php bin/console doctrine:query:sql "SHOW PROCESSLIST"
```

## Development

### Code Quality & Testing

#### Static Analysis
```bash
# Run PHPStan for static analysis
vendor/bin/phpstan analyse src --level=8

# Run PHP CS Fixer for code style
vendor/bin/php-cs-fixer fix --dry-run --diff

# Apply code style fixes
vendor/bin/php-cs-fixer fix
```

#### Testing
```bash
# Run PHPUnit tests
vendor/bin/phpunit

# Run tests with coverage
vendor/bin/phpunit --coverage-html=var/coverage

# Run specific test suite
vendor/bin/phpunit --testsuite=unit
```

#### Code Quality Tools
- **[PHPStan](https://phpstan.org)**: Static analysis for PHP
- **[PHP CS Fixer](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer)**: Code style fixing
- **[PHPUnit](https://phpunit.de)**: Unit testing framework
- **[Psalm](https://psalm.dev)**: Alternative static analysis (optional)

### Asset Management

#### Development Assets
```bash
# Install dependencies
npm install

# Watch for changes during development
npm run watch

# Build for production
npm run build

# Build with source maps for debugging
npm run dev
```

### Symfony Console Commands

#### Common Commands
```bash
# List all available commands
php bin/console list

# Clear cache
php bin/console cache:clear

# Create database schema
php bin/console doctrine:schema:create

# Generate Doctrine entities from database
php bin/console doctrine:mapping:import "App\Entity" annotation --path=src/Entity

# Generate database migration
php bin/console doctrine:migrations:diff

# Run migrations
php bin/console doctrine:migrations:migrate
```

#### Debug Commands
```bash
# Debug routes
php bin/console debug:router

# Debug container services
php bin/console debug:container

# Debug Doctrine entities
php bin/console doctrine:mapping:info

# Show current configuration
php bin/console config:dump framework
```

### API Documentation

The REST API is documented using OpenAPI/Swagger. Access the documentation at:
- Development: `https://your-domain.com/api/doc`
- Production: Check your API documentation endpoint

### Performance Optimization

#### Production Optimizations
```bash
# Enable OPcache (already configured in PHP 8.3)
php -m | grep opcache

# Preload configuration (PHP 7.4+)
echo "opcache.preload=/var/www/project/config/preload.php" >> /etc/php/8.3/fpm/php.ini

# Use Redis for sessions (optional)
composer require symfony/redis-pack
echo "SESSION_HANDLER=redis" >> .env
```

#### Monitoring
```bash
# Enable Symfony profiler in development
echo "APP_DEBUG=1" >> .env

# Blackfire.io integration (optional)
composer require blackfire/php-sdk

# Enable Symfony web profiler
composer require --dev symfony/web-profiler-bundle
```

## Troubleshooting

### Common Issues

#### Permission Issues
```bash
# Fix var directory permissions
sudo chown -R www-data:www-data var/
sudo chmod -R 755 var/
sudo chmod -R 777 var/cache/ var/log/ var/sessions/
```

#### Database Connection Issues
```bash
# Test database connection
php bin/console doctrine:query:sql "SELECT 1"

# Check database configuration
php bin/console config:dump doctrine
```

#### Cache Issues
```bash
# Clear all caches
php bin/console cache:clear
rm -rf var/cache/*
php bin/console cache:warmup
```

#### Composer Issues
```bash
# Clear Composer cache
composer clear-cache

# Reinstall dependencies
rm -rf vendor/
composer install
```

### Support

For support and bug reports:
- Check the [CHANGELOG](CHANGELOG.md) for recent changes
- Review [ARCHITECTURE](ARCHITECTURE.md) for detailed technical documentation
- Create issues at: `https://github.com/humdek-unibe-ch/sh-selfhelp/issues`

### Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

### License

This project is licensed under the terms specified in the LICENSE file.