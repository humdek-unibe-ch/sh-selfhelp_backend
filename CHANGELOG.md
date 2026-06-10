# v0.1.0 (Not released yet)

## Registration & User Management

- **Multi-group registration**: Users can now be enrolled in multiple groups at once during registration. Admins can select multiple groups in the register section, and new users are automatically added to all selected groups.
- **Open registration**: Admins can enable open registration that allows users to sign up with just their email address, no invitation code required. This is perfect for public-facing registrations.
- **Customizable registration labels**: All registration lifecycle labels (form fields, buttons, status messages) are now fully customizable through the CMS with support for multiple languages.

## Plugin System

- **Plugin registry integration**: Built-in plugin registry browser shows available plugins from configured sources. Browse, discover, and install plugins directly from the admin interface.
- **Official Humdek registry**: Pre-configured with the official Humdek plugin registry for easy access to trusted plugins.
- **System-managed plugin sources**: Core plugin sources are protected and can only be modified by system administrators, preventing accidental changes to critical registry configurations.
- **Improved plugin development**: Better support for local plugin development with automatic stylesheet URL resolution for live-reload during development.

## Cross-Repository CI/CD

- **Coordinated feature branch support**: CI workflows now support coordinated development across multiple repositories using the same branch name. Feature branches automatically validate against matching branches in sibling repos instead of always comparing to main.
- **Same-branch-or-main resolution**: Smart CI resolves sibling repository references to matching feature branches when available, falling back to main for solo branches or after merge.

## User Impersonation

- **Admin user impersonation**: Administrators can now impersonate any user to view the platform from their perspective. Perfect for troubleshooting user issues and providing support.
- **Audit logging**: All actions performed during impersonation are logged with both the original admin and the target user, maintaining a complete audit trail.
- **Real-time impersonation status**: Impersonation status is pushed in real-time via Mercure, so the UI immediately shows when impersonation is active.
- **Stop impersonation**: Dedicated endpoint to stop impersonation with proper JWT blacklisting.

## Real-Time Updates

- **ACL push notifications**: User permission changes are pushed in real-time via Mercure, eliminating the need for polling. When a user's permissions change, the UI updates instantly.
- **Impersonation notifications**: Real-time updates when impersonation starts or stops, providing immediate feedback to administrators and users.

## Security & Authentication

- **OAuth 2.0 compliant tokens**: Impersonation tokens follow RFC 8693 OAuth 2.0 Token Exchange standard for better compatibility and security.
- **Configurable token lifetimes**: All JWT token lifetimes (access, refresh, impersonation) are configurable via environment variables with sensible defaults.
- **Enhanced security**: Updated to latest Symfony security patches and dependency updates for improved security posture.

## Content Management

- **Complete style documentation**: Every CMS style is now fully documented with both administrator and developer perspectives, making it easier to understand and use the style system.
- **SEO improvements**: Page endpoints now return title and description metadata for better search engine optimization.
- **Canonical database schema**: Database tables and columns now follow consistent naming conventions (lowercase_snake_case) for better maintainability.

## Architecture & Performance

- **Doctrine migrations only**: Database bootstrap now uses only Doctrine migrations, eliminating the need for SQL bootstrap scripts and making upgrades more reliable.
- **Improved transaction handling**: All data-changing operations are wrapped in database transactions with comprehensive audit logging.
- **Performance optimizations**: Fixed N+1 query issues, added batch processing, and improved database query efficiency throughout the application.

## API & Integration

- **REST API v1**: Comprehensive REST API with versioning, JWT authentication, and refresh token support for secure third-party integrations.
- **Role-based access control**: Granular permission system with roles and permissions for fine-grained access control.
- **API request logging**: All API requests are logged for security auditing and debugging purposes.

## Documentation

- **Complete developer documentation**: Comprehensive documentation for authentication, authorization, plugin development, and CMS styling.
- **API usage guides**: Detailed guides for API endpoints, authentication flows, and common integration patterns.
- **Cross-repo compatibility**: Documentation for managing version alignment across the SelfHelp ecosystem.
