# SelfHelp Symfony Backend - Developer Documentation

Welcome to the comprehensive developer documentation for the SelfHelp Symfony Backend API. This documentation provides a complete understanding of the architecture, patterns, and conventions used in this project.

## 📁 Documentation Structure

This developer documentation is organized into the following sections:

### Core Architecture
- [🏗️ **System Architecture Overview**](./01-system-architecture.md) - High-level system design and components
- [🔄 **Dynamic Routing System**](./02-dynamic-routing.md) - How routes are loaded from database dynamically
- [🔐 **Authentication & Authorization**](./03-authentication-authorization.md) - JWT authentication and permission system
- [📊 **Database Design**](./04-database-design.md) - Database schema and entity relationships

### API Development
- [🌐 **API Design Patterns**](./05-api-patterns.md) - REST API conventions and response formats
- [📋 **JSON Schema Validation**](./06-json-schema-validation.md) - Request/response validation system
- [📝 **Versioning Strategy**](./07-versioning-strategy.md) - API versioning and migration approach

### Content Management System
- [📄 **CMS Architecture**](./08-cms-architecture.md) - Page, section, and field management
- [🎨 **Asset Management**](./09-asset-management.md) - File upload and asset handling
- [🔧 **Interpolation System**](./10-interpolation-system.md) - Variable interpolation and templating

### System Services
- [⚡ **Scheduled Jobs**](./11-scheduled-jobs.md) - Background task scheduling and execution
- [📈 **Transaction Logging**](./12-transaction-logging.md) - Audit trail and change tracking
- [🔍 **Access Control Lists (ACL)**](./13-acl-system.md) - Fine-grained permission system
- [🔒 **Data Access Management**](./19-data-access-management.md) - Role-based data access control with auditing

### Development Guidelines
- [🛠️ **Development Workflow**](./14-development-workflow.md) - How to add features and maintain code
- [🧪 **Testing Guidelines**](./15-testing-guidelines.md) - Testing strategies and best practices
- [🚀 **Deployment Process**](./16-deployment-process.md) - Version management and deployment
- [🧱 **Seeding System Pages**](./21-seeding-system-pages.md) - How `is_system` CMS pages (login, privacy, profile, …) are shipped and extended
- [🩺 **Local Debugging With A Production DB Copy**](./22-local-debugging-with-production-db.md) - What a DB dump restores locally, what plugin state must be rebuilt manually, and the safe recovery workflow
- [✅ **Backend CI Quality Gate**](./23-ci-quality-gate.md) - The `plugin-host-check` workflow: schemas, migrations, DI, doctor, and baselined PHPStan for the plugin scope
- [🛡️ **Core PHPStan Gate**](./24-core-phpstan-gate.md) - The strict, no-baseline `core-backend-check` workflow: how to run core PHPStan locally, why there is no baseline, and how to handle failures

## 🚀 Quick Start for New Developers

1. **Read the System Architecture** - Start with [System Architecture Overview](./01-system-architecture.md)
2. **Understand Dynamic Routing** - Essential for API development: [Dynamic Routing System](./02-dynamic-routing.md)
3. **Learn API Patterns** - Follow established conventions: [API Design Patterns](./05-api-patterns.md)
4. **Review Development Workflow** - Understand the process: [Development Workflow](./14-development-workflow.md)

## 🎯 Key Principles

This project follows these core principles:

### 1. **Database-Driven Configuration**
- API routes are stored in database, not code
- Permissions are managed through database relationships
- Configuration changes don't require code deployment

### 2. **Strict Version Management**
- **Major Version** (7.5.1 → 7.6.0): Database schema changes
- **Minor Version** (7.5.1 → 7.5.2): Code-only changes
- All database changes go through SQL update scripts

### 3. **Comprehensive Validation**
- All API requests validated against JSON schemas
- All API responses validated in debug mode
- Entity-schema alignment enforced

### 4. **Transaction Integrity**
- All CUD operations wrapped in database transactions
- Complete audit trail through TransactionService
- Automatic rollback on failures

### 5. **Consistent Response Format**
- Standardized JSON envelope for all responses
- Proper HTTP status codes
- Debug information in non-production environments

## 🔧 Technology Stack

- **Framework**: Symfony 7.4
- **PHP Version**: >= 8.4
- **ORM**: Doctrine ORM 3.6 / DBAL 4.4
- **Architecture**: PSR-4 compliant
- **Authentication**: JWT with LexikJWTAuthenticationBundle
- **Validation**: JSON Schema validation
- **Database**: MySQL 8 (Doctrine migrations); stored procedure `get_user_acl` for ACL checks; Redis for caching

## 📖 Additional Resources

- [Main Project Documentation](../../ARCHITECTURE.md) - Complete project documentation
- [API Routes](./02-dynamic-routing.md) - DB-driven `api_routes` table, loaded by `ApiRouteLoader`; rows are seeded/changed via Doctrine migrations
- [Database Schema](../../migrations/) - Doctrine migrations are the canonical schema (the baseline + seed migrations). `db/legacy/*.sql` is deprecated reference only
- [JSON Schemas](../../config/schemas/api/v1/) - Request/response validation schemas

---

**Note**: This documentation is maintained alongside the codebase. When making changes, ensure documentation is updated accordingly.
