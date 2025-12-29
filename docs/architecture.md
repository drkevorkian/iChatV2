# Sentinel Chat Platform - Architecture Documentation

## Overview

Sentinel Chat is a security-first, multi-platform encrypted chat application with a modular architecture designed for scalability and security.

## System Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                      Client Layer                            │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐       │
│  │  Web (PHP)   │  │ Flutter App  │  │  Future...   │       │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘       │
└─────────┼─────────────────┼─────────────────┼───────────────┘
          │                 │                 │
          └─────────────────┼─────────────────┘
                            │
┌───────────────────────────┼───────────────────────────────────┐
│                    API Gateway Layer                           │
│  ┌──────────────────────────────────────────────────────┐     │
│  │         Secure API Proxy (PHP)                       │     │
│  │  - Handles authentication server-side                │     │
│  │  - Prevents API secret exposure                      │     │
│  └──────────────────────────────────────────────────────┘     │
└───────────────────────────┼───────────────────────────────────┘
                            │
┌───────────────────────────┼───────────────────────────────────┐
│                    Application Layer                           │
│  ┌──────────────────────────────────────────────────────┐     │
│  │         NestJS Backend (TypeScript)                   │     │
│  │  - REST API endpoints                                 │     │
│  │  - WebSocket for real-time                            │     │
│  │  - GraphQL for directory/config                       │     │
│  │  - gRPC for internal services                         │     │
│  └──────────────────────────────────────────────────────┘     │
│                                                                 │
│  ┌──────────────────────────────────────────────────────┐     │
│  │         Python Runtime Service (FastAPI)              │     │
│  │  - Drains temporary outbox                            │     │
│  │  - Syncs messages to primary server                   │     │
│  └──────────────────────────────────────────────────────┘     │
└───────────────────────────┼───────────────────────────────────┘
                            │
┌───────────────────────────┼───────────────────────────────────┐
│                      Data Layer                                │
│  ┌──────────────────┐  ┌──────────────────┐                 │
│  │  PostgreSQL      │  │  MySQL/MariaDB    │                 │
│  │  (Primary DB)    │  │  (Temp Outbox)    │                 │
│  └──────────────────┘  └──────────────────┘                 │
│                                                                 │
│  ┌──────────────────┐                                         │
│  │  Redis           │                                         │
│  │  (Cache/Sessions)│                                         │
│  └──────────────────┘                                         │
└─────────────────────────────────────────────────────────────────┘
```

## Component Details

### 1. PHP Web Interface (`iChat/`)

**Purpose**: Main web application providing user interface and temporary message queuing.

**Key Features**:
- Blizzard blue themed UI
- Role-based views (User, Moderator, Admin, Area 51)
- Message queuing when primary server is offline
- Instant Messaging with read receipts
- Admin dashboard with telemetry
- Secure API proxy for client-side calls

**Security**:
- All database queries use prepared statements
- Server-side API proxy prevents secret exposure
- Security headers (CSP, X-Frame-Options, etc.)
- Input validation and sanitization
- Soft deletes for audit trails

### 2. NestJS Backend (`server/src/`)

**Purpose**: Primary API server handling core business logic.

**Modules**:
- **Health**: Health check endpoints
- **Messaging**: Message operations
- **Rooms**: Room management and access control
- **Key Escrow**: Escrow request management
- **Word Filter**: Message filtering service

**Security**:
- Helmet middleware for security headers
- Rate limiting with Throttler
- CORS configuration
- Validation pipes
- JWT authentication (future)

### 3. Python Runtime Service (`server/runtime/`)

**Purpose**: Drains temporary outbox and syncs to primary server.

**Features**:
- Batch processing of queued messages
- Automatic retry on failure
- Health check endpoints
- Configurable batch size and interval

**Security**:
- SQLAlchemy ORM with parameterized queries
- API secret authentication
- Trusted host middleware

## Data Flow

### Message Sending Flow

1. User composes message in web interface
2. Message is encrypted client-side (simplified in current implementation)
3. Message is queued to MySQL temp_outbox via PHP API
4. Python runtime service polls temp_outbox
5. Runtime service delivers to NestJS primary server
6. NestJS processes message (filtering, storage)
7. Message marked as delivered in temp_outbox

### Instant Messaging Flow

1. User sends IM via web interface
2. IM stored in MySQL im_messages table (queued status)
3. When primary server is available, IMs are promoted to "sent"
4. Recipient views inbox, IM marked as "read" when opened
5. Read receipts tracked via read_at timestamp

## Security Architecture

### Encryption

- **In Transit**: TLS 1.3 for all connections
- **At Rest**: AES-256-GCM for message encryption
- **Key Exchange**: RSA-4096 for room access keys

### Authentication & Authorization

- **API Secrets**: Shared secrets for service-to-service communication
- **Role-Based Access Control**: Guest, Member, Paid-Member, Owner, Moderator, Administrator
- **JWT Tokens**: For user authentication (future)

### Database Security

- **Prepared Statements**: All queries use parameterized statements
- **Soft Deletes**: Never hard delete user data
- **Connection Pooling**: Limits and manages database connections
- **SSL/TLS**: Encrypted database connections in production

## Deployment Architecture

### Development
- Single server running all services
- Local databases
- Development certificates

### Production
- Load balancer → Multiple NestJS instances
- Separate database servers (PostgreSQL primary, MySQL temp)
- Redis cluster for caching
- CDN for static assets
- Docker containers for services
- Kubernetes orchestration (future)

## Scalability Considerations

- **Horizontal Scaling**: Stateless services can scale horizontally
- **Database Sharding**: Room-based sharding for large scale
- **Caching**: Redis for frequently accessed data
- **Message Queue**: RabbitMQ/Kafka for high-volume messaging (future)
- **CDN**: Static asset delivery

## Monitoring & Observability

- **Health Checks**: `/health`, `/ready`, `/live` endpoints
- **Logging**: Structured logging with correlation IDs
- **Metrics**: Prometheus metrics (future)
- **Tracing**: Distributed tracing (future)
- **Alerting**: PagerDuty/OpsGenie integration (future)

## Future Enhancements

- Flutter mobile clients (iOS, Android)
- WebSocket real-time messaging
- GraphQL API for directory/config
- gRPC for internal service communication
- End-to-end encryption with key escrow
- Multi-region deployment
- Infrastructure as Code (Terraform/CloudFormation)

## Security Threat Model

### Threats Addressed

1. **SQL Injection**: Prevented by prepared statements
2. **XSS**: Prevented by output encoding and CSP headers
3. **CSRF**: Prevented by CSRF tokens
4. **Man-in-the-Middle**: Prevented by TLS 1.3
5. **API Secret Exposure**: Prevented by server-side proxy
6. **Data Leakage**: Prevented by encryption at rest
7. **DDoS**: Mitigated by rate limiting
8. **Privilege Escalation**: Prevented by RBAC

### Security Best Practices

- Defense in depth
- Principle of least privilege
- Secure by default
- Fail securely
- Security through obscurity is NOT relied upon
- Regular security audits
- Penetration testing
- Bug bounty program (future)

