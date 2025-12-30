# Sentinel Chat Platform - iChat Web Interface

The main PHP web application for the Sentinel Chat Platform, located in the `iChat/` folder.

## Structure

```
iChat/
├── index.php          # Main entry point (root of iChat folder)
├── bootstrap.php      # Autoloader and environment setup
├── api/               # API endpoints
│   ├── messages.php   # Message queuing API
│   ├── im.php         # Instant messaging API
│   ├── admin.php      # Admin dashboard API
│   └── proxy.php      # Secure API proxy (server-side auth)
├── css/               # Stylesheets
│   └── styles.css     # Blizzard blue themed styles
├── js/                # JavaScript files
│   └── app.js         # Main application JavaScript
├── src/               # PHP classes
│   ├── Config.php     # Configuration manager
│   ├── Database.php   # Database connection manager
│   ├── Repositories/  # Data access layer
│   └── Services/      # Business logic services
├── tests/             # CLI-only test scripts
├── schema.sql         # Database schema
└── env.sample         # Environment configuration template
```

## Requirements

- PHP 8.3+ with PDO extension
- MySQL/MariaDB 5.7+ or 10.3+
- Web server (Apache/Nginx) configured to serve `iChat/` folder
- jQuery 3.7.1 (loaded from CDN)

## Installation

1. **Copy environment file:**
   ```bash
   cp env.sample .env
   ```

2. **Update `.env` with your configuration:**
   - Database credentials
   - API shared secret (CRITICAL: Change from default!)
   - Application URLs

3. **Create database:**
   ```bash
   mysql -u root -p < schema.sql
   ```

4. **Configure web server:**
   - Point document root to `iChat/` folder, OR
   - Map `/iChat/` URL path to the `iChat/` folder

5. **Set permissions:**
   ```bash
   chmod 755 iChat/
   chmod 666 iChat/logs/  # If logs directory exists
   ```

## Security Features

- **Prepared Statements**: All database queries use PDO prepared statements
- **Input Validation**: All user input is validated and sanitized
- **Security Headers**: CSP, X-Frame-Options, X-Content-Type-Options, etc.
- **API Proxy**: Server-side proxy prevents API secret exposure
- **Soft Deletes**: All data uses soft deletes for audit trails
- **Role-Based Access**: Different views for different user roles

## API Endpoints

All API endpoints require `X-API-SECRET` header (except proxy which handles it server-side).

### Messages API (`/iChat/api/messages.php`)
- `GET` - List pending messages
- `POST` - Enqueue a new message

### IM API (`/iChat/api/im.php`)
- `GET?action=inbox&user=handle` - Get inbox
- `POST?action=send` - Send IM
- `POST?action=login` - Promote queued IMs to sent
- `POST?action=open` - Mark IM as read
- `GET?action=badge&user=handle` - Get unread count

### Admin API (`/iChat/api/admin.php`)
- `GET` - Get admin dashboard data
- `POST?action=escrow-request` - Submit escrow request

### Proxy API (`/iChat/api/proxy.php`)
- `GET/POST?path=endpoint` - Secure proxy for client-side calls

## Views

- **User View**: Room pulse dashboard, message composer, pending outbox
- **Moderator View**: Flagged messages, moderation tools
- **Admin View**: Telemetry, escrow requests, system status
- **Area 51**: Secret Area

## Testing

CLI-only test scripts are located in `tests/` directory and are protected by `.htaccess` to prevent web access.

## Development

- Use PHP 8.3+ strict types
- Follow PSR-4 autoloading standards
- All database queries MUST use prepared statements
- Never expose API secrets in client-side code
- Always validate and sanitize user input

## License

MIT License - See LICENSE file for details

